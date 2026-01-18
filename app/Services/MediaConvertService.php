<?php

namespace App\Services;

use Aws\MediaConvert\MediaConvertClient;
use Aws\Exception\AwsException;
use Illuminate\Support\Facades\Log;

class MediaConvertService
{
    private ?MediaConvertClient $client = null;
    private ?string $endpoint = null;

    /**
     * Status mapping from AWS to Zencoder.
     */
    private const STATUS_MAP = [
        'SUBMITTED' => 'waiting',
        'PROGRESSING' => 'processing',
        'COMPLETE' => 'finished',
        'CANCELED' => 'cancelled',
        'ERROR' => 'failed',
    ];

    /**
     * Get or discover the MediaConvert endpoint.
     */
    private function getEndpoint(): string
    {
        if ($this->endpoint) {
            return $this->endpoint;
        }

        if (config('aws.mediaconvert.endpoint')) {
            $this->endpoint = config('aws.mediaconvert.endpoint');
            return $this->endpoint;
        }

        // Discover endpoint
        $client = new MediaConvertClient([
            'version' => '2017-08-29',
            'region' => config('aws.region'),
            'credentials' => [
                'key' => config('aws.credentials.key'),
                'secret' => config('aws.credentials.secret'),
            ],
        ]);

        $result = $client->describeEndpoints();
        $this->endpoint = $result['Endpoints'][0]['Url'];

        Log::info("Discovered MediaConvert endpoint: {$this->endpoint}");

        return $this->endpoint;
    }

    /**
     * Get or create the MediaConvert client.
     */
    private function getClient(): MediaConvertClient
    {
        if ($this->client) {
            return $this->client;
        }

        $this->client = new MediaConvertClient([
            'version' => '2017-08-29',
            'region' => config('aws.region'),
            'endpoint' => $this->getEndpoint(),
            'credentials' => [
                'key' => config('aws.credentials.key'),
                'secret' => config('aws.credentials.secret'),
            ],
        ]);

        return $this->client;
    }

    /**
     * Create a MediaConvert job.
     */
    public function createJob(array $jobSettings): array
    {
        $client = $this->getClient();

        // Ensure required fields
        if (!isset($jobSettings['Role'])) {
            $jobSettings['Role'] = config('aws.mediaconvert.role_arn');
        }

        if (config('aws.mediaconvert.queue_arn') && !isset($jobSettings['Queue'])) {
            $jobSettings['Queue'] = config('aws.mediaconvert.queue_arn');
        }

        try {
            $result = $client->createJob($jobSettings);
            Log::info("Created MediaConvert job: {$result['Job']['Id']}");
            return $result['Job'];
        } catch (AwsException $e) {
            Log::error("Failed to create MediaConvert job: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Get a MediaConvert job by ID.
     */
    public function getJob(string $jobId): array
    {
        $client = $this->getClient();

        try {
            $result = $client->getJob(['Id' => $jobId]);
            return $result['Job'];
        } catch (AwsException $e) {
            Log::error("Failed to get MediaConvert job {$jobId}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Cancel a MediaConvert job.
     */
    public function cancelJob(string $jobId): bool
    {
        $client = $this->getClient();

        try {
            $client->cancelJob(['Id' => $jobId]);
            Log::info("Cancelled MediaConvert job: {$jobId}");
            return true;
        } catch (AwsException $e) {
            Log::error("Failed to cancel MediaConvert job {$jobId}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * List MediaConvert jobs.
     */
    public function listJobs(?string $status = null, int $maxResults = 20, ?string $nextToken = null): array
    {
        $client = $this->getClient();

        $params = ['MaxResults' => $maxResults];

        if ($status) {
            $params['Status'] = $status;
        }

        if ($nextToken) {
            $params['NextToken'] = $nextToken;
        }

        try {
            $result = $client->listJobs($params);
            return [
                'jobs' => $result['Jobs'] ?? [],
                'next_token' => $result['NextToken'] ?? null,
            ];
        } catch (AwsException $e) {
            Log::error("Failed to list MediaConvert jobs: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Map AWS MediaConvert status to Zencoder status.
     */
    public static function mapStatusToZencoder(string $awsStatus): string
    {
        return self::STATUS_MAP[$awsStatus] ?? 'pending';
    }

    /**
     * Calculate job progress percentage.
     */
    public static function calculateProgress(array $job): float
    {
        $status = $job['Status'] ?? '';

        if ($status === 'COMPLETE') {
            return 100.0;
        }

        if (in_array($status, ['CANCELED', 'ERROR'])) {
            return 0.0;
        }

        if ($status === 'PROGRESSING') {
            return (float) ($job['JobPercentComplete'] ?? 0);
        }

        return 0.0;
    }

    /**
     * Extract output details from a completed MediaConvert job.
     */
    public function getJobOutputDetails(array $job): array
    {
        $outputs = [];
        $outputGroups = $job['Settings']['OutputGroups'] ?? [];

        foreach ($outputGroups as $groupIdx => $group) {
            $groupOutputs = $group['Outputs'] ?? [];
            $outputSettings = $group['OutputGroupSettings'] ?? [];

            // Determine output destination
            $destination = '';
            if (isset($outputSettings['FileGroupSettings'])) {
                $destination = $outputSettings['FileGroupSettings']['Destination'] ?? '';
            } elseif (isset($outputSettings['HlsGroupSettings'])) {
                $destination = $outputSettings['HlsGroupSettings']['Destination'] ?? '';
            } elseif (isset($outputSettings['DashIsoGroupSettings'])) {
                $destination = $outputSettings['DashIsoGroupSettings']['Destination'] ?? '';
            }

            foreach ($groupOutputs as $outIdx => $output) {
                $videoDesc = $output['VideoDescription'] ?? [];
                $audioDescs = $output['AudioDescriptions'] ?? [];

                $outputs[] = [
                    'group_index' => $groupIdx,
                    'output_index' => $outIdx,
                    'destination' => $destination,
                    'name_modifier' => $output['NameModifier'] ?? '',
                    'extension' => $output['Extension'] ?? '',
                    'container' => $output['ContainerSettings']['Container'] ?? '',
                    'video' => $videoDesc ? [
                        'width' => $videoDesc['Width'] ?? null,
                        'height' => $videoDesc['Height'] ?? null,
                        'codec' => $videoDesc['CodecSettings']['Codec'] ?? null,
                    ] : null,
                    'audio' => $audioDescs ? [
                        'codec' => $audioDescs[0]['CodecSettings']['Codec'] ?? null,
                    ] : null,
                ];
            }
        }

        return $outputs;
    }
}
