<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    protected $fillable = ['conversation_id', 'role', 'content', 'sources'];

    protected function casts(): array
    {
        return ['sources' => 'array'];
    }
}
