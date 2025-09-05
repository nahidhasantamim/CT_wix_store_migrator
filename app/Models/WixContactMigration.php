<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WixContactMigration extends Model
{
    protected $fillable = [
        'user_id', 'from_store_id', 'to_store_id', 'contact_email', 'contact_name',
        'destination_contact_id', 'status', 'error_message'
    ];

}
