<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Flag extends Model
{
    protected $fillable = ['name', 'code'];

    public function configs(): HasMany
    {
        return $this->hasMany(Config::class);
    }

    /**
     * Превращает ISO код (nl, us) в Emoji флаг
     */
    public function getEmojiAttribute(): string
    {
        return collect(str_split(strtoupper($this->code)))
            ->map(fn($char) => mb_chr(ord($char) + 127397))
            ->implode('');
    }
    public function getImageUrlAttribute(): string
    {
        $code = strtolower($this->code);

        if ($code === 'uk') $code = 'gb';

        return "https://purecatamphetamine.github.io/country-flag-icons/3x2/{$code->strtoupper()}.svg";
    }
}
