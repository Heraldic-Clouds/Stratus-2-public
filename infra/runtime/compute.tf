resource "aws_iam_role" "node" {
  name = "stratus2-node"
  assume_role_policy = jsonencode({
    Version = "2012-10-17", Statement = [{
      Effect = "Allow", Principal = {
        Service = "ec2.amazonaws.com"
      }, Action = "sts:AssumeRole"
    }]
  })

}
resource "aws_iam_role_policy_attachment" "ssm" {
  role       = aws_iam_role.node.name
  policy_arn = "arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore"

}
resource "aws_iam_role_policy" "node" {
  role = aws_iam_role.node.id
  policy = jsonencode({
    Version = "2012-10-17", Statement = [
      {
        Effect = "Allow", Action = ["secretsmanager:GetSecretValue"], Resource = var.app_secret_arn
      },
      {
        Effect = "Allow", Action = ["sqs:SendMessage", "sqs:GetQueueUrl"], Resource = concat([aws_sqs_queue.analysis.arn], aws_sqs_queue.photo[*].arn)
      },
      {
        Effect = "Allow", Action = ["s3:ListBucket"], Resource = aws_s3_bucket.backups.arn
      },
      {
        Effect = "Allow", Action = ["s3:PutObject", "s3:GetObject"], Resource = ["${aws_s3_bucket.backups.arn}/backups/*", "${aws_s3_bucket.backups.arn}/releases/*"]
      },
      {
        Effect = "Allow", Action = ["ecr:GetAuthorizationToken"], Resource = "*"
      },
      {
        Effect = "Allow", Action = ["ecr:BatchGetImage", "ecr:GetDownloadUrlForLayer", "ecr:BatchCheckLayerAvailability"], Resource = "arn:aws:ecr:${var.aws_region}:${data.aws_caller_identity.current.account_id}:repository/stratus2-nextcloud"
      },
      {
        Effect = "Allow", Action = ["cloudwatch:PutMetricData"], Resource = "*", Condition = {
          StringEquals = {
            "cloudwatch:namespace" = "Stratus2"
          }
        }
      },
      {
        Effect = "Allow", Action = ["logs:CreateLogStream", "logs:PutLogEvents", "logs:DescribeLogStreams"], Resource = "${aws_cloudwatch_log_group.node.arn}:*"
      }
    ]
  })

}
resource "aws_iam_instance_profile" "node" {
  name = "stratus2-node"
  role = aws_iam_role.node.name

}
resource "aws_ebs_volume" "data" {

  availability_zone = aws_subnet.public[0].availability_zone
  size              = 80
  type              = "gp3"
  encrypted         = true
  tags = {
    Name = "stratus2-persistent-state"
  }
  lifecycle {
    prevent_destroy = true
  }

}
resource "aws_instance" "node" {

  ami                         = data.aws_ssm_parameter.ami.value
  instance_type               = "t3.xlarge"
  subnet_id                   = aws_subnet.public[0].id
  associate_public_ip_address = true
  vpc_security_group_ids      = [aws_security_group.node.id]
  iam_instance_profile        = aws_iam_instance_profile.node.name
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
  user_data = templatefile("${path.module}/cloud-init.sh.tftpl", {
    data_volume_id = aws_ebs_volume.data.id, k3s_version = var.k3s_version, region = var.aws_region
  })
  user_data_replace_on_change = true
  tags = {
    Name = "stratus2-single", Role = "stratus2-node"
  }
  lifecycle {
    ignore_changes = [ami]
  }

}
resource "aws_volume_attachment" "data" {
  device_name                    = "/dev/sdf"
  volume_id                      = aws_ebs_volume.data.id
  instance_id                    = aws_instance.node.id
  stop_instance_before_detaching = true

}
resource "aws_cloudwatch_log_group" "node" {
  name              = "/stratus2/node"
  retention_in_days = 30

}
