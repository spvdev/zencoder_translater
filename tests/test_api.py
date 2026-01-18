"""
API endpoint tests.
"""

import pytest
from fastapi.testclient import TestClient

from app.main import app


@pytest.fixture
def client():
    """Create test client."""
    return TestClient(app)


class TestHealthEndpoints:
    """Test health check endpoints."""

    def test_root(self, client):
        """Test root endpoint."""
        response = client.get("/")
        assert response.status_code == 200
        data = response.json()
        assert "service" in data
        assert "status" in data
        assert data["status"] == "healthy"

    def test_health(self, client):
        """Test health endpoint."""
        response = client.get("/health")
        assert response.status_code == 200
        data = response.json()
        assert data["status"] == "healthy"


class TestJobEndpoints:
    """Test job-related endpoints."""

    def test_create_job_without_input(self, client):
        """Test job creation without input fails."""
        response = client.post(
            "/v2/jobs",
            json={},
        )
        assert response.status_code == 422  # Validation error

    def test_list_jobs(self, client):
        """Test listing jobs."""
        response = client.get("/v2/jobs")
        assert response.status_code == 200
        assert isinstance(response.json(), list)

    def test_get_nonexistent_job(self, client):
        """Test getting a job that doesn't exist."""
        response = client.get("/v2/jobs/99999")
        assert response.status_code == 404

    def test_get_nonexistent_output(self, client):
        """Test getting an output that doesn't exist."""
        response = client.get("/v2/outputs/99999")
        assert response.status_code == 404


class TestAccountEndpoints:
    """Test account-related endpoints."""

    def test_get_account(self, client):
        """Test account endpoint."""
        response = client.get("/v2/account")
        assert response.status_code == 200
        data = response.json()
        assert "account_state" in data
        assert data["account_state"] == "active"

    def test_get_minutes_report(self, client):
        """Test minutes report endpoint."""
        response = client.get("/v2/reports/minutes")
        assert response.status_code == 200
        data = response.json()
        assert "total" in data


class TestTranslator:
    """Test the Zencoder to AWS translator."""

    def test_video_codec_mapping(self):
        """Test video codec translation."""
        from app.services.translator import ZencoderToAWSTranslator

        translator = ZencoderToAWSTranslator()

        assert translator.VIDEO_CODEC_MAP["h264"] == "H_264"
        assert translator.VIDEO_CODEC_MAP["h265"] == "H_265"
        assert translator.VIDEO_CODEC_MAP["vp9"] == "VP9"

    def test_audio_codec_mapping(self):
        """Test audio codec translation."""
        from app.services.translator import ZencoderToAWSTranslator

        translator = ZencoderToAWSTranslator()

        assert translator.AUDIO_CODEC_MAP["aac"] == "AAC"
        assert translator.AUDIO_CODEC_MAP["mp3"] == "MP3"
        assert translator.AUDIO_CODEC_MAP["ac3"] == "AC3"

    def test_container_mapping(self):
        """Test container format translation."""
        from app.services.translator import ZencoderToAWSTranslator

        translator = ZencoderToAWSTranslator()

        assert translator.CONTAINER_MAP["mp4"] == "MP4"
        assert translator.CONTAINER_MAP["webm"] == "WEBM"
        assert translator.CONTAINER_MAP["mov"] == "MOV"

    def test_s3_url_normalization(self):
        """Test S3 URL normalization."""
        from app.services.translator import ZencoderToAWSTranslator

        translator = ZencoderToAWSTranslator()

        # Already S3 URL
        assert translator._normalize_s3_url("s3://bucket/key") == "s3://bucket/key"

        # HTTPS virtual-hosted style
        url = "https://bucket.s3.amazonaws.com/key/file.mp4"
        assert translator._normalize_s3_url(url) == "s3://bucket/key/file.mp4"


class TestZencoderModels:
    """Test Zencoder-compatible models."""

    def test_job_request_model(self):
        """Test ZencoderJobRequest model."""
        from app.models.zencoder import ZencoderJobRequest

        request = ZencoderJobRequest(
            input="s3://bucket/input.mp4",
            outputs=[],
            test=True,
        )

        assert request.input == "s3://bucket/input.mp4"
        assert request.test is True

    def test_output_request_model(self):
        """Test ZencoderOutputRequest model."""
        from app.models.zencoder import ZencoderOutputRequest

        output = ZencoderOutputRequest(
            label="720p",
            format="mp4",
            video_codec="h264",
            width=1280,
            height=720,
            video_bitrate=2500,
        )

        assert output.label == "720p"
        assert output.format == "mp4"
        assert output.width == 1280
        assert output.height == 720
