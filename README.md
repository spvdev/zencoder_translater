# Zencoder to AWS MediaConvert Translator API

A drop-in replacement API that translates Zencoder API requests to AWS MediaConvert, enabling seamless migration from Zencoder to AWS for video transcoding.

## Overview

This middleware API provides a Zencoder-compatible interface that translates transcoding requests to AWS MediaConvert. This allows existing applications using Zencoder to migrate to AWS with minimal code changes.

### Features

- **Zencoder API v2 Compatibility**: Supports core Zencoder API endpoints
- **AWS MediaConvert Backend**: Leverages AWS MediaConvert for transcoding
- **Job Tracking**: Persistent job tracking with SQLite/PostgreSQL
- **Webhook Support**: Zencoder-compatible webhook notifications
- **Multiple Output Formats**: MP4, WebM, HLS, DASH, and more
- **Docker Ready**: Easy deployment with Docker and docker-compose

## Quick Start

### Prerequisites

- Python 3.11+
- AWS account with MediaConvert access
- S3 buckets for input/output files
- IAM role for MediaConvert

### Installation

1. Clone the repository:
```bash
git clone <repository-url>
cd zencoder_translater
```

2. Create a virtual environment:
```bash
python -m venv venv
source venv/bin/activate  # On Windows: venv\Scripts\activate
```

3. Install dependencies:
```bash
pip install -r requirements.txt
```

4. Configure environment:
```bash
cp .env.example .env
# Edit .env with your settings
```

5. Run the server:
```bash
uvicorn app.main:app --host 0.0.0.0 --port 8000
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
| `DATABASE_URL` | sqlite | Database connection string |

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

### 1. Create IAM Role

Create an IAM role with the following trust policy:

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

Attach a policy with these permissions:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "s3:GetObject",
        "s3:PutObject"
      ],
      "Resource": [
        "arn:aws:s3:::your-input-bucket/*",
        "arn:aws:s3:::your-output-bucket/*"
      ]
    }
  ]
}
```

### 2. Get MediaConvert Endpoint

```bash
aws mediaconvert describe-endpoints --region us-east-1
```

### 3. Configure S3 Buckets

Ensure your S3 buckets have proper permissions for MediaConvert to read/write.

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
pytest tests/ -v
```

### API Documentation

Once running, visit:
- Swagger UI: http://localhost:8000/docs
- ReDoc: http://localhost:8000/redoc

## License

MIT License - see LICENSE file for details.
