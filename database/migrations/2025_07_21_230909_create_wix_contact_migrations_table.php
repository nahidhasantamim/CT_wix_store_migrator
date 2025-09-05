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
        Schema::create('wix_contact_migrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('from_store_id')->nullable();
            $table->string('to_store_id')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('destination_contact_id')->nullable();
            $table->enum('status', ['pending', 'success', 'failed', 'skipped'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            // Optionally, add a unique constraint if you want to avoid duplicate migrations
            // $table->unique(['user_id', 'from_store_id', 'to_store_id', 'contact_email'], 'unique_contact_migration');
        });
    }

    public function down()
    {
        Schema::dropIfExists('wix_contact_migrations');
    }
};
