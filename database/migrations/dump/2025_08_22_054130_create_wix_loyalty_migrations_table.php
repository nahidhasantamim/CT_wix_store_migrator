<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wix_loyalty_migrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('from_store_id')->index();
            $table->string('to_store_id')->nullable()->index();

            $table->string('contact_email')->nullable()->index();
            $table->string('source_account_id')->nullable()->index();
            $table->string('destination_account_id')->nullable()->index();

            $table->integer('starting_balance')->default(0);
            $table->string('status')->default('pending'); // pending | success | failed | skipped
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'from_store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wix_loyalty_migrations');
    }
};
