<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WixBrandMigration extends Model
{
    protected $fillable = [
        'user_id',
        'from_store_id',
        'to_store_id',
        'source_brand_id',
        'source_brand_name',
        'destination_brand_id',
        'status',
        'error_message',
    ];
}