<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WixStore extends Model
{
    protected $fillable = [
        'user_id',
        'instance_id',
        'instance_token',
        'store_name',
        'store_logo',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function logs()
    {
        return $this->hasMany(WixLog::class, 'store_id');
    }

    
}

