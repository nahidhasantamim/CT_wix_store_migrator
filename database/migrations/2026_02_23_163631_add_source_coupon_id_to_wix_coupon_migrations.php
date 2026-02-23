<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wix_coupon_migrations', function (Blueprint $table) {
            $table->string('source_coupon_id')->nullable()->index()->after('to_store_id');
        });
    }

    public function down(): void
    {
        Schema::table('wix_coupon_migrations', function (Blueprint $table) {
            $table->dropIndex(['source_coupon_id']);
            $table->dropColumn('source_coupon_id');
        });
    }
};
