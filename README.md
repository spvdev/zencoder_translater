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

### Docker Deployment

```bash
# Build and run with docker-compose
docker-compose up -d

# Or build manually
docker build -t zencoder-translator .
docker run -p 8000:8000 --env-file .env zencoder-translator
```

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
│   └── database.php
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
