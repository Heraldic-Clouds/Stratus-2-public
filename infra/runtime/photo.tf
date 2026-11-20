resource "aws_sqs_queue" "photo_dead" {
  count                     = var.enable_photo_enhancement_infrastructure ? 1 : 0
  name                      = "stratus2-photo-dead"
  message_retention_seconds = 1209600
  sqs_managed_sse_enabled   = true
}
resource "aws_sqs_queue" "photo" {
  count                      = var.enable_photo_enhancement_infrastructure ? 1 : 0
  name                       = "stratus2-photo"
  visibility_timeout_seconds = 5400
  message_retention_seconds  = 86400
  sqs_managed_sse_enabled    = true
  redrive_policy             = jsonencode({ deadLetterTargetArn = aws_sqs_queue.photo_dead[0].arn, maxReceiveCount = 4 })
}
resource "aws_cloudwatch_log_group" "photo" {
  count             = var.enable_photo_enhancement_infrastructure ? 1 : 0
  name              = "/aws/lambda/stratus2-photo"
  retention_in_days = 14
}
resource "aws_iam_role_policy" "photo_logs" {
  count  = var.enable_photo_enhancement_infrastructure ? 1 : 0
  role   = aws_iam_role.worker.id
  policy = jsonencode({ Version = "2012-10-17", Statement = [{ Effect = "Allow", Action = ["logs:CreateLogStream", "logs:PutLogEvents"], Resource = "${aws_cloudwatch_log_group.photo[0].arn}:*" }] })
}
resource "aws_lambda_function" "photo" {
  lifecycle {
    ignore_changes = [image_uri]
  }
  count         = var.enable_photo_enhancement_infrastructure ? 1 : 0
  function_name = "stratus2-photo"
  role          = aws_iam_role.worker.arn
  package_type  = "Image"
  image_uri     = var.worker_image
  memory_size   = 2048
  timeout       = 900
  publish       = true
  image_config {
    command = ["worker.enhancement.handler"]
  }
  environment {
    variables = { OPENAI_SECRET_ARN = var.openai_secret_arn, NEXTCLOUD_URL = "https://${var.domain}", OPENAI_IMAGE_MODEL = "gpt-image-2" }
  }
  depends_on = [aws_iam_role_policy.worker, aws_iam_role_policy.photo_logs]
}
resource "aws_lambda_alias" "photo" {
  count            = var.enable_photo_enhancement_infrastructure ? 1 : 0
  name             = "active"
  function_name    = aws_lambda_function.photo[0].function_name
  function_version = aws_lambda_function.photo[0].version
  lifecycle {
    ignore_changes = [function_version]
  }
}
resource "aws_lambda_event_source_mapping" "photo" {
  count                   = var.enable_photo_enhancement_infrastructure ? 1 : 0
  event_source_arn        = aws_sqs_queue.photo[0].arn
  function_name           = aws_lambda_alias.photo[0].arn
  batch_size              = 1
  function_response_types = ["ReportBatchItemFailures"]
  scaling_config {
    maximum_concurrency = 2
  }
}
resource "aws_cloudwatch_metric_alarm" "photo_dead" {
  count               = var.enable_photo_enhancement_infrastructure ? 1 : 0
  alarm_name          = "stratus2-photo-dead-letter"
  namespace           = "AWS/SQS"
  metric_name         = "ApproximateNumberOfMessagesVisible"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 1
  period              = 300
  statistic           = "Maximum"
  threshold           = 0
  dimensions          = { QueueName = aws_sqs_queue.photo_dead[0].name }
  alarm_actions       = [aws_sns_topic.alerts.arn]
}
