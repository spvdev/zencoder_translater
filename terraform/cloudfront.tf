# CloudFront Distribution
resource "aws_cloudfront_distribution" "main" {
  enabled         = true
  comment         = "Zencoder Translator API"
  is_ipv6_enabled = true
  http_version    = "http2and3"
  price_class     = "PriceClass_100"

  aliases = ["zencoder-api.juniper.education"]

  origin {
    domain_name = aws_lb.main.dns_name
    origin_id   = "zencoder-alb"

    custom_origin_config {
      http_port                = 80
      https_port               = 443
      origin_protocol_policy   = "http-only"
      origin_ssl_protocols     = ["TLSv1.2"]
      origin_read_timeout      = 30
      origin_keepalive_timeout = 5
    }
  }

  default_cache_behavior {
    target_origin_id       = "zencoder-alb"
    viewer_protocol_policy = "redirect-to-https"
    compress               = true

    allowed_methods = ["HEAD", "DELETE", "POST", "GET", "OPTIONS", "PUT", "PATCH"]
    cached_methods  = ["HEAD", "GET"]

    forwarded_values {
      query_string = true

      cookies {
        forward = "none"
      }

      headers = [
        "Authorization",
        "Accept",
        "Zencoder-Api-Key",
        "Host",
        "Content-Type",
      ]
    }

    min_ttl     = 0
    default_ttl = 0
    max_ttl     = 0
  }

  viewer_certificate {
    acm_certificate_arn      = var.acm_certificate_arn
    ssl_support_method       = "sni-only"
    minimum_protocol_version = "TLSv1.2_2021"
  }

  restrictions {
    geo_restriction {
      restriction_type = "none"
    }
  }

  tags = {
    Name = "${var.project_name}-cloudfront"
  }
}
