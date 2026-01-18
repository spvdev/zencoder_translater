"""
AWS MediaConvert service integration.

This module handles all interactions with AWS MediaConvert, including
job creation, status polling, and result retrieval.
"""

import logging
from typing import Any, Dict, List, Optional
from datetime import datetime

import boto3
from botocore.exceptions import ClientError

from app.config import get_settings

logger = logging.getLogger(__name__)


class MediaConvertService:
    """Service for interacting with AWS MediaConvert."""

    def __init__(self):
        """Initialize the MediaConvert service."""
        self.settings = get_settings()
        self._client = None
        self._endpoint = None

    def _get_endpoint(self) -> str:
        """Get or discover the MediaConvert endpoint."""
        if self._endpoint:
            return self._endpoint

        if self.settings.mediaconvert_endpoint:
            self._endpoint = self.settings.mediaconvert_endpoint
            return self._endpoint

        # Discover endpoint
        client = boto3.client(
            "mediaconvert",
            region_name=self.settings.aws_region,
            aws_access_key_id=self.settings.aws_access_key_id,
            aws_secret_access_key=self.settings.aws_secret_access_key,
        )
        response = client.describe_endpoints()
        self._endpoint = response["Endpoints"][0]["Url"]
        logger.info(f"Discovered MediaConvert endpoint: {self._endpoint}")
        return self._endpoint

    def _get_client(self):
        """Get or create the MediaConvert client."""
        if self._client:
            return self._client

        endpoint = self._get_endpoint()
        self._client = boto3.client(
            "mediaconvert",
            endpoint_url=endpoint,
            region_name=self.settings.aws_region,
            aws_access_key_id=self.settings.aws_access_key_id,
            aws_secret_access_key=self.settings.aws_secret_access_key,
        )
        return self._client

    def create_job(self, job_settings: Dict[str, Any]) -> Dict[str, Any]:
        """
        Create a MediaConvert job.

        Args:
            job_settings: MediaConvert job settings dictionary.

        Returns:
            MediaConvert job response.

        Raises:
            ClientError: If job creation fails.
        """
        client = self._get_client()

        # Ensure required fields
        if "Role" not in job_settings:
            job_settings["Role"] = self.settings.mediaconvert_role_arn

        if self.settings.mediaconvert_queue_arn and "Queue" not in job_settings:
            job_settings["Queue"] = self.settings.mediaconvert_queue_arn

        try:
            response = client.create_job(**job_settings)
            logger.info(f"Created MediaConvert job: {response['Job']['Id']}")
            return response["Job"]
        except ClientError as e:
            logger.error(f"Failed to create MediaConvert job: {e}")
            raise

    def get_job(self, job_id: str) -> Dict[str, Any]:
        """
        Get a MediaConvert job by ID.

        Args:
            job_id: The MediaConvert job ID.

        Returns:
            MediaConvert job details.
        """
        client = self._get_client()

        try:
            response = client.get_job(Id=job_id)
            return response["Job"]
        except ClientError as e:
            logger.error(f"Failed to get MediaConvert job {job_id}: {e}")
            raise

    def cancel_job(self, job_id: str) -> bool:
        """
        Cancel a MediaConvert job.

        Args:
            job_id: The MediaConvert job ID.

        Returns:
            True if cancellation was successful.
        """
        client = self._get_client()

        try:
            client.cancel_job(Id=job_id)
            logger.info(f"Cancelled MediaConvert job: {job_id}")
            return True
        except ClientError as e:
            logger.error(f"Failed to cancel MediaConvert job {job_id}: {e}")
            raise

    def list_jobs(
        self,
        status: Optional[str] = None,
        max_results: int = 20,
        next_token: Optional[str] = None,
    ) -> Dict[str, Any]:
        """
        List MediaConvert jobs.

        Args:
            status: Filter by status (SUBMITTED, PROGRESSING, COMPLETE, CANCELED, ERROR).
            max_results: Maximum number of results.
            next_token: Pagination token.

        Returns:
            List of jobs and pagination info.
        """
        client = self._get_client()

        params = {"MaxResults": max_results}
        if status:
            params["Status"] = status
        if next_token:
            params["NextToken"] = next_token

        try:
            response = client.list_jobs(**params)
            return {
                "jobs": response.get("Jobs", []),
                "next_token": response.get("NextToken"),
            }
        except ClientError as e:
            logger.error(f"Failed to list MediaConvert jobs: {e}")
            raise

    @staticmethod
    def map_status_to_zencoder(aws_status: str) -> str:
        """
        Map AWS MediaConvert status to Zencoder status.

        Args:
            aws_status: AWS MediaConvert job status.

        Returns:
            Equivalent Zencoder status.
        """
        status_map = {
            "SUBMITTED": "waiting",
            "PROGRESSING": "processing",
            "COMPLETE": "finished",
            "CANCELED": "cancelled",
            "ERROR": "failed",
        }
        return status_map.get(aws_status, "pending")

    @staticmethod
    def calculate_progress(job: Dict[str, Any]) -> float:
        """
        Calculate job progress percentage.

        Args:
            job: MediaConvert job details.

        Returns:
            Progress percentage (0-100).
        """
        status = job.get("Status", "")

        if status == "COMPLETE":
            return 100.0
        elif status in ("CANCELED", "ERROR"):
            return 0.0
        elif status == "PROGRESSING":
            # MediaConvert provides percentage for progressing jobs
            return float(job.get("JobPercentComplete", 0))
        else:
            return 0.0

    def get_job_output_details(self, job: Dict[str, Any]) -> List[Dict[str, Any]]:
        """
        Extract output details from a completed MediaConvert job.

        Args:
            job: MediaConvert job details.

        Returns:
            List of output details.
        """
        outputs = []
        output_groups = job.get("Settings", {}).get("OutputGroups", [])

        for group_idx, group in enumerate(output_groups):
            group_outputs = group.get("Outputs", [])
            output_settings = group.get("OutputGroupSettings", {})

            # Determine output destination
            destination = ""
            if "FileGroupSettings" in output_settings:
                destination = output_settings["FileGroupSettings"].get("Destination", "")
            elif "HlsGroupSettings" in output_settings:
                destination = output_settings["HlsGroupSettings"].get("Destination", "")
            elif "DashIsoGroupSettings" in output_settings:
                destination = output_settings["DashIsoGroupSettings"].get("Destination", "")

            for out_idx, output in enumerate(group_outputs):
                video_desc = output.get("VideoDescription", {})
                audio_descs = output.get("AudioDescriptions", [])

                output_detail = {
                    "group_index": group_idx,
                    "output_index": out_idx,
                    "destination": destination,
                    "name_modifier": output.get("NameModifier", ""),
                    "extension": output.get("Extension", ""),
                    "container": output.get("ContainerSettings", {}).get("Container", ""),
                    "video": {
                        "width": video_desc.get("Width"),
                        "height": video_desc.get("Height"),
                        "codec": video_desc.get("CodecSettings", {}).get("Codec"),
                    } if video_desc else None,
                    "audio": {
                        "codec": audio_descs[0].get("CodecSettings", {}).get("Codec")
                        if audio_descs else None,
                    } if audio_descs else None,
                }
                outputs.append(output_detail)

        return outputs


# Singleton instance
_mediaconvert_service: Optional[MediaConvertService] = None


def get_mediaconvert_service() -> MediaConvertService:
    """Get or create the MediaConvert service singleton."""
    global _mediaconvert_service
    if _mediaconvert_service is None:
        _mediaconvert_service = MediaConvertService()
    return _mediaconvert_service
