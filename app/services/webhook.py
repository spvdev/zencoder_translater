"""
Webhook notification service.

Handles sending Zencoder-compatible webhook notifications
when jobs complete or fail.
"""

import asyncio
import logging
from typing import Any, Dict, List, Optional, Union

import httpx

from app.config import get_settings

logger = logging.getLogger(__name__)


class WebhookService:
    """Service for sending webhook notifications."""

    def __init__(self):
        """Initialize the webhook service."""
        self.settings = get_settings()

    async def send_notification(
        self,
        url: str,
        payload: Dict[str, Any],
        format: str = "json",
        event: Optional[str] = None,
    ) -> bool:
        """
        Send a single webhook notification.

        Args:
            url: The webhook URL.
            payload: The notification payload.
            format: Payload format ('json' or 'xml').
            event: Optional event type filter.

        Returns:
            True if notification was successful.
        """
        headers = {"Content-Type": "application/json"}

        if event:
            headers["X-Zencoder-Event"] = event

        for attempt in range(self.settings.webhook_max_retries):
            try:
                async with httpx.AsyncClient(
                    timeout=self.settings.webhook_timeout
                ) as client:
                    response = await client.post(
                        url,
                        json=payload,
                        headers=headers,
                    )

                    if response.status_code < 400:
                        logger.info(f"Webhook sent successfully to {url}")
                        return True

                    logger.warning(
                        f"Webhook to {url} returned status {response.status_code}"
                    )

            except httpx.TimeoutException:
                logger.warning(
                    f"Webhook to {url} timed out (attempt {attempt + 1})"
                )
            except httpx.RequestError as e:
                logger.warning(
                    f"Webhook to {url} failed: {e} (attempt {attempt + 1})"
                )

            # Exponential backoff
            if attempt < self.settings.webhook_max_retries - 1:
                await asyncio.sleep(2 ** attempt)

        logger.error(
            f"Failed to send webhook to {url} after "
            f"{self.settings.webhook_max_retries} attempts"
        )
        return False

    async def send_job_notifications(
        self,
        notifications: List[Union[str, Dict[str, Any]]],
        payload: Dict[str, Any],
        event: str = "output_finished",
    ) -> List[bool]:
        """
        Send notifications to multiple endpoints.

        Args:
            notifications: List of notification URLs or configs.
            payload: The notification payload.
            event: The event type (output_finished, job_finished, etc.).

        Returns:
            List of success/failure for each notification.
        """
        results = []

        for notification in notifications:
            if isinstance(notification, str):
                # Simple URL string
                result = await self.send_notification(
                    url=notification,
                    payload=payload,
                    event=event,
                )
            elif isinstance(notification, dict):
                # Notification config object
                url = notification.get("url")
                if not url:
                    logger.warning("Notification config missing URL")
                    results.append(False)
                    continue

                # Check event filter
                notify_event = notification.get("event", event)
                if notify_event != event:
                    logger.debug(
                        f"Skipping notification for event {event} "
                        f"(configured for {notify_event})"
                    )
                    results.append(True)  # Not a failure, just filtered
                    continue

                result = await self.send_notification(
                    url=url,
                    payload=payload,
                    format=notification.get("format", "json"),
                    event=event,
                )
            else:
                logger.warning(f"Invalid notification config: {notification}")
                results.append(False)
                continue

            results.append(result)

        return results

    def build_output_notification_payload(
        self,
        output: Dict[str, Any],
        job: Dict[str, Any],
        input_info: Optional[Dict[str, Any]] = None,
    ) -> Dict[str, Any]:
        """
        Build a Zencoder-compatible output notification payload.

        Args:
            output: Output details.
            job: Job details.
            input_info: Optional input media info.

        Returns:
            Notification payload dictionary.
        """
        payload = {
            "output": output,
            "job": {
                "id": job.get("id"),
                "state": job.get("state"),
                "pass_through": job.get("pass_through"),
                "created_at": job.get("created_at"),
                "finished_at": job.get("finished_at"),
            },
        }

        if input_info:
            payload["input"] = input_info

        return payload

    def build_job_notification_payload(
        self,
        job: Dict[str, Any],
        outputs: List[Dict[str, Any]],
        input_info: Optional[Dict[str, Any]] = None,
    ) -> Dict[str, Any]:
        """
        Build a Zencoder-compatible job notification payload.

        Args:
            job: Job details.
            outputs: List of output details.
            input_info: Optional input media info.

        Returns:
            Notification payload dictionary.
        """
        payload = {
            "job": {
                "id": job.get("id"),
                "state": job.get("state"),
                "pass_through": job.get("pass_through"),
                "created_at": job.get("created_at"),
                "finished_at": job.get("finished_at"),
                "outputs": outputs,
            },
        }

        if input_info:
            payload["input"] = input_info

        return payload


# Singleton instance
_webhook_service: Optional[WebhookService] = None


def get_webhook_service() -> WebhookService:
    """Get or create the webhook service singleton."""
    global _webhook_service
    if _webhook_service is None:
        _webhook_service = WebhookService()
    return _webhook_service
