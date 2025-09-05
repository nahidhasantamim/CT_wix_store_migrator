<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WixOrderMigration extends Model
{
    protected $fillable = [
        'user_id',
        'from_store_id',
        'to_store_id',
        'source_order_id',
        'destination_order_id',
        'order_number',
        'status',
        'error_message',
    ];
}