resource "aws_lb_listener_rule" "login" {
  listener_arn = data.aws_lb_listener.https.arn
  priority     = 78

  action {
    type = "redirect"
    redirect {
      path        = "/index.php/login"
      query       = "#{query}"
      status_code = "HTTP_302"
    }
  }

  condition {
    host_header {
      values = [var.domain]
    }
  }

  condition {
    path_pattern {
      values = ["/login"]
    }
  }

  condition {
    source_ip {
      values = var.allowed_cidrs
    }
  }
}
