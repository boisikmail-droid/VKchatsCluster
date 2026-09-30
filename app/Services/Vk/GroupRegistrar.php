<?php

namespace App\Services\Vk;

use App\Models\VkGroup;
use Carbon\Carbon;

class GroupRegistrar
{
    public function register(array $input): VkGroup
    {
        $client = new VkClient($input['access_token']);
        $params = ['fields' => config('vk.group_fields')];

        if (!empty($input['vk_group_id'])) {
            $params['group_ids'] = (string) $input['vk_group_id'];
        } elseif (!empty($input['screen_name'])) {
            $params['group_ids'] = $input['screen_name'];
        }

        $groupPayload = $this->firstGroup($client->call('groups.getById', $params));

        if ($groupPayload === null) {
            throw new VkApiException('Не удалось прочитать сообщество. Проверьте ключ сообщества и id группы.');
        }

        $group = VkGroup::firstOrNew(['vk_id' => (int) $groupPayload['id']]);
        $group->fillFromVk($groupPayload);
        $group->confirmation_code = trim((string) $input['confirmation_code']);
        $group->secret_key = trim((string) $input['secret_key']);
        $group->access_token = $input['access_token'];
        $group->synced_at = Carbon::now();
        $group->save();

        return $group;
    }

    private function firstGroup(array $response): ?array
    {
        if (isset($response['groups'][0]) && is_array($response['groups'][0])) {
            return $response['groups'][0];
        }

        if (isset($response[0]['id'])) {
            return $response[0];
        }

        return null;
    }
}
