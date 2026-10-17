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
        'is_active',
        'is_modernized',
        'link_overrides',
        'inbound_id',
        'priority',
    ];

    protected $casts = [
        'is_main'        => 'boolean',
        'is_active'      => 'boolean',
        'is_modernized'  => 'boolean',
        'link_overrides' => 'array',
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
