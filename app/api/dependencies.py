"""
API dependencies and utilities.
"""

from typing import Optional

from fastapi import Header, HTTPException

from app.config import get_settings


async def verify_api_key(
    zencoder_api_key: Optional[str] = Header(None, alias="Zencoder-Api-Key"),
    authorization: Optional[str] = Header(None),
) -> str:
    """
    Verify and extract API key from request headers.

    Supports both:
    - Zencoder-Api-Key header (Zencoder native)
    - Authorization: Bearer <key> header
    """
    settings = get_settings()

    api_key = zencoder_api_key
    if not api_key and authorization:
        if authorization.startswith("Bearer "):
            api_key = authorization[7:]
        else:
            api_key = authorization

    # If no API key is configured, allow all requests (for development)
    if not settings.api_key:
        return api_key or ""

    if not api_key or api_key != settings.api_key:
        raise HTTPException(
            status_code=401,
            detail={"errors": ["Invalid API key"]},
        )

    return api_key
