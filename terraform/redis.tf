# ElastiCache Subnet Group
resource "aws_elasticache_subnet_group" "main" {
  name       = "${var.project_name}-redis-subnets"
  subnet_ids = aws_subnet.private[*].id

  tags = {
    Name = "${var.project_name}-redis-subnets"
  }
}

# Redis AUTH token stored in Secrets Manager with rotation
resource "aws_secretsmanager_secret" "redis_auth" {
  name        = "${var.project_name}-redis-auth"
  description = "Redis AUTH token for ElastiCache"

  tags = {
    Name = "${var.project_name}-redis-auth"
  }
}

resource "random_password" "redis_auth" {
  length  = 32
  special = false
}

resource "aws_secretsmanager_secret_version" "redis_auth" {
  secret_id = aws_secretsmanager_secret.redis_auth.id

  secret_string = jsonencode({
    authToken = random_password.redis_auth.result
  })
}

# ElastiCache Redis Replication Group (supports AUTH)
resource "aws_elasticache_replication_group" "main" {
  replication_group_id = "${var.project_name}-redis"
  description          = "Zencoder Translator Redis with AUTH"
  node_type            = var.redis_node_type
  num_cache_clusters   = 1
  engine_version       = "7.1"
  port                 = 6379
  parameter_group_name = "default.redis7"

  subnet_group_name  = aws_elasticache_subnet_group.main.name
  security_group_ids = [aws_security_group.redis.id]

  transit_encryption_enabled = true
  auth_token                 = random_password.redis_auth.result

  auto_minor_version_upgrade = true
  snapshot_retention_limit   = 0
  at_rest_encryption_enabled = true

  tags = {
    Name = "${var.project_name}-redis"
  }
}
