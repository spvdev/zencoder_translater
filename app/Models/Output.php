<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Output extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'job_id',
        'aws_output_key',
        'label',
        'output_url',
        'format',
        'state',
        'progress',
        'error_message',
        'error_class',
        'file_size_bytes',
        'duration_ms',
        'width',
        'height',
        'video_codec',
        'video_bitrate_kbps',
        'audio_codec',
        'audio_bitrate_kbps',
        'audio_sample_rate',
        'audio_channels',
        'frame_rate',
        'md5_checksum',
        'original_settings',
        'notifications',
        'submitted_at',
        'finished_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'original_settings' => 'array',
        'notifications' => 'array',
        'progress' => 'float',
        'frame_rate' => 'float',
        'submitted_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * Get the job that owns the output.
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /**
     * Convert to Zencoder output status format.
     */
    public function toZencoderStatus(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'url' => $this->output_url,
            'state' => $this->state,
            'error_message' => $this->error_message,
            'error_class' => $this->error_class,
        ];
    }

    /**
     * Convert to Zencoder output details format.
     */
    public function toZencoderDetails(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'url' => $this->output_url,
            'state' => $this->state,
            'error_message' => $this->error_message,
            'error_class' => $this->error_class,
            'file_size_in_bytes' => $this->file_size_bytes,
            'duration_in_ms' => $this->duration_ms,
            'width' => $this->width,
            'height' => $this->height,
            'video_codec' => $this->video_codec,
            'video_bitrate_in_kbps' => $this->video_bitrate_kbps,
            'audio_codec' => $this->audio_codec,
            'audio_bitrate_in_kbps' => $this->audio_bitrate_kbps,
            'audio_sample_rate' => $this->audio_sample_rate,
            'channels' => $this->audio_channels ? (string) $this->audio_channels : null,
            'frame_rate' => $this->frame_rate,
            'format' => $this->format,
            'md5_checksum' => $this->md5_checksum,
            'finished_at' => $this->finished_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Convert to webhook notification payload.
     */
    public function toWebhookPayload(): array
    {
        return [
            'output' => $this->toZencoderDetails(),
            'job' => [
                'id' => $this->job_id,
                'state' => $this->job?->state,
                'pass_through' => $this->job?->pass_through,
            ],
        ];
    }
}
