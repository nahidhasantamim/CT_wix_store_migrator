<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WixBackInStockMigration extends Model
{
    protected $fillable = [
        'user_id',
        'from_store_id',
        'to_store_id',
        'source_request_id',
        'source_email',
        'source_product_id',
        'source_variant_id',
        'destination_product_id',
        'destination_request_id',
        'status',
        'error_message',
    ];
}
