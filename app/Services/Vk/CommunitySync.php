<?php

namespace App\Services\Vk;

use App\Models\VkGroup;
use App\Models\VkGroupMember;
use App\Models\VkUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CommunitySync
{
    public function sync(VkGroup $group): array
    {
        if ($group->access_token === null || $group->access_token === '') {
            throw new VkApiException('У сообщества нет ключа доступа.');
        }

        $client = new VkClient($group->access_token);
        $this->refreshGroup($group, $client);

        $seen = [];
        $offset = 0;
        $total = 0;

        do {
            $page = $client->call('groups.getMembers', [
                'group_id' => $group->vk_id,
                'fields' => config('vk.user_fields'),
                'offset' => $offset,
                'count' => 1000,
            ]);

            $total = (int) ($page['count'] ?? 0);
            $items = $page['items'] ?? [];

            foreach ($items as $item) {
                if (!is_array($item)) {
                    $item = ['id' => $item];
                }

                $user = $this->upsertUser($item);
                $seen[] = $user->id;
                $this->touchMember($group, $user, [
                    'role' => 'member',
                    'is_member' => true,
                    'left_at' => null,
                ]);
            }

            $offset += 1000;
        } while ($offset < $total && $items !== []);

        $stale = VkGroupMember::where('group_id', $group->id)->where('is_member', true);

        if ($seen !== []) {
            $stale->whereNotIn('user_id', $seen);
        }

        $stale->update([
            'is_member' => false,
            'left_at' => Carbon::now(),
        ]);

        try {
            $this->applyManagerRoles($group, $client);
        } catch (VkApiException $e) {
            Log::warning('vk manager roles were not loaded', [
                'group_id' => $group->vk_id,
                'error' => $e->getMessage(),
            ]);
        }

        $group->members_count = $total;
        $group->synced_at = Carbon::now();
        $group->save();

        return [
            'members' => count($seen),
            'total' => $total,
        ];
    }

    public function upsertUser(array $profile): VkUser
    {
        $user = VkUser::firstOrNew(['vk_id' => (int) $profile['id']]);
        $user->fillFromVk($profile);

        if ($user->first_name === null) {
            $user->first_name = '';
        }

        if ($user->last_name === null) {
            $user->last_name = '';
        }

        $user->save();

        return $user;
    }

    public function touchMember(VkGroup $group, VkUser $user, array $attributes = []): VkGroupMember
    {
        $member = VkGroupMember::firstOrNew([
            'group_id' => $group->id,
            'user_id' => $user->id,
        ]);

        if (!$member->exists) {
            $member->role = 'member';
        }

        foreach ($attributes as $key => $value) {
            $member->{$key} = $value;
        }

        $member->save();

        return $member;
    }

    private function refreshGroup(VkGroup $group, VkClient $client): void
    {
        $response = $client->call('groups.getById', [
            'group_ids' => (string) $group->vk_id,
            'fields' => config('vk.group_fields'),
        ]);

        $payload = $response['groups'][0] ?? $response[0] ?? null;

        if (!is_array($payload) || empty($payload['id'])) {
            return;
        }

        $group->fillFromVk($payload);
        $group->save();
    }

    private function applyManagerRoles(VkGroup $group, VkClient $client): void
    {
        $response = $client->call('groups.getMembers', [
            'group_id' => $group->vk_id,
            'filter' => 'managers',
            'fields' => config('vk.user_fields'),
            'count' => 1000,
        ]);

        foreach ($response['items'] ?? [] as $item) {
            if (!is_array($item) || empty($item['id'])) {
                continue;
            }

            $user = $this->upsertUser($item);
            $this->touchMember($group, $user, [
                'role' => $item['role'] ?? 'moderator',
                'is_member' => true,
                'left_at' => null,
            ]);
        }
    }
}
