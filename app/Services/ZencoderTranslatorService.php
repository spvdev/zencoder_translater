<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class ZencoderTranslatorService
{
    /**
     * Video codec mappings.
     */
    private const VIDEO_CODEC_MAP = [
        'h264' => 'H_264',
        'h.264' => 'H_264',
        'avc' => 'H_264',
        'h265' => 'H_265',
        'h.265' => 'H_265',
        'hevc' => 'H_265',
        'vp8' => 'VP8',
        'vp9' => 'VP9',
        'av1' => 'AV1',
        'mpeg2' => 'MPEG2',
        'prores' => 'PRORES',
    ];

    /**
     * Audio codec mappings.
     */
    private const AUDIO_CODEC_MAP = [
        'aac' => 'AAC',
        'mp3' => 'MP3',
        'ac3' => 'AC3',
        'eac3' => 'EAC3',
        'vorbis' => 'VORBIS',
        'opus' => 'OPUS',
        'flac' => 'FLAC',
        'pcm' => 'WAV',
        'wav' => 'WAV',
    ];

    /**
     * Container format mappings.
     */
    private const CONTAINER_MAP = [
        'mp4' => 'MP4',
        'm4v' => 'MP4',
        'mov' => 'MOV',
        'webm' => 'WEBM',
        'mkv' => 'MKV',
        'avi' => 'AVI',
        'mxf' => 'MXF',
        'ts' => 'M2TS',
        'm2ts' => 'M2TS',
        'mpegts' => 'M2TS',
        'raw' => 'RAW',
    ];

    /**
     * H.264 profile mappings.
     */
    private const H264_PROFILE_MAP = [
        'baseline' => 'BASELINE',
        'main' => 'MAIN',
        'high' => 'HIGH',
        'high10' => 'HIGH_10BIT',
        'high422' => 'HIGH_422',
        'high444' => 'HIGH_444_PREDICTIVE',
    ];

    /**
     * H.264 level mappings.
     */
    private const H264_LEVEL_MAP = [
        '1' => 'LEVEL_1',
        '1.1' => 'LEVEL_1_1',
        '1.2' => 'LEVEL_1_2',
        '1.3' => 'LEVEL_1_3',
        '2' => 'LEVEL_2',
        '2.1' => 'LEVEL_2_1',
        '2.2' => 'LEVEL_2_2',
        '3' => 'LEVEL_3',
        '3.1' => 'LEVEL_3_1',
        '3.2' => 'LEVEL_3_2',
        '4' => 'LEVEL_4',
        '4.1' => 'LEVEL_4_1',
        '4.2' => 'LEVEL_4_2',
        '5' => 'LEVEL_5',
        '5.1' => 'LEVEL_5_1',
        '5.2' => 'LEVEL_5_2',
    ];

    /**
     * Zencoder region to AWS region mappings.
     */
    private const REGION_MAP = [
        'us' => 'us-east-1',
        'us-east' => 'us-east-1',
        'us-west' => 'us-west-2',
        'europe' => 'eu-west-1',
        'eu' => 'eu-west-1',
        'eu-dublin' => 'eu-west-1',
        'eu-west' => 'eu-west-1',
        'asia' => 'ap-northeast-1',
        'asia-pacific' => 'ap-southeast-1',
        'australia' => 'ap-southeast-2',
        'south-america' => 'sa-east-1',
    ];

    /**
     * Translate a Zencoder job request to MediaConvert job settings.
     */
    public function translateJob(array $request): array
    {
        // Get outputs
        $outputs = $request['outputs'] ?? [];
        if (isset($request['output'])) {
            $outputs[] = $request['output'];
        }

        if (empty($outputs)) {
            $outputs = [[]]; // Default output
        }

        // Build input configuration
        $inputConfig = $this->buildInput($request['input']);

        // Group outputs by type
        $outputGroups = $this->buildOutputGroups($outputs, $request);

        // Build the job settings
        $jobSettings = [
            'Settings' => [
                'Inputs' => [$inputConfig],
                'OutputGroups' => $outputGroups,
            ],
            'Role' => config('aws.mediaconvert.role_arn'),
        ];

        // Add queue if configured
        if (config('aws.mediaconvert.queue_arn')) {
            $jobSettings['Queue'] = config('aws.mediaconvert.queue_arn');
        }

        // Add user metadata for tracking
        $jobSettings['UserMetadata'] = [
            'source' => 'zencoder-translator',
            'test' => ($request['test'] ?? false) ? 'true' : 'false',
        ];

        if (isset($request['pass_through'])) {
            $jobSettings['UserMetadata']['pass_through'] = substr($request['pass_through'], 0, 256);
        }

        return $jobSettings;
    }

    /**
     * Build MediaConvert input configuration.
     */
    private function buildInput(string $inputUrl): array
    {
        return [
            'FileInput' => $this->normalizeS3Url($inputUrl),
            'AudioSelectors' => [
                'Audio Selector 1' => [
                    'DefaultSelection' => 'DEFAULT',
                ],
            ],
            'VideoSelector' => [],
            'TimecodeSource' => 'ZEROBASED',
        ];
    }

    /**
     * Build output groups from Zencoder outputs.
     */
    private function buildOutputGroups(array $outputs, array $request): array
    {
        $outputGroups = [];

        // Separate outputs by type
        $standardOutputs = [];
        $hlsOutputs = [];
        $dashOutputs = [];
        $thumbnailOutputs = [];

        foreach ($outputs as $output) {
            // Check if this is a thumbnail-only output
            if (isset($output['thumbnails']) && !isset($output['video_codec']) && !isset($output['audio_codec'])) {
                $thumbnailOutputs[] = $output;
                continue;
            }

            $streamingFormat = strtolower($output['streaming_delivery_format'] ?? '');
            $outputType = strtolower($output['type'] ?? '');

            if ($streamingFormat === 'hls' || $outputType === 'segmented') {
                $hlsOutputs[] = $output;
            } elseif ($streamingFormat === 'dash') {
                $dashOutputs[] = $output;
            } else {
                // Check if output has embedded thumbnails config
                if (isset($output['thumbnails'])) {
                    $thumbnailOutputs[] = ['thumbnails' => $output['thumbnails']];
                }
                $standardOutputs[] = $output;
            }
        }

        // Build standard file output group
        if (!empty($standardOutputs)) {
            $fileGroup = $this->buildFileOutputGroup($standardOutputs);
            if ($fileGroup) {
                $outputGroups[] = $fileGroup;
            }
        }

        // Build HLS output group
        if (!empty($hlsOutputs)) {
            $hlsGroup = $this->buildHlsOutputGroup($hlsOutputs);
            if ($hlsGroup) {
                $outputGroups[] = $hlsGroup;
            }
        }

        // Build DASH output group
        if (!empty($dashOutputs)) {
            $dashGroup = $this->buildDashOutputGroup($dashOutputs);
            if ($dashGroup) {
                $outputGroups[] = $dashGroup;
            }
        }

        // Build thumbnail/frame capture output group
        if (!empty($thumbnailOutputs)) {
            $thumbnailGroup = $this->buildThumbnailOutputGroup($thumbnailOutputs, $request);
            if ($thumbnailGroup) {
                $outputGroups[] = $thumbnailGroup;
            }
        }

        return $outputGroups;
    }

    /**
     * Build a thumbnail/frame capture output group.
     */
    private function buildThumbnailOutputGroup(array $thumbnailOutputs, array $request): ?array
    {
        if (empty($thumbnailOutputs)) {
            return null;
        }

        // Use first thumbnail config
        $thumbConfig = $thumbnailOutputs[0]['thumbnails'] ?? [];
        if (empty($thumbConfig)) {
            return null;
        }

        // Determine destination
        $destination = $thumbConfig['base_url'] ?? null;
        if (!$destination) {
            // Default to input path with /thumbs/ suffix
            $inputUrl = $request['input'] ?? '';
            $basePath = dirname($this->normalizeS3Url($inputUrl));
            $destination = $basePath . '/thumbs/';
        } else {
            $destination = $this->normalizeS3Url($destination);
            if (!str_ends_with($destination, '/')) {
                $destination .= '/';
            }
        }

        // Calculate frame capture settings
        $times = $thumbConfig['times'] ?? [5]; // Default to 5 seconds
        $interval = $thumbConfig['interval'] ?? null;
        $number = $thumbConfig['number'] ?? null;

        $frameCaptureSettings = [];

        if ($interval) {
            // Interval-based capture (every N seconds)
            $frameCaptureSettings['FramerateDenominator'] = (int) $interval;
            $frameCaptureSettings['FramerateNumerator'] = 1;
        } elseif ($number && $number > 1) {
            // Capture N frames evenly distributed
            // MediaConvert doesn't directly support this, so we approximate
            $frameCaptureSettings['FramerateDenominator'] = 10;
            $frameCaptureSettings['FramerateNumerator'] = 1;
            $frameCaptureSettings['MaxCaptures'] = (int) $number;
        } else {
            // Single frame capture at specific time(s)
            // For single captures, we use a very low framerate and max captures
            $frameCaptureSettings['FramerateDenominator'] = 1;
            $frameCaptureSettings['FramerateNumerator'] = 1;
            $frameCaptureSettings['MaxCaptures'] = count($times);
        }

        $frameCaptureSettings['Quality'] = $thumbConfig['quality'] ?? 80;

        // Build video description for thumbnails
        $videoDescription = [
            'CodecSettings' => [
                'Codec' => 'FRAME_CAPTURE',
                'FrameCaptureSettings' => $frameCaptureSettings,
            ],
        ];

        // Set dimensions if specified
        if (!empty($thumbConfig['width'])) {
            $videoDescription['Width'] = (int) $thumbConfig['width'];
        }
        if (!empty($thumbConfig['height'])) {
            $videoDescription['Height'] = (int) $thumbConfig['height'];
        }

        // Build name modifier from label if provided
        $nameModifier = '';
        if (!empty($thumbConfig['label'])) {
            $nameModifier = '_' . pathinfo($thumbConfig['label'], PATHINFO_FILENAME);
        }

        return [
            'Name' => 'Thumbnails',
            'OutputGroupSettings' => [
                'Type' => 'FILE_GROUP_SETTINGS',
                'FileGroupSettings' => [
                    'Destination' => $destination,
                ],
            ],
            'Outputs' => [
                [
                    'NameModifier' => $nameModifier ?: '_thumb',
                    'ContainerSettings' => [
                        'Container' => 'RAW',
                    ],
                    'VideoDescription' => $videoDescription,
                ],
            ],
        ];
    }

    /**
     * Build a file output group.
     */
    private function buildFileOutputGroup(array $outputs): ?array
    {
        if (empty($outputs)) {
            return null;
        }

        $destination = $this->getOutputDestination($outputs[0]);

        $group = [
            'Name' => 'File Group',
            'OutputGroupSettings' => [
                'Type' => 'FILE_GROUP_SETTINGS',
                'FileGroupSettings' => [
                    'Destination' => $destination,
                ],
            ],
            'Outputs' => [],
        ];

        foreach ($outputs as $output) {
            $group['Outputs'][] = $this->buildOutput($output);
        }

        return $group;
    }

    /**
     * Build an HLS output group.
     */
    private function buildHlsOutputGroup(array $outputs): ?array
    {
        if (empty($outputs)) {
            return null;
        }

        $firstOutput = $outputs[0];
        $destination = $this->getOutputDestination($firstOutput);
        $segmentLength = $firstOutput['segment_seconds'] ?? 6;

        $group = [
            'Name' => 'HLS Group',
            'OutputGroupSettings' => [
                'Type' => 'HLS_GROUP_SETTINGS',
                'HlsGroupSettings' => [
                    'Destination' => $destination,
                    'SegmentLength' => $segmentLength,
                    'MinSegmentLength' => 0,
                    'SegmentControl' => 'SEGMENTED_FILES',
                    'ManifestDurationFormat' => 'INTEGER',
                ],
            ],
            'Outputs' => [],
        ];

        foreach ($outputs as $output) {
            $group['Outputs'][] = $this->buildOutput($output, true, false);
        }

        return $group;
    }

    /**
     * Build a DASH output group.
     */
    private function buildDashOutputGroup(array $outputs): ?array
    {
        if (empty($outputs)) {
            return null;
        }

        $firstOutput = $outputs[0];
        $destination = $this->getOutputDestination($firstOutput);
        $segmentLength = $firstOutput['segment_seconds'] ?? 6;

        $group = [
            'Name' => 'DASH Group',
            'OutputGroupSettings' => [
                'Type' => 'DASH_ISO_GROUP_SETTINGS',
                'DashIsoGroupSettings' => [
                    'Destination' => $destination,
                    'SegmentLength' => $segmentLength,
                    'FragmentLength' => 2,
                    'SegmentControl' => 'SEGMENTED_FILES',
                ],
            ],
            'Outputs' => [],
        ];

        foreach ($outputs as $output) {
            $group['Outputs'][] = $this->buildOutput($output, false, true);
        }

        return $group;
    }

    /**
     * Build a single output configuration.
     */
    private function buildOutput(array $output, bool $isHls = false, bool $isDash = false): array
    {
        $config = [];

        // Add name modifier for multiple outputs
        if (!empty($output['label'])) {
            $config['NameModifier'] = "_{$output['label']}";
        } elseif (!empty($output['filename'])) {
            $name = pathinfo($output['filename'], PATHINFO_FILENAME);
            $config['NameModifier'] = "_{$name}";
        }

        // Container settings
        $container = $this->getContainer($output, $isHls, $isDash);
        $config['ContainerSettings'] = ['Container' => $container];

        // Video settings
        if (empty($output['skip_video'])) {
            $config['VideoDescription'] = $this->buildVideoDescription($output);
        }

        // Audio settings
        if (empty($output['skip_audio'])) {
            $config['AudioDescriptions'] = [$this->buildAudioDescription($output, $isHls)];
        }

        return $config;
    }

    /**
     * Build video description settings.
     */
    private function buildVideoDescription(array $output): array
    {
        $video = [];

        // Resolution
        [$width, $height] = $this->getDimensions($output);
        if ($width) {
            $video['Width'] = $width;
        }
        if ($height) {
            $video['Height'] = $height;
        }

        // Scaling behavior
        if (!empty($output['aspect_mode'])) {
            $video['ScalingBehavior'] = $this->getScalingBehavior($output['aspect_mode']);
        }

        // Frame rate
        if (!empty($output['frame_rate'])) {
            $video['FramerateControl'] = 'SPECIFIED';
            $video['FramerateNumerator'] = (int) ($output['frame_rate'] * 1000);
            $video['FramerateDenominator'] = 1000;
        } elseif (!empty($output['max_frame_rate'])) {
            $video['FramerateControl'] = 'INITIALIZE_FROM_SOURCE';
            $video['FramerateConversionAlgorithm'] = 'INTERPOLATE';
        }

        // Codec settings
        $codec = $this->getVideoCodec($output);
        $video['CodecSettings'] = $this->buildVideoCodecSettings($output, $codec);

        return $video;
    }

    /**
     * Build video codec settings.
     */
    private function buildVideoCodecSettings(array $output, string $codec): array
    {
        $settings = ['Codec' => $codec];

        switch ($codec) {
            case 'H_264':
                $settings['H264Settings'] = $this->buildH264Settings($output);
                break;
            case 'H_265':
                $settings['H265Settings'] = $this->buildH265Settings($output);
                break;
            case 'VP8':
                $settings['Vp8Settings'] = $this->buildVp8Settings($output);
                break;
            case 'VP9':
                $settings['Vp9Settings'] = $this->buildVp9Settings($output);
                break;
        }

        return $settings;
    }

    /**
     * Build H.264 codec settings.
     */
    private function buildH264Settings(array $output): array
    {
        $settings = [
            'RateControlMode' => !empty($output['one_pass']) ? 'CBR' : 'QVBR',
        ];

        // Bitrate
        if (!empty($output['video_bitrate'])) {
            $settings['Bitrate'] = $output['video_bitrate'] * 1000;
            $settings['RateControlMode'] = 'CBR';
        } elseif (!empty($output['quality'])) {
            $qvbrQuality = min(10, max(1, $output['quality'] * 2));
            $settings['QvbrSettings'] = ['QvbrQualityLevel' => $qvbrQuality];
            $settings['RateControlMode'] = 'QVBR';
        }

        // Max bitrate
        if (!empty($output['max_video_bitrate'])) {
            $settings['MaxBitrate'] = $output['max_video_bitrate'] * 1000;
        }

        // Profile
        if (!empty($output['h264_profile'])) {
            $profile = self::H264_PROFILE_MAP[strtolower($output['h264_profile'])] ?? 'MAIN';
            $settings['CodecProfile'] = $profile;
        }

        // Level
        if (!empty($output['h264_level'])) {
            $level = self::H264_LEVEL_MAP[$output['h264_level']] ?? 'AUTO';
            $settings['CodecLevel'] = $level;
        }

        // Reference frames
        if (!empty($output['h264_reference_frames'])) {
            $settings['NumberReferenceFrames'] = $output['h264_reference_frames'];
        }

        // B-frames
        if (isset($output['h264_bframes'])) {
            $settings['NumberBFramesBetweenReferenceFrames'] = $output['h264_bframes'];
        }

        // GOP settings
        if (!empty($output['keyframe_interval'])) {
            $settings['GopSize'] = $output['keyframe_interval'];
            $settings['GopSizeUnits'] = 'FRAMES';
        }

        return $settings;
    }

    /**
     * Build H.265/HEVC codec settings.
     */
    private function buildH265Settings(array $output): array
    {
        $settings = [
            'RateControlMode' => 'QVBR',
        ];

        if (!empty($output['video_bitrate'])) {
            $settings['Bitrate'] = $output['video_bitrate'] * 1000;
            $settings['RateControlMode'] = 'CBR';
        } elseif (!empty($output['quality'])) {
            $qvbrQuality = min(10, max(1, $output['quality'] * 2));
            $settings['QvbrSettings'] = ['QvbrQualityLevel' => $qvbrQuality];
        }

        if (!empty($output['max_video_bitrate'])) {
            $settings['MaxBitrate'] = $output['max_video_bitrate'] * 1000;
        }

        if (!empty($output['keyframe_interval'])) {
            $settings['GopSize'] = $output['keyframe_interval'];
            $settings['GopSizeUnits'] = 'FRAMES';
        }

        return $settings;
    }

    /**
     * Build VP8 codec settings.
     */
    private function buildVp8Settings(array $output): array
    {
        $settings = [
            'RateControlMode' => 'VBR',
        ];

        if (!empty($output['video_bitrate'])) {
            $settings['Bitrate'] = $output['video_bitrate'] * 1000;
        }

        if (!empty($output['max_video_bitrate'])) {
            $settings['MaxBitrate'] = $output['max_video_bitrate'] * 1000;
        }

        return $settings;
    }

    /**
     * Build VP9 codec settings.
     */
    private function buildVp9Settings(array $output): array
    {
        $settings = [
            'RateControlMode' => 'VBR',
        ];

        if (!empty($output['video_bitrate'])) {
            $settings['Bitrate'] = $output['video_bitrate'] * 1000;
        }

        if (!empty($output['max_video_bitrate'])) {
            $settings['MaxBitrate'] = $output['max_video_bitrate'] * 1000;
        }

        return $settings;
    }

    /**
     * Build audio description settings.
     */
    private function buildAudioDescription(array $output, bool $isHls = false): array
    {
        $audio = [
            'AudioSourceName' => 'Audio Selector 1',
        ];

        $codec = $this->getAudioCodec($output, $isHls);
        $audio['CodecSettings'] = $this->buildAudioCodecSettings($output, $codec);

        return $audio;
    }

    /**
     * Build audio codec settings.
     */
    private function buildAudioCodecSettings(array $output, string $codec): array
    {
        $settings = ['Codec' => $codec];

        switch ($codec) {
            case 'AAC':
                $aacSettings = [
                    'CodecProfile' => 'LC',
                    'RateControlMode' => 'CBR',
                    'Bitrate' => !empty($output['audio_bitrate'])
                        ? $output['audio_bitrate'] * 1000
                        : 128000,
                ];

                if (!empty($output['audio_sample_rate'])) {
                    $aacSettings['SampleRate'] = $output['audio_sample_rate'];
                }

                if (!empty($output['audio_channels'])) {
                    $aacSettings['CodingMode'] = $this->getAacCodingMode($output['audio_channels']);
                }

                $settings['AacSettings'] = $aacSettings;
                break;

            case 'MP3':
                $mp3Settings = [
                    'RateControlMode' => 'CBR',
                    'Bitrate' => !empty($output['audio_bitrate'])
                        ? $output['audio_bitrate'] * 1000
                        : 128000,
                ];

                if (!empty($output['audio_sample_rate'])) {
                    $mp3Settings['SampleRate'] = $output['audio_sample_rate'];
                }

                if (!empty($output['audio_channels'])) {
                    $mp3Settings['Channels'] = $output['audio_channels'];
                }

                $settings['Mp3Settings'] = $mp3Settings;
                break;

            case 'AC3':
                $ac3Settings = [
                    'Bitrate' => !empty($output['audio_bitrate'])
                        ? $output['audio_bitrate'] * 1000
                        : 384000,
                ];

                if (!empty($output['audio_sample_rate'])) {
                    $ac3Settings['SampleRate'] = $output['audio_sample_rate'];
                }

                $settings['Ac3Settings'] = $ac3Settings;
                break;
        }

        return $settings;
    }

    /**
     * Get AAC coding mode from channel count.
     */
    private function getAacCodingMode(int $channels): string
    {
        return match ($channels) {
            1 => 'CODING_MODE_1_0',
            2 => 'CODING_MODE_2_0',
            6 => 'CODING_MODE_5_1',
            default => 'CODING_MODE_2_0',
        };
    }

    /**
     * Get output dimensions.
     */
    private function getDimensions(array $output): array
    {
        $width = $output['width'] ?? null;
        $height = $output['height'] ?? null;

        // Parse size string (e.g., "1920x1080")
        if (!empty($output['size']) && preg_match('/(\d+)x(\d+)/', $output['size'], $matches)) {
            $width = (int) $matches[1];
            $height = (int) $matches[2];
        }

        return [$width, $height];
    }

    /**
     * Determine video codec from output settings.
     */
    private function getVideoCodec(array $output): string
    {
        if (!empty($output['video_codec'])) {
            $codec = strtolower($output['video_codec']);
            return self::VIDEO_CODEC_MAP[$codec] ?? 'H_264';
        }

        // Infer from format/container
        $format = strtolower($output['format'] ?? 'mp4');
        if ($format === 'webm') {
            return 'VP9';
        }

        return 'H_264';
    }

    /**
     * Determine audio codec from output settings.
     */
    private function getAudioCodec(array $output, bool $isHls = false): string
    {
        if (!empty($output['audio_codec'])) {
            $codec = strtolower($output['audio_codec']);
            return self::AUDIO_CODEC_MAP[$codec] ?? 'AAC';
        }

        $format = strtolower($output['format'] ?? 'mp4');
        if ($format === 'webm') {
            return 'OPUS';
        }

        return 'AAC';
    }

    /**
     * Determine container format.
     */
    private function getContainer(array $output, bool $isHls = false, bool $isDash = false): string
    {
        if ($isHls) {
            return 'M3U8';
        }
        if ($isDash) {
            return 'MPD';
        }

        if (!empty($output['format'])) {
            $format = strtolower($output['format']);
            return self::CONTAINER_MAP[$format] ?? 'MP4';
        }

        return 'MP4';
    }

    /**
     * Map Zencoder aspect mode to MediaConvert scaling behavior.
     */
    private function getScalingBehavior(string $aspectMode): string
    {
        return match (strtolower($aspectMode)) {
            'stretch' => 'STRETCH_TO_OUTPUT',
            default => 'DEFAULT',
        };
    }

    /**
     * Determine output destination S3 path.
     */
    private function getOutputDestination(array $output): string
    {
        if (!empty($output['url'])) {
            return $this->normalizeS3Url($output['url']);
        }

        if (!empty($output['base_url'])) {
            $base = $this->normalizeS3Url($output['base_url']);
            if (!str_ends_with($base, '/')) {
                $base .= '/';
            }
            return $base;
        }

        // Use default bucket
        $bucket = config('aws.s3.output_bucket');
        $prefix = config('aws.s3.output_prefix');
        return "s3://{$bucket}/{$prefix}";
    }

    /**
     * Normalize URL to S3 URI format.
     */
    private function normalizeS3Url(string $url): string
    {
        if (str_starts_with($url, 's3://')) {
            return $url;
        }

        // Handle HTTPS S3 URLs
        $parsed = parse_url($url);

        if (isset($parsed['host'])) {
            // https://bucket.s3.amazonaws.com/key
            if (str_ends_with($parsed['host'], '.s3.amazonaws.com')) {
                $bucket = str_replace('.s3.amazonaws.com', '', $parsed['host']);
                $key = ltrim($parsed['path'] ?? '', '/');
                return "s3://{$bucket}/{$key}";
            }

            // https://s3.amazonaws.com/bucket/key
            if ($parsed['host'] === 's3.amazonaws.com') {
                $parts = explode('/', ltrim($parsed['path'] ?? '', '/'), 2);
                $bucket = $parts[0];
                $key = $parts[1] ?? '';
                return "s3://{$bucket}/{$key}";
            }

            // Handle S3 regional URLs
            if (str_contains($parsed['host'], '.s3.') && str_contains($parsed['host'], '.amazonaws.com')) {
                $bucket = explode('.s3.', $parsed['host'])[0];
                $key = ltrim($parsed['path'] ?? '', '/');
                return "s3://{$bucket}/{$key}";
            }
        }

        return $url;
    }

    /**
     * Map Zencoder region to AWS region.
     */
    public function mapRegion(?string $zencoderRegion): string
    {
        if (!$zencoderRegion) {
            return config('aws.region', 'us-east-1');
        }

        $region = strtolower($zencoderRegion);
        return self::REGION_MAP[$region] ?? config('aws.region', 'us-east-1');
    }

    /**
     * Get the base output path from an output configuration.
     * Strips the filename and returns just the directory path.
     */
    public function getBaseOutputPath(array $output): string
    {
        $url = $output['url'] ?? $output['base_url'] ?? '';

        if (empty($url)) {
            return '';
        }

        $normalized = $this->normalizeS3Url($url);

        // If it's already a directory (ends with /), return as-is
        if (str_ends_with($normalized, '/')) {
            return $normalized;
        }

        // Otherwise, get the directory portion
        return dirname($normalized) . '/';
    }
}
