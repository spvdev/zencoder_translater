"""
Database models for job tracking and persistence.
"""

from datetime import datetime
from typing import Optional

from sqlalchemy import (
    Column,
    DateTime,
    Float,
    ForeignKey,
    Integer,
    String,
    Text,
    Boolean,
    JSON,
)
from sqlalchemy.orm import DeclarativeBase, relationship


class Base(DeclarativeBase):
    """SQLAlchemy declarative base."""

    pass


class Job(Base):
    """Transcoding job record."""

    __tablename__ = "jobs"

    id = Column(Integer, primary_key=True, autoincrement=True)
    aws_job_id = Column(String(255), unique=True, nullable=True, index=True)

    # State tracking
    state = Column(String(50), default="pending", index=True)
    progress = Column(Float, default=0.0)
    error_message = Column(Text, nullable=True)
    error_class = Column(String(100), nullable=True)

    # Input details
    input_url = Column(Text, nullable=False)
    input_media_info = Column(JSON, nullable=True)

    # Job settings
    test_mode = Column(Boolean, default=False)
    region = Column(String(50), nullable=True)
    pass_through = Column(Text, nullable=True)

    # Notification settings
    notifications = Column(JSON, nullable=True)

    # Timestamps
    created_at = Column(DateTime, default=datetime.utcnow)
    submitted_at = Column(DateTime, nullable=True)
    started_at = Column(DateTime, nullable=True)
    finished_at = Column(DateTime, nullable=True)
    updated_at = Column(DateTime, default=datetime.utcnow, onupdate=datetime.utcnow)

    # Relationships
    outputs = relationship("Output", back_populates="job", cascade="all, delete-orphan")

    def to_zencoder_status(self) -> dict:
        """Convert to Zencoder status format."""
        return {
            "state": self.state,
            "progress": self.progress,
            "input": self.input_media_info,
            "outputs": [output.to_zencoder_status() for output in self.outputs],
        }

    def to_zencoder_details(self) -> dict:
        """Convert to Zencoder job details format."""
        return {
            "id": self.id,
            "state": self.state,
            "input_media_file": self.input_media_info,
            "output_media_files": [
                output.to_zencoder_details() for output in self.outputs
            ],
            "created_at": self.created_at.isoformat() if self.created_at else None,
            "finished_at": self.finished_at.isoformat() if self.finished_at else None,
            "updated_at": self.updated_at.isoformat() if self.updated_at else None,
            "submitted_at": self.submitted_at.isoformat() if self.submitted_at else None,
            "pass_through": self.pass_through,
            "test": self.test_mode,
        }


class Output(Base):
    """Individual output record for a job."""

    __tablename__ = "outputs"

    id = Column(Integer, primary_key=True, autoincrement=True)
    job_id = Column(Integer, ForeignKey("jobs.id"), nullable=False, index=True)
    aws_output_key = Column(String(255), nullable=True)

    # Output settings (stored from request)
    label = Column(String(255), nullable=True)
    output_url = Column(Text, nullable=True)
    format = Column(String(50), nullable=True)

    # State tracking
    state = Column(String(50), default="pending", index=True)
    progress = Column(Float, default=0.0)
    error_message = Column(Text, nullable=True)
    error_class = Column(String(100), nullable=True)

    # Media info (populated after completion)
    file_size_bytes = Column(Integer, nullable=True)
    duration_ms = Column(Integer, nullable=True)
    width = Column(Integer, nullable=True)
    height = Column(Integer, nullable=True)
    video_codec = Column(String(50), nullable=True)
    video_bitrate_kbps = Column(Integer, nullable=True)
    audio_codec = Column(String(50), nullable=True)
    audio_bitrate_kbps = Column(Integer, nullable=True)
    audio_sample_rate = Column(Integer, nullable=True)
    audio_channels = Column(Integer, nullable=True)
    frame_rate = Column(Float, nullable=True)
    md5_checksum = Column(String(64), nullable=True)

    # Original request settings
    original_settings = Column(JSON, nullable=True)
    notifications = Column(JSON, nullable=True)

    # Timestamps
    created_at = Column(DateTime, default=datetime.utcnow)
    submitted_at = Column(DateTime, nullable=True)
    finished_at = Column(DateTime, nullable=True)
    updated_at = Column(DateTime, default=datetime.utcnow, onupdate=datetime.utcnow)

    # Relationship
    job = relationship("Job", back_populates="outputs")

    def to_zencoder_status(self) -> dict:
        """Convert to Zencoder output status format."""
        return {
            "id": self.id,
            "label": self.label,
            "url": self.output_url,
            "state": self.state,
            "error_message": self.error_message,
            "error_class": self.error_class,
        }

    def to_zencoder_details(self) -> dict:
        """Convert to Zencoder output details format."""
        return {
            "id": self.id,
            "label": self.label,
            "url": self.output_url,
            "state": self.state,
            "error_message": self.error_message,
            "error_class": self.error_class,
            "file_size_in_bytes": self.file_size_bytes,
            "duration_in_ms": self.duration_ms,
            "width": self.width,
            "height": self.height,
            "video_codec": self.video_codec,
            "video_bitrate_in_kbps": self.video_bitrate_kbps,
            "audio_codec": self.audio_codec,
            "audio_bitrate_in_kbps": self.audio_bitrate_kbps,
            "audio_sample_rate": self.audio_sample_rate,
            "channels": str(self.audio_channels) if self.audio_channels else None,
            "frame_rate": self.frame_rate,
            "format": self.format,
            "md5_checksum": self.md5_checksum,
            "finished_at": self.finished_at.isoformat() if self.finished_at else None,
            "submitted_at": self.submitted_at.isoformat() if self.submitted_at else None,
            "created_at": self.created_at.isoformat() if self.created_at else None,
            "updated_at": self.updated_at.isoformat() if self.updated_at else None,
        }

    def to_webhook_payload(self) -> dict:
        """Convert to webhook notification payload."""
        return {
            "output": self.to_zencoder_details(),
            "job": {
                "id": self.job_id,
                "state": self.job.state if self.job else None,
                "pass_through": self.job.pass_through if self.job else None,
            },
        }
