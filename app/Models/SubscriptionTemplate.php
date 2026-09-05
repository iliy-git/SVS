<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionTemplate extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'is_active'];

    public function inbounds(): HasMany
    {
        return $table = $this->hasMany(TemplateInbound::class, 'template_id');
    }
}
