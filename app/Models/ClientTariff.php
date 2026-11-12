<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientTariff extends Model
{
    protected $fillable = [
        'client_id',
        'tariff_id',
        'custom_price',
        'expires_at',
    ];

    protected $casts = [
        'expires_at'   => 'datetime',
        'custom_price' => 'decimal:2',
    ];

    protected $appends = [
        'price',
        'is_custom_price',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'client_tariff_id');
    }

    /**
     * Действующая стоимость тарифа: персональная, либо стандартная по тарифу
     */
    public function getPriceAttribute(): float
    {
        if ($this->custom_price !== null) {
            return (float) $this->custom_price;
        }

        return (float) ($this->tariff?->price ?? 0);
    }

    public function getIsCustomPriceAttribute(): bool
    {
        return $this->custom_price !== null;
    }
}

