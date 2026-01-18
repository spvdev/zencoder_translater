<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class WebhookService
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => config('app.webhook.timeout', 30),
        ]);
    }

    /**
     * Send a single webhook notification.
     *
     * @param string $url Webhook URL
     * @param array $payload Notification payload
     * @param string $format Payload format (json or xml)
     * @param string|null $event Event type
     * @param array $customHeaders Custom headers to include
     */
    public function sendNotification(
        string $url,
        array $payload,
        string $format = 'json',
        ?string $event = null,
        array $customHeaders = []
    ): bool {
        $headers = ['Content-Type' => 'application/json'];

        if ($event) {
            $headers['X-Zencoder-Event'] = $event;
        }

        // Merge custom headers (these can override defaults)
        $headers = array_merge($headers, $customHeaders);

        $maxRetries = config('app.webhook.max_retries', 3);

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                $response = $this->client->post($url, [
                    'json' => $payload,
                    'headers' => $headers,
                ]);

                if ($response->getStatusCode() < 400) {
                    Log::info("Webhook sent successfully to {$url}");
                    return true;
                }

                Log::warning("Webhook to {$url} returned status {$response->getStatusCode()}");
            } catch (RequestException $e) {
                Log::warning("Webhook to {$url} failed: {$e->getMessage()} (attempt " . ($attempt + 1) . ")");
            }

            // Exponential backoff
            if ($attempt < $maxRetries - 1) {
                sleep(pow(2, $attempt));
            }
        }

        Log::error("Failed to send webhook to {$url} after {$maxRetries} attempts");
        return false;
    }

    /**
     * Send notifications to multiple endpoints.
     */
    public function sendJobNotifications(array $notifications, array $payload, string $event = 'output_finished'): array
    {
        $results = [];

        foreach ($notifications as $notification) {
            if (is_string($notification)) {
                // Simple URL string
                $results[] = $this->sendNotification($notification, $payload, 'json', $event);
            } elseif (is_array($notification)) {
                // Notification config object
                $url = $notification['url'] ?? null;
                if (!$url) {
                    Log::warning('Notification config missing URL');
                    $results[] = false;
                    continue;
                }

                // Check event filter
                $notifyEvent = $notification['event'] ?? $event;
                if ($notifyEvent !== $event) {
                    Log::debug("Skipping notification for event {$event} (configured for {$notifyEvent})");
                    $results[] = true; // Not a failure, just filtered
                    continue;
                }

                // Extract custom headers if provided
                $customHeaders = $notification['headers'] ?? [];

                $results[] = $this->sendNotification(
                    $url,
                    $payload,
                    $notification['format'] ?? 'json',
                    $event,
                    $customHeaders
                );
            } else {
                Log::warning("Invalid notification config: " . json_encode($notification));
                $results[] = false;
            }
        }

        return $results;
    }

    /**
     * Build a Zencoder-compatible output notification payload.
     */
    public function buildOutputNotificationPayload(array $output, array $job, ?array $inputInfo = null): array
    {
        $payload = [
            'output' => $output,
            'job' => [
                'id' => $job['id'] ?? null,
                'state' => $job['state'] ?? null,
                'pass_through' => $job['pass_through'] ?? null,
                'created_at' => $job['created_at'] ?? null,
                'finished_at' => $job['finished_at'] ?? null,
            ],
        ];

        if ($inputInfo) {
            $payload['input'] = $inputInfo;
        }

        return $payload;
    }

    /**
     * Build a Zencoder-compatible job notification payload.
     */
    public function buildJobNotificationPayload(array $job, array $outputs, ?array $inputInfo = null): array
    {
        $payload = [
            'job' => [
                'id' => $job['id'] ?? null,
                'state' => $job['state'] ?? null,
                'pass_through' => $job['pass_through'] ?? null,
                'created_at' => $job['created_at'] ?? null,
                'finished_at' => $job['finished_at'] ?? null,
                'outputs' => $outputs,
            ],
        ];

        if ($inputInfo) {
            $payload['input'] = $inputInfo;
        }

        return $payload;
    }
}
