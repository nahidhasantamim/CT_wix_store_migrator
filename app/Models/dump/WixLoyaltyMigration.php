<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WixLoyaltyMigration extends Model
{
    protected $fillable = [
        'user_id',
        'from_store_id',
        'to_store_id',
        'contact_email',
        'source_account_id',
        'destination_account_id',
        'starting_balance',
        'status',
        'error_message',
    ];
}
