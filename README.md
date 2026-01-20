# Zencoder to AWS MediaConvert Translator API

A drop-in replacement API built with Laravel Lumen that translates Zencoder API requests to AWS MediaConvert, enabling seamless migration from Zencoder to AWS for video transcoding.

## Overview

This middleware API provides a Zencoder-compatible interface that translates transcoding requests to AWS MediaConvert. This allows existing applications using Zencoder to migrate to AWS with minimal code changes.

### Features

- **Zencoder API v2 Compatibility**: Supports core Zencoder API endpoints
- **AWS MediaConvert Backend**: Leverages AWS MediaConvert for transcoding
- **Job Tracking**: Persistent job tracking with SQLite/MySQL/PostgreSQL
- **Webhook Support**: Zencoder-compatible webhook notifications
- **Multiple Output Formats**: MP4, WebM, HLS, DASH, and more
- **Docker Ready**: Easy deployment with Docker and docker-compose
- **Built with Lumen**: Lightweight, high-performance PHP framework

## Quick Start

### Prerequisites

- PHP 8.1+
- Composer
- AWS account with MediaConvert access
- S3 buckets for input/output files
- IAM role for MediaConvert

### Installation

1. Clone the repository:
```bash
git clone <repository-url>
cd zencoder_translater
```

2. Install dependencies:
```bash
composer install
```

3. Configure environment:
```bash
cp .env.example .env
# Edit .env with your settings
```

4. Create the database:
```bash
touch database/database.sqlite
php artisan migrate
```

5. Run the server:
```bash
php -S localhost:8000 -t public
```

### Docker Deployment (Local)

```bash
# Build and run with docker-compose
docker-compose up -d

# Or build manually
docker build -t zencoder-translator .
docker run -p 8000:8000 --env-file .env zencoder-translator
```

### AWS ECS Fargate Deployment

For production deployment on AWS ECS Fargate with RDS and ElastiCache, see [AWS Deployment Guide](#aws-ecs-fargate-deployment) below.

## Configuration

### Required Settings

| Variable | Description |
|----------|-------------|
| `MEDIACONVERT_ROLE_ARN` | IAM role ARN for MediaConvert |
| `S3_OUTPUT_BUCKET` | Default S3 bucket for transcoded outputs |

### Optional Settings

| Variable | Default | Description |
|----------|---------|-------------|
| `API_KEY` | (none) | API key for authentication |
| `AWS_REGION` | us-east-1 | AWS region |
| `MEDIACONVERT_ENDPOINT` | (auto) | MediaConvert endpoint URL |
| `S3_INPUT_BUCKET` | (none) | Default input bucket |
| `DB_CONNECTION` | sqlite | Database driver |

See `.env.example` for all configuration options.

## API Endpoints

### Jobs

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/v2/jobs` | Create a new transcoding job |
| GET | `/v2/jobs` | List all jobs |
| GET | `/v2/jobs/{id}` | Get job details |
| GET | `/v2/jobs/{id}/progress` | Get job progress |
| PUT | `/v2/jobs/{id}/cancel` | Cancel a job |
| PUT | `/v2/jobs/{id}/resubmit` | Resubmit a failed job |

### Outputs

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/v2/outputs/{id}` | Get output details |
| GET | `/v2/outputs/{id}/progress` | Get output progress |

### Account

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/v2/account` | Get account information |
| GET | `/v2/reports/minutes` | Get usage report |

## Usage Examples

### Create a Job

```bash
curl -X POST http://localhost:8000/v2/jobs \
  -H "Content-Type: application/json" \
  -H "Zencoder-Api-Key: your-api-key" \
  -d '{
    "input": "s3://my-bucket/input/video.mp4",
    "outputs": [
      {
        "label": "mp4_720p",
        "format": "mp4",
        "video_codec": "h264",
        "width": 1280,
        "height": 720,
        "video_bitrate": 2500,
        "base_url": "s3://my-bucket/output/"
      },
      {
        "label": "webm_720p",
        "format": "webm",
        "video_codec": "vp9",
        "width": 1280,
        "height": 720,
        "video_bitrate": 2000,
        "base_url": "s3://my-bucket/output/"
      }
    ],
    "notifications": [
      "https://example.com/webhook"
    ]
  }'
```

### Check Job Status

```bash
curl http://localhost:8000/v2/jobs/123/progress \
  -H "Zencoder-Api-Key: your-api-key"
```

### Cancel a Job

```bash
curl -X PUT http://localhost:8000/v2/jobs/123/cancel \
  -H "Zencoder-Api-Key: your-api-key"
```

## Zencoder to AWS Mapping

### Video Codecs

| Zencoder | AWS MediaConvert |
|----------|------------------|
| h264, avc | H_264 |
| h265, hevc | H_265 |
| vp8 | VP8 |
| vp9 | VP9 |

### Audio Codecs

| Zencoder | AWS MediaConvert |
|----------|------------------|
| aac | AAC |
| mp3 | MP3 |
| ac3 | AC3 |
| vorbis | VORBIS |

### Container Formats

| Zencoder | AWS MediaConvert |
|----------|------------------|
| mp4 | MP4 |
| webm | WEBM |
| mov | MOV |
| ts | M2TS |

### Streaming Formats

| Zencoder | AWS MediaConvert |
|----------|------------------|
| HLS (type: segmented) | HLS_GROUP_SETTINGS |
| DASH | DASH_ISO_GROUP_SETTINGS |

## AWS Setup

### S3 Bucket Structure

Based on your existing Zencoder setup, the S3 structure follows this pattern:

```
s3://your-bucket/
├── path/to/
│   ├── video.mov                      # Input file
│   ├── video_zencoder.mp4             # Transcoded output (MP4)
│   ├── video_zencoder.webm            # Transcoded output (WebM)
│   └── video_zencoder/
│       └── thumbs/
│           └── {gid}_thumb.png        # Video thumbnail
```

**Note:** Input and output files typically reside in the **same bucket** with outputs having the `_zencoder` suffix.

### 1. Create IAM Role for MediaConvert

Create an IAM role that MediaConvert will assume to access your S3 bucket.

**Trust Policy** (allows MediaConvert to assume this role):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Principal": {
        "Service": "mediaconvert.amazonaws.com"
      },
      "Action": "sts:AssumeRole"
    }
  ]
}
```

**Permissions Policy** (for same bucket input/output):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "S3ReadWrite",
      "Effect": "Allow",
      "Action": [
        "s3:GetObject",
        "s3:PutObject",
        "s3:PutObjectAcl"
      ],
      "Resource": [
        "arn:aws:s3:::your-bucket/*"
      ]
    },
    {
      "Sid": "S3ListBucket",
      "Effect": "Allow",
      "Action": [
        "s3:ListBucket"
      ],
      "Resource": [
        "arn:aws:s3:::your-bucket"
      ]
    }
  ]
}
```

**For separate input/output buckets:**

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "S3ReadInput",
      "Effect": "Allow",
      "Action": [
        "s3:GetObject"
      ],
      "Resource": [
        "arn:aws:s3:::your-input-bucket/*"
      ]
    },
    {
      "Sid": "S3WriteOutput",
      "Effect": "Allow",
      "Action": [
        "s3:PutObject",
        "s3:PutObjectAcl"
      ],
      "Resource": [
        "arn:aws:s3:::your-output-bucket/*"
      ]
    }
  ]
}
```

### 2. Create the Role via AWS CLI

```bash
# Create the role
aws iam create-role \
  --role-name MediaConvertRole \
  --assume-role-policy-document file://trust-policy.json

# Attach the permissions policy
aws iam put-role-policy \
  --role-name MediaConvertRole \
  --policy-name MediaConvertS3Access \
  --policy-document file://permissions-policy.json

# Get the role ARN (you'll need this for MEDIACONVERT_ROLE_ARN)
aws iam get-role --role-name MediaConvertRole --query 'Role.Arn' --output text
```

### 3. Get MediaConvert Endpoint

```bash
# For eu-west-1 (Ireland/Dublin)
aws mediaconvert describe-endpoints --region eu-west-1

# Example output:
# {
#   "Endpoints": [
#     {
#       "Url": "https://abc123xyz.mediaconvert.eu-west-1.amazonaws.com"
#     }
#   ]
# }
```

### 4. Configure Environment

```bash
# .env
AWS_ACCESS_KEY_ID=your-access-key
AWS_SECRET_ACCESS_KEY=your-secret-key
AWS_REGION=eu-west-1

MEDIACONVERT_ENDPOINT=https://abc123xyz.mediaconvert.eu-west-1.amazonaws.com
MEDIACONVERT_ROLE_ARN=arn:aws:iam::123456789012:role/MediaConvertRole

# Same bucket for input/output
S3_OUTPUT_BUCKET=your-bucket
S3_OUTPUT_PREFIX=
```

### 5. S3 Bucket Policy (Optional)

If your bucket is not in the same AWS account, add a bucket policy:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "MediaConvertAccess",
      "Effect": "Allow",
      "Principal": {
        "AWS": "arn:aws:iam::123456789012:role/MediaConvertRole"
      },
      "Action": [
        "s3:GetObject",
        "s3:PutObject",
        "s3:PutObjectAcl"
      ],
      "Resource": "arn:aws:s3:::your-bucket/*"
    }
  ]
}
```

## AWS ECS Fargate Deployment

This section covers deploying the translator API on AWS ECS Fargate with RDS (PostgreSQL/MySQL) and ElastiCache (Redis).

### Architecture Overview

```
                    ┌─────────────────────────────────────────────────────────────┐
                    │                         VPC                                  │
                    │  ┌──────────────────────────────────────────────────────┐   │
                    │  │                    Public Subnet                      │   │
Internet ──────────►│  │  ┌─────────────────────────────────────────────┐     │   │
                    │  │  │        Application Load Balancer             │     │   │
                    │  │  └─────────────────────────────────────────────┘     │   │
                    │  └──────────────────────────────────────────────────────┘   │
                    │                            │                                 │
                    │  ┌──────────────────────────────────────────────────────┐   │
                    │  │                   Private Subnet                      │   │
                    │  │  ┌─────────────────────────────────────────────┐     │   │
                    │  │  │          ECS Fargate Service                 │     │   │
                    │  │  │  ┌─────────────┐  ┌─────────────┐           │     │   │
                    │  │  │  │   Task 1    │  │   Task 2    │           │     │   │
                    │  │  │  └─────────────┘  └─────────────┘           │     │   │
                    │  │  └─────────────────────────────────────────────┘     │   │
                    │  │           │                    │                      │   │
                    │  │     ┌─────┴────────────────────┴─────┐               │   │
                    │  │     │                                 │               │   │
                    │  │  ┌──▼────────────┐    ┌──────────────▼──┐            │   │
                    │  │  │   RDS         │    │   ElastiCache   │            │   │
                    │  │  │  (PostgreSQL) │    │   (Redis)       │            │   │
                    │  │  └───────────────┘    └─────────────────┘            │   │
                    │  └──────────────────────────────────────────────────────┘   │
                    └─────────────────────────────────────────────────────────────┘
```

### Prerequisites

- AWS CLI configured
- Docker installed
- ECR repository created
- VPC with public and private subnets

### 1. Create ECR Repository and Push Image

```bash
# Create ECR repository
aws ecr create-repository --repository-name zencoder-translator

# Get login credentials
aws ecr get-login-password --region us-east-1 | \
  docker login --username AWS --password-stdin 123456789012.dkr.ecr.us-east-1.amazonaws.com

# Build and push
docker build -t zencoder-translator .
docker tag zencoder-translator:latest 123456789012.dkr.ecr.us-east-1.amazonaws.com/zencoder-translator:latest
docker push 123456789012.dkr.ecr.us-east-1.amazonaws.com/zencoder-translator:latest
```

### 2. Create RDS PostgreSQL Instance

```bash
# Create DB subnet group
aws rds create-db-subnet-group \
  --db-subnet-group-name zencoder-db-subnet \
  --db-subnet-group-description "Subnet group for Zencoder translator" \
  --subnet-ids subnet-xxxxx subnet-yyyyy

# Create PostgreSQL instance
aws rds create-db-instance \
  --db-instance-identifier zencoder-translator-db \
  --db-instance-class db.t3.micro \
  --engine postgres \
  --engine-version 15 \
  --master-username dbadmin \
  --master-user-password "YourSecurePassword" \
  --allocated-storage 20 \
  --db-subnet-group-name zencoder-db-subnet \
  --vpc-security-group-ids sg-xxxxx \
  --no-publicly-accessible \
  --storage-encrypted
```

### 3. Create ElastiCache Redis Cluster

```bash
# Create cache subnet group
aws elasticache create-cache-subnet-group \
  --cache-subnet-group-name zencoder-cache-subnet \
  --cache-subnet-group-description "Subnet group for Zencoder translator" \
  --subnet-ids subnet-xxxxx subnet-yyyyy

# Create Redis cluster
aws elasticache create-cache-cluster \
  --cache-cluster-id zencoder-redis \
  --cache-node-type cache.t3.micro \
  --engine redis \
  --num-cache-nodes 1 \
  --cache-subnet-group-name zencoder-cache-subnet \
  --security-group-ids sg-xxxxx
```

### 4. Create ECS Task Execution Role

Create `ecs-task-execution-policy.json`:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "ecr:GetAuthorizationToken",
        "ecr:BatchCheckLayerAvailability",
        "ecr:GetDownloadUrlForLayer",
        "ecr:BatchGetImage"
      ],
      "Resource": "*"
    },
    {
      "Effect": "Allow",
      "Action": [
        "logs:CreateLogStream",
        "logs:PutLogEvents"
      ],
      "Resource": "*"
    },
    {
      "Effect": "Allow",
      "Action": [
        "ssm:GetParameters",
        "secretsmanager:GetSecretValue"
      ],
      "Resource": "*"
    }
  ]
}
```

Create `ecs-task-role-policy.json` (permissions for the application):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "SecretsManagerAccess",
      "Effect": "Allow",
      "Action": [
        "secretsmanager:GetSecretValue"
      ],
      "Resource": "arn:aws:secretsmanager:us-east-1:123456789012:secret:zencoder-translator/*"
    },
    {
      "Sid": "MediaConvertAccess",
      "Effect": "Allow",
      "Action": [
        "mediaconvert:CreateJob",
        "mediaconvert:GetJob",
        "mediaconvert:CancelJob",
        "mediaconvert:ListJobs",
        "mediaconvert:DescribeEndpoints"
      ],
      "Resource": "*"
    },
    {
      "Sid": "PassRoleToMediaConvert",
      "Effect": "Allow",
      "Action": "iam:PassRole",
      "Resource": "arn:aws:iam::123456789012:role/MediaConvertRole"
    },
    {
      "Sid": "S3Access",
      "Effect": "Allow",
      "Action": [
        "s3:GetObject",
        "s3:PutObject",
        "s3:ListBucket"
      ],
      "Resource": [
        "arn:aws:s3:::your-media-bucket",
        "arn:aws:s3:::your-media-bucket/*"
      ]
    }
  ]
}
```

```bash
# Create execution role
aws iam create-role \
  --role-name ecsTaskExecutionRole \
  --assume-role-policy-document '{
    "Version": "2012-10-17",
    "Statement": [{
      "Effect": "Allow",
      "Principal": {"Service": "ecs-tasks.amazonaws.com"},
      "Action": "sts:AssumeRole"
    }]
  }'

aws iam put-role-policy \
  --role-name ecsTaskExecutionRole \
  --policy-name EcsTaskExecutionPolicy \
  --policy-document file://ecs-task-execution-policy.json

# Create task role (for application permissions)
aws iam create-role \
  --role-name ZencoderTranslatorTaskRole \
  --assume-role-policy-document '{
    "Version": "2012-10-17",
    "Statement": [{
      "Effect": "Allow",
      "Principal": {"Service": "ecs-tasks.amazonaws.com"},
      "Action": "sts:AssumeRole"
    }]
  }'

aws iam put-role-policy \
  --role-name ZencoderTranslatorTaskRole \
  --policy-name ZencoderTranslatorPolicy \
  --policy-document file://ecs-task-role-policy.json
```

### 5. Create Secrets Manager Secret

Store all configuration in a single Secrets Manager secret. The container fetches this at startup.

```bash
# Create the secret with all configuration
aws secretsmanager create-secret \
  --name zencoder-translator/config \
  --description "Zencoder Translator API configuration" \
  --secret-string '{
    "APP_ENV": "production",
    "APP_DEBUG": "false",
    "APP_URL": "https://your-alb-url.amazonaws.com",
    "LOG_CHANNEL": "stderr",
    "LOG_LEVEL": "info",
    "DB_CONNECTION": "pgsql",
    "DB_HOST": "zencoder-translator-db.xxxxx.us-east-1.rds.amazonaws.com",
    "DB_PORT": "5432",
    "DB_DATABASE": "zencoder_translator",
    "DB_USERNAME": "dbadmin",
    "DB_PASSWORD": "your-secure-password",
    "REDIS_HOST": "zencoder-redis.xxxxx.cache.amazonaws.com",
    "REDIS_PORT": "6379",
    "CACHE_DRIVER": "redis",
    "QUEUE_CONNECTION": "redis",
    "API_KEY": "your-api-key",
    "MEDIACONVERT_ENDPOINT": "https://xxxxx.mediaconvert.us-east-1.amazonaws.com",
    "MEDIACONVERT_ROLE_ARN": "arn:aws:iam::123456789012:role/MediaConvertRole",
    "S3_OUTPUT_BUCKET": "your-media-bucket"
  }'

# To update the secret later:
aws secretsmanager update-secret \
  --secret-id zencoder-translator/config \
  --secret-string '{"key": "new-value", ...}'
```

### 6. Create ECS Task Definition

Create `task-definition.json`. The container uses `SECRET_ARN` to fetch all configuration from Secrets Manager at startup:

```json
{
  "family": "zencoder-translator",
  "networkMode": "awsvpc",
  "requiresCompatibilities": ["FARGATE"],
  "cpu": "512",
  "memory": "1024",
  "executionRoleArn": "arn:aws:iam::123456789012:role/ecsTaskExecutionRole",
  "taskRoleArn": "arn:aws:iam::123456789012:role/ZencoderTranslatorTaskRole",
  "containerDefinitions": [
    {
      "name": "zencoder-translator",
      "image": "123456789012.dkr.ecr.us-east-1.amazonaws.com/zencoder-translator:latest",
      "essential": true,
      "portMappings": [
        {
          "containerPort": 8000,
          "protocol": "tcp"
        }
      ],
      "environment": [
        {"name": "AWS_REGION", "value": "us-east-1"},
        {"name": "SECRET_ARN", "value": "arn:aws:secretsmanager:us-east-1:123456789012:secret:zencoder-translator/config-AbCdEf"}
      ],
      "logConfiguration": {
        "logDriver": "awslogs",
        "options": {
          "awslogs-group": "/ecs/zencoder-translator",
          "awslogs-region": "us-east-1",
          "awslogs-stream-prefix": "ecs"
        }
      },
      "healthCheck": {
        "command": ["CMD-SHELL", "wget -qO- http://localhost:8000/health || exit 1"],
        "interval": 30,
        "timeout": 5,
        "retries": 3,
        "startPeriod": 60
      }
    }
  ]
}
```

```bash
# Create CloudWatch log group
aws logs create-log-group --log-group-name /ecs/zencoder-translator

# Register task definition
aws ecs register-task-definition --cli-input-json file://task-definition.json
```

### 7. Create Application Load Balancer

```bash
# Create ALB
aws elbv2 create-load-balancer \
  --name zencoder-alb \
  --subnets subnet-public1 subnet-public2 \
  --security-groups sg-alb \
  --scheme internet-facing \
  --type application

# Create target group
aws elbv2 create-target-group \
  --name zencoder-tg \
  --protocol HTTP \
  --port 8000 \
  --vpc-id vpc-xxxxx \
  --target-type ip \
  --health-check-path /health \
  --health-check-interval-seconds 30

# Create listener
aws elbv2 create-listener \
  --load-balancer-arn arn:aws:elasticloadbalancing:us-east-1:123456789012:loadbalancer/app/zencoder-alb/xxxxx \
  --protocol HTTP \
  --port 80 \
  --default-actions Type=forward,TargetGroupArn=arn:aws:elasticloadbalancing:us-east-1:123456789012:targetgroup/zencoder-tg/xxxxx
```

### 8. Create ECS Service

```bash
# Create ECS cluster
aws ecs create-cluster --cluster-name zencoder-cluster

# Create service
aws ecs create-service \
  --cluster zencoder-cluster \
  --service-name zencoder-translator \
  --task-definition zencoder-translator:1 \
  --desired-count 2 \
  --launch-type FARGATE \
  --network-configuration "awsvpcConfiguration={subnets=[subnet-private1,subnet-private2],securityGroups=[sg-ecs],assignPublicIp=DISABLED}" \
  --load-balancers "targetGroupArn=arn:aws:elasticloadbalancing:us-east-1:123456789012:targetgroup/zencoder-tg/xxxxx,containerName=zencoder-translator,containerPort=8000"
```

### 9. Security Group Rules

**ALB Security Group (sg-alb):**
- Inbound: TCP 80/443 from 0.0.0.0/0
- Outbound: TCP 8000 to sg-ecs

**ECS Security Group (sg-ecs):**
- Inbound: TCP 8000 from sg-alb
- Outbound: TCP 5432 to sg-rds (PostgreSQL)
- Outbound: TCP 6379 to sg-redis (ElastiCache)
- Outbound: TCP 443 to 0.0.0.0/0 (AWS APIs, webhooks)

**RDS Security Group (sg-rds):**
- Inbound: TCP 5432 from sg-ecs

**ElastiCache Security Group (sg-redis):**
- Inbound: TCP 6379 from sg-ecs

### 10. Run Database Migrations

Run migrations as a one-off ECS task **before** deploying new versions with schema changes. This approach avoids race conditions when multiple containers start simultaneously.

```bash
# Initial setup: Run migrations after creating RDS instance
aws ecs run-task \
  --cluster zencoder-cluster \
  --task-definition zencoder-translator:1 \
  --launch-type FARGATE \
  --network-configuration "awsvpcConfiguration={subnets=[subnet-private1],securityGroups=[sg-ecs],assignPublicIp=DISABLED}" \
  --overrides '{
    "containerOverrides": [{
      "name": "zencoder-translator",
      "command": ["php", "artisan", "migrate", "--force"]
    }]
  }'

# Check migration status
aws ecs run-task \
  --cluster zencoder-cluster \
  --task-definition zencoder-translator:1 \
  --launch-type FARGATE \
  --network-configuration "awsvpcConfiguration={subnets=[subnet-private1],securityGroups=[sg-ecs],assignPublicIp=DISABLED}" \
  --overrides '{
    "containerOverrides": [{
      "name": "zencoder-translator",
      "command": ["php", "artisan", "migrate:status"]
    }]
  }'

# Rollback last migration batch (if needed)
aws ecs run-task \
  --cluster zencoder-cluster \
  --task-definition zencoder-translator:1 \
  --launch-type FARGATE \
  --network-configuration "awsvpcConfiguration={subnets=[subnet-private1],securityGroups=[sg-ecs],assignPublicIp=DISABLED}" \
  --overrides '{
    "containerOverrides": [{
      "name": "zencoder-translator",
      "command": ["php", "artisan", "migrate:rollback", "--force"]
    }]
  }'
```

### Cost Optimization Tips

1. **Use Fargate Spot** for non-critical workloads (up to 70% savings)
2. **Right-size RDS** - Start with db.t3.micro and scale as needed
3. **Use ElastiCache Serverless** for variable workloads
4. **Enable auto-scaling** based on CPU/memory metrics
5. **Use Reserved Capacity** for predictable workloads

### Monitoring

CloudWatch metrics to monitor:
- ECS: CPUUtilization, MemoryUtilization
- ALB: RequestCount, TargetResponseTime, HTTPCode_Target_5XX_Count
- RDS: CPUUtilization, DatabaseConnections, FreeStorageSpace
- ElastiCache: CurrConnections, CacheHits, CacheMisses

## Project Structure

```
zencoder_translater/
├── app/
│   ├── Console/
│   ├── Exceptions/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── AccountController.php
│   │   │   ├── JobController.php
│   │   │   └── OutputController.php
│   │   └── Middleware/
│   │       ├── ApiKeyMiddleware.php
│   │       └── CorsMiddleware.php
│   ├── Jobs/
│   │   └── ProcessJobCompletion.php
│   ├── Models/
│   │   ├── Job.php
│   │   └── Output.php
│   ├── Providers/
│   │   ├── AppServiceProvider.php
│   │   └── AwsServiceProvider.php
│   └── Services/
│       ├── MediaConvertService.php
│       ├── WebhookService.php
│       └── ZencoderTranslatorService.php
├── bootstrap/
│   └── app.php
├── config/
│   ├── app.php
│   ├── aws.php
│   ├── cache.php
│   ├── database.php
│   ├── logging.php
│   └── queue.php
├── database/
│   └── migrations/
├── docker/
│   ├── nginx.conf
│   └── supervisord.conf
├── public/
│   └── index.php
├── routes/
│   └── web.php
├── storage/
├── tests/
├── .env.example
├── artisan
├── composer.json
├── docker-compose.yml
├── Dockerfile
└── README.md
```

## Migration Guide

### Updating Your Application

1. **Update Base URL**: Change from `https://app.zencoder.com/api/v2` to your translator API URL
2. **Update API Key**: Use the API key configured in your translator
3. **Test with Test Mode**: Use `"test": true` to validate without processing

### Unsupported Features

Some Zencoder features are not directly mapped:

- Live streaming (use AWS MediaLive instead)
- FTP/SFTP inputs (pre-upload to S3)
- Zencoder-specific analytics

## Development

### Running Tests

```bash
./vendor/bin/phpunit
```

### Code Style

```bash
./vendor/bin/pint
```

## License

MIT License - see LICENSE file for details.
