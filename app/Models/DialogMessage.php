<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DialogMessage extends Model
{
    protected $table = 'messages';

    protected $fillable = [
        'conversation_id',
        'group_id',
        'user_id',
        'from_vk_id',
        'direction',
        'cmid',
        'vk_message_id',
        'reply_to_cmid',
        'in_reply_to_message_id',
        'text',
        'attachment_count',
        'activity_event_id',
        'occurred_at',
    ];

    protected $casts = [
        'from_vk_id' => 'integer',
        'cmid' => 'integer',
        'vk_message_id' => 'integer',
        'reply_to_cmid' => 'integer',
        'attachment_count' => 'integer',
        'occurred_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(VkUser::class, 'user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(DialogAttachment::class, 'message_id');
    }
}
