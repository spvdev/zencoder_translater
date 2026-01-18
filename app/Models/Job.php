<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Job extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'aws_job_id',
        'state',
        'progress',
        'error_message',
        'error_class',
        'input_url',
        'input_media_info',
        'test_mode',
        'region',
        'pass_through',
        'notifications',
        'submitted_at',
        'started_at',
        'finished_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'input_media_info' => 'array',
        'notifications' => 'array',
        'test_mode' => 'boolean',
        'progress' => 'float',
        'submitted_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * Get the outputs for the job.
     */
    public function outputs(): HasMany
    {
        return $this->hasMany(Output::class);
    }

    /**
     * Convert to Zencoder status format.
     */
    public function toZencoderStatus(): array
    {
        return [
            'state' => $this->state,
            'progress' => $this->progress,
            'input' => $this->input_media_info,
            'outputs' => $this->outputs->map(fn($output) => $output->toZencoderStatus())->toArray(),
        ];
    }

    /**
     * Convert to Zencoder job details format.
     */
    public function toZencoderDetails(): array
    {
        return [
            'id' => $this->id,
            'state' => $this->state,
            'input_media_file' => $this->input_media_info,
            'output_media_files' => $this->outputs->map(fn($output) => $output->toZencoderDetails())->toArray(),
            'created_at' => $this->created_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'pass_through' => $this->pass_through,
            'test' => $this->test_mode,
        ];
    }

    /**
     * Convert to Zencoder job response format (for job creation).
     */
    public function toZencoderResponse(): array
    {
        return [
            'id' => $this->id,
            'outputs' => $this->outputs->map(fn($output) => [
                'id' => $output->id,
                'label' => $output->label,
                'url' => $output->output_url,
            ])->toArray(),
            'test' => $this->test_mode,
        ];
    }
}
