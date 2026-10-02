<?php

namespace App\Console\Commands;

use App\Models\ActivityEvent;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\DialogAttachment;
use App\Models\DialogMessage;
use App\Models\VkGroup;
use App\Services\Vk\ConversationRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RebuildDialogsCommand extends Command
{
    protected $signature = 'vk:rebuild-dialogs {--enrich : Подтянуть имена и состав бесед из ВКонтакте}';

    protected $description = 'Собрать беседы, участников, сообщения и вложения из уже сохранённых событий';

    public function handle(ConversationRecorder $recorder): int
    {
        Schema::disableForeignKeyConstraints();
        DialogAttachment::query()->delete();
        DialogMessage::query()->delete();
        ConversationMember::query()->delete();
        Conversation::query()->delete();
        Schema::enableForeignKeyConstraints();

        $count = 0;

        foreach (['message_new', 'message_reply', 'message_out', 'message_read'] as $eventType) {
            ActivityEvent::query()
                ->where('event_type', $eventType)
                ->orderBy('id')
                ->chunkById(200, function ($events) use ($recorder, &$count) {
                    foreach ($events as $event) {
                        $group = $event->group;

                        if ($group === null) {
                            continue;
                        }

                        $recorder->ingest($group, $event);
                        $count++;
                    }
                });
        }

        $this->info('Событий разобрано: '.$count);

        if (!$this->option('enrich')) {
            return 0;
        }

        $enriched = 0;

        foreach (VkGroup::query()->cursor() as $group) {
            $recorder->discover($group);

            foreach ($group->conversations()->cursor() as $conversation) {
                $conversation->members_synced_at = null;
                $conversation->save();
                $recorder->enrich($group, $conversation);
                $enriched++;
            }
        }

        $this->info('Бесед обновлено из ВКонтакте: '.$enriched);

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            $rows = DB::select("SELECT person, dialog, messages_7d FROM user_activity WHERE kind = 'chat' AND messages_7d > 0 ORDER BY messages_7d DESC LIMIT 15");

            foreach ($rows as $row) {
                $this->line($row->person.' | '.$row->dialog.' | '.$row->messages_7d);
            }
        }

        return 0;
    }
}
