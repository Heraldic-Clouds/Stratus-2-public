variable "aws_region" {
  type    = string
  default = "us-west-2"

}
variable "domain" {
  type    = string
  default = "stratus.example.com"

}
variable "app_secret_arn" {
  type = string
}
variable "openai_secret_arn" {
  type = string
}
variable "worker_image" {

  type = string
  validation {
    condition     = can(regex("@sha256:[a-f0-9]{64}$", var.worker_image))
    error_message = "Pin the worker image by ECR SHA-256 digest."

  }

}
variable "hosted_zone_id" {
  type    = string
  default = ""

}
variable "alert_email" {

  type = string
  validation {
    condition     = can(regex("^[^@]+@[^@]+\\.[^@]+$", var.alert_email))
    error_message = "Provide an email address for budget and failure alerts."

  }

}
variable "monthly_budget" {
  type    = number
  default = 250

}
variable "k3s_version" {
  type    = string
  default = "v1.34.1+k3s1"

}
variable "enable_photo_enhancement_infrastructure" {
  type    = bool
  default = false
}
