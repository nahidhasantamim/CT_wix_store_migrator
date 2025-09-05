<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Adjust the list to exactly match what you use in code
        DB::statement("
            ALTER TABLE `wix_collection_migrations`
            MODIFY `status` ENUM('pending','success','failed','skipped')
            NOT NULL DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        // Revert if needed
        DB::statement("
            ALTER TABLE `wix_collection_migrations`
            MODIFY `status` ENUM('pending','success','failed')
            NOT NULL DEFAULT 'pending'
        ");
    }
};