<?php

namespace App\Jobs;

use App\Models\Job;
use App\Models\Output;
use App\Services\MediaConvertService;
use App\Services\WebhookService;
use Aws\S3\S3Client;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class ProcessJobCompletion implements ShouldQueue
{
    use InteractsWithQueue, Queueable;
    private int $jobId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $jobId)
    {
        $this->jobId = $jobId;
    }

    /**
     * Execute the job.
     */
    public function handle(MediaConvertService $mediaConvert, WebhookService $webhook): void
    {
        $job = Job::with('outputs')->find($this->jobId);

        if (!$job || !$job->aws_job_id) {
            return;
        }

        try {
            // Get AWS job status
            $awsJob = $mediaConvert->getJob($job->aws_job_id);
            $awsStatus = $awsJob['Status'] ?? '';
            $zencoderStatus = MediaConvertService::mapStatusToZencoder($awsStatus);

            // Update job state
            $job->update([
                'state' => $zencoderStatus,
                'progress' => MediaConvertService::calculateProgress($awsJob),
            ]);

            if (in_array($awsStatus, ['COMPLETE', 'ERROR', 'CANCELED'])) {
                $job->update(['finished_at' => Carbon::now()]);

                if ($awsStatus === 'ERROR') {
                    $job->update([
                        'error_message' => $awsJob['ErrorMessage'] ?? 'Unknown error',
                        'error_class' => $awsJob['ErrorCode'] ?? 'TranscodingError',
                    ]);
                }

                // Update outputs with metadata from MediaConvert
                if ($awsStatus === 'COMPLETE') {
                    $this->updateOutputMetadata($job, $awsJob);
                } else {
                    foreach ($job->outputs as $output) {
                        $output->update([
                            'state' => $zencoderStatus,
                            'finished_at' => null,
                        ]);
                    }
                }

                // Discover thumbnails for completed thumbnail outputs
                if ($awsStatus === 'COMPLETE') {
                    foreach ($job->outputs as $output) {
                        if ($output->label === null && $output->output_url && !$output->thumbnails) {
                            try {
                                $thumbnails = $this->discoverThumbnails($output);
                                if ($thumbnails) {
                                    $output->update(['thumbnails' => $thumbnails]);
                                }
                            } catch (\Exception $e) {
                                Log::warning("Failed to discover thumbnails: {$e->getMessage()}");
                            }
                        }
                    }
                    $job->load('outputs');
                }

                // Populate input media info
                if ($awsStatus === 'COMPLETE') {
                    try {
                        $job->update(['input_media_info' => $this->extractInputMediaInfo($job, $awsJob)]);
                    } catch (\Exception $e) {
                        Log::warning("Failed to extract input media info: {$e->getMessage()}");
                    }
                }

                // Send notifications
                if ($job->notifications) {
                    $payload = $webhook->buildJobNotificationPayload(
                        $job->toZencoderDetails(),
                        $job->outputs->map(fn($o) => $o->toZencoderDetails())->toArray(),
                        $job->input_media_info
                    );
                    $event = $awsStatus === 'COMPLETE' ? 'job_finished' : 'job_failed';
                    $webhook->sendJobNotifications($job->notifications, $payload, $event);
                }

                // Send per-output notifications
                foreach ($job->outputs as $output) {
                    if ($output->notifications) {
                        $outputPayload = $webhook->buildOutputNotificationPayload(
                            $output->toZencoderDetails(),
                            $job->toZencoderDetails(),
                            $job->input_media_info
                        );
                        $event = $awsStatus === 'COMPLETE' ? 'output_finished' : 'output_failed';
                        $webhook->sendJobNotifications($output->notifications, $outputPayload, $event);
                    }
                }
            } else {
                // Job still processing, check again later
                $retryJob = new self($this->jobId);
                $retryJob->delay(30);
                dispatch($retryJob);
            }
        } catch (\Exception $e) {
            Log::error("Error processing job completion for {$this->jobId}: {$e->getMessage()}");
        }
    }

    /**
     * Extract media metadata from the completed MediaConvert job and update output records.
     */
    private function updateOutputMetadata(Job $job, array $awsJob): void
    {
        $outputGroups = $awsJob['Settings']['OutputGroups'] ?? [];
        $outputGroupDetails = $awsJob['OutputGroupDetails'] ?? [];

        // Build a flat list of MediaConvert outputs with their details
        $mcOutputs = [];
        foreach ($outputGroups as $groupIdx => $group) {
            $groupType = $group['OutputGroupSettings']['Type'] ?? '';
            $outputs = $group['Outputs'] ?? [];
            $details = $outputGroupDetails[$groupIdx]['OutputDetails'] ?? [];

            foreach ($outputs as $outIdx => $mcOutput) {
                $detail = $details[$outIdx] ?? [];
                $videoDesc = $mcOutput['VideoDescription'] ?? [];
                $codecSettings = $videoDesc['CodecSettings'] ?? [];
                $audioDescs = $mcOutput['AudioDescriptions'] ?? [];
                $container = $mcOutput['ContainerSettings']['Container'] ?? null;

                // Determine if this is a thumbnail output
                $isThumbnail = isset($codecSettings['FrameCaptureSettings']);

                // Extract video codec
                $videoCodec = null;
                if (isset($codecSettings['H264Settings'])) {
                    $videoCodec = 'h264';
                } elseif (isset($codecSettings['H265Settings'])) {
                    $videoCodec = 'h265';
                } elseif (isset($codecSettings['Vp8Settings'])) {
                    $videoCodec = 'vp8';
                } elseif (isset($codecSettings['Vp9Settings'])) {
                    $videoCodec = 'vp9';
                }

                // Extract video bitrate
                $videoBitrate = null;
                foreach (['H264Settings', 'H265Settings', 'Vp8Settings', 'Vp9Settings'] as $codecKey) {
                    if (isset($codecSettings[$codecKey]['Bitrate'])) {
                        $videoBitrate = intval($codecSettings[$codecKey]['Bitrate'] / 1000);
                        break;
                    }
                }

                // Extract audio info
                $audioCodec = null;
                $audioBitrate = null;
                $audioSampleRate = null;
                $audioChannels = null;
                if (!empty($audioDescs)) {
                    $audioCodecSettings = $audioDescs[0]['CodecSettings'] ?? [];
                    if (isset($audioCodecSettings['AacSettings'])) {
                        $audioCodec = 'aac';
                        $aac = $audioCodecSettings['AacSettings'];
                        $audioBitrate = isset($aac['Bitrate']) ? intval($aac['Bitrate'] / 1000) : null;
                        $audioSampleRate = $aac['SampleRate'] ?? null;
                        $audioChannels = $aac['Channels'] ?? 2;
                    } elseif (isset($audioCodecSettings['Mp3Settings'])) {
                        $audioCodec = 'mp3';
                        $mp3 = $audioCodecSettings['Mp3Settings'];
                        $audioBitrate = isset($mp3['Bitrate']) ? intval($mp3['Bitrate'] / 1000) : null;
                        $audioSampleRate = $mp3['SampleRate'] ?? null;
                    }
                }

                // Extract duration and video details from OutputGroupDetails
                $durationMs = $detail['DurationInMs'] ?? null;
                $videoDetails = $detail['VideoDetails'] ?? [];

                $mcOutputs[] = [
                    'is_thumbnail' => $isThumbnail,
                    'width' => $videoDetails['WidthInPx'] ?? $videoDesc['Width'] ?? null,
                    'height' => $videoDetails['HeightInPx'] ?? $videoDesc['Height'] ?? null,
                    'duration_ms' => $durationMs,
                    'video_codec' => $isThumbnail ? null : $videoCodec,
                    'video_bitrate_kbps' => $videoBitrate,
                    'audio_codec' => $audioCodec,
                    'audio_bitrate_kbps' => $audioBitrate,
                    'audio_sample_rate' => $audioSampleRate,
                    'audio_channels' => $audioChannels,
                    'frame_rate' => $videoDetails['FrameRate'] ?? null,
                    'container' => $container,
                ];
            }
        }

        // Match MediaConvert outputs to our Output records
        // Video outputs first, then thumbnail outputs
        $videoMcOutputs = array_values(array_filter($mcOutputs, fn($o) => !$o['is_thumbnail']));
        $thumbMcOutputs = array_values(array_filter($mcOutputs, fn($o) => $o['is_thumbnail']));

        $videoIdx = 0;
        $thumbIdx = 0;

        foreach ($job->outputs as $output) {
            $isThumbnailOutput = $output->label === null;

            if ($isThumbnailOutput && isset($thumbMcOutputs[$thumbIdx])) {
                $mc = $thumbMcOutputs[$thumbIdx++];
            } elseif (!$isThumbnailOutput && isset($videoMcOutputs[$videoIdx])) {
                $mc = $videoMcOutputs[$videoIdx++];
            } else {
                $output->update([
                    'state' => 'finished',
                    'finished_at' => Carbon::now(),
                ]);
                continue;
            }

            // Try to get file size from S3
            $fileSize = null;
            if (!$isThumbnailOutput && $output->output_url) {
                try {
                    $fileSize = $this->getS3FileSize($output->output_url);
                } catch (\Exception $e) {
                    Log::debug("Could not get file size for {$output->output_url}: {$e->getMessage()}");
                }
            }

            $updateData = [
                'state' => 'finished',
                'finished_at' => Carbon::now(),
                'width' => $mc['width'],
                'height' => $mc['height'],
                'duration_ms' => $mc['duration_ms'],
                'video_codec' => $mc['video_codec'],
                'video_bitrate_kbps' => $mc['video_bitrate_kbps'],
                'audio_codec' => $mc['audio_codec'],
                'audio_bitrate_kbps' => $mc['audio_bitrate_kbps'],
                'audio_sample_rate' => $mc['audio_sample_rate'],
                'audio_channels' => $mc['audio_channels'],
                'frame_rate' => $mc['frame_rate'],
                'format' => $mc['container'] ? strtolower($mc['container']) : null,
            ];

            if ($fileSize !== null) {
                $updateData['file_size_bytes'] = $fileSize;
            }

            $output->update($updateData);

            Log::debug("Updated output {$output->id} metadata", array_filter($updateData, fn($v) => $v !== null));
        }
    }

    /**
     * Get file size from S3 for a given s3:// URL.
     */
    private function getS3FileSize(string $s3Url): ?int
    {
        if (!preg_match('#^s3://([^/]+)/(.+)$#', $s3Url, $matches)) {
            return null;
        }

        $s3Config = [
            'version' => 'latest',
            'region' => config('aws.s3.region', config('aws.region')),
        ];
        if (config('aws.credentials')) {
            $s3Config['credentials'] = config('aws.credentials');
        }
        $s3 = new S3Client($s3Config);

        $result = $s3->headObject([
            'Bucket' => $matches[1],
            'Key' => $matches[2],
        ]);

        return $result['ContentLength'] ?? null;
    }

    /**
     * Extract input media information from the completed AWS job.
     */
    private function extractInputMediaInfo(Job $job, array $awsJob): array
    {
        $input = $awsJob['Settings']['Inputs'][0] ?? [];
        $inputUrl = $job->input_url;

        // Try to get input file metadata from S3
        $fileSize = null;
        if ($inputUrl) {
            try {
                $fileSize = $this->getS3FileSize($inputUrl);
            } catch (\Exception $e) {
                Log::debug("Could not get input file size: {$e->getMessage()}");
            }
        }

        // Get duration and dimensions from the first completed video output
        // (best approximation of source properties available from MediaConvert)
        $firstVideoOutput = $job->outputs->first(fn($o) => $o->label !== null && $o->duration_ms);

        return [
            'url' => $inputUrl,
            'format' => pathinfo(parse_url($inputUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: null,
            'duration_in_ms' => $firstVideoOutput?->duration_ms,
            'file_size_in_bytes' => $fileSize,
            'width' => null, // MediaConvert doesn't expose input dimensions directly
            'height' => null,
            'video_codec' => null,
            'audio_codec' => null,
            'state' => 'finished',
        ];
    }

    /**
     * Discover thumbnail files from S3 for a thumbnail output.
     */
    private function discoverThumbnails(Output $output): array
    {
        $s3Url = $output->output_url;
        if (!preg_match('#^s3://([^/]+)/(.+)$#', $s3Url, $matches)) {
            return [];
        }

        $bucket = $matches[1];
        $prefix = rtrim($matches[2], '/') . '/';

        // Use configured S3 region (may differ from MediaConvert region)
        $s3Config = [
            'version' => 'latest',
            'region' => config('aws.s3.region', config('aws.region')),
        ];
        if (config('aws.credentials')) {
            $s3Config['credentials'] = config('aws.credentials');
        }
        $s3 = new S3Client($s3Config);

        $result = $s3->listObjectsV2([
            'Bucket' => $bucket,
            'Prefix' => $prefix,
            'MaxKeys' => 500,
        ]);

        $contents = $result['Contents'] ?? [];
        if (empty($contents)) {
            return [];
        }

        // Sort by key to ensure chronological order (frame numbers are sequential)
        usort($contents, fn($a, $b) => strcmp($a['Key'], $b['Key']));

        // Check if we need to keep only the last frame (times-based capture)
        $settings = $output->original_settings ?? [];
        $thumbSettings = $settings['thumbnails'] ?? [];
        $times = $thumbSettings['times'] ?? null;

        if ($times && count($contents) > 1) {
            // Keep only the last frame (captured at the target timestamp)
            $keepObject = end($contents);
            $deleteObjects = array_slice($contents, 0, -1);

            // Delete the intermediate frames from S3
            if (!empty($deleteObjects)) {
                try {
                    $deleteResult = $s3->deleteObjects([
                        'Bucket' => $bucket,
                        'Delete' => [
                            'Objects' => array_map(fn($o) => ['Key' => $o['Key']], $deleteObjects),
                        ],
                    ]);
                    $errors = $deleteResult['Errors'] ?? [];
                    if (!empty($errors)) {
                        Log::warning("Failed to delete " . count($errors) . " intermediate thumbnails", [
                            'errors' => array_map(fn($e) => $e['Key'] . ': ' . $e['Code'], $errors),
                        ]);
                    } else {
                        Log::debug("Cleaned up " . count($deleteObjects) . " intermediate thumbnail frames");
                    }
                } catch (\Exception $e) {
                    Log::warning("Failed to clean up intermediate thumbnails: {$e->getMessage()}");
                }
            }

            $contents = [$keepObject];
        }

        // Determine thumbnail dimensions — must NEVER be null (Django crashes on null)
        $width = $output->width;
        $height = $output->height;
        if (!$width || !$height) {
            $width = $thumbSettings['width'] ?? 640;
            $height = $thumbSettings['height'] ?? null;

            // If height still missing, compute from video output's aspect ratio
            if (!$height) {
                $videoOutput = $output->job?->outputs?->first(fn($o) => $o->label !== null && $o->width && $o->height);
                if ($videoOutput) {
                    $height = intval($width * $videoOutput->height / $videoOutput->width);
                    // Ensure even number for consistency
                    $height = $height & ~1;
                } else {
                    // Last resort: assume 16:9
                    $height = intval($width * 9 / 16);
                    $height = $height & ~1;
                }
            }
        }

        $images = [];
        foreach ($contents as $object) {
            $key = $object['Key'];
            $url = "https://{$bucket}.s3.amazonaws.com/{$key}";
            $dimensions = ($width && $height) ? "{$width}x{$height}" : null;
            $images[] = [
                'url' => $url,
                'file_size_bytes' => (string) ($object['Size'] ?? ''),
                'dimensions' => $dimensions,
            ];
        }

        return $images ? [['images' => $images]] : [];
    }
}
