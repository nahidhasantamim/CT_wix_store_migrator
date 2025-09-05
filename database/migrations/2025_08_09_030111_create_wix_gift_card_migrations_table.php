<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('wix_gift_card_migrations', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id')->index();
            $table->string('from_store_id')->nullable();
            $table->string('to_store_id')->nullable();

            $table->string('source_gift_card_id')->nullable();
            $table->string('destination_gift_card_id')->nullable();

            // Obfuscated code (as returned by query/get). Useful for reference
            $table->string('source_code_suffix')->nullable();

            $table->string('initial_value_amount')->nullable();
            $table->string('currency')->nullable();

            $table->enum('status', ['pending', 'success', 'failed', 'skipped'])->default('pending');
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->unique(
                ['user_id', 'from_store_id', 'to_store_id', 'source_gift_card_id'],
                'unique_gift_card_migration'
            );

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wix_gift_card_migrations');
    }
};
