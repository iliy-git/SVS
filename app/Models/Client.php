<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'address',
        'additional_info',
        'telegram_id'
    ];

    public function subscriptions()
    {
        return $this->belongsToMany(Subscription::class, 'client_subscription');
    }

    public function scopeSearch(Builder $query, string $search = ''): Builder
    {
        if (empty($search)) {
            return $query;
        }

        // Очищаем строку от возможных пробелов и знака # (если админ введет #25)
        $cleanSearch = ltrim(trim($search), '#');

        return $query->where(function ($q) use ($search, $cleanSearch) {
            // Стандартный поиск по тексту
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('address', 'like', "%{$search}%");

            // Если введенное значение — это число, подключаем поиск по ID подписки
            if (is_numeric($cleanSearch)) {
                $q->orWhereHas('subscriptions', function ($subQuery) use ($cleanSearch) {
                    $subQuery->where('subscriptions.id', $cleanSearch);
                });
            }
        });
    }
}
