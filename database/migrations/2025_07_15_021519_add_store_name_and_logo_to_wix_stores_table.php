<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('wix_stores', function (Blueprint $table) {
            $table->string('store_name')->nullable()->after('instance_id');
            $table->string('store_logo')->nullable()->after('store_name');
        });
    }

    public function down()
    {
        Schema::table('wix_stores', function (Blueprint $table) {
            $table->dropColumn('store_name');
            $table->dropColumn('store_logo');
        });
    }

};
