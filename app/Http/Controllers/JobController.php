<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessJobCompletion;
use App\Models\Job;
use App\Models\Output;
use App\Services\MediaConvertService;
use App\Services\ZencoderTranslatorService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class JobController extends Controller
{
    public function __construct(
        private MediaConvertService $mediaConvert,
        private ZencoderTranslatorService $translator
    ) {
    }

    /**
     * Create a new transcoding job.
     * POST /v2/jobs
     */
    public function create(Request $request)
    {
        $this->validate($request, [
            'input' => 'required|string',
            'outputs' => 'array',
            'outputs.*.label' => 'string',
            'outputs.*.url' => 'string',
            'outputs.*.base_url' => 'string',
            'outputs.*.format' => 'string',
            'test' => 'boolean',
            'region' => 'string',
            'pass_through' => 'string',
            'notifications' => 'array',
        ]);

        $data = $request->all();

        // Create job record
        $job = Job::create([
            'input_url' => $data['input'],
            'test_mode' => $data['test'] ?? false,
            'region' => $data['region'] ?? null,
            'pass_through' => $data['pass_through'] ?? null,
            'notifications' => $data['notifications'] ?? null,
            'state' => 'pending',
            'submitted_at' => Carbon::now(),
        ]);

        // Get outputs
        $outputs = $data['outputs'] ?? [];
        if (isset($data['output'])) {
            $outputs[] = $data['output'];
        }
        if (empty($outputs)) {
            $outputs = [[]]; // Default output
        }

        // Create output records
        foreach ($outputs as $idx => $outputData) {
            // Thumbnail-only outputs have no video_codec and no label
            $isThumbnailOnly = isset($outputData['thumbnails'])
                && !isset($outputData['video_codec'])
                && !isset($outputData['audio_codec']);

            // Determine output URL
            $outputUrl = $outputData['url'] ?? $outputData['base_url'] ?? null;
            if (!$outputUrl && $isThumbnailOnly && isset($outputData['thumbnails']['base_url'])) {
                $outputUrl = $outputData['thumbnails']['base_url'];
            }

            Output::create([
                'job_id' => $job->id,
                'label' => $isThumbnailOnly ? null : ($outputData['label'] ?? "output_{$idx}"),
                'format' => $outputData['format'] ?? null,
                'output_url' => $outputUrl,
                'original_settings' => $outputData,
                'notifications' => $outputData['notifications'] ?? null,
                'state' => 'pending',
                'submitted_at' => Carbon::now(),
            ]);
        }

        // Translate to MediaConvert format
        try {
            $mediaConvertSettings = $this->translator->translateJob($data);
        } catch (\Exception $e) {
            Log::error("Failed to translate job: {$e->getMessage()}");
            $job->update([
                'state' => 'failed',
                'error_message' => "Translation error: {$e->getMessage()}",
                'error_class' => 'TranslationError',
            ]);
            return $this->errorResponse(["Invalid job configuration: {$e->getMessage()}"]);
        }

        // Submit to MediaConvert (unless test mode without AWS configured)
        if (!($data['test'] ?? false) || config('aws.mediaconvert.role_arn')) {
            try {
                $awsJob = $this->mediaConvert->createJob($mediaConvertSettings);
                $job->update([
                    'aws_job_id' => $awsJob['Id'],
                    'state' => 'waiting',
                ]);

                // Update outputs with AWS state
                $job->outputs()->update(['state' => 'waiting']);

                // Dispatch job to check completion
                dispatch(new ProcessJobCompletion($job->id));
            } catch (\Exception $e) {
                Log::error("Failed to create MediaConvert job: {$e->getMessage()}");
                $job->update([
                    'state' => 'failed',
                    'error_message' => "AWS error: {$e->getMessage()}",
                    'error_class' => 'AWSError',
                ]);
            }
        } else {
            // Test mode without AWS - simulate success
            $job->update([
                'state' => 'finished',
                'finished_at' => Carbon::now(),
            ]);
            $job->outputs()->update([
                'state' => 'finished',
                'finished_at' => Carbon::now(),
            ]);
        }

        $job->load('outputs');

        return response()->json($job->toZencoderResponse(), 201);
    }

    /**
     * List all jobs.
     * GET /v2/jobs
     */
    public function index(Request $request)
    {
        $page = $request->get('page', 1);
        $perPage = min($request->get('per_page', 50), 100);
        $state = $request->get('state');

        $query = Job::with('outputs')->orderBy('created_at', 'desc');

        if ($state) {
            $query->where('state', $state);
        }

        $jobs = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json(
            $jobs->map(fn($job) => ['job' => $job->toZencoderDetails()])->toArray()
        );
    }

    /**
     * Get job details.
     * GET /v2/jobs/{id}
     */
    public function show($id)
    {
        $job = Job::with('outputs')->find($id);

        if (!$job) {
            return $this->notFoundResponse('Job');
        }

        return response()->json(['job' => $job->toZencoderDetails()]);
    }

    /**
     * Get job progress.
     * GET /v2/jobs/{id}/progress
     */
    public function progress($id)
    {
        $job = Job::with('outputs')->find($id);

        if (!$job) {
            return $this->notFoundResponse('Job');
        }

        // If job has AWS ID and is still processing, get live status
        if ($job->aws_job_id && in_array($job->state, ['waiting', 'processing'])) {
            try {
                $awsJob = $this->mediaConvert->getJob($job->aws_job_id);
                $job->update([
                    'state' => MediaConvertService::mapStatusToZencoder($awsJob['Status'] ?? ''),
                    'progress' => MediaConvertService::calculateProgress($awsJob),
                ]);
            } catch (\Exception $e) {
                Log::warning("Failed to get AWS job status: {$e->getMessage()}");
            }
        }

        return response()->json($job->toZencoderStatus());
    }

    /**
     * Cancel a job.
     * PUT /v2/jobs/{id}/cancel
     */
    public function cancel($id)
    {
        $job = Job::with('outputs')->find($id);

        if (!$job) {
            return $this->notFoundResponse('Job');
        }

        if (in_array($job->state, ['finished', 'failed', 'cancelled'])) {
            return $this->conflictResponse("Job is already {$job->state}");
        }

        // Cancel in AWS if job has been submitted
        if ($job->aws_job_id) {
            try {
                $this->mediaConvert->cancelJob($job->aws_job_id);
            } catch (\Exception $e) {
                Log::warning("Failed to cancel AWS job: {$e->getMessage()}");
            }
        }

        // Update local state
        $job->update([
            'state' => 'cancelled',
            'finished_at' => Carbon::now(),
        ]);
        $job->outputs()->update([
            'state' => 'cancelled',
            'finished_at' => Carbon::now(),
        ]);

        // Return 204 No Content for successful cancellation (Zencoder compatible)
        return response()->noContent();
    }

    /**
     * Resubmit a failed or cancelled job.
     * PUT /v2/jobs/{id}/resubmit
     */
    public function resubmit($id)
    {
        $job = Job::with('outputs')->find($id);

        if (!$job) {
            return $this->notFoundResponse('Job');
        }

        if (!in_array($job->state, ['failed', 'cancelled'])) {
            return $this->conflictResponse('Only failed or cancelled jobs can be resubmitted');
        }

        // Reset job state
        $job->update([
            'state' => 'pending',
            'error_message' => null,
            'error_class' => null,
            'finished_at' => null,
            'submitted_at' => Carbon::now(),
        ]);

        // Reset output states
        $job->outputs()->update([
            'state' => 'pending',
            'error_message' => null,
            'error_class' => null,
            'finished_at' => null,
            'submitted_at' => Carbon::now(),
        ]);

        // Rebuild the job request from stored data
        $outputRequests = $job->outputs->map(fn($output) => $output->original_settings ?? [])->toArray();

        $request = [
            'input' => $job->input_url,
            'outputs' => $outputRequests ?: null,
            'region' => $job->region,
            'test' => $job->test_mode,
            'pass_through' => $job->pass_through,
        ];

        try {
            $mediaConvertSettings = $this->translator->translateJob($request);
            $awsJob = $this->mediaConvert->createJob($mediaConvertSettings);

            $job->update([
                'aws_job_id' => $awsJob['Id'],
                'state' => 'waiting',
            ]);
            $job->outputs()->update(['state' => 'waiting']);

            dispatch(new ProcessJobCompletion($job->id));
        } catch (\Exception $e) {
            Log::error("Failed to resubmit job: {$e->getMessage()}");
            $job->update([
                'state' => 'failed',
                'error_message' => "Resubmit error: {$e->getMessage()}",
                'error_class' => 'ResubmitError',
            ]);
        }

        return response()->json([
            'id' => $job->id,
            'state' => $job->state,
        ]);
    }

    /**
     * Manually trigger job completion check.
     * POST /v2/jobs/{id}/finish
     */
    public function finish($id)
    {
        $job = Job::find($id);

        if (!$job) {
            return $this->notFoundResponse('Job');
        }

        dispatch(new ProcessJobCompletion($job->id));

        return response()->json([
            'id' => $job->id,
            'message' => 'Job completion check scheduled',
        ]);
    }
}
