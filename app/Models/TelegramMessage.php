<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramMessage extends Model
{
    protected $fillable = [
        'text',
        'is_from_bot',
        'telegram_user_id',
    ];
}
