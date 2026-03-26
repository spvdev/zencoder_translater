# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Zencoder to AWS MediaConvert Translator API — a Laravel Lumen 10.x drop-in replacement that translates Zencoder API requests into AWS MediaConvert calls. Clients using the Zencoder API can migrate to AWS without changing their integration code.

## Common Commands

### Development

```bash
# Install dependencies
composer install

# Copy environment config (done automatically on install)
cp .env.example .env

# Run database migrations
php artisan migrate

# Start the development server (serves on port 8000)
php -S localhost:8000 -t public

# Process queue jobs (needed for async job completion tracking)
php artisan queue:work

# Interactive REPL
php artisan tinker
```

### Testing

```bash
# Run all tests
./vendor/bin/phpunit

# Run a single test file
./vendor/bin/phpunit tests/Feature/ApiTest.php

# Run a specific test method
./vendor/bin/phpunit --filter test_health_endpoint_returns_ok
```

Tests use Lumen's `TestCase` base class with `seeStatusCode()` / `seeJson()` / `seeJsonStructure()` assertion style. No phpunit.xml exists — PHPUnit auto-discovers tests from the `tests/` directory using composer autoload (`Tests\\` → `tests/`).

### Code Style

```bash
# Format code with Laravel Pint
./vendor/bin/pint
```

### Docker

```bash
# Build and run
docker-compose up --build

# With Redis for queue processing
docker-compose --profile with-redis up --build
```

The container runs nginx + php-fpm + queue workers via supervisord on port 8000.

## Architecture

### Request Flow

```
Client (Zencoder API format)
  → routes/web.php (both /v2/ and /api/v2/ prefixes)
  → ApiKeyMiddleware (header, bearer token, or query param auth)
  → Controllers (JobController, OutputController, AccountController)
  → ZencoderTranslatorService (translates Zencoder params → MediaConvert JobSettings)
  → MediaConvertService (AWS SDK wrapper, lazy-loads endpoint)
  → AWS MediaConvert API
```

### Key Services (app/Services/)

- **ZencoderTranslatorService** — The core translation layer. Contains all codec/format/profile mappings (Zencoder names → AWS enums), output grouping logic (file vs HLS vs DASH vs thumbnails), and S3 URL normalization. This is the most complex file in the project.
- **MediaConvertService** — Thin wrapper around the AWS SDK. Handles endpoint discovery, job CRUD, status mapping (AWS states → Zencoder states), and progress extraction.
- **WebhookService** — Delivers completion/failure notifications via HTTP POST with exponential backoff retry.

### Async Job Tracking

When a transcoding job is created, `ProcessJobCompletion` (app/Jobs/) is queued. It polls AWS for status, updates the local Job/Output models, sends webhooks on completion, and re-queues itself with a 30-second delay if the job is still processing. Queue driver is configurable (sync for dev, Redis/SQS for production).

### Data Models (app/Models/)

- **Job** — Tracks transcoding jobs. Has `aws_job_id`, state machine (pending → waiting → processing → finished/failed/cancelled), and `toZencoderResponse()`/`toZencoderStatus()`/`toZencoderDetails()` methods for Zencoder-compatible output formatting.
- **Output** — Belongs to Job (cascade delete). Stores per-output media metadata (dimensions, codecs, bitrates, duration, file size) and has its own state/progress tracking.

### Authentication

`ApiKeyMiddleware` checks three locations in order: `Zencoder-Api-Key` header, `Authorization: Bearer` header, `api_key` query param. If no `API_KEY` is configured in env, all requests pass through (development mode).

### Configuration

All config lives in `config/` files reading from environment variables. Key groups:
- **aws.php** — AWS credentials, MediaConvert endpoint/role/queue ARN, S3 buckets
- **app.php** — API key, webhook timeout/retries
- **database.php** — Aurora MySQL (default), SQLite, PostgreSQL connections + Redis

Production deployments on ECS Fargate use AWS Secrets Manager — the Docker entrypoint (`docker/entrypoint.sh`) fetches a secret by ARN and exports its JSON keys as environment variables.

### Dual API Paths

Both `/v2/` and `/api/v2/` route groups exist for backward compatibility with different Zencoder client libraries.
