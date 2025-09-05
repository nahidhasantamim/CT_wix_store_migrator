<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WixMemberMigration extends Model
{
    protected $fillable = [
        'user_id',
        'from_store_id',
        'to_store_id',
        'source_member_id',
        'source_member_email',
        'source_member_name',
        'destination_member_id',
        'status',
        'error_message',
    ];
}