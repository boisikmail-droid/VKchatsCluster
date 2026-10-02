<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DialogAttachment extends Model
{
    protected $table = 'message_attachments';

    protected $fillable = [
        'message_id',
        'type',
        'owner_vk_id',
        'media_id',
        'title',
        'url',
        'raw',
    ];

    protected $casts = [
        'owner_vk_id' => 'integer',
        'media_id' => 'integer',
        'raw' => 'array',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(DialogMessage::class, 'message_id');
    }
}
