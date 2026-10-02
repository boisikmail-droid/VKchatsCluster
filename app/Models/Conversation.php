<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = [
        'group_id',
        'peer_id',
        'kind',
        'title',
        'interlocutor_user_id',
        'members_count',
        'last_read_cmid',
        'last_message_at',
        'last_read_at',
        'members_synced_at',
    ];

    protected $casts = [
        'peer_id' => 'integer',
        'members_count' => 'integer',
        'last_read_cmid' => 'integer',
        'last_message_at' => 'datetime',
        'last_read_at' => 'datetime',
        'members_synced_at' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(VkGroup::class, 'group_id');
    }

    public function interlocutor(): BelongsTo
    {
        return $this->belongsTo(VkUser::class, 'interlocutor_user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ConversationMember::class, 'conversation_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(DialogMessage::class, 'conversation_id');
    }
}
