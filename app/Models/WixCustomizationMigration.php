<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WixCustomizationMigration extends Model
{
    protected $fillable = [
        'user_id',
        'from_store_id',
        'to_store_id',
        'source_customization_id',
        'source_customization_name',
        'destination_customization_id',
        'status',
        'error_message',
    ];
}