<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Subscription extends Model
{
    protected $fillable = ['name', 'token', 'with_balancer', 'expires_at'];

    protected $casts = [
        'with_balancer' => 'boolean',
        'expires_at' => 'datetime'
    ];

    public function configs(): BelongsToMany
    {
        return $this->belongsToMany(Config::class);
    }
    public function clients()
    {
        return $this->belongsToMany(Client::class, 'client_subscription');
    }
}
