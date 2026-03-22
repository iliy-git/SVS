<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'address',
        'additional_info'
    ];

    public function subscriptions()
    {
        return $this->belongsToMany(Subscription::class, 'client_subscription');
    }
}
