terraform {

  required_version = ">= 1.13, < 2.0"
  required_providers {
    aws = {
      source = "hashicorp/aws", version = "~> 6.0"
    }
  }

}
provider "aws" {

  region = var.aws_region
  default_tags {
    tags = {
      Project = "stratus2", ManagedBy = "terraform"
    }
  }

}
variable "aws_region" {
  type    = string
  default = "us-west-2"

}
variable "repository_subject" {

  description = "Exact repository portion of the GitHub OIDC subject, including immutable IDs when enabled."
  type        = string

}
variable "hosted_zone_id" {
  description = "The Route 53 zone this deployment may modify."
  type        = string
}

variable "existing_oidc_provider_arn" {
  type    = string
  default = ""

}
data "aws_caller_identity" "current" {

}
locals {
  prefix = "stratus2-${data.aws_caller_identity.current.account_id}"
}
resource "aws_s3_bucket" "state" {

  bucket = "${local.prefix}-tfstate"
  lifecycle {
    prevent_destroy = true
  }

}
resource "aws_s3_bucket_versioning" "state" {
  bucket = aws_s3_bucket.state.id
  versioning_configuration {
    status = "Enabled"
  }

}
resource "aws_s3_bucket_server_side_encryption_configuration" "state" {
  bucket = aws_s3_bucket.state.id
  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }

}
resource "aws_s3_bucket_public_access_block" "state" {

  bucket                  = aws_s3_bucket.state.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true

}
resource "aws_s3_bucket_policy" "state" {
  bucket = aws_s3_bucket.state.id
  policy = jsonencode({
    Version = "2012-10-17", Statement = [{
      Sid = "TLSOnly", Effect = "Deny", Principal = "*", Action = "s3:*", Resource = [aws_s3_bucket.state.arn, "${aws_s3_bucket.state.arn}/*"], Condition = {
        Bool = {
          "aws:SecureTransport" = "false"
        }
      }
    }]
  })

}
resource "aws_ecr_repository" "images" {

  for_each             = toset(["nextcloud", "worker"])
  name                 = "stratus2-${each.key}"
  image_tag_mutability = "IMMUTABLE"
  image_scanning_configuration {
    scan_on_push = true
  }
  encryption_configuration {
    encryption_type = "AES256"
  }
  lifecycle {
    prevent_destroy = true
  }

}
resource "aws_ecr_lifecycle_policy" "images" {

  for_each   = aws_ecr_repository.images
  repository = each.value.name
  policy = jsonencode({
    rules = [{
      rulePriority = 1, description = "Retain 20 immutable releases", selection = {
        tagStatus = "any", countType = "imageCountMoreThan", countNumber = 20
        }, action = {
        type = "expire"
      }
    }]
  })

}
resource "aws_ecr_repository_policy" "lambda_pull" {
  repository = aws_ecr_repository.images["worker"].name
  policy     = jsonencode({ Version = "2012-10-17", Statement = [{ Sid = "LambdaImagePull", Effect = "Allow", Principal = { Service = "lambda.amazonaws.com" }, Action = ["ecr:BatchGetImage", "ecr:GetDownloadUrlForLayer"], Condition = { StringEquals = { "aws:SourceAccount" = data.aws_caller_identity.current.account_id }, ArnLike = { "aws:SourceArn" = "arn:aws:lambda:${var.aws_region}:${data.aws_caller_identity.current.account_id}:function:stratus2-*" } } }] })
}
resource "aws_secretsmanager_secret" "app" {

  name                    = "stratus2/app"
  description             = "Runtime credentials; populate outside Terraform so values never enter state."
  recovery_window_in_days = 30
  lifecycle {
    prevent_destroy = true
  }

}
resource "aws_secretsmanager_secret" "openai" {

  name                    = "stratus2/openai"
  description             = "OpenAI worker key only; no Nextcloud administrator or storage credentials."
  recovery_window_in_days = 30
  lifecycle {
    prevent_destroy = true
  }

}
resource "aws_iam_openid_connect_provider" "github" {

  count          = var.existing_oidc_provider_arn == "" ? 1 : 0
  url            = "https://token.actions.githubusercontent.com"
  client_id_list = ["sts.amazonaws.com"]

}
resource "aws_iam_role" "deploy" {

  name = "stratus2-github-deploy"
  assume_role_policy = jsonencode({
    Version = "2012-10-17", Statement = [{
      Effect = "Allow", Principal = {
        Federated = var.existing_oidc_provider_arn != "" ? var.existing_oidc_provider_arn : aws_iam_openid_connect_provider.github[0].arn
        }, Action = "sts:AssumeRoleWithWebIdentity", Condition = {
        StringEquals = {
          "token.actions.githubusercontent.com:aud" = "sts.amazonaws.com", "token.actions.githubusercontent.com:sub" = "repo:${var.repository_subject}:environment:production"
        }
      }
    }]
  })

}
locals {
  deployment_statements = [
    {
      Sid = "ReadStagingPromotionArtifacts", Effect = "Allow", Action = ["s3:GetObject"], Resource = "arn:aws:s3:::stratus2-staging-${data.aws_caller_identity.current.account_id}-backups/releases/*"
    },
    {
      Sid = "ReadStagingPromotionImages", Effect = "Allow", Action = ["ecr:DescribeImages", "ecr:BatchGetImage", "ecr:GetDownloadUrlForLayer", "ecr:BatchCheckLayerAvailability"], Resource = "arn:aws:ecr:${var.aws_region}:${data.aws_caller_identity.current.account_id}:repository/stratus2-staging-*"
    },
    {
      Sid = "TerraformState", Effect = "Allow", Action = ["s3:ListBucket"], Resource = aws_s3_bucket.state.arn
    },
    {
      Sid = "TerraformStateObjects", Effect = "Allow", Action = ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"], Resource = "${aws_s3_bucket.state.arn}/runtime/*"
    },
    {
      Sid = "EcrLogin", Effect = "Allow", Action = ["ecr:GetAuthorizationToken"], Resource = "*"
    },
    {
      Sid = "ReleaseImages", Effect = "Allow", Action = ["ecr:BatchCheckLayerAvailability", "ecr:GetDownloadUrlForLayer", "ecr:BatchGetImage", "ecr:PutImage", "ecr:InitiateLayerUpload", "ecr:UploadLayerPart", "ecr:CompleteLayerUpload", "ecr:DescribeImages", "ecr:DescribeRepositories"], Resource = [for r in aws_ecr_repository.images : r.arn]
    },
    {
      Sid = "RuntimeNetwork", Effect = "Allow", Action = ["ec2:Describe*", "ec2:CreateVpc", "ec2:DeleteVpc", "ec2:ModifyVpcAttribute", "ec2:CreateSubnet", "ec2:DeleteSubnet", "ec2:ModifySubnetAttribute", "ec2:CreateInternetGateway", "ec2:DeleteInternetGateway", "ec2:AttachInternetGateway", "ec2:DetachInternetGateway", "ec2:CreateRouteTable", "ec2:DeleteRouteTable", "ec2:CreateRoute", "ec2:DeleteRoute", "ec2:AssociateRouteTable", "ec2:DisassociateRouteTable", "ec2:CreateSecurityGroup", "ec2:DeleteSecurityGroup", "ec2:AuthorizeSecurityGroupIngress", "ec2:AuthorizeSecurityGroupEgress", "ec2:RevokeSecurityGroupIngress", "ec2:RevokeSecurityGroupEgress", "ec2:CreateTags", "ec2:DeleteTags", "ec2:RunInstances", "ec2:TerminateInstances", "ec2:ModifyInstanceAttribute", "ec2:ModifyInstanceCreditSpecification", "ec2:CreateVolume", "ec2:DeleteVolume", "ec2:ModifyVolume", "ec2:AttachVolume", "ec2:DetachVolume", "ec2:AssociateIamInstanceProfile", "ec2:DisassociateIamInstanceProfile", "ec2:ReplaceIamInstanceProfileAssociation"], Resource = "*"
    },
    {
      Sid = "RuntimeAlb", Effect = "Allow", Action = ["elasticloadbalancing:CreateLoadBalancer", "elasticloadbalancing:DeleteLoadBalancer", "elasticloadbalancing:Describe*", "elasticloadbalancing:CreateTargetGroup", "elasticloadbalancing:DeleteTargetGroup", "elasticloadbalancing:ModifyTargetGroup", "elasticloadbalancing:ModifyTargetGroupAttributes", "elasticloadbalancing:ModifyLoadBalancerAttributes", "elasticloadbalancing:SetSecurityGroups", "elasticloadbalancing:SetSubnets", "elasticloadbalancing:CreateListener", "elasticloadbalancing:ModifyListener", "elasticloadbalancing:DeleteListener", "elasticloadbalancing:RegisterTargets", "elasticloadbalancing:DeregisterTargets", "elasticloadbalancing:AddTags", "elasticloadbalancing:RemoveTags"], Resource = "*"
    },
    {
      Sid = "Certificates", Effect = "Allow", Action = ["acm:RequestCertificate", "acm:DescribeCertificate", "acm:DeleteCertificate", "acm:AddTagsToCertificate", "acm:RemoveTagsFromCertificate", "acm:ListTagsForCertificate"], Resource = "*"
    },
    {
      Sid = "RuntimeQueues", Effect = "Allow", Action = ["sqs:CreateQueue", "sqs:DeleteQueue", "sqs:GetQueueAttributes", "sqs:GetQueueUrl", "sqs:SetQueueAttributes", "sqs:TagQueue", "sqs:UntagQueue", "sqs:ListQueueTags"], Resource = "arn:aws:sqs:${var.aws_region}:${data.aws_caller_identity.current.account_id}:stratus2-*"
    },
    {
      Sid = "RuntimeLambda", Effect = "Allow", Action = ["lambda:CreateFunction", "lambda:UpdateFunctionCode", "lambda:UpdateFunctionConfiguration", "lambda:DeleteFunction", "lambda:GetFunction", "lambda:GetFunctionConfiguration", "lambda:ListVersionsByFunction", "lambda:GetFunctionCodeSigningConfig", "lambda:GetRuntimeManagementConfig", "lambda:PutFunctionConcurrency", "lambda:GetFunctionConcurrency", "lambda:DeleteFunctionConcurrency", "lambda:TagResource", "lambda:UntagResource", "lambda:ListTags", "lambda:PublishVersion", "lambda:GetAlias", "lambda:CreateAlias", "lambda:UpdateAlias", "lambda:ListAliases"], Resource = "arn:aws:lambda:${var.aws_region}:${data.aws_caller_identity.current.account_id}:function:stratus2-*"
    },
    {
      Sid = "RuntimeEventMappings", Effect = "Allow", Action = ["lambda:CreateEventSourceMapping", "lambda:UpdateEventSourceMapping", "lambda:DeleteEventSourceMapping", "lambda:GetEventSourceMapping", "lambda:ListEventSourceMappings"], Resource = "*"
    },
    {
      Sid = "RuntimeRoles", Effect = "Allow", Action = ["iam:CreateRole", "iam:DeleteRole", "iam:GetRole", "iam:UpdateAssumeRolePolicy", "iam:PutRolePolicy", "iam:GetRolePolicy", "iam:DeleteRolePolicy", "iam:ListRolePolicies", "iam:AttachRolePolicy", "iam:DetachRolePolicy", "iam:ListAttachedRolePolicies", "iam:TagRole", "iam:UntagRole", "iam:ListInstanceProfilesForRole"], Resource = ["arn:aws:iam::${data.aws_caller_identity.current.account_id}:role/stratus2-node", "arn:aws:iam::${data.aws_caller_identity.current.account_id}:role/stratus2-worker"]
    },
    {
      Sid = "InstanceProfile", Effect = "Allow", Action = ["iam:CreateInstanceProfile", "iam:DeleteInstanceProfile", "iam:GetInstanceProfile", "iam:AddRoleToInstanceProfile", "iam:RemoveRoleFromInstanceProfile", "iam:TagInstanceProfile", "iam:UntagInstanceProfile"], Resource = "arn:aws:iam::${data.aws_caller_identity.current.account_id}:instance-profile/stratus2-*"
    },
    {
      Sid = "PassRuntimeRoles", Effect = "Allow", Action = ["iam:PassRole"], Resource = ["arn:aws:iam::${data.aws_caller_identity.current.account_id}:role/stratus2-node", "arn:aws:iam::${data.aws_caller_identity.current.account_id}:role/stratus2-worker"], Condition = {
        StringEquals = {
          "iam:PassedToService" = ["ec2.amazonaws.com", "lambda.amazonaws.com"]
        }
      }
    },
    {
      Sid = "ServiceLinkedRole", Effect = "Allow", Action = ["iam:CreateServiceLinkedRole"], Resource = "arn:aws:iam::${data.aws_caller_identity.current.account_id}:role/aws-service-role/elasticloadbalancing.amazonaws.com/*", Condition = {
        StringEquals = {
          "iam:AWSServiceName" = "elasticloadbalancing.amazonaws.com"
        }
      }
    },
    {
      Sid = "RuntimeBuckets", Effect = "Allow", Action = ["s3:CreateBucket", "s3:DeleteBucket", "s3:GetBucket*", "s3:GetAccelerateConfiguration", "s3:GetReplicationConfiguration", "s3:ListBucket", "s3:PutBucketVersioning", "s3:PutEncryptionConfiguration", "s3:GetEncryptionConfiguration", "s3:PutBucketPublicAccessBlock", "s3:GetBucketPublicAccessBlock", "s3:PutBucketPolicy", "s3:DeleteBucketPolicy", "s3:PutLifecycleConfiguration", "s3:GetLifecycleConfiguration", "s3:PutBucketTagging"], Resource = "arn:aws:s3:::${local.prefix}-backups"
    },
    {
      Sid = "ReleaseBundle", Effect = "Allow", Action = ["s3:PutObject", "s3:GetObject"], Resource = "arn:aws:s3:::${local.prefix}-backups/releases/*"
    },
    {
      Sid = "Monitoring", Effect = "Allow", Action = ["logs:CreateLogGroup", "logs:DeleteLogGroup", "logs:DescribeLogGroups", "logs:PutRetentionPolicy", "logs:DeleteRetentionPolicy", "logs:ListTagsForResource", "logs:TagResource", "logs:UntagResource", "cloudwatch:PutMetricAlarm", "cloudwatch:DeleteAlarms", "cloudwatch:DescribeAlarms", "cloudwatch:ListTagsForResource", "cloudwatch:TagResource", "cloudwatch:UntagResource", "sns:CreateTopic", "sns:DeleteTopic", "sns:GetTopicAttributes", "sns:SetTopicAttributes", "sns:Subscribe", "sns:Unsubscribe", "sns:GetSubscriptionAttributes", "sns:ListTagsForResource", "sns:TagResource", "sns:UntagResource", "budgets:ViewBudget", "budgets:ModifyBudget"], Resource = "*"
    },
    {
      Sid = "AmiLookup", Effect = "Allow", Action = ["ssm:GetParameter"], Resource = "arn:aws:ssm:${var.aws_region}::parameter/aws/service/ami-amazon-linux-latest/*"
    },
    {
      Sid = "DeploymentCommand", Effect = "Allow", Action = ["ssm:SendCommand"], Resource = ["arn:aws:ssm:${var.aws_region}::document/AWS-RunShellScript", "arn:aws:ec2:${var.aws_region}:${data.aws_caller_identity.current.account_id}:instance/*"]
    },
    {
      Sid = "DeploymentStatus", Effect = "Allow", Action = ["ssm:GetCommandInvocation", "ssm:DescribeInstanceInformation"], Resource = "*"
    },
    {
      Sid = "Dns", Effect = "Allow", Action = ["route53:GetHostedZone", "route53:ListResourceRecordSets", "route53:ChangeResourceRecordSets", "route53:ListTagsForResource", "route53:ChangeTagsForResource", "route53:GetChange"], Resource = ["arn:aws:route53:::hostedzone/${var.hosted_zone_id}", "arn:aws:route53:::change/*"]
    },
    {
      Sid = "BudgetTagRead", Effect = "Allow", Action = ["budgets:ListTagsForResource"], Resource = "arn:aws:budgets::${data.aws_caller_identity.current.account_id}:budget/stratus2-monthly"
    },
    {
      Sid = "RuntimeVpcSecurityGroupLookup", Effect = "Allow", Action = ["ec2:GetSecurityGroupsForVpc"], Resource = "arn:aws:ec2:${var.aws_region}:${data.aws_caller_identity.current.account_id}:vpc/*", Condition = {
        StringEquals = {
          "aws:ResourceTag/Name" = "stratus2"
        }
      }
    },
    {
      Sid = "RuntimeEventMappingTagCreate", Effect = "Allow", Action = ["lambda:TagResource"], Resource = "arn:aws:lambda:${var.aws_region}:${data.aws_caller_identity.current.account_id}:event-source-mapping:*", Condition = {
        StringEquals = {
          "aws:RequestTag/Project" = "stratus2"
        }
      }
    },
    {
      Sid = "RuntimeEventMappingTagMaintenance", Effect = "Allow", Action = ["lambda:ListTags", "lambda:TagResource", "lambda:UntagResource"], Resource = "arn:aws:lambda:${var.aws_region}:${data.aws_caller_identity.current.account_id}:event-source-mapping:*", Condition = {
        StringEquals = {
          "aws:ResourceTag/Project" = "stratus2"
        }
      }
    }
  ]
}
resource "aws_iam_policy" "deploy" {
  for_each = { for index, statements in chunklist([for statement in local.deployment_statements : jsonencode(statement)], 4) : tostring(index) => statements }
  name     = "stratus2-deploy-${each.key}"
  policy   = jsonencode({ Version = "2012-10-17", Statement = [for statement in each.value : jsondecode(statement)] })
}
resource "aws_iam_role_policy_attachment" "deploy" {
  for_each   = aws_iam_policy.deploy
  role       = aws_iam_role.deploy.name
  policy_arn = each.value.arn
}
output "state_bucket" {
  value = aws_s3_bucket.state.id
}
output "deploy_role_arn" {
  value = aws_iam_role.deploy.arn
}
resource "aws_iam_role" "preflight" {
  name               = "stratus2-github-preflight"
  assume_role_policy = aws_iam_role.deploy.assume_role_policy
}
resource "aws_iam_role_policy" "preflight" {
  role   = aws_iam_role.preflight.id
  policy = jsonencode({ Version = "2012-10-17", Statement = [{ Effect = "Allow", Action = ["secretsmanager:GetSecretValue"], Resource = [aws_secretsmanager_secret.app.arn, aws_secretsmanager_secret.openai.arn] }] })
}
output "preflight_role_arn" {
  value = aws_iam_role.preflight.arn
}
output "app_secret_arn" {
  value = aws_secretsmanager_secret.app.arn
}
output "openai_secret_arn" {
  value = aws_secretsmanager_secret.openai.arn
}
output "images" {
  value = {
    for key, r in aws_ecr_repository.images : key => r.repository_url
  }
}
