<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WixInfoSectionMigration extends Model
{
    protected $fillable = [
        'user_id',
        'from_store_id',
        'to_store_id',
        'source_info_section_id',
        'source_info_section_name',
        'destination_info_section_id',
        'status',
        'error_message',
    ];
}