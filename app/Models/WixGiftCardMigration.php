<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WixGiftCardMigration extends Model
{
    protected $fillable = [
        'user_id',
        'from_store_id',
        'to_store_id',
        'source_gift_card_id',
        'destination_gift_card_id',
        'source_code_suffix',
        'initial_value_amount',
        'currency',
        'status',
        'error_message',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
