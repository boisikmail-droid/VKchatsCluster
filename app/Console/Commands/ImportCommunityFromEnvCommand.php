<?php

namespace App\Console\Commands;

use App\Services\Vk\GroupRegistrar;
use App\Services\Vk\VkApiException;
use Illuminate\Console\Command;

class ImportCommunityFromEnvCommand extends Command
{
    protected $signature = 'vk:import-env';

    protected $description = 'Записать сообщество из переменных окружения, если они заданы';

    public function handle(GroupRegistrar $registrar): int
    {
        $token = trim((string) env('VK_ACCESS_TOKEN', ''));
        $secret = trim((string) env('VK_CALLBACK_SECRET', ''));
        $groupId = trim((string) env('VK_GROUP_ID', ''));
        $code = trim((string) env('VK_CONFIRMATION_CODE', ''));

        if ($token === '' && $secret === '' && $groupId === '') {
            return 0;
        }

        if ($token === '' || $secret === '' || $groupId === '' || $code === '') {
            $this->error('Для импорта сообщества нужны VK_GROUP_ID, VK_ACCESS_TOKEN, VK_CALLBACK_SECRET и VK_CONFIRMATION_CODE.');

            return 1;
        }

        try {
            $group = $registrar->register([
                'access_token' => $token,
                'vk_group_id' => $groupId,
                'secret_key' => $secret,
                'confirmation_code' => $code,
            ]);
        } catch (VkApiException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $this->info('Сообщество '.$group->vk_id.' записано из переменных окружения.');

        return 0;
    }
}
