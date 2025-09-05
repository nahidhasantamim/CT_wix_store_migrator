<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wix_loyalty_account_migrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();

            // Identity of source & destination stores
            $table->uuid('from_store_id')->index();
            $table->uuid('to_store_id')->nullable()->index();

            // Source identity & hints (to assist contact matching)
            $table->uuid('source_account_id')->nullable()->index();
            $table->uuid('source_contact_id')->nullable()->index();
            $table->string('source_email')->nullable()->index();
            $table->string('source_name')->nullable();

            // Exported snapshot info (lightweight)
            $table->integer('source_points_balance')->nullable();
            $table->string('source_tier_name')->nullable();

            // Destination identity
            $table->uuid('destination_account_id')->nullable()->index();
            $table->uuid('destination_contact_id')->nullable()->index();

            // Status
            $table->enum('status', ['pending', 'success', 'failed', 'skipped'])->default('pending')->index();
            $table->text('error_message')->nullable();

            $table->timestamps();

            // Prevent duplicates while still allowing multiple exports over time:
            // a single (user, from_store, source_account) can be imported to a given to_store only once
            $table->unique(
                ['user_id', 'from_store_id', 'source_account_id', 'to_store_id'],
                'unique_loyalty_account_migration'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wix_loyalty_account_migrations');
    }
};
