<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WixProductMigration extends Model
{
    protected $fillable = [
        'user_id',
        'from_store_id',
        'to_store_id',
        'source_product_id',
        'source_product_sku',
        'source_product_name',
        'destination_product_id',
        'status',
        'error_message',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}