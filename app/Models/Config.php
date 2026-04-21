<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Config extends Model
{
    protected $fillable = [
        'subscription_id',
        'node_id',
        'name',
        'email',
        'link',
        'traffic_limit',
        'up',
        'down',
        'expiry_time',
        'flag_id',
        'is_main',
        ];

    public function subscriptions(): BelongsToMany
    {
        return $this->belongsToMany(Subscription::class);
    }

    public function flag(): BelongsTo
    {
        return $this->belongsTo(Flag::class);
    }
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
