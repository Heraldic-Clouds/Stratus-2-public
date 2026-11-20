terraform {

  required_version = ">= 1.13, < 2.0"
  required_providers {
    aws = {
      source = "hashicorp/aws", version = "~> 6.0"
    }
  }
  backend "s3" {

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
data "aws_caller_identity" "current" {

}
provider "aws" {
  alias  = "billing"
  region = "us-east-1"
}
data "aws_availability_zones" "available" {
  state = "available"
}
data "aws_ssm_parameter" "ami" {
  name = "/aws/service/ami-amazon-linux-latest/al2023-ami-kernel-default-x86_64"
}
locals {
  prefix = "stratus2-${data.aws_caller_identity.current.account_id}"
}
