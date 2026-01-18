"""
Configuration management for the Zencoder-to-AWS translation API.
"""

from functools import lru_cache
from typing import Optional

from pydantic_settings import BaseSettings


class Settings(BaseSettings):
    """Application settings loaded from environment variables."""

    # API Settings
    app_name: str = "Zencoder AWS Translator API"
    debug: bool = False
    api_prefix: str = "/api/v2"

    # Authentication
    api_key: str = ""  # Zencoder-compatible API key for authentication

    # AWS Configuration
    aws_access_key_id: Optional[str] = None
    aws_secret_access_key: Optional[str] = None
    aws_region: str = "us-east-1"

    # AWS MediaConvert Settings
    mediaconvert_endpoint: Optional[str] = None  # Custom endpoint URL
    mediaconvert_role_arn: str = ""  # IAM role ARN for MediaConvert
    mediaconvert_queue_arn: Optional[str] = None  # Optional queue ARN

    # S3 Settings
    s3_input_bucket: str = ""  # Default input bucket
    s3_output_bucket: str = ""  # Default output bucket
    s3_output_prefix: str = "transcoded/"

    # Database
    database_url: str = "sqlite+aiosqlite:///./zencoder_jobs.db"

    # Redis (for Celery)
    redis_url: str = "redis://localhost:6379/0"

    # Webhook Settings
    webhook_timeout: int = 30
    webhook_max_retries: int = 3

    # Job Settings
    default_job_ttl_hours: int = 72  # How long to keep job records

    class Config:
        env_file = ".env"
        env_file_encoding = "utf-8"
        case_sensitive = False


@lru_cache()
def get_settings() -> Settings:
    """Get cached settings instance."""
    return Settings()
