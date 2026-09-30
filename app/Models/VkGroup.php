<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VkGroup extends Model
{
    protected $fillable = [
        'vk_id',
        'name',
        'screen_name',
        'description',
        'type',
        'photo_url',
        'members_count',
        'is_closed',
        'confirmation_code',
        'secret_key',
        'access_token',
        'raw',
        'synced_at',
    ];

    protected $hidden = [
        'access_token',
        'secret_key',
        'confirmation_code',
    ];

    protected $casts = [
        'vk_id' => 'integer',
        'members_count' => 'integer',
        'is_closed' => 'integer',
        'raw' => 'array',
        'synced_at' => 'datetime',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(VkGroupMember::class, 'group_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ActivityEvent::class, 'group_id');
    }

    public function fillFromVk(array $payload): void
    {
        $this->vk_id = (int) $payload['id'];
        $this->name = (string) ($payload['name'] ?? '');
        $this->screen_name = $payload['screen_name'] ?? null;
        $this->description = $payload['description'] ?? null;
        $this->type = $payload['type'] ?? null;
        $this->photo_url = $payload['photo_200'] ?? $payload['photo_100'] ?? $payload['photo_50'] ?? null;
        $this->members_count = (int) ($payload['members_count'] ?? $this->members_count ?? 0);
        $this->is_closed = (int) ($payload['is_closed'] ?? 0);
        $this->raw = $payload;
    }

    public function present(bool $withRaw = false): array
    {
        $data = [
            'id' => $this->id,
            'vk_id' => $this->vk_id,
            'name' => $this->name,
            'screen_name' => $this->screen_name,
            'description' => $this->description,
            'type' => $this->type,
            'photo_url' => $this->photo_url,
            'members_count' => $this->members_count,
            'is_closed' => $this->is_closed,
            'has_token' => $this->access_token !== null && $this->access_token !== '',
            'synced_at' => $this->synced_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if ($withRaw) {
            $data['raw'] = $this->raw;
        }

        return $data;
    }
}
