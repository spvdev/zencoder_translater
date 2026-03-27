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

                // Update outputs
                foreach ($job->outputs as $output) {
                    $output->update([
                        'state' => $zencoderStatus,
                        'finished_at' => $awsStatus === 'COMPLETE' ? Carbon::now() : null,
                    ]);
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

        // Determine bucket region (may differ from MediaConvert region)
        $s3Config = [
            'version' => 'latest',
            'region' => config('aws.s3.region', config('aws.region')),
        ];
        if (config('aws.credentials')) {
            $s3Config['credentials'] = config('aws.credentials');
        }
        $s3 = new S3Client($s3Config);

        // Detect actual bucket region if different
        try {
            $bucketRegion = $s3->getBucketLocation(['Bucket' => $bucket])['LocationConstraint'] ?? 'us-east-1';
            if ($bucketRegion && $bucketRegion !== $s3Config['region']) {
                $s3Config['region'] = $bucketRegion;
                $s3 = new S3Client($s3Config);
            }
        } catch (\Exception $e) {
            // Continue with configured region
        }

        $result = $s3->listObjectsV2([
            'Bucket' => $bucket,
            'Prefix' => $prefix,
            'MaxKeys' => 10,
        ]);

        // Get thumbnail width from original settings
        $settings = $output->original_settings ?? [];
        $thumbSettings = $settings['thumbnails'] ?? [];
        $width = $thumbSettings['width'] ?? 640;
        $height = intval($width * 9 / 16); // Estimate 16:9 aspect ratio

        $images = [];
        foreach ($result['Contents'] ?? [] as $object) {
            $key = $object['Key'];
            $url = "http://{$bucket}.s3.amazonaws.com/{$key}";
            $images[] = [
                'url' => $url,
                'file_size_bytes' => $object['Size'] ?? null,
                'dimensions' => "{$width}x{$height}",
            ];
        }

        return $images ? [['images' => $images]] : [];
    }
}
