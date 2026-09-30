<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VkGroupMember extends Model
{
    protected $fillable = [
        'group_id',
        'user_id',
        'role',
        'is_member',
        'messages_allowed',
        'joined_at',
        'left_at',
    ];

    protected $casts = [
        'is_member' => 'boolean',
        'messages_allowed' => 'boolean',
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
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
