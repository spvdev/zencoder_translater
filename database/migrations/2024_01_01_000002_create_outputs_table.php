<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained()->onDelete('cascade');
            $table->string('aws_output_key')->nullable();

            // Output settings
            $table->string('label')->nullable();
            $table->text('output_url')->nullable();
            $table->string('format', 50)->nullable();

            // State tracking
            $table->string('state', 50)->default('pending')->index();
            $table->float('progress')->default(0);
            $table->text('error_message')->nullable();
            $table->string('error_class', 100)->nullable();

            // Media info (populated after completion)
            $table->bigInteger('file_size_bytes')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->string('video_codec', 50)->nullable();
            $table->integer('video_bitrate_kbps')->nullable();
            $table->string('audio_codec', 50)->nullable();
            $table->integer('audio_bitrate_kbps')->nullable();
            $table->integer('audio_sample_rate')->nullable();
            $table->integer('audio_channels')->nullable();
            $table->float('frame_rate')->nullable();
            $table->string('md5_checksum', 64)->nullable();

            // Original request settings
            $table->json('original_settings')->nullable();
            $table->json('notifications')->nullable();

            // Thumbnail data (populated for video outputs with thumbnails)
            $table->json('thumbnails')->nullable();

            // Timestamps
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outputs');
    }
};
