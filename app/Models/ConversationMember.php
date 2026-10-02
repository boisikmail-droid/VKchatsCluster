<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationMember extends Model
{
    protected $fillable = [
        'conversation_id',
        'member_vk_id',
        'user_id',
        'display_name',
        'is_admin',
        'is_current',
        'last_message_at',
    ];

    protected $casts = [
        'member_vk_id' => 'integer',
        'is_admin' => 'boolean',
        'is_current' => 'boolean',
        'last_message_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(VkUser::class, 'user_id');
    }
}
