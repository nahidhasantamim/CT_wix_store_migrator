<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WixLog extends Model
{
    protected $fillable = [
        'user_id', 'action', 'details', 'status'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

}
