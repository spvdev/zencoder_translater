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
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('aws_job_id')->nullable()->unique()->index();

            // State tracking
            $table->string('state', 50)->default('pending')->index();
            $table->float('progress')->default(0);
            $table->text('error_message')->nullable();
            $table->string('error_class', 100)->nullable();

            // Input details
            $table->text('input_url');
            $table->json('input_media_info')->nullable();

            // Job settings
            $table->boolean('test_mode')->default(false);
            $table->string('region', 50)->nullable();
            $table->text('pass_through')->nullable();

            // Notification settings
            $table->json('notifications')->nullable();

            // Timestamps
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jobs');
    }
};
