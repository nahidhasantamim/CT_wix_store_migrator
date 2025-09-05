<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WixLoyaltyImportMigration extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'from_store_id',
        'to_store_id',
        'source_import_id',
        'file_name',
        'file_url',
        'status',
    ];
}
