<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Set jobs auto-increment to avoid collisions with legacy Zencoder job IDs.
     *
     * Real Zencoder IDs range from ~28M to ~1.7B. Starting at 2B ensures
     * our IDs never collide with existing Django UploadedVideo/UploadedAudio
     * records that still reference old Zencoder job IDs.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE jobs AUTO_INCREMENT = 2000000000');
    }

    public function down(): void
    {
        // No rollback needed — auto_increment only affects future inserts
    }
};
