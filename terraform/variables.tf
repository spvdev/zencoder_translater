variable "aws_region" {
  description = "AWS region for all resources"
  type        = string
  default     = "eu-west-1"
}

variable "project_name" {
  description = "Project name used for resource naming"
  type        = string
  default     = "zencoder"
}

variable "account_id" {
  description = "AWS account ID"
  type        = string
  default     = "517241097221"
}

variable "vpc_cidr" {
  description = "CIDR block for the VPC"
  type        = string
  default     = "10.1.0.0/16"
}

variable "public_subnet_cidrs" {
  description = "CIDR blocks for public subnets"
  type        = list(string)
  default     = ["10.1.1.0/24", "10.1.2.0/24"]
}

variable "private_subnet_cidrs" {
  description = "CIDR blocks for private subnets"
  type        = list(string)
  default     = ["10.1.10.0/24", "10.1.11.0/24"]
}

variable "availability_zones" {
  description = "Availability zones"
  type        = list(string)
  default     = ["eu-west-1a", "eu-west-1b"]
}

# ECS
variable "ecs_cpu" {
  description = "ECS task CPU units"
  type        = number
  default     = 512
}

variable "ecs_memory" {
  description = "ECS task memory in MB"
  type        = number
  default     = 1024
}

variable "ecs_desired_count" {
  description = "Desired number of ECS tasks"
  type        = number
  default     = 1
}

variable "container_port" {
  description = "Container port"
  type        = number
  default     = 8000
}

# Aurora
variable "db_instance_class" {
  description = "Aurora DB instance class"
  type        = string
  default     = "db.t4g.medium"
}

variable "db_name" {
  description = "Database name"
  type        = string
  default     = "zencoder_translator"
}

variable "db_master_username" {
  description = "Database master username"
  type        = string
  default     = "dbadmin"
}

variable "db_master_password" {
  description = "Database master password"
  type        = string
  sensitive   = true
}

variable "db_backup_retention" {
  description = "Backup retention period in days"
  type        = number
  default     = 7
}

# Redis
variable "redis_node_type" {
  description = "ElastiCache Redis node type"
  type        = string
  default     = "cache.t4g.micro"
}

# Application
variable "api_key" {
  description = "API key for the translator service"
  type        = string
  sensitive   = true
}

variable "mediaconvert_role_arn" {
  description = "MediaConvert IAM role ARN (will be created by this config)"
  type        = string
  default     = ""
}

variable "webhook_timeout" {
  description = "Webhook timeout in seconds"
  type        = number
  default     = 30
}

variable "webhook_max_retries" {
  description = "Maximum webhook retry attempts"
  type        = number
  default     = 3
}

# CloudFront
variable "acm_certificate_arn" {
  description = "ACM certificate ARN (must be in us-east-1 for CloudFront)"
  type        = string
  default     = "arn:aws:acm:us-east-1:517241097221:certificate/df10a332-2675-48b0-a41b-9ef017807671"
}
