output "alb_dns_name" {
  description = "ALB DNS name - point zencoder-api.juniper.education CNAME here"
  value       = aws_lb.main.dns_name
}

output "alb_zone_id" {
  description = "ALB canonical hosted zone ID (for Route53 alias records)"
  value       = aws_lb.main.zone_id
}

output "ecr_repository_url" {
  description = "ECR repository URL for pushing Docker images"
  value       = aws_ecr_repository.main.repository_url
}

output "aurora_endpoint" {
  description = "Aurora cluster writer endpoint"
  value       = aws_rds_cluster.main.endpoint
}

output "aurora_reader_endpoint" {
  description = "Aurora cluster reader endpoint"
  value       = aws_rds_cluster.main.reader_endpoint
}

output "redis_endpoint" {
  description = "Redis endpoint"
  value       = aws_elasticache_replication_group.main.primary_endpoint_address
}

output "db_secret_arn" {
  description = "RDS-managed master password secret ARN"
  value       = try(aws_rds_cluster.main.master_user_secret[0].secret_arn, "")
}

output "redis_secret_arn" {
  description = "Redis AUTH token secret ARN"
  value       = aws_secretsmanager_secret.redis_auth.arn
}

output "ecs_cluster_name" {
  description = "ECS cluster name"
  value       = aws_ecs_cluster.main.name
}

output "ecs_service_name" {
  description = "ECS service name"
  value       = aws_ecs_service.main.name
}

output "mediaconvert_role_arn" {
  description = "MediaConvert IAM role ARN"
  value       = aws_iam_role.mediaconvert.arn
}

output "secret_arn" {
  description = "Secrets Manager secret ARN"
  value       = aws_secretsmanager_secret.config.arn
}

output "vpc_id" {
  description = "VPC ID"
  value       = aws_vpc.main.id
}

output "nat_public_ip" {
  description = "NAT Gateway public IP (for allowlisting)"
  value       = aws_eip.nat.public_ip
}

output "cloudfront_domain_name" {
  description = "CloudFront distribution domain name"
  value       = aws_cloudfront_distribution.main.domain_name
}

output "cloudfront_distribution_id" {
  description = "CloudFront distribution ID"
  value       = aws_cloudfront_distribution.main.id
}
