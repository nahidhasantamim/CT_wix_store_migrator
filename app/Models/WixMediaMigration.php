<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WixMediaMigration extends Model
{
    protected $fillable = [
        'user_id',
        'from_store_id',
        'to_store_id',
        'folder_id',
        'folder_name',
        'total_files',
        'imported_files',
        'status',
        'error_message'
    ];
}
