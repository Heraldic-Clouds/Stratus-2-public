resource "aws_sqs_queue" "dead" {
  name                      = "stratus2-analysis-dead"
  message_retention_seconds = 1209600
  sqs_managed_sse_enabled   = true

}
resource "aws_sqs_queue" "analysis" {
  name                       = "stratus2-analysis"
  visibility_timeout_seconds = 1800
  message_retention_seconds  = 86400
  sqs_managed_sse_enabled    = true
  redrive_policy = jsonencode({
    deadLetterTargetArn = aws_sqs_queue.dead.arn, maxReceiveCount = 4
  })

}
resource "aws_sqs_queue_redrive_allow_policy" "dead" {
  queue_url = aws_sqs_queue.dead.id
  redrive_allow_policy = jsonencode({
    redrivePermission = "byQueue", sourceQueueArns = [aws_sqs_queue.analysis.arn]
  })

}
resource "aws_sqs_queue_policy" "tls" {
  for_each = {
    analysis = aws_sqs_queue.analysis, dead = aws_sqs_queue.dead
  }
  queue_url = each.value.id
  policy = jsonencode({
    Version = "2012-10-17", Statement = [{
      Effect = "Deny", Principal = "*", Action = "sqs:*", Resource = each.value.arn, Condition = {
        Bool = {
          "aws:SecureTransport" = "false"
        }
      }
    }]
  })

}
resource "aws_iam_role" "worker" {
  name = "stratus2-worker"
  assume_role_policy = jsonencode({
    Version = "2012-10-17", Statement = [{
      Effect = "Allow", Principal = {
        Service = "lambda.amazonaws.com"
      }, Action = "sts:AssumeRole"
    }]
  })

}
resource "aws_cloudwatch_log_group" "worker" {
  name              = "/aws/lambda/stratus2-analysis"
  retention_in_days = 14

}
resource "aws_iam_role_policy" "worker" {
  role = aws_iam_role.worker.id
  policy = jsonencode({
    Version = "2012-10-17", Statement = [
      {
        Effect = "Allow", Action = ["sqs:ReceiveMessage", "sqs:DeleteMessage", "sqs:GetQueueAttributes"], Resource = concat([aws_sqs_queue.analysis.arn], aws_sqs_queue.photo[*].arn)
      },
      {
        Effect = "Allow", Action = ["secretsmanager:GetSecretValue"], Resource = var.openai_secret_arn
      },
      {
        Effect = "Allow", Action = ["logs:CreateLogStream", "logs:PutLogEvents"], Resource = "${aws_cloudwatch_log_group.worker.arn}:*"
      }
    ]
  })

}
resource "aws_lambda_function" "analysis" {
  lifecycle {
    ignore_changes = [image_uri]
  }

  function_name = "stratus2-analysis"
  role          = aws_iam_role.worker.arn
  package_type  = "Image"
  image_uri     = var.worker_image
  architectures = ["x86_64"]
  memory_size   = 1536
  timeout       = 300
  # The SQS mapping below limits analysis to four concurrent workers. Avoid a
  # reservation: accounts with a total quota of ten cannot reserve concurrency.
  reserved_concurrent_executions = -1
  environment {
    variables = {
      OPENAI_SECRET_ARN = var.openai_secret_arn, NEXTCLOUD_URL = "https://${var.domain}", OPENAI_MODEL = "gpt-4.1-mini"
    }
  }
  depends_on = [aws_iam_role_policy.worker]

}
resource "aws_lambda_event_source_mapping" "analysis" {

  event_source_arn        = aws_sqs_queue.analysis.arn
  function_name           = aws_lambda_function.analysis.arn
  batch_size              = 1
  function_response_types = ["ReportBatchItemFailures"]
  scaling_config {
    maximum_concurrency = 4
  }

}
