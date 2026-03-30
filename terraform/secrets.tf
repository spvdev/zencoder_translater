# Secrets Manager - Application Configuration
resource "aws_secretsmanager_secret" "config" {
  name        = "${var.project_name}-translator-config"
  description = "Zencoder Translator API configuration"

  tags = {
    Name = "${var.project_name}-translator-config"
  }
}

resource "aws_secretsmanager_secret_version" "config" {
  secret_id = aws_secretsmanager_secret.config.id

  secret_string = jsonencode({
    APP_NAME             = "Zencoder AWS Translator"
    APP_ENV              = "production"
    APP_DEBUG            = "false"
    APP_TIMEZONE         = "UTC"
    API_KEY              = var.api_key
    DB_CONNECTION        = "mysql"
    DB_HOST              = aws_rds_cluster.main.endpoint
    DB_PORT              = "3306"
    DB_DATABASE          = var.db_name
    DB_USERNAME          = var.db_master_username
    DB_PASSWORD          = var.db_master_password
    REDIS_CLIENT         = "predis"
    REDIS_HOST           = aws_elasticache_cluster.main.cache_nodes[0].address
    REDIS_PORT           = "6379"
    REDIS_PREFIX         = "zencoder_"
    CACHE_DRIVER         = "redis"
    QUEUE_CONNECTION     = "redis"
    AWS_REGION           = var.aws_region
    AWS_S3_REGION        = var.aws_region
    MEDIACONVERT_ENDPOINT = "https://mediaconvert.${var.aws_region}.amazonaws.com"
    MEDIACONVERT_ROLE_ARN = aws_iam_role.mediaconvert.arn
    S3_INPUT_BUCKET      = aws_s3_bucket.input.id
    S3_OUTPUT_BUCKET     = aws_s3_bucket.output.id
    S3_OUTPUT_PREFIX     = "transcoded/"
    WEBHOOK_TIMEOUT      = tostring(var.webhook_timeout)
    WEBHOOK_MAX_RETRIES  = tostring(var.webhook_max_retries)
    LOG_CHANNEL          = "stderr"
    LOG_LEVEL            = "debug"
  })
}
