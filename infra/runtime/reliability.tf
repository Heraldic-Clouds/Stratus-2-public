resource "aws_s3_bucket" "backups" {

  bucket = "${local.prefix}-backups"
  lifecycle {
    prevent_destroy = true
  }

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
resource "aws_s3_bucket_public_access_block" "backups" {

  bucket                  = aws_s3_bucket.backups.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true

}
resource "aws_s3_bucket_policy" "backups" {
  bucket = aws_s3_bucket.backups.id
  policy = jsonencode({
    Version = "2012-10-17", Statement = [{
      Effect = "Deny", Principal = "*", Action = "s3:*", Resource = [aws_s3_bucket.backups.arn, "${aws_s3_bucket.backups.arn}/*"], Condition = {
        Bool = {
          "aws:SecureTransport" = "false"
        }
      }
    }]
  })

}
resource "aws_s3_bucket_lifecycle_configuration" "backups" {
  bucket = aws_s3_bucket.backups.id
  rule {
    id     = "backup-retention"
    status = "Enabled"
    filter {
      prefix = "backups/"
    }
    expiration {
      days = 30
    }
    noncurrent_version_expiration {
      noncurrent_days = 30
    }
    abort_incomplete_multipart_upload {
      days_after_initiation = 1
    }

  }
  rule {
    id     = "release-retention"
    status = "Enabled"
    filter {
      prefix = "releases/"
    }
    expiration {
      days = 90
    }
    noncurrent_version_expiration {
      noncurrent_days = 7
    }

  }

}
resource "aws_sns_topic" "alerts" {
  name = "stratus2-alerts"
}
resource "aws_sns_topic_subscription" "email" {
  topic_arn = aws_sns_topic.alerts.arn
  protocol  = "email"
  endpoint  = var.alert_email

}
resource "aws_cloudwatch_metric_alarm" "node" {

  alarm_name          = "stratus2-ec2-status"
  namespace           = "AWS/EC2"
  metric_name         = "StatusCheckFailed"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 2
  period              = 60
  statistic           = "Maximum"
  threshold           = 0
  dimensions = {
    InstanceId = aws_instance.node.id
  }
  alarm_actions = [aws_sns_topic.alerts.arn]

}
resource "aws_cloudwatch_metric_alarm" "backups" {

  alarm_name          = "stratus2-backup-missing"
  namespace           = "Stratus2"
  metric_name         = "BackupSuccess"
  comparison_operator = "LessThanThreshold"
  evaluation_periods  = 1
  period              = 90000
  statistic           = "Sum"
  threshold           = 1
  treat_missing_data  = "breaching"
  alarm_actions       = [aws_sns_topic.alerts.arn]

}
resource "aws_cloudwatch_metric_alarm" "dead" {

  alarm_name          = "stratus2-analysis-dead-letter"
  namespace           = "AWS/SQS"
  metric_name         = "ApproximateNumberOfMessagesVisible"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 1
  period              = 300
  statistic           = "Maximum"
  threshold           = 0
  dimensions = {
    QueueName = aws_sqs_queue.dead.name
  }
  alarm_actions = [aws_sns_topic.alerts.arn]

}
resource "aws_cloudwatch_metric_alarm" "health" {

  alarm_name          = "stratus2-unhealthy-application"
  namespace           = "AWS/ApplicationELB"
  metric_name         = "UnHealthyHostCount"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 3
  period              = 60
  statistic           = "Maximum"
  threshold           = 0
  dimensions = {
    LoadBalancer = aws_lb.main.arn_suffix, TargetGroup = aws_lb_target_group.nextcloud.arn_suffix
  }
  alarm_actions = [aws_sns_topic.alerts.arn]

}
resource "aws_budgets_budget" "monthly" {
  provider = aws.billing

  name         = "stratus2-monthly"
  budget_type  = "COST"
  limit_amount = tostring(var.monthly_budget)
  limit_unit   = "USD"
  time_unit    = "MONTHLY"
  notification {
    comparison_operator        = "GREATER_THAN"
    threshold                  = 80
    threshold_type             = "PERCENTAGE"
    notification_type          = "ACTUAL"
    subscriber_email_addresses = [var.alert_email]

  }
  notification {
    comparison_operator        = "GREATER_THAN"
    threshold                  = 100
    threshold_type             = "PERCENTAGE"
    notification_type          = "FORECASTED"
    subscriber_email_addresses = [var.alert_email]

  }

}
