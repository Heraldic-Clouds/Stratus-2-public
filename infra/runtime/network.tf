resource "aws_vpc" "main" {
  cidr_block           = "10.42.0.0/16"
  enable_dns_support   = true
  enable_dns_hostnames = true
  tags = {
    Name = "stratus2"
  }

}
resource "aws_subnet" "public" {

  count             = 2
  vpc_id            = aws_vpc.main.id
  availability_zone = data.aws_availability_zones.available.names[count.index]
  cidr_block        = cidrsubnet(aws_vpc.main.cidr_block, 8, count.index)
  tags = {
    Name = "stratus2-public-${count.index}"
  }

}
resource "aws_internet_gateway" "main" {
  vpc_id = aws_vpc.main.id
}
resource "aws_route_table" "public" {
  vpc_id = aws_vpc.main.id
  route {
    cidr_block = "0.0.0.0/0"
    gateway_id = aws_internet_gateway.main.id

  }

}
resource "aws_route_table_association" "public" {
  count          = 2
  subnet_id      = aws_subnet.public[count.index].id
  route_table_id = aws_route_table.public.id

}
resource "aws_security_group" "alb" {

  name        = "stratus2-alb"
  description = "Public HTTPS and HTTP redirect"
  vpc_id      = aws_vpc.main.id

}
resource "aws_vpc_security_group_ingress_rule" "https" {
  security_group_id = aws_security_group.alb.id
  cidr_ipv4         = "0.0.0.0/0"
  ip_protocol       = "tcp"
  from_port         = 443
  to_port           = 443

}
resource "aws_vpc_security_group_ingress_rule" "http" {
  security_group_id = aws_security_group.alb.id
  cidr_ipv4         = "0.0.0.0/0"
  ip_protocol       = "tcp"
  from_port         = 80
  to_port           = 80

}
resource "aws_security_group" "node" {
  name        = "stratus2-node"
  description = "ALB application ingress only; administration through SSM"
  vpc_id      = aws_vpc.main.id

}
resource "aws_vpc_security_group_ingress_rule" "node_app" {
  security_group_id            = aws_security_group.node.id
  referenced_security_group_id = aws_security_group.alb.id
  ip_protocol                  = "tcp"
  from_port                    = 30080
  to_port                      = 30080

}
resource "aws_vpc_security_group_egress_rule" "alb_app" {
  security_group_id            = aws_security_group.alb.id
  referenced_security_group_id = aws_security_group.node.id
  ip_protocol                  = "tcp"
  from_port                    = 30080
  to_port                      = 30080

}
resource "aws_vpc_security_group_egress_rule" "node_https" {
  security_group_id = aws_security_group.node.id
  cidr_ipv4         = "0.0.0.0/0"
  ip_protocol       = "tcp"
  from_port         = 443
  to_port           = 443

}
resource "aws_vpc_security_group_egress_rule" "node_dns" {
  for_each          = toset(["tcp", "udp"])
  security_group_id = aws_security_group.node.id
  cidr_ipv4         = "0.0.0.0/0"
  ip_protocol       = each.value
  from_port         = 53
  to_port           = 53

}
resource "aws_lb" "main" {

  name                       = "stratus2"
  internal                   = false
  load_balancer_type         = "application"
  security_groups            = [aws_security_group.alb.id]
  subnets                    = aws_subnet.public[*].id
  drop_invalid_header_fields = true
  enable_deletion_protection = true
  idle_timeout               = 300

}
resource "aws_lb_target_group" "nextcloud" {

  name     = "stratus2-nextcloud"
  port     = 30080
  protocol = "HTTP"
  vpc_id   = aws_vpc.main.id
  health_check {
    path                = "/status.php"
    matcher             = "200"
    healthy_threshold   = 2
    unhealthy_threshold = 3

  }

}
resource "aws_lb_target_group_attachment" "node" {
  target_group_arn = aws_lb_target_group.nextcloud.arn
  target_id        = aws_instance.node.id
  port             = 30080

}
resource "aws_acm_certificate" "site" {
  domain_name       = var.domain
  validation_method = "DNS"
  lifecycle {
    create_before_destroy = true
  }

}
resource "aws_route53_record" "validation" {

  for_each = var.hosted_zone_id != "" ? {
    for d in aws_acm_certificate.site.domain_validation_options : d.domain_name => d
    } : {

  }
  zone_id = var.hosted_zone_id
  name    = each.value.resource_record_name
  type    = each.value.resource_record_type
  records = [each.value.resource_record_value]
  ttl     = 300

}
resource "aws_acm_certificate_validation" "site" {

  certificate_arn         = aws_acm_certificate.site.arn
  validation_record_fqdns = var.hosted_zone_id != "" ? [for r in aws_route53_record.validation : r.fqdn] : [for d in aws_acm_certificate.site.domain_validation_options : d.resource_record_name]
  timeouts {
    create = "45m"
  }

}
resource "aws_lb_listener" "https" {
  load_balancer_arn = aws_lb.main.arn
  port              = 443
  protocol          = "HTTPS"
  ssl_policy        = "ELBSecurityPolicy-TLS13-1-2-2021-06"
  certificate_arn   = aws_acm_certificate_validation.site.certificate_arn
  default_action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.nextcloud.arn

  }

}
resource "aws_lb_listener" "redirect" {
  load_balancer_arn = aws_lb.main.arn
  port              = 80
  protocol          = "HTTP"
  default_action {
    type = "redirect"
    redirect {
      port        = "443"
      protocol    = "HTTPS"
      status_code = "HTTP_301"

    }

  }

}
resource "aws_route53_record" "site" {
  count   = var.hosted_zone_id != "" ? 1 : 0
  zone_id = var.hosted_zone_id
  name    = var.domain
  type    = "A"
  alias {
    name                   = aws_lb.main.dns_name
    zone_id                = aws_lb.main.zone_id
    evaluate_target_health = true

  }

}
