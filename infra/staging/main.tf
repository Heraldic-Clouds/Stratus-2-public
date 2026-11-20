terraform {
  required_version = ">= 1.13, < 2.0"

  required_providers {
    aws = {
      source  = "hashicorp/aws",
      version = "~> 6.0",
    }
  }

  backend "s3" {

  }
}

provider "aws" {
  region = var.aws_region

  default_tags {
    tags = {
      Project     = "stratus2-staging",
      ManagedBy   = "terraform",
      Environment = "staging",
    }
  }
}

variable "aws_region" {
  default = "us-west-2"
}

variable "domain" {
  default = "staging.example.com"
}

variable "hosted_zone_id" {
  type = string
}

variable "openai_secret_arn" {
  type = string
}

variable "worker_image" {
  type = string

  validation {
    condition     = can(regex("/stratus2-staging-worker@sha256:[a-f0-9]{64}$", var.worker_image))
    error_message = "Use an immutable staging worker image digest."
  }
}

variable "allowed_cidrs" {
  type = list(string)

  validation {
    condition     = length(var.allowed_cidrs) > 0 && length(var.allowed_cidrs) <= 3 && alltrue([for cidr in var.allowed_cidrs : can(cidrnetmask(cidr)) && !contains(["0.0.0.0/0"], cidr)])
    error_message = "Specify tester IPv4 CIDRs; staging cannot be public to everyone."
  }
}

variable "repository_subject" {
}

variable "state_bucket" {
  type = string
}

data "aws_caller_identity" "current" {

}

data "aws_vpc" "production" {
  tags = {
    Name = "stratus2",
  }
}

data "aws_subnet" "public" {
  tags = {
    Name = "stratus2-public-0",
  }
}

data "aws_lb" "production" {
  name = "stratus2"
}

data "aws_lb_listener" "https" {
  load_balancer_arn = data.aws_lb.production.arn
  port              = 443
}

data "aws_ssm_parameter" "ami" {
  name = "/aws/service/ami-amazon-linux-latest/al2023-ami-kernel-default-x86_64"
}

data "aws_iam_openid_connect_provider" "github" {
  url = "https://token.actions.githubusercontent.com"
}

locals {
  prefix     = "stratus2-staging"
  account    = data.aws_caller_identity.current.account_id
  worker_arn = "arn:aws:lambda:${var.aws_region}:${local.account}:function:${local.prefix}-*"
}

resource "aws_secretsmanager_secret" "app" {
  name                    = "stratus2-staging/app"
  recovery_window_in_days = 30
}

resource "aws_ecr_repository" "images" {
  for_each = toset([
    "nextcloud",
    "worker"
  ])
  name                 = "${local.prefix}-${each.key}"
  image_tag_mutability = "IMMUTABLE"

  image_scanning_configuration {
    scan_on_push = true
  }
}

resource "aws_ecr_repository_policy" "worker" {
  repository = aws_ecr_repository.images["worker"].name
  policy = jsonencode({
    Version = "2012-10-17",
    Statement = [
      {
        Effect = "Allow",
        Principal = {
          Service = "lambda.amazonaws.com"
        },
        Action = [
          "ecr:BatchGetImage",
          "ecr:GetDownloadUrlForLayer"
        ],
        Condition = {
          StringEquals = {
            "aws:SourceAccount" = local.account
          },
          ArnLike = {
            "aws:SourceArn" = local.worker_arn
          }
        }
      }
    ]
  })
}

resource "aws_security_group" "node" {
  name   = "${local.prefix}-node"
  vpc_id = data.aws_vpc.production.id
}

resource "aws_vpc_security_group_ingress_rule" "web" {
  security_group_id            = aws_security_group.node.id
  referenced_security_group_id = tolist(data.aws_lb.production.security_groups)[0]
  ip_protocol                  = "tcp"
  from_port                    = 30080
  to_port                      = 30080
}

resource "aws_vpc_security_group_egress_rule" "https" {
  security_group_id = aws_security_group.node.id
  cidr_ipv4         = "0.0.0.0/0"
  ip_protocol       = "tcp"
  from_port         = 443
  to_port           = 443
}

resource "aws_vpc_security_group_egress_rule" "dns" {
  security_group_id = aws_security_group.node.id
  cidr_ipv4         = "0.0.0.0/0"
  ip_protocol       = "udp"
  from_port         = 53
  to_port           = 53
}

resource "aws_vpc_security_group_egress_rule" "alb" {
  security_group_id            = tolist(data.aws_lb.production.security_groups)[0]
  referenced_security_group_id = aws_security_group.node.id
  ip_protocol                  = "tcp"
  from_port                    = 30080
  to_port                      = 30080
}

resource "aws_iam_role" "node" {
  name = "${local.prefix}-node"
  assume_role_policy = jsonencode({
    Version = "2012-10-17",
    Statement = [
      {
        Effect = "Allow",
        Principal = {
          Service = "ec2.amazonaws.com"
        },
        Action = "sts:AssumeRole"
      }
    ]
  })
}

resource "aws_iam_role_policy_attachment" "ssm" {
  role       = aws_iam_role.node.name
  policy_arn = "arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore"
}

resource "aws_iam_role_policy" "node" {
  role = aws_iam_role.node.id
  policy = jsonencode({
    Version = "2012-10-17",
    Statement = [
      {
        Effect = "Allow",
        Action = [
          "secretsmanager:GetSecretValue"
        ],
        Resource = aws_secretsmanager_secret.app.arn
      },
      {
        Effect = "Allow",
        Action = [
          "sqs:SendMessage",
          "sqs:GetQueueUrl"
        ],
        Resource = [
          for q in aws_sqs_queue.work :
          q.arn
        ]
      },
      {
        Effect = "Allow",
        Action = [
          "ecr:GetAuthorizationToken"
        ],
        Resource = "*"
      },
      {
        Effect = "Allow",
        Action = [
          "ecr:BatchGetImage",
          "ecr:GetDownloadUrlForLayer",
          "ecr:BatchCheckLayerAvailability"
        ],
        Resource = aws_ecr_repository.images["nextcloud"].arn
      },
      {
        Effect = "Allow",
        Action = [
          "s3:GetObject",
          "s3:PutObject"
        ],
        Resource = "${aws_s3_bucket.backups.arn}/*"
      },
      {
        Effect = "Allow",
        Action = [
          "s3:ListBucket"
        ],
        Resource = aws_s3_bucket.backups.arn
      },
      {
        Effect = "Allow",
        Action = [
          "cloudwatch:PutMetricData"
        ],
        Resource = "*",
        Condition = {
          StringEquals = {
            "cloudwatch:namespace" = "Stratus2"
          }
        }
      }
    ]
  })
}

resource "aws_iam_instance_profile" "node" {
  name = "${local.prefix}-node"
  role = aws_iam_role.node.name
}

resource "aws_ebs_volume" "data" {
  availability_zone = data.aws_subnet.public.availability_zone
  size              = 40
  type              = "gp3"
  encrypted         = true

  lifecycle {
    prevent_destroy = true
  }
}

resource "aws_instance" "node" {
  ami                         = data.aws_ssm_parameter.ami.value
  instance_type               = "t3.large"
  subnet_id                   = data.aws_subnet.public.id
  associate_public_ip_address = true
  vpc_security_group_ids = [
    aws_security_group.node.id,
  ]
  iam_instance_profile = aws_iam_instance_profile.node.name

  metadata_options {
    http_tokens                 = "required"
    http_put_response_hop_limit = 2
  }

  credit_specification {
    cpu_credits = "standard"
  }

  root_block_device {
    volume_type = "gp3"
    volume_size = 20
    encrypted   = true
  }

  user_data = templatefile("${path.module}/../runtime/cloud-init.sh.tftpl", {
    data_volume_id = aws_ebs_volume.data.id,
    k3s_version    = "v1.34.1+k3s1",
    region         = var.aws_region
  })
  user_data_replace_on_change = true
  tags = {
    Name = "stratus2-staging",
    Role = "stratus2-staging-node",
  }

  lifecycle {
    ignore_changes = [
      ami,
    ]
  }
}

resource "aws_volume_attachment" "data" {
  device_name                    = "/dev/sdf"
  volume_id                      = aws_ebs_volume.data.id
  instance_id                    = aws_instance.node.id
  stop_instance_before_detaching = true
}

resource "aws_s3_bucket" "backups" {
  bucket = "${local.prefix}-${local.account}-backups"
}

resource "aws_s3_bucket_public_access_block" "backups" {
  bucket                  = aws_s3_bucket.backups.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_versioning" "backups" {
  bucket = aws_s3_bucket.backups.id

  versioning_configuration {
    status = "Enabled"
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "backups" {
  bucket = aws_s3_bucket.backups.id

  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

resource "aws_s3_bucket_lifecycle_configuration" "backups" {
  bucket = aws_s3_bucket.backups.id

  rule {
    id     = "staging-retention"
    status = "Enabled"

    filter {
      prefix = "backups/"
    }

    expiration {
      days = 14
    }

    noncurrent_version_expiration {
      noncurrent_days = 14
    }
  }
}

resource "aws_lb_target_group" "staging" {
  name     = local.prefix
  port     = 30080
  protocol = "HTTP"
  vpc_id   = data.aws_vpc.production.id

  health_check {
    path    = "/status.php"
    matcher = "200"
  }
}

resource "aws_lb_target_group_attachment" "staging" {
  target_group_arn = aws_lb_target_group.staging.arn
  target_id        = aws_instance.node.id
  port             = 30080
}

resource "aws_acm_certificate" "staging" {
  domain_name       = var.domain
  validation_method = "DNS"

  lifecycle {
    create_before_destroy = true
  }
}

resource "aws_route53_record" "validation" {
  for_each = {
    for d in aws_acm_certificate.staging.domain_validation_options :
    d.domain_name => d
  }
  zone_id = var.hosted_zone_id
  name    = each.value.resource_record_name
  type    = each.value.resource_record_type
  records = [
    each.value.resource_record_value,
  ]
  ttl = 300
}

resource "aws_acm_certificate_validation" "staging" {
  certificate_arn = aws_acm_certificate.staging.arn
  validation_record_fqdns = [
    for r in aws_route53_record.validation :
    r.fqdn
  ]
}

resource "aws_lb_listener_certificate" "staging" {
  listener_arn    = data.aws_lb_listener.https.arn
  certificate_arn = aws_acm_certificate_validation.staging.certificate_arn
}

resource "aws_lb_listener_rule" "callbacks" {
  listener_arn = data.aws_lb_listener.https.arn
  priority     = 80

  action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.staging.arn
  }

  condition {
    host_header {
      values = [
        var.domain,
      ]
    }
  }

  condition {
    path_pattern {
      values = [
        "/index.php/apps/stratus2_ai/worker/*",
        "/index.php/apps/stratus2_ai/photo-worker/*",
        "/index.php/apps/stratus2_ai/billing/webhook",
      ]
    }
  }
}

resource "aws_lb_listener_rule" "testers" {
  listener_arn = data.aws_lb_listener.https.arn
  priority     = 81

  action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.staging.arn
  }

  condition {
    host_header {
      values = [
        var.domain,
      ]
    }
  }

  condition {
    source_ip {
      values = var.allowed_cidrs
    }
  }
}

resource "aws_lb_listener_rule" "deny" {
  listener_arn = data.aws_lb_listener.https.arn
  priority     = 82

  action {
    type = "fixed-response"

    fixed_response {
      content_type = "text/plain"
      status_code  = "403"
      message_body = "Private staging environment"
    }
  }

  condition {
    host_header {
      values = [
        var.domain,
      ]
    }
  }
}

resource "aws_route53_record" "staging" {
  zone_id = var.hosted_zone_id
  name    = var.domain
  type    = "A"

  alias {
    name                   = data.aws_lb.production.dns_name
    zone_id                = data.aws_lb.production.zone_id
    evaluate_target_health = true
  }
}

resource "aws_sqs_queue" "dead" {
  for_each = toset([
    "analysis",
    "photo"
  ])
  name                      = "${local.prefix}-${each.key}-dead"
  sqs_managed_sse_enabled   = true
  message_retention_seconds = 1209600
}

resource "aws_sqs_queue" "work" {
  for_each = toset([
    "analysis",
    "photo"
  ])
  name                       = "${local.prefix}-${each.key}"
  sqs_managed_sse_enabled    = true
  visibility_timeout_seconds = 5400
  message_retention_seconds  = 86400
  redrive_policy = jsonencode({
    deadLetterTargetArn = aws_sqs_queue.dead[each.key].arn,
    maxReceiveCount     = 4
  })
}

resource "aws_iam_role" "worker" {
  name = "${local.prefix}-worker"
  assume_role_policy = jsonencode({
    Version = "2012-10-17",
    Statement = [
      {
        Effect = "Allow",
        Principal = {
          Service = "lambda.amazonaws.com"
        },
        Action = "sts:AssumeRole"
      }
    ]
  })
}

resource "aws_cloudwatch_log_group" "worker" {
  for_each = toset([
    "analysis",
    "photo"
  ])
  name              = "/aws/lambda/${local.prefix}-${each.key}"
  retention_in_days = 14
}

resource "aws_iam_role_policy" "worker" {
  role = aws_iam_role.worker.id
  policy = jsonencode({
    Version = "2012-10-17",
    Statement = [
      {
        Effect = "Allow",
        Action = [
          "sqs:ReceiveMessage",
          "sqs:DeleteMessage",
          "sqs:GetQueueAttributes"
        ],
        Resource = [
          for q in aws_sqs_queue.work :
          q.arn
        ]
      },
      {
        Effect = "Allow",
        Action = [
          "secretsmanager:GetSecretValue"
        ],
        Resource = var.openai_secret_arn
      },
      {
        Effect = "Allow",
        Action = [
          "logs:CreateLogStream",
          "logs:PutLogEvents"
        ],
        Resource = [
          for l in aws_cloudwatch_log_group.worker :
          "${l.arn}:*"
        ]
      }
    ]
  })
}

resource "aws_lambda_function" "worker" {
  lifecycle {
    ignore_changes = [image_uri]
  }
  for_each = toset([
    "analysis",
    "photo"
  ])
  function_name = "${local.prefix}-${each.key}"
  role          = aws_iam_role.worker.arn
  package_type  = "Image"
  image_uri     = var.worker_image
  memory_size   = each.key == "photo" ? 2048 : 1536
  timeout       = each.key == "photo" ? 900 : 300
  publish       = true

  image_config {
    command = [
      each.key == "photo" ? "worker.enhancement.handler" : "worker.handler.handler",
    ]
  }

  environment {
    variables = {
      OPENAI_SECRET_ARN  = var.openai_secret_arn,
      NEXTCLOUD_URL      = "https://${var.domain}",
      OPENAI_MODEL       = "gpt-4.1-mini",
      OPENAI_IMAGE_MODEL = "gpt-image-2",
    }
  }

  depends_on = [
    aws_iam_role_policy.worker,
    aws_ecr_repository_policy.worker,
  ]
}

resource "aws_lambda_alias" "worker" {
  for_each         = aws_lambda_function.worker
  name             = "active"
  function_name    = each.value.function_name
  function_version = each.value.version
  lifecycle {
    ignore_changes = [function_version]
  }
}

resource "aws_lambda_event_source_mapping" "worker" {
  for_each         = aws_sqs_queue.work
  event_source_arn = each.value.arn
  function_name    = aws_lambda_alias.worker[each.key].arn
  batch_size       = 1
  function_response_types = [
    "ReportBatchItemFailures",
  ]

  scaling_config {
    maximum_concurrency = 2
  }
}

output "instance_id" {
  value = aws_instance.node.id
}

output "app_secret_arn" {
  value = aws_secretsmanager_secret.app.arn
}

output "queue_url" {
  value = aws_sqs_queue.work["analysis"].url
}

output "photo_queue_url" {
  value = aws_sqs_queue.work["photo"].url
}

output "backup_bucket" {
  value = aws_s3_bucket.backups.id
}

output "worker_functions" {
  value = {
    for k, v in aws_lambda_function.worker :
    k => v.function_name
  }
}
