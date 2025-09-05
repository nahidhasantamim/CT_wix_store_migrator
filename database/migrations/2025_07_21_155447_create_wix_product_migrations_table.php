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
        Schema::create('wix_product_migrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index(); // Associate with users
            $table->string('from_store_id')->nullable();
            $table->string('to_store_id')->nullable();
            $table->string('source_product_id')->nullable();
            $table->string('source_product_sku')->nullable();
            $table->string('source_product_name')->nullable();
            $table->string('destination_product_id')->nullable();
            $table->enum('status', ['pending', 'success', 'failed', 'skipped'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'from_store_id', 'to_store_id', 'source_product_id'], 'unique_product_migration');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wix_product_migrations');
    }
};
