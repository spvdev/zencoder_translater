"""
Pytest configuration and fixtures.
"""

import os
import sys

# Add the project root to the path
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import pytest


@pytest.fixture(scope="session", autouse=True)
def setup_test_environment():
    """Set up test environment variables."""
    os.environ.setdefault("DEBUG", "true")
    os.environ.setdefault("API_KEY", "")
    os.environ.setdefault("DATABASE_URL", "sqlite+aiosqlite:///:memory:")
    os.environ.setdefault("MEDIACONVERT_ROLE_ARN", "arn:aws:iam::123456789012:role/TestRole")
    os.environ.setdefault("S3_OUTPUT_BUCKET", "test-bucket")
    yield
