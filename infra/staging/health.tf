resource "aws_lb_listener_rule" "health" {
  listener_arn = data.aws_lb_listener.https.arn
  priority     = 79
  action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.staging.arn
  }
  condition {
    host_header {
      values = [var.domain]
    }
  }
  condition {
    path_pattern {
      values = ["/status.php", "/index.php/apps/stratus2_ai/auth/oidc/discovery"]
    }
  }
}
