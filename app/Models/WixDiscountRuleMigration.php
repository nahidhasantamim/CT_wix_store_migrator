<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WixDiscountRuleMigration extends Model
{
    protected $fillable = [
        'user_id',
        'from_store_id',
        'to_store_id',
        'source_rule_id',
        'source_rule_name',
        'destination_rule_id',
        'status',
        'error_message',
    ];
}
