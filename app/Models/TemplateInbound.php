<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemplateInbound extends Model
{
    protected $fillable = ['template_id', 'node_id', 'inbound_id', 'priority', 'traffic_limit_gb',
        'is_tls',];

    public function template(): BelongsTo
    {
        return $this->belongsTo(SubscriptionTemplate::class, 'template_id');
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'node_id');
    }
}
