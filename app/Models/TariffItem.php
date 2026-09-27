<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TariffItem extends Model
{
    protected $fillable = [
        'tariff_id',
        'template_id',
        'device_limit',
        'custom_name'
    ];

    protected $casts = [
        'device_limit' => 'integer'
    ];

    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SubscriptionTemplate::class, 'template_id');
    }
}