resource "aws_s3_bucket_policy" "backups" {
  bucket = aws_s3_bucket.backups.id
  policy = jsonencode({ Version = "2012-10-17", Statement = [{
    Effect    = "Deny", Principal = "*", Action = "s3:*", Resource = [aws_s3_bucket.backups.arn, "${aws_s3_bucket.backups.arn}/*"],
    Condition = { Bool = { "aws:SecureTransport" = "false" } }
  }] })
}
resource "aws_sqs_queue_policy" "tls" {
  for_each  = merge({ for key, q in aws_sqs_queue.work : "work-${key}" => q }, { for key, q in aws_sqs_queue.dead : "dead-${key}" => q })
  queue_url = each.value.url
  policy = jsonencode({ Version = "2012-10-17", Statement = [{
    Effect = "Deny", Principal = "*", Action = "sqs:*", Resource = each.value.arn, Condition = { Bool = { "aws:SecureTransport" = "false" } }
  }] })
}
