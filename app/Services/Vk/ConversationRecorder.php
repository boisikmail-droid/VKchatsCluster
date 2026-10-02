<?php

namespace App\Services\Vk;

use App\Models\ActivityEvent;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\DialogAttachment;
use App\Models\DialogMessage;
use App\Models\VkGroup;
use App\Models\VkUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class ConversationRecorder
{
    public function __construct(private CommunitySync $communities)
    {
    }

    public function ingest(VkGroup $group, ActivityEvent $event): void
    {
        try {
            $this->store($group, $event);
        } catch (Throwable $e) {
            Log::warning('dialog store failed', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function discover(VkGroup $group): int
    {
        if ($group->access_token === null || $group->access_token === '' || app()->environment('testing')) {
            return 0;
        }

        try {
            $client = new VkClient($group->access_token);
            $found = 0;
            $offset = 0;

            do {
                $response = $client->call('messages.getConversations', [
                    'count' => 100,
                    'offset' => $offset,
                    'extended' => 1,
                    'fields' => config('vk.user_fields'),
                ]);

                foreach ($response['profiles'] ?? [] as $profile) {
                    if (is_array($profile) && !empty($profile['id'])) {
                        $this->communities->upsertUser($profile);
                    }
                }

                $items = $response['items'] ?? [];

                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $peer = $item['conversation']['peer'] ?? [];
                    $peerId = (int) ($peer['id'] ?? 0);

                    if ($peerId <= 0) {
                        continue;
                    }

                    $conversation = $this->conversation($group, $peerId, $peerId < 2000000000 ? $peerId : null);
                    $title = $item['conversation']['chat_settings']['title'] ?? null;
                    $members = $item['conversation']['chat_settings']['members_count'] ?? null;

                    if (is_string($title) && $title !== '') {
                        $conversation->title = $title;
                    }

                    if ($members !== null) {
                        $conversation->members_count = (int) $members;
                    }

                    $conversation->save();
                    $found++;
                }

                $offset += is_array($items) ? count($items) : 0;
            } while (is_array($items) && count($items) === 100 && $offset < 200);

            return $found;
        } catch (Throwable $e) {
            Log::warning('dialog discover failed', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    public function storeHistoryMessage(VkGroup $group, array $message): bool
    {
        $text = $this->text($message['text'] ?? null);

        if (!empty($message['action']) && $text === null && empty($message['attachments'])) {
            return false;
        }

        $peerId = (int) ($message['peer_id'] ?? 0);
        $fromId = (int) ($message['from_id'] ?? 0);

        if ($peerId <= 0) {
            return false;
        }

        $outgoing = (int) ($message['out'] ?? 0) === 1 || $fromId === -$group->vk_id;
        $cmid = $this->positiveInt($message['conversation_message_id'] ?? null);
        $conversation = $this->conversation(
            $group,
            $peerId,
            !$outgoing && $peerId < 2000000000 && $fromId > 0 ? $fromId : null,
        );

        if ($cmid !== null && DialogMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', $outgoing ? 'out' : 'in')
            ->where('cmid', $cmid)
            ->exists()) {
            return false;
        }

        if ($outgoing && $text !== null) {
            $pending = DialogMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('direction', 'out')
                ->whereNull('cmid')
                ->where('text', $text)
                ->orderByDesc('id')
                ->first();

            if ($pending !== null) {
                $pending->cmid = $cmid;
                $pending->vk_message_id = $this->positiveInt($message['id'] ?? null);
                $pending->save();
                $this->replaceAttachments($pending, $message);

                return false;
            }
        }

        if (!$outgoing && $fromId === 0) {
            return false;
        }

        $user = $fromId > 0 ? $this->communities->upsertUser(['id' => $fromId]) : null;
        $occurredAt = !empty($message['date'])
            ? Carbon::createFromTimestamp((int) $message['date'])
            : Carbon::now();
        $name = $outgoing
            ? $group->name
            : ($user !== null ? trim($user->first_name.' '.$user->last_name) : null);

        $this->rememberMember(
            $conversation,
            $outgoing ? -$group->vk_id : $fromId,
            $user,
            $occurredAt,
            $name !== null && $name !== '' ? $name : null,
        );

        $this->saveMessage($conversation, $group, [
            'user_id' => $outgoing ? null : $user?->id,
            'from_vk_id' => $outgoing ? -$group->vk_id : $fromId,
            'direction' => $outgoing ? 'out' : 'in',
            'cmid' => $cmid,
            'vk_message_id' => $this->positiveInt($message['id'] ?? null),
            'text' => $text,
            'occurred_at' => $occurredAt,
        ], $message);

        if ($conversation->last_message_at === null || $occurredAt->greaterThan($conversation->last_message_at)) {
            $conversation->last_message_at = $occurredAt;
            $conversation->save();
        }

        return true;
    }

    public function enrich(VkGroup $group, Conversation $conversation): void
    {
        if ($group->access_token === null || $group->access_token === '' || app()->environment('testing')) {
            return;
        }

        try {
            $client = new VkClient($group->access_token);
            $this->refreshProfiles($client, $conversation);
            $this->refreshConversation($client, $conversation);
        } catch (Throwable $e) {
            Log::warning('dialog enrich failed', [
                'peer_id' => $conversation->peer_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function store(VkGroup $group, ActivityEvent $event): void
    {
        if (DialogMessage::where('activity_event_id', $event->id)->exists()) {
            return;
        }

        if ($event->event_type === 'message_out') {
            $this->storeOutgoing($group, $event);

            return;
        }

        if ($event->event_type === 'message_read') {
            $this->storeRead($group, $event);

            return;
        }

        if (!in_array($event->event_type, ['message_new', 'message_reply'], true)) {
            return;
        }

        $message = $event->payload['object']['message'] ?? $event->payload['object'] ?? [];

        if (!is_array($message)) {
            return;
        }

        $peerId = (int) ($message['peer_id'] ?? $event->peer_id ?? 0);

        if ($peerId <= 0) {
            return;
        }

        $outgoing = $event->actor === 'bot'
            || (int) ($message['out'] ?? 0) === 1
            || $event->event_type === 'message_reply';

        if ($outgoing) {
            $this->storeEcho($group, $event, $message, $peerId);

            return;
        }

        $this->storeIncoming($group, $event, $message, $peerId);
    }

    private function storeIncoming(VkGroup $group, ActivityEvent $event, array $message, int $peerId): void
    {
        $fromId = (int) ($message['from_id'] ?? 0);
        $conversation = $this->conversation($group, $peerId, $fromId > 0 ? $fromId : null);
        $user = $fromId > 0 ? $this->communities->upsertUser(['id' => $fromId]) : null;
        $occurredAt = $this->occurredAt($event, $message);

        if ($user !== null) {
            $this->rememberMember($conversation, $fromId, $user, $occurredAt);
        }

        $this->rememberMember($conversation, -$group->vk_id, null, null, $group->name);

        $row = $this->saveMessage($conversation, $group, [
            'user_id' => $user?->id,
            'from_vk_id' => $fromId,
            'direction' => 'in',
            'cmid' => $this->positiveInt($message['conversation_message_id'] ?? null),
            'vk_message_id' => $this->positiveInt($message['id'] ?? null),
            'text' => $this->text($message['text'] ?? null),
            'activity_event_id' => $event->id,
            'occurred_at' => $occurredAt,
        ], $message);

        $conversation->last_message_at = $occurredAt;
        $conversation->save();
        unset($row);
    }

    private function storeRead(VkGroup $group, ActivityEvent $event): void
    {
        $object = $event->payload['object'] ?? [];
        $peerId = (int) ($object['peer_id'] ?? $event->peer_id ?? 0);
        $cmid = $this->positiveInt($object['conversation_message_id'] ?? null);

        if ($peerId <= 0 || $cmid === null) {
            return;
        }

        $conversation = Conversation::query()
            ->where('group_id', $group->id)
            ->where('peer_id', $peerId)
            ->first();

        if ($conversation === null) {
            return;
        }

        if ($conversation->last_read_cmid !== null && $conversation->last_read_cmid > $cmid) {
            return;
        }

        $conversation->last_read_cmid = $cmid;
        $conversation->last_read_at = $event->occurred_at ?? Carbon::now();
        $conversation->save();
    }

    private function storeOutgoing(VkGroup $group, ActivityEvent $event): void
    {
        $peerId = (int) ($event->peer_id ?? 0);

        if ($peerId <= 0) {
            return;
        }

        $payload = is_array($event->payload) ? $event->payload : [];
        $replyTo = $this->positiveInt($payload['reply_to'] ?? null);
        $conversation = $this->conversation($group, $peerId, $peerId < 2000000000 ? $peerId : null);
        $parent = $replyTo === null ? null : DialogMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'in')
            ->where('cmid', $replyTo)
            ->first();
        $text = $this->text($payload['text'] ?? null);

        $this->rememberMember($conversation, -$group->vk_id, null, $event->occurred_at, $group->name);

        if ($text !== null) {
            $existing = DialogMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('direction', 'out')
                ->where('text', $text)
                ->where(function ($query) use ($replyTo) {
                    $query->whereNull('reply_to_cmid');

                    if ($replyTo !== null) {
                        $query->orWhere('reply_to_cmid', $replyTo);
                    }
                })
                ->orderByDesc('id')
                ->first();

            if ($existing !== null) {
                if ($existing->reply_to_cmid === null) {
                    $existing->reply_to_cmid = $replyTo;
                    $existing->in_reply_to_message_id = $parent?->id;
                }

                if ($existing->activity_event_id === null) {
                    $existing->activity_event_id = $event->id;
                }

                $existing->save();
                $conversation->last_message_at = $event->occurred_at ?? Carbon::now();
                $conversation->save();

                return;
            }
        }

        $this->saveMessage($conversation, $group, [
            'user_id' => null,
            'from_vk_id' => -$group->vk_id,
            'direction' => 'out',
            'reply_to_cmid' => $replyTo,
            'in_reply_to_message_id' => $parent?->id,
            'text' => $text,
            'activity_event_id' => $event->id,
            'occurred_at' => $event->occurred_at ?? Carbon::now(),
        ], []);

        $conversation->last_message_at = $event->occurred_at ?? Carbon::now();
        $conversation->save();
    }

    private function storeEcho(VkGroup $group, ActivityEvent $event, array $message, int $peerId): void
    {
        $conversation = $this->conversation($group, $peerId, $peerId < 2000000000 ? $peerId : null);
        $text = $this->text($message['text'] ?? null);
        $cmid = $this->positiveInt($message['conversation_message_id'] ?? null);

        if ($cmid !== null) {
            $already = DialogMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('direction', 'out')
                ->where('cmid', $cmid)
                ->first();

            if ($already !== null) {
                $this->replaceAttachments($already, $message);

                return;
            }
        }

        $existing = DialogMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'out')
            ->whereNull('cmid')
            ->when($text !== null, fn ($query) => $query->where('text', $text))
            ->orderByDesc('id')
            ->first();

        if ($existing !== null) {
            $existing->cmid = $cmid;
            $existing->vk_message_id = $this->positiveInt($message['id'] ?? null);
            $existing->save();
            $this->replaceAttachments($existing, $message);

            return;
        }

        $this->saveMessage($conversation, $group, [
            'user_id' => null,
            'from_vk_id' => (int) ($message['from_id'] ?? -$group->vk_id),
            'direction' => 'out',
            'cmid' => $cmid,
            'vk_message_id' => $this->positiveInt($message['id'] ?? null),
            'text' => $text,
            'activity_event_id' => $event->id,
            'occurred_at' => $this->occurredAt($event, $message),
        ], $message);
    }

    private function conversation(VkGroup $group, int $peerId, ?int $interlocutorVkId): Conversation
    {
        $conversation = Conversation::firstOrNew([
            'group_id' => $group->id,
            'peer_id' => $peerId,
        ]);
        $conversation->kind = $peerId >= 2000000000 ? 'chat' : 'dm';

        if (!$conversation->exists) {
            $conversation->members_count = $conversation->kind === 'dm' ? 2 : 0;
        }

        $interlocutor = null;

        if ($conversation->kind === 'dm' && $interlocutorVkId !== null && $interlocutorVkId > 0) {
            $interlocutor = $this->communities->upsertUser(['id' => $interlocutorVkId]);
            $conversation->interlocutor_user_id = $interlocutor->id;
        }

        $conversation->save();

        if ($interlocutor !== null) {
            $this->rememberMember($conversation, $interlocutor->vk_id, $interlocutor, null);
        }

        $this->rememberMember($conversation, -$group->vk_id, null, null, $group->name);

        return $conversation;
    }

    private function rememberMember(
        Conversation $conversation,
        int $memberVkId,
        ?VkUser $user,
        ?Carbon $lastMessageAt,
        ?string $displayName = null,
        bool $isAdmin = false,
    ): void {
        if ($user === null && $memberVkId > 0) {
            $user = $this->communities->upsertUser(['id' => $memberVkId]);
        }

        $member = ConversationMember::firstOrNew([
            'conversation_id' => $conversation->id,
            'member_vk_id' => $memberVkId,
        ]);
        $member->user_id = $user?->id;
        $member->is_current = true;

        if ($displayName !== null && $displayName !== '') {
            $member->display_name = $displayName;
        }

        if ($isAdmin) {
            $member->is_admin = true;
        }

        if ($lastMessageAt !== null) {
            $member->last_message_at = $lastMessageAt;
        }

        $member->save();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function saveMessage(Conversation $conversation, VkGroup $group, array $attributes, array $source): DialogMessage
    {
        $attachments = $this->attachmentsOf($source);
        $message = new DialogMessage($attributes);
        $message->conversation_id = $conversation->id;
        $message->group_id = $group->id;
        $message->attachment_count = count($attachments);
        $message->save();

        foreach ($attachments as $attachment) {
            $attachment->message_id = $message->id;
            $attachment->save();
        }

        return $message;
    }

    private function replaceAttachments(DialogMessage $message, array $source): void
    {
        $message->attachments()->delete();
        $attachments = $this->attachmentsOf($source);
        $message->attachment_count = count($attachments);
        $message->save();

        foreach ($attachments as $attachment) {
            $attachment->message_id = $message->id;
            $attachment->save();
        }
    }

    /**
     * @return array<int, DialogAttachment>
     */
    private function attachmentsOf(array $message): array
    {
        $rows = [];

        foreach ($message['attachments'] ?? [] as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }

            $type = (string) ($attachment['type'] ?? '');
            $object = is_array($attachment[$type] ?? null) ? $attachment[$type] : [];

            if ($type === '') {
                continue;
            }

            $rows[] = new DialogAttachment([
                'type' => $type,
                'owner_vk_id' => isset($object['owner_id']) ? (int) $object['owner_id'] : null,
                'media_id' => isset($object['id']) ? (int) $object['id'] : null,
                'title' => $this->attachmentTitle($type, $object),
                'url' => $this->attachmentUrl($type, $object),
                'raw' => $attachment,
            ]);
        }

        return $rows;
    }

    private function attachmentTitle(string $type, array $object): ?string
    {
        $title = match ($type) {
            'doc', 'link', 'audio', 'video' => $object['title'] ?? null,
            'audio_message' => 'голосовое',
            'wall' => isset($object['owner_id'], $object['id']) ? 'wall'.$object['owner_id'].'_'.$object['id'] : null,
            default => $object['text'] ?? null,
        };

        $title = trim((string) $title);

        return $title === '' ? null : mb_substr($title, 0, 250);
    }

    private function attachmentUrl(string $type, array $object): ?string
    {
        $sizes = $object['sizes'] ?? $object['images'] ?? [];
        $best = null;
        $width = -1;

        foreach ($sizes as $size) {
            if (!is_array($size) || empty($size['url'])) {
                continue;
            }

            $candidate = (int) ($size['width'] ?? 0);

            if ($candidate >= $width) {
                $width = $candidate;
                $best = (string) $size['url'];
            }
        }

        if ($best !== null) {
            return $best;
        }

        foreach (['url', 'player'] as $key) {
            if (!empty($object[$key]) && is_string($object[$key])) {
                return $object[$key];
            }
        }

        if ($type === 'wall' && isset($object['owner_id'], $object['id'])) {
            return 'https://vk.com/wall'.$object['owner_id'].'_'.$object['id'];
        }

        return null;
    }

    private function refreshProfiles(VkClient $client, Conversation $conversation): void
    {
        $ids = $conversation->members()
            ->where('member_vk_id', '>', 0)
            ->pluck('member_vk_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return;
        }

        $response = $client->call('users.get', [
            'user_ids' => implode(',', array_slice($ids, 0, 100)),
            'fields' => config('vk.user_fields'),
        ]);

        $profiles = array_is_list($response) ? $response : ($response['items'] ?? []);

        foreach ($profiles as $profile) {
            if (!is_array($profile) || empty($profile['id'])) {
                continue;
            }

            $user = $this->communities->upsertUser($profile);
            $name = trim($user->first_name.' '.$user->last_name);
            ConversationMember::query()
                ->where('conversation_id', $conversation->id)
                ->where('member_vk_id', $user->vk_id)
                ->update([
                    'user_id' => $user->id,
                    'display_name' => $name !== '' ? $name : null,
                ]);
        }
    }

    private function pullHistory(VkClient $client, VkGroup $group, Conversation $conversation): void
    {
        if ($conversation->kind !== 'chat') {
            return;
        }

        $offset = 0;
        $pages = 0;

        while ($pages < 20) {
            $response = $client->call('messages.getHistory', [
                'peer_id' => $conversation->peer_id,
                'count' => 200,
                'offset' => $offset,
                'extended' => 1,
                'fields' => config('vk.user_fields'),
            ]);

            foreach ($response['profiles'] ?? [] as $profile) {
                if (is_array($profile) && !empty($profile['id'])) {
                    $this->communities->upsertUser($profile);
                }
            }

            $items = $response['items'] ?? [];

            if (!is_array($items) || $items === []) {
                break;
            }

            $oldest = null;

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $this->storeHistoryMessage($group, $item);

                if (!empty($item['date'])) {
                    $date = Carbon::createFromTimestamp((int) $item['date']);
                    $oldest = $oldest === null || $date->lessThan($oldest) ? $date : $oldest;
                }
            }

            $pages++;
            $offset += count($items);

            if (count($items) < 200) {
                break;
            }

            if ($oldest !== null && $oldest->lessThan(Carbon::now()->subDays(90))) {
                break;
            }
        }
    }

    private function refreshConversation(VkClient $client, Conversation $conversation): void
    {
        if ($conversation->members_synced_at !== null && $conversation->members_synced_at->gt(Carbon::now()->subHours(6))) {
            return;
        }

        $info = $client->call('messages.getConversationsById', [
            'peer_ids' => (string) $conversation->peer_id,
        ]);
        $item = $info['items'][0] ?? null;

        if (is_array($item)) {
            $title = $item['chat_settings']['title'] ?? null;
            $count = $item['chat_settings']['members_count'] ?? null;

            if (is_string($title) && $title !== '') {
                $conversation->title = $title;
            }

            if ($count !== null) {
                $conversation->members_count = (int) $count;
            }
        }

        if ($conversation->kind === 'chat') {
            try {
                $this->refreshMembers($client, $conversation);
            } catch (VkApiException $e) {
                Log::info('chat members were not loaded', [
                    'peer_id' => $conversation->peer_id,
                    'error' => $e->getMessage(),
                ]);
            }

            $group = $conversation->group;

            if ($group !== null) {
                try {
                    $this->pullHistory($client, $group, $conversation);
                } catch (Throwable $e) {
                    Log::warning('history import failed', [
                        'peer_id' => $conversation->peer_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $conversation->members_synced_at = Carbon::now();
        $conversation->save();
    }

    private function refreshMembers(VkClient $client, Conversation $conversation): void
    {
        $response = $client->call('messages.getConversationMembers', [
            'peer_id' => $conversation->peer_id,
            'fields' => config('vk.user_fields'),
        ]);
        $seen = [];
        $groupNames = [];

        foreach ($response['groups'] ?? [] as $community) {
            if (!is_array($community) || empty($community['id'])) {
                continue;
            }

            $groupNames[-1 * (int) $community['id']] = trim((string) ($community['name'] ?? ''));
        }

        foreach ($response['profiles'] ?? [] as $profile) {
            if (is_array($profile) && !empty($profile['id'])) {
                $this->communities->upsertUser($profile);
            }
        }

        foreach ($response['items'] ?? [] as $item) {
            if (!is_array($item) || !isset($item['member_id'])) {
                continue;
            }

            $memberId = (int) $item['member_id'];
            $seen[] = $memberId;
            $user = $memberId > 0 ? VkUser::where('vk_id', $memberId)->first() : null;
            $name = $user !== null ? trim($user->first_name.' '.$user->last_name) : ($groupNames[$memberId] ?? null);
            $this->rememberMember(
                $conversation,
                $memberId,
                $user,
                null,
                $name !== '' ? $name : null,
                (bool) ($item['is_admin'] ?? false) || (bool) ($item['is_owner'] ?? false),
            );
        }

        if ($seen !== []) {
            $conversation->members()->whereNotIn('member_vk_id', $seen)->update(['is_current' => false]);
            $conversation->members_count = count($seen);
        }
    }

    private function occurredAt(ActivityEvent $event, array $message): Carbon
    {
        if (!empty($message['date'])) {
            return Carbon::createFromTimestamp((int) $message['date']);
        }

        return $event->occurred_at ?? Carbon::now();
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function positiveInt(mixed $value): ?int
    {
        $number = (int) $value;

        return $number > 0 ? $number : null;
    }
}
