<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WixCollectionMigration extends Model
{
    protected $fillable = [
        'user_id',
        'from_store_id',
        'to_store_id',
        'source_collection_id',
        'source_collection_slug',
        'source_collection_name',
        'destination_collection_id',
        'status',
        'error_message'
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}