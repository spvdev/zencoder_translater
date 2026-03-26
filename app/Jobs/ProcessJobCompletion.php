<?php

namespace App\Jobs;

use App\Models\Job;
use App\Services\MediaConvertService;
use App\Services\WebhookService;
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
}
