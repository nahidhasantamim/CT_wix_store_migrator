<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WixLoyaltyAccountMigration extends Model
{
    protected $fillable = [
        'user_id',
        'from_store_id',
        'to_store_id',
        'source_account_id',
        'source_contact_id',
        'source_email',
        'source_name',
        'source_points_balance',
        'source_tier_name',
        'destination_account_id',
        'destination_contact_id',
        'status',
        'error_message',
    ];
}
