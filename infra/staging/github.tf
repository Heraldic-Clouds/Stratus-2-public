resource "aws_iam_role" "github" {
  name = "stratus2-staging-github-deploy"
  assume_role_policy = jsonencode({ Version = "2012-10-17", Statement = [{
    Effect    = "Allow", Principal = { Federated = data.aws_iam_openid_connect_provider.github.arn }, Action = "sts:AssumeRoleWithWebIdentity",
    Condition = { StringEquals = { "token.actions.githubusercontent.com:aud" = "sts.amazonaws.com", "token.actions.githubusercontent.com:sub" = "repo:${var.repository_subject}:environment:staging" } }
  }] })
}
resource "aws_iam_role_policy" "github" {
  role = aws_iam_role.github.id
  policy = jsonencode({ Version = "2012-10-17", Statement = [
    { Effect = "Allow", Action = ["ecr:GetAuthorizationToken"], Resource = "*" },
    { Effect = "Allow", Action = ["ecr:DescribeImages", "ecr:BatchCheckLayerAvailability", "ecr:GetDownloadUrlForLayer", "ecr:BatchGetImage", "ecr:PutImage", "ecr:InitiateLayerUpload", "ecr:UploadLayerPart", "ecr:CompleteLayerUpload"], Resource = [for r in aws_ecr_repository.images : r.arn] },
    { Effect = "Allow", Action = ["s3:ListBucket"], Resource = ["arn:aws:s3:::${var.state_bucket}", aws_s3_bucket.backups.arn] },
    { Effect = "Allow", Action = ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"], Resource = "arn:aws:s3:::${var.state_bucket}/runtime/staging*" },
    { Effect = "Allow", Action = ["s3:GetObject", "s3:PutObject"], Resource = "${aws_s3_bucket.backups.arn}/releases/*" },
    { Effect = "Allow", Action = ["ssm:SendCommand"], Resource = "arn:aws:ssm:${var.aws_region}::document/AWS-RunShellScript" },
    { Effect = "Allow", Action = ["ssm:SendCommand"], Resource = "arn:aws:ec2:${var.aws_region}:${local.account}:instance/*", Condition = { StringEquals = { "ssm:resourceTag/Environment" = "staging" } } },
    { Effect = "Allow", Action = ["ssm:GetCommandInvocation"], Resource = "*" },
    { Effect = "Allow", Action = ["lambda:GetFunction", "lambda:GetFunctionConfiguration", "lambda:UpdateFunctionCode", "lambda:PublishVersion", "lambda:GetAlias", "lambda:CreateAlias", "lambda:UpdateAlias", "lambda:ListAliases"], Resource = local.worker_arn },
    { Effect = "Allow", Action = ["lambda:ListEventSourceMappings", "lambda:GetEventSourceMapping"], Resource = "*" },
    { Effect = "Allow", Action = ["lambda:UpdateEventSourceMapping"], Resource = "*", Condition = { ArnLike = { "lambda:FunctionArn" = local.worker_arn } } },
    { Effect = "Allow", Action = ["ec2:StartInstances", "ec2:StopInstances"], Resource = "arn:aws:ec2:${var.aws_region}:${local.account}:instance/*", Condition = { StringEquals = { "ec2:ResourceTag/Environment" = "staging" } } }
  ] })
}
output "github_deploy_role_arn" {
  value = aws_iam_role.github.arn
}
