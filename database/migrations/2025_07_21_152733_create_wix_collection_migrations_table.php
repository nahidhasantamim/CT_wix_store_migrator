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
        Schema::create('wix_collection_migrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index(); // Associate with users
            $table->string('from_store_id')->nullable();
            $table->string('to_store_id')->nullable();
            $table->string('source_collection_id')->nullable();
            $table->string('source_collection_slug')->nullable();
            $table->string('source_collection_name')->nullable();
            $table->string('destination_collection_id')->nullable();
            $table->enum('status', ['pending', 'success', 'failed', 'skipped'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'from_store_id', 'to_store_id', 'source_collection_id'], 'unique_migration');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('wix_collection_migrations');
    }
};
