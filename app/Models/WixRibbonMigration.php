<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WixRibbonMigration extends Model
{
    protected $fillable = [
        'user_id',
        'from_store_id',
        'to_store_id',
        'source_ribbon_id',
        'source_ribbon_name',
        'destination_ribbon_id',
        'status',
        'error_message',
    ];
}