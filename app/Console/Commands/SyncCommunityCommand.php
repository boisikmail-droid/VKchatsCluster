<?php

namespace App\Console\Commands;

use App\Models\VkGroup;
use App\Services\Vk\CommunitySync;
use App\Services\Vk\VkApiException;
use Illuminate\Console\Command;

class SyncCommunityCommand extends Command
{
    protected $signature = 'vk:sync {group : Local id or VK community id}';

    protected $description = 'Load a VK community profile and its members into the database';

    public function handle(CommunitySync $sync): int
    {
        $argument = (string) $this->argument('group');
        $group = VkGroup::where('vk_id', $argument)->first() ?: VkGroup::find($argument);

        if ($group === null) {
            $this->error('Сообщество не найдено. Сначала зарегистрируйте его через POST /api/groups.');

            return 1;
        }

        try {
            $result = $sync->sync($group);
        } catch (VkApiException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $this->info('Участников синхронизировано: '.$result['members'].' из '.$result['total']);

        return 0;
    }
}
