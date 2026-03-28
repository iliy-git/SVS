<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Node extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'ip',
        'port',
        'api_key',
        'is_active',
        'last_seen',
        'flag_id'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_seen' => 'datetime',
    ];

    public function flag()
    {
        return $this->belongsTo(Flag::class);
    }
}
