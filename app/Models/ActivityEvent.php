<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityEvent extends Model
{
    protected $fillable = [
        'group_id',
        'user_id',
        'actor',
        'event_type',
        'vk_event_id',
        'peer_id',
        'payload',
        'occurred_at',
    ];

    protected $casts = [
        'peer_id' => 'integer',
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(VkGroup::class, 'group_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(VkUser::class, 'user_id');
    }
}
