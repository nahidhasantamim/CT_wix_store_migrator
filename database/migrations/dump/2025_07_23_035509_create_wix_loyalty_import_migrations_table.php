<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void {
        Schema::create('wix_loyalty_import_migrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('from_store_id')->nullable();
            $table->string('to_store_id')->nullable();
            $table->string('source_import_id')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_url')->nullable();
            $table->enum('status', ['initiated', 'imported', 'failed', 'unknown'])->default('initiated');
            $table->timestamps();

            $table->unique(['user_id', 'source_import_id'], 'unique_loyalty_import');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void {
        Schema::dropIfExists('wix_loyalty_import_migrations');
    }
};
