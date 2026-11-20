output "instance_id" {
  value = aws_instance.node.id
}
output "queue_url" {
  value = aws_sqs_queue.analysis.url
}
output "backup_bucket" {
  value = aws_s3_bucket.backups.id
}
output "alb_dns_name" {
  value = aws_lb.main.dns_name
}
output "alb_zone_id" {
  value = aws_lb.main.zone_id
}
output "certificate_dns_records" {
  value = [for d in aws_acm_certificate.site.domain_validation_options : {
    name = d.resource_record_name, type = d.resource_record_type, value = d.resource_record_value
  }]
}
output "app_secret_arn" {
  value = var.app_secret_arn
}
output "photo_queue_url" {
  value = try(aws_sqs_queue.photo[0].url, "")
}
output "worker_functions" {
  value = merge({ analysis = aws_lambda_function.analysis.function_name }, var.enable_photo_enhancement_infrastructure ? { photo = aws_lambda_function.photo[0].function_name } : {})
}
