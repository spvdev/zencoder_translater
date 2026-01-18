"""
Zencoder-compatible request and response models.

These models mirror the Zencoder API specification to provide
drop-in compatibility for existing Zencoder clients.
"""

from datetime import datetime
from typing import Any, Dict, List, Optional, Union

from pydantic import BaseModel, Field, HttpUrl


class ZencoderThumbnail(BaseModel):
    """Thumbnail generation settings."""

    number: Optional[int] = None
    interval: Optional[int] = None
    times: Optional[List[float]] = None
    size: Optional[str] = None
    base_url: Optional[str] = None
    prefix: Optional[str] = None
    format: Optional[str] = "png"
    aspect_mode: Optional[str] = None
    public: Optional[bool] = False


class ZencoderWatermark(BaseModel):
    """Watermark overlay settings."""

    url: str
    x: Optional[Union[int, str]] = None
    y: Optional[Union[int, str]] = None
    width: Optional[Union[int, str]] = None
    height: Optional[Union[int, str]] = None
    origin: Optional[str] = None
    opacity: Optional[float] = 1.0


class ZencoderOutputRequest(BaseModel):
    """Zencoder output specification."""

    # Basic settings
    label: Optional[str] = None
    url: Optional[str] = None  # Alias for base_url + filename
    base_url: Optional[str] = None
    filename: Optional[str] = None
    public: Optional[bool] = False

    # Video settings
    format: Optional[str] = None  # mp4, webm, etc.
    video_codec: Optional[str] = None
    video_bitrate: Optional[int] = None
    width: Optional[int] = None
    height: Optional[int] = None
    size: Optional[str] = None  # WxH format
    aspect_mode: Optional[str] = None  # preserve, stretch, crop, pad
    frame_rate: Optional[float] = None
    max_frame_rate: Optional[float] = None
    keyframe_interval: Optional[int] = None
    video_bit_depth: Optional[int] = None
    quality: Optional[int] = None  # 1-5

    # Audio settings
    audio_codec: Optional[str] = None
    audio_bitrate: Optional[int] = None
    audio_sample_rate: Optional[int] = None
    audio_channels: Optional[int] = None
    skip_audio: Optional[bool] = False

    # H.264 specific
    h264_profile: Optional[str] = None  # baseline, main, high
    h264_level: Optional[str] = None
    h264_reference_frames: Optional[int] = None
    h264_bframes: Optional[int] = None

    # Processing
    speed: Optional[int] = None  # 1-5, tradeoff between speed and quality
    decoder_bitrate_cap: Optional[int] = None
    decoder_buffer_size: Optional[int] = None
    one_pass: Optional[bool] = None
    audio_constant_bitrate: Optional[bool] = None

    # Clipping
    clip_length: Optional[Union[int, float, str]] = None
    start_clip: Optional[Union[int, float, str]] = None

    # Thumbnails
    thumbnails: Optional[Union[ZencoderThumbnail, List[ZencoderThumbnail]]] = None

    # Watermarks
    watermarks: Optional[List[ZencoderWatermark]] = None

    # Notifications
    notifications: Optional[List[Union[str, Dict[str, Any]]]] = None

    # HLS/DASH specific
    segment_seconds: Optional[int] = None
    type: Optional[str] = None  # segmented, standard
    streaming_delivery_format: Optional[str] = None  # hls, dash

    # Advanced
    skip_video: Optional[bool] = False
    source: Optional[str] = None  # For dependent outputs
    copy_video: Optional[bool] = None
    copy_audio: Optional[bool] = None
    deinterlace: Optional[str] = None
    max_video_bitrate: Optional[int] = None
    min_video_bitrate: Optional[int] = None

    # Credentials
    credentials: Optional[str] = None

    # Additional pass-through options
    class Config:
        extra = "allow"


class ZencoderJobRequest(BaseModel):
    """Zencoder job creation request."""

    # Input
    input: str = Field(..., description="URL of the input media file")
    input_credentials: Optional[str] = None

    # Outputs
    outputs: Optional[List[ZencoderOutputRequest]] = None
    output: Optional[ZencoderOutputRequest] = None  # Single output shorthand

    # Job settings
    region: Optional[str] = None
    test: Optional[bool] = False  # Test mode (no charges)
    private: Optional[bool] = False
    download_connections: Optional[int] = None
    pass_through: Optional[str] = None  # Custom data to pass through

    # Notifications
    notifications: Optional[List[Union[str, Dict[str, Any]]]] = None

    # Advanced
    api_key: Optional[str] = None  # Can be passed in body instead of header

    class Config:
        extra = "allow"


class ZencoderOutputStatus(BaseModel):
    """Status of a single output."""

    id: int
    label: Optional[str] = None
    url: Optional[str] = None
    state: str  # pending, waiting, processing, finished, failed, cancelled
    error_message: Optional[str] = None
    error_class: Optional[str] = None
    primary: Optional[bool] = None
    md5_checksum: Optional[str] = None
    file_size_bytes: Optional[int] = None
    file_size_in_bytes: Optional[int] = None
    duration_in_ms: Optional[int] = None
    audio_sample_rate: Optional[int] = None
    audio_bitrate_in_kbps: Optional[int] = None
    audio_codec: Optional[str] = None
    channels: Optional[str] = None
    video_bitrate_in_kbps: Optional[int] = None
    video_codec: Optional[str] = None
    frame_rate: Optional[float] = None
    width: Optional[int] = None
    height: Optional[int] = None
    format: Optional[str] = None
    total_bitrate_in_kbps: Optional[int] = None
    finished_at: Optional[datetime] = None
    submitted_at: Optional[datetime] = None
    created_at: Optional[datetime] = None
    updated_at: Optional[datetime] = None


class ZencoderJobStatus(BaseModel):
    """Job status response."""

    state: str  # pending, waiting, processing, finished, failed, cancelled
    progress: Optional[float] = None  # 0-100
    input: Optional[Dict[str, Any]] = None
    outputs: Optional[List[ZencoderOutputStatus]] = None


class ZencoderJobDetails(BaseModel):
    """Full job details response."""

    id: int
    state: str
    input_media_file: Optional[Dict[str, Any]] = None
    output_media_files: Optional[List[Dict[str, Any]]] = None
    thumbnails: Optional[List[Dict[str, Any]]] = None
    created_at: Optional[datetime] = None
    finished_at: Optional[datetime] = None
    updated_at: Optional[datetime] = None
    submitted_at: Optional[datetime] = None
    pass_through: Optional[str] = None
    test: Optional[bool] = None
    privacy: Optional[bool] = None


class ZencoderJobResponse(BaseModel):
    """Response after creating a job."""

    id: int
    outputs: List[Dict[str, Any]]
    test: Optional[bool] = None


class ZencoderErrorResponse(BaseModel):
    """Error response format."""

    errors: List[str]


class ZencoderWebhookPayload(BaseModel):
    """Webhook notification payload sent to client endpoints."""

    output: Optional[ZencoderOutputStatus] = None
    job: Optional[Dict[str, Any]] = None
    input: Optional[Dict[str, Any]] = None
