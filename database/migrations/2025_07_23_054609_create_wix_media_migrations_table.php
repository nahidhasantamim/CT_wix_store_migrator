<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wix_media_migrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('from_store_id')->nullable();
            $table->string('to_store_id')->nullable();
            $table->string('folder_id')->nullable();
            $table->string('folder_name')->nullable();
            $table->integer('total_files')->default(0);
            $table->integer('imported_files')->default(0);
            $table->enum('status', ['pending', 'success', 'failed', 'skipped'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'from_store_id', 'to_store_id', 'folder_id'], 'unique_media_migration');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wix_media_migrations');
    }
};