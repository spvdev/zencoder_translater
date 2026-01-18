"""
Zencoder to AWS MediaConvert translation service.

This module translates Zencoder job specifications into AWS MediaConvert
job settings, handling all the format and option conversions.
"""

import logging
import re
from typing import Any, Dict, List, Optional, Tuple
from urllib.parse import urlparse

from app.config import get_settings
from app.models.zencoder import ZencoderJobRequest, ZencoderOutputRequest

logger = logging.getLogger(__name__)


class ZencoderToAWSTranslator:
    """Translates Zencoder requests to AWS MediaConvert format."""

    # Video codec mappings
    VIDEO_CODEC_MAP = {
        "h264": "H_264",
        "h.264": "H_264",
        "avc": "H_264",
        "h265": "H_265",
        "h.265": "H_265",
        "hevc": "H_265",
        "vp8": "VP8",
        "vp9": "VP9",
        "av1": "AV1",
        "mpeg2": "MPEG2",
        "prores": "PRORES",
    }

    # Audio codec mappings
    AUDIO_CODEC_MAP = {
        "aac": "AAC",
        "mp3": "MP3",
        "ac3": "AC3",
        "eac3": "EAC3",
        "vorbis": "VORBIS",
        "opus": "OPUS",
        "flac": "FLAC",
        "pcm": "WAV",
        "wav": "WAV",
    }

    # Container format mappings
    CONTAINER_MAP = {
        "mp4": "MP4",
        "m4v": "MP4",
        "mov": "MOV",
        "webm": "WEBM",
        "mkv": "MKV",
        "avi": "AVI",
        "mxf": "MXF",
        "ts": "M2TS",
        "m2ts": "M2TS",
        "mpegts": "M2TS",
        "raw": "RAW",
    }

    # H.264 profile mappings
    H264_PROFILE_MAP = {
        "baseline": "BASELINE",
        "main": "MAIN",
        "high": "HIGH",
        "high10": "HIGH_10BIT",
        "high422": "HIGH_422",
        "high444": "HIGH_444_PREDICTIVE",
    }

    # H.264 level mappings
    H264_LEVEL_MAP = {
        "1": "LEVEL_1",
        "1.1": "LEVEL_1_1",
        "1.2": "LEVEL_1_2",
        "1.3": "LEVEL_1_3",
        "2": "LEVEL_2",
        "2.1": "LEVEL_2_1",
        "2.2": "LEVEL_2_2",
        "3": "LEVEL_3",
        "3.1": "LEVEL_3_1",
        "3.2": "LEVEL_3_2",
        "4": "LEVEL_4",
        "4.1": "LEVEL_4_1",
        "4.2": "LEVEL_4_2",
        "5": "LEVEL_5",
        "5.1": "LEVEL_5_1",
        "5.2": "LEVEL_5_2",
    }

    def __init__(self):
        """Initialize the translator."""
        self.settings = get_settings()

    def translate_job(self, request: ZencoderJobRequest) -> Dict[str, Any]:
        """
        Translate a Zencoder job request to MediaConvert job settings.

        Args:
            request: Zencoder job request.

        Returns:
            MediaConvert job settings dictionary.
        """
        # Get outputs (handle both 'outputs' list and single 'output')
        outputs = request.outputs or []
        if request.output:
            outputs.append(request.output)

        if not outputs:
            # Create a default output
            outputs = [ZencoderOutputRequest()]

        # Build input configuration
        input_config = self._build_input(request.input)

        # Group outputs by type (standard file, HLS, DASH)
        output_groups = self._build_output_groups(outputs, request)

        # Build the job settings
        job_settings = {
            "Settings": {
                "Inputs": [input_config],
                "OutputGroups": output_groups,
            },
            "Role": self.settings.mediaconvert_role_arn,
        }

        # Add queue if configured
        if self.settings.mediaconvert_queue_arn:
            job_settings["Queue"] = self.settings.mediaconvert_queue_arn

        # Add user metadata for tracking
        job_settings["UserMetadata"] = {
            "source": "zencoder-translator",
            "test": str(request.test or False),
        }
        if request.pass_through:
            job_settings["UserMetadata"]["pass_through"] = request.pass_through[:256]

        return job_settings

    def _build_input(self, input_url: str) -> Dict[str, Any]:
        """Build MediaConvert input configuration."""
        input_config = {
            "FileInput": self._normalize_s3_url(input_url),
            "AudioSelectors": {
                "Audio Selector 1": {
                    "DefaultSelection": "DEFAULT",
                }
            },
            "VideoSelector": {},
            "TimecodeSource": "ZEROBASED",
        }

        return input_config

    def _build_output_groups(
        self, outputs: List[ZencoderOutputRequest], request: ZencoderJobRequest
    ) -> List[Dict[str, Any]]:
        """Build output groups from Zencoder outputs."""
        output_groups = []

        # Separate outputs by streaming type
        standard_outputs = []
        hls_outputs = []
        dash_outputs = []

        for output in outputs:
            streaming_format = (output.streaming_delivery_format or "").lower()
            output_type = (output.type or "").lower()

            if streaming_format == "hls" or output_type == "segmented":
                hls_outputs.append(output)
            elif streaming_format == "dash":
                dash_outputs.append(output)
            else:
                standard_outputs.append(output)

        # Build standard file output group
        if standard_outputs:
            file_group = self._build_file_output_group(standard_outputs)
            if file_group:
                output_groups.append(file_group)

        # Build HLS output group
        if hls_outputs:
            hls_group = self._build_hls_output_group(hls_outputs)
            if hls_group:
                output_groups.append(hls_group)

        # Build DASH output group
        if dash_outputs:
            dash_group = self._build_dash_output_group(dash_outputs)
            if dash_group:
                output_groups.append(dash_group)

        return output_groups

    def _build_file_output_group(
        self, outputs: List[ZencoderOutputRequest]
    ) -> Optional[Dict[str, Any]]:
        """Build a file output group."""
        if not outputs:
            return None

        # Determine destination
        destination = self._get_output_destination(outputs[0])

        group = {
            "Name": "File Group",
            "OutputGroupSettings": {
                "Type": "FILE_GROUP_SETTINGS",
                "FileGroupSettings": {
                    "Destination": destination,
                },
            },
            "Outputs": [],
        }

        for output in outputs:
            output_config = self._build_output(output)
            group["Outputs"].append(output_config)

        return group

    def _build_hls_output_group(
        self, outputs: List[ZencoderOutputRequest]
    ) -> Optional[Dict[str, Any]]:
        """Build an HLS output group."""
        if not outputs:
            return None

        # Use first output for group settings
        first_output = outputs[0]
        destination = self._get_output_destination(first_output)
        segment_length = first_output.segment_seconds or 6

        group = {
            "Name": "HLS Group",
            "OutputGroupSettings": {
                "Type": "HLS_GROUP_SETTINGS",
                "HlsGroupSettings": {
                    "Destination": destination,
                    "SegmentLength": segment_length,
                    "MinSegmentLength": 0,
                    "SegmentControl": "SEGMENTED_FILES",
                    "ManifestDurationFormat": "INTEGER",
                },
            },
            "Outputs": [],
        }

        for output in outputs:
            output_config = self._build_output(output, is_hls=True)
            group["Outputs"].append(output_config)

        return group

    def _build_dash_output_group(
        self, outputs: List[ZencoderOutputRequest]
    ) -> Optional[Dict[str, Any]]:
        """Build a DASH output group."""
        if not outputs:
            return None

        first_output = outputs[0]
        destination = self._get_output_destination(first_output)
        segment_length = first_output.segment_seconds or 6

        group = {
            "Name": "DASH Group",
            "OutputGroupSettings": {
                "Type": "DASH_ISO_GROUP_SETTINGS",
                "DashIsoGroupSettings": {
                    "Destination": destination,
                    "SegmentLength": segment_length,
                    "FragmentLength": 2,
                    "SegmentControl": "SEGMENTED_FILES",
                },
            },
            "Outputs": [],
        }

        for output in outputs:
            output_config = self._build_output(output, is_dash=True)
            group["Outputs"].append(output_config)

        return group

    def _build_output(
        self,
        output: ZencoderOutputRequest,
        is_hls: bool = False,
        is_dash: bool = False,
    ) -> Dict[str, Any]:
        """Build a single output configuration."""
        config = {}

        # Add name modifier for multiple outputs
        if output.label:
            config["NameModifier"] = f"_{output.label}"
        elif output.filename:
            # Extract name without extension
            name = output.filename.rsplit(".", 1)[0]
            config["NameModifier"] = f"_{name}"

        # Container settings
        container = self._get_container(output, is_hls, is_dash)
        config["ContainerSettings"] = {"Container": container}

        # Video settings
        if not output.skip_video:
            video_desc = self._build_video_description(output)
            config["VideoDescription"] = video_desc

        # Audio settings
        if not output.skip_audio:
            audio_desc = self._build_audio_description(output, is_hls)
            config["AudioDescriptions"] = [audio_desc]

        return config

    def _build_video_description(
        self, output: ZencoderOutputRequest
    ) -> Dict[str, Any]:
        """Build video description settings."""
        video = {}

        # Resolution
        width, height = self._get_dimensions(output)
        if width:
            video["Width"] = width
        if height:
            video["Height"] = height

        # Scaling behavior
        if output.aspect_mode:
            video["ScalingBehavior"] = self._get_scaling_behavior(output.aspect_mode)

        # Frame rate
        if output.frame_rate:
            video["FramerateControl"] = "SPECIFIED"
            video["FramerateNumerator"] = int(output.frame_rate * 1000)
            video["FramerateDenominator"] = 1000
        elif output.max_frame_rate:
            video["FramerateControl"] = "INITIALIZE_FROM_SOURCE"
            video["FramerateConversionAlgorithm"] = "INTERPOLATE"

        # Codec settings
        codec = self._get_video_codec(output)
        video["CodecSettings"] = self._build_video_codec_settings(output, codec)

        return video

    def _build_video_codec_settings(
        self, output: ZencoderOutputRequest, codec: str
    ) -> Dict[str, Any]:
        """Build video codec settings."""
        settings = {"Codec": codec}

        if codec == "H_264":
            h264_settings = self._build_h264_settings(output)
            settings["H264Settings"] = h264_settings
        elif codec == "H_265":
            h265_settings = self._build_h265_settings(output)
            settings["H265Settings"] = h265_settings
        elif codec == "VP8":
            settings["Vp8Settings"] = self._build_vp8_settings(output)
        elif codec == "VP9":
            settings["Vp9Settings"] = self._build_vp9_settings(output)

        return settings

    def _build_h264_settings(self, output: ZencoderOutputRequest) -> Dict[str, Any]:
        """Build H.264 codec settings."""
        settings = {
            "RateControlMode": "CBR" if output.one_pass else "QVBR",
        }

        # Bitrate
        if output.video_bitrate:
            settings["Bitrate"] = output.video_bitrate * 1000  # kbps to bps
            settings["RateControlMode"] = "CBR"
        elif output.quality:
            # Map Zencoder quality (1-5) to QVBR quality (1-10)
            qvbr_quality = min(10, max(1, output.quality * 2))
            settings["QvbrSettings"] = {"QvbrQualityLevel": qvbr_quality}
            settings["RateControlMode"] = "QVBR"

        # Max bitrate
        if output.max_video_bitrate:
            settings["MaxBitrate"] = output.max_video_bitrate * 1000

        # Profile
        if output.h264_profile:
            profile = self.H264_PROFILE_MAP.get(
                output.h264_profile.lower(), "MAIN"
            )
            settings["CodecProfile"] = profile

        # Level
        if output.h264_level:
            level = self.H264_LEVEL_MAP.get(output.h264_level, "AUTO")
            settings["CodecLevel"] = level

        # Reference frames
        if output.h264_reference_frames:
            settings["NumberReferenceFrames"] = output.h264_reference_frames

        # B-frames
        if output.h264_bframes is not None:
            settings["NumberBFramesBetweenReferenceFrames"] = output.h264_bframes

        # GOP settings
        if output.keyframe_interval:
            settings["GopSize"] = output.keyframe_interval
            settings["GopSizeUnits"] = "FRAMES"

        return settings

    def _build_h265_settings(self, output: ZencoderOutputRequest) -> Dict[str, Any]:
        """Build H.265/HEVC codec settings."""
        settings = {
            "RateControlMode": "QVBR",
        }

        if output.video_bitrate:
            settings["Bitrate"] = output.video_bitrate * 1000
            settings["RateControlMode"] = "CBR"
        elif output.quality:
            qvbr_quality = min(10, max(1, output.quality * 2))
            settings["QvbrSettings"] = {"QvbrQualityLevel": qvbr_quality}

        if output.max_video_bitrate:
            settings["MaxBitrate"] = output.max_video_bitrate * 1000

        if output.keyframe_interval:
            settings["GopSize"] = output.keyframe_interval
            settings["GopSizeUnits"] = "FRAMES"

        return settings

    def _build_vp8_settings(self, output: ZencoderOutputRequest) -> Dict[str, Any]:
        """Build VP8 codec settings."""
        settings = {
            "RateControlMode": "VBR",
        }

        if output.video_bitrate:
            settings["Bitrate"] = output.video_bitrate * 1000

        if output.max_video_bitrate:
            settings["MaxBitrate"] = output.max_video_bitrate * 1000

        return settings

    def _build_vp9_settings(self, output: ZencoderOutputRequest) -> Dict[str, Any]:
        """Build VP9 codec settings."""
        settings = {
            "RateControlMode": "VBR",
        }

        if output.video_bitrate:
            settings["Bitrate"] = output.video_bitrate * 1000

        if output.max_video_bitrate:
            settings["MaxBitrate"] = output.max_video_bitrate * 1000

        return settings

    def _build_audio_description(
        self, output: ZencoderOutputRequest, is_hls: bool = False
    ) -> Dict[str, Any]:
        """Build audio description settings."""
        audio = {
            "AudioSourceName": "Audio Selector 1",
        }

        # Codec settings
        codec = self._get_audio_codec(output, is_hls)
        audio["CodecSettings"] = self._build_audio_codec_settings(output, codec)

        return audio

    def _build_audio_codec_settings(
        self, output: ZencoderOutputRequest, codec: str
    ) -> Dict[str, Any]:
        """Build audio codec settings."""
        settings = {"Codec": codec}

        if codec == "AAC":
            aac_settings = {
                "CodecProfile": "LC",
                "RateControlMode": "CBR",
            }

            if output.audio_bitrate:
                aac_settings["Bitrate"] = output.audio_bitrate * 1000
            else:
                aac_settings["Bitrate"] = 128000  # Default 128 kbps

            if output.audio_sample_rate:
                aac_settings["SampleRate"] = output.audio_sample_rate

            if output.audio_channels:
                aac_settings["CodingMode"] = self._get_aac_coding_mode(
                    output.audio_channels
                )

            settings["AacSettings"] = aac_settings

        elif codec == "MP3":
            mp3_settings = {
                "RateControlMode": "CBR",
            }

            if output.audio_bitrate:
                mp3_settings["Bitrate"] = output.audio_bitrate * 1000
            else:
                mp3_settings["Bitrate"] = 128000

            if output.audio_sample_rate:
                mp3_settings["SampleRate"] = output.audio_sample_rate

            if output.audio_channels:
                mp3_settings["Channels"] = output.audio_channels

            settings["Mp3Settings"] = mp3_settings

        elif codec == "AC3":
            ac3_settings = {}

            if output.audio_bitrate:
                ac3_settings["Bitrate"] = output.audio_bitrate * 1000
            else:
                ac3_settings["Bitrate"] = 384000

            if output.audio_sample_rate:
                ac3_settings["SampleRate"] = output.audio_sample_rate

            settings["Ac3Settings"] = ac3_settings

        return settings

    def _get_aac_coding_mode(self, channels: int) -> str:
        """Get AAC coding mode from channel count."""
        coding_modes = {
            1: "CODING_MODE_1_0",
            2: "CODING_MODE_2_0",
            6: "CODING_MODE_5_1",
        }
        return coding_modes.get(channels, "CODING_MODE_2_0")

    def _get_dimensions(
        self, output: ZencoderOutputRequest
    ) -> Tuple[Optional[int], Optional[int]]:
        """Get output dimensions."""
        width = output.width
        height = output.height

        # Parse size string (e.g., "1920x1080")
        if output.size:
            match = re.match(r"(\d+)x(\d+)", output.size)
            if match:
                width = int(match.group(1))
                height = int(match.group(2))

        return width, height

    def _get_video_codec(self, output: ZencoderOutputRequest) -> str:
        """Determine video codec from output settings."""
        if output.video_codec:
            codec = output.video_codec.lower()
            return self.VIDEO_CODEC_MAP.get(codec, "H_264")

        # Infer from format/container
        format_lower = (output.format or "mp4").lower()
        if format_lower in ("webm",):
            return "VP9"
        elif format_lower in ("mp4", "m4v", "mov"):
            return "H_264"

        return "H_264"

    def _get_audio_codec(
        self, output: ZencoderOutputRequest, is_hls: bool = False
    ) -> str:
        """Determine audio codec from output settings."""
        if output.audio_codec:
            codec = output.audio_codec.lower()
            return self.AUDIO_CODEC_MAP.get(codec, "AAC")

        # Infer from format/container
        format_lower = (output.format or "mp4").lower()
        if format_lower == "webm":
            return "OPUS"
        elif is_hls:
            return "AAC"

        return "AAC"

    def _get_container(
        self,
        output: ZencoderOutputRequest,
        is_hls: bool = False,
        is_dash: bool = False,
    ) -> str:
        """Determine container format."""
        if is_hls:
            return "M3U8"
        if is_dash:
            return "MPD"

        if output.format:
            format_lower = output.format.lower()
            return self.CONTAINER_MAP.get(format_lower, "MP4")

        return "MP4"

    def _get_scaling_behavior(self, aspect_mode: str) -> str:
        """Map Zencoder aspect mode to MediaConvert scaling behavior."""
        mode_map = {
            "preserve": "DEFAULT",
            "stretch": "STRETCH_TO_OUTPUT",
            "crop": "DEFAULT",
            "pad": "DEFAULT",
        }
        return mode_map.get(aspect_mode.lower(), "DEFAULT")

    def _get_output_destination(self, output: ZencoderOutputRequest) -> str:
        """Determine output destination S3 path."""
        if output.url:
            return self._normalize_s3_url(output.url)

        if output.base_url:
            base = self._normalize_s3_url(output.base_url)
            if not base.endswith("/"):
                base += "/"
            return base

        # Use default bucket
        bucket = self.settings.s3_output_bucket
        prefix = self.settings.s3_output_prefix
        return f"s3://{bucket}/{prefix}"

    def _normalize_s3_url(self, url: str) -> str:
        """Normalize URL to S3 URI format."""
        if url.startswith("s3://"):
            return url

        # Handle HTTPS S3 URLs
        parsed = urlparse(url)

        if "s3.amazonaws.com" in parsed.netloc:
            # https://bucket.s3.amazonaws.com/key or
            # https://s3.amazonaws.com/bucket/key
            if parsed.netloc.endswith(".s3.amazonaws.com"):
                bucket = parsed.netloc.replace(".s3.amazonaws.com", "")
                key = parsed.path.lstrip("/")
            else:
                parts = parsed.path.lstrip("/").split("/", 1)
                bucket = parts[0]
                key = parts[1] if len(parts) > 1 else ""
            return f"s3://{bucket}/{key}"

        # Handle S3 regional URLs
        if ".s3." in parsed.netloc and ".amazonaws.com" in parsed.netloc:
            bucket = parsed.netloc.split(".s3.")[0]
            key = parsed.path.lstrip("/")
            return f"s3://{bucket}/{key}"

        # Return as-is if not recognized as S3
        return url


# Singleton instance
_translator: Optional[ZencoderToAWSTranslator] = None


def get_translator() -> ZencoderToAWSTranslator:
    """Get or create the translator singleton."""
    global _translator
    if _translator is None:
        _translator = ZencoderToAWSTranslator()
    return _translator
