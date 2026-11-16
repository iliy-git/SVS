<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportMessage extends Model
{
    protected $fillable = [
        'client_id',
        'sender',
        'message',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
