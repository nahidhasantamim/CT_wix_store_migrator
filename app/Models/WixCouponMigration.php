<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WixCouponMigration extends Model
{
    use HasFactory;

    // Table name
    protected $table = 'wix_coupon_migrations';

    // Fields that are mass assignable
    protected $fillable = [
        'user_id',
        'from_store_id',
        'to_store_id',
        'source_coupon_code',
        'source_coupon_name',
        'destination_coupon_id',
        'status',
        'error_message',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
