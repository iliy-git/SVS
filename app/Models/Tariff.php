<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tariff extends Model
{
    protected $fillable = [
        'name',
        'description',
        'price',
        'duration_days',
        'is_active',
        'is_public',
    ];

    protected $casts = [
        'is_active'     => 'boolean',
        'is_public'     => 'boolean',
        'price'         => 'decimal:2',
        'duration_days' => 'integer',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(TariffItem::class);
    }

    public function clientTariffs(): HasMany
    {
        return $this->hasMany(ClientTariff::class);
    }

    /**
     * Только публичные тарифы для витрины/бота
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true)->where('is_active', true);
    }
}
