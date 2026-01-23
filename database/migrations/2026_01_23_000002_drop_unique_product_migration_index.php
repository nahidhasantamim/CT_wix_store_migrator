<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('wix_product_migrations', function (Blueprint $table) {
            $table->dropUnique('unique_product_migration');
            $table->index(['user_id', 'from_store_id', 'to_store_id', 'source_product_id'], 'idx_product_migration');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wix_product_migrations', function (Blueprint $table) {
            $table->dropIndex('idx_product_migration');
            $table->unique(['user_id', 'from_store_id', 'to_store_id', 'source_product_id'], 'unique_product_migration');
        });
    }
};
