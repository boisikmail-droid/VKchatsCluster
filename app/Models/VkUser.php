<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VkUser extends Model
{
    protected $fillable = [
        'vk_id',
        'first_name',
        'last_name',
        'screen_name',
        'photo_url',
        'sex',
        'city',
        'can_write_private_message',
        'raw',
    ];

    protected $casts = [
        'vk_id' => 'integer',
        'sex' => 'integer',
        'can_write_private_message' => 'boolean',
        'raw' => 'array',
    ];

    public function memberships(): HasMany
    {
        return $this->hasMany(VkGroupMember::class, 'user_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ActivityEvent::class, 'user_id');
    }

    public function fillFromVk(array $profile): void
    {
        $this->vk_id = (int) $profile['id'];

        if (array_key_exists('first_name', $profile)) {
            $this->first_name = (string) $profile['first_name'];
        }

        if (array_key_exists('last_name', $profile)) {
            $this->last_name = (string) $profile['last_name'];
        }

        if (array_key_exists('screen_name', $profile)) {
            $this->screen_name = $profile['screen_name'];
        } elseif (array_key_exists('domain', $profile)) {
            $this->screen_name = $profile['domain'];
        }

        if (isset($profile['photo_100'])) {
            $this->photo_url = $profile['photo_100'];
        }

        if (array_key_exists('sex', $profile)) {
            $this->sex = $profile['sex'] === null ? null : (int) $profile['sex'];
        }

        if (isset($profile['city']['title'])) {
            $this->city = $profile['city']['title'];
        }

        if (array_key_exists('can_write_private_message', $profile)) {
            $this->can_write_private_message = (bool) $profile['can_write_private_message'];
        }

        if (count($profile) > 1) {
            $this->raw = $profile;
        }
    }
}
