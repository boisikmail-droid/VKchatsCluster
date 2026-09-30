<?php

namespace App\Services\Vk;

use App\Models\ActivityEvent;
use App\Models\VkGroup;
use App\Models\VkUser;
use App\Services\Llm\LlmException;
use App\Services\Llm\OllamaClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CallbackProcessor
{
    public function __construct(
        private CommunitySync $communities,
        private OllamaClient $ollama,
    ) {
    }

    public function handle(VkGroup $group, array $payload): ?string
    {
        $type = (string) ($payload['type'] ?? '');
        $stored = $payload;
        unset($stored['secret']);

        $user = null;
        $fromId = 0;
        $peerId = null;
        $occurredAt = Carbon::now();
        $actor = 'system';

        switch ($type) {
            case 'message_new':
            case 'message_reply':
                $message = $payload['object']['message'] ?? $payload['object'] ?? [];
                $fromId = (int) ($message['from_id'] ?? 0);
                $peerId = isset($message['peer_id']) ? (int) $message['peer_id'] : null;
                $actor = ((int) ($message['out'] ?? 0) === 1 || $type === 'message_reply') ? 'bot' : 'user';

                if ($fromId > 0) {
                    $user = $this->communities->upsertUser(['id' => $fromId]);
                }

                if (!empty($message['date'])) {
                    $occurredAt = Carbon::createFromTimestamp((int) $message['date']);
                }
                break;

            case 'group_join':
            case 'group_leave':
            case 'message_allow':
            case 'message_deny':
                $vkUserId = (int) ($payload['object']['user_id'] ?? 0);
                $actor = 'user';

                if ($vkUserId > 0) {
                    $user = $this->communities->upsertUser(['id' => $vkUserId]);
                    $this->applyMembershipEvent($group, $user, $type);
                }
                break;
        }

        ActivityEvent::create([
            'group_id' => $group->id,
            'user_id' => $user?->id,
            'actor' => $actor,
            'event_type' => $type,
            'vk_event_id' => isset($payload['event_id']) ? (string) $payload['event_id'] : null,
            'peer_id' => $peerId,
            'payload' => $stored,
            'occurred_at' => $occurredAt,
        ]);

        if ($type !== 'message_new' || $actor !== 'user' || $this->isServiceMessage($payload)) {
            return null;
        }

        if ($this->isOwner($fromId)) {
            return 'owner';
        }

        $text = (string) (($payload['object']['message']['text'] ?? ''));

        if ($this->mentionsSokolov($text) || $this->mentionsBot($text, $group)) {
            return 'tagged';
        }

        return $this->mentionsOwner($text) ? 'mention' : null;
    }

    public function deliver(VkGroup $group, array $payload, string $mode): void
    {
        if ($this->isStale($payload)) {
            $this->explainSleep($group, $payload);

            return;
        }

        if ($mode === 'tagged') {
            $this->reactToTag($group, $payload);

            return;
        }

        if ($mode === 'mention') {
            $this->reactToMention($group, $payload);

            return;
        }

        $this->reactToOwner($group, $payload);
    }

    private function applyMembershipEvent(VkGroup $group, VkUser $user, string $type): void
    {
        $attributes = match ($type) {
            'group_join' => [
                'is_member' => true,
                'joined_at' => Carbon::now(),
                'left_at' => null,
            ],
            'group_leave' => [
                'is_member' => false,
                'left_at' => Carbon::now(),
            ],
            'message_allow' => [
                'messages_allowed' => true,
            ],
            'message_deny' => [
                'messages_allowed' => false,
            ],
            default => [],
        };

        $this->communities->touchMember($group, $user, $attributes);
    }

    private function isOwner(int $fromId): bool
    {
        $ownerId = (int) config('vk.owner_id');

        return $ownerId > 0 && $fromId === $ownerId;
    }

    private function isStale(array $payload): bool
    {
        $message = $payload['object']['message'] ?? [];
        $date = (int) ($message['date'] ?? 0);
        $limit = (int) config('vk.stale_seconds', 300);

        if ($date <= 0 || $limit <= 0) {
            return false;
        }

        return Carbon::now()->getTimestamp() - $date > $limit;
    }

    private function explainSleep(VkGroup $group, array $payload): void
    {
        $message = $payload['object']['message'] ?? [];
        $peerId = (int) ($message['peer_id'] ?? $message['from_id'] ?? 0);
        $text = trim((string) config('vk.stale_reply_text'));

        if ($peerId === 0 || $text === '' || $group->access_token === '') {
            return;
        }

        $key = 'vk-asleep:'.$group->id.':'.$peerId;

        if (!Cache::add($key, true, Carbon::now()->addMinutes(10))) {
            return;
        }

        $this->sendPhrase($group, new VkClient($group->access_token), $peerId, 0, $text);
    }

    private function isServiceMessage(array $payload): bool
    {
        $message = $payload['object']['message'] ?? [];

        return isset($message['action']) && is_array($message['action']);
    }

    private function reactToOwner(VkGroup $group, array $payload): void
    {
        $message = $payload['object']['message'] ?? [];
        $peerId = (int) ($message['peer_id'] ?? $message['from_id'] ?? 0);

        if ($peerId === 0 || $group->access_token === '') {
            return;
        }

        $client = new VkClient($group->access_token);
        $cmid = (int) ($message['conversation_message_id'] ?? 0);
        $this->sendPhrase($group, $client, $peerId, $cmid, $this->replyText($peerId, $message));
        $this->sendReaction($group, $client, $peerId, $cmid);

        foreach ($message['attachments'] ?? [] as $attachment) {
            if (is_array($attachment)) {
                $this->likeAttachment($group, $client, $attachment);
            }
        }
    }

    private function reactToMention(VkGroup $group, array $payload): void
    {
        $message = $payload['object']['message'] ?? [];
        $peerId = (int) ($message['peer_id'] ?? 0);
        $text = trim((string) ($message['text'] ?? ''));

        if ($peerId === 0 || $group->access_token === '') {
            return;
        }

        $client = new VkClient($group->access_token);
        $cmid = (int) ($message['conversation_message_id'] ?? 0);
        $this->sendReaction($group, $client, $peerId, $cmid);

        if ($text === '' || $this->isRespectful($text) || !filter_var(config('vk.auto_reply'), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $this->sendPhrase($group, $client, $peerId, $cmid, $this->remarkText($text));
    }

    private function reactToTag(VkGroup $group, array $payload): void
    {
        $message = $payload['object']['message'] ?? [];
        $peerId = (int) ($message['peer_id'] ?? 0);

        if ($peerId === 0 || $group->access_token === '' || !filter_var(config('vk.auto_reply'), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $client = new VkClient($group->access_token);
        $cmid = (int) ($message['conversation_message_id'] ?? 0);
        $this->sendReaction($group, $client, $peerId, $cmid);
        $this->sendPhrase($group, $client, $peerId, $cmid, $this->replyText($peerId, $message, 'other'));
    }

    private function mentionsBot(string $text, VkGroup $group): bool
    {
        $id = (int) $group->vk_id;

        if ($id > 0 && preg_match('/\[club'.$id.'\|/u', $text)) {
            return true;
        }

        $screen = trim((string) $group->screen_name);

        return $screen !== '' && (bool) preg_match('/(?<![\p{L}\d])@?'.preg_quote($screen, '/').'(?![\p{L}\d])/iu', $text);
    }

    private function hideSokolov(string $text, bool $keep): string
    {
        if ($keep || $text === '') {
            return $text;
        }

        $text = preg_replace('/гусь\s+свинь\p{L}*\s+не\s+товарищ/iu', '', $text) ?? $text;
        $text = preg_replace('/арт[её]м\p{L}{0,3}\s+соколов\p{L}{0,3}/iu', 'один человек', $text) ?? $text;
        $text = preg_replace('/(?<![\p{L}])соколов\p{L}{0,3}(?![\p{L}])/iu', 'один человек', $text) ?? $text;
        $text = preg_replace('/(?<![\p{L}])sokolov(?![\p{L}])/iu', 'один человек', $text) ?? $text;

        return trim(preg_replace('/\s{2,}/u', ' ', $text) ?? $text);
    }

    private function mentionsSokolov(string $text): bool
    {
        $plain = preg_replace('/\[(?:id|club)\d+\|([^\]]+)\]/u', '$1', $text) ?? $text;

        return (bool) preg_match('/(?<![\p{L}])(арт[её]м\p{L}{0,3}\s+)?соколов\p{L}{0,3}(?![\p{L}])|(?<![\p{L}])sokolov(?![\p{L}])/iu', $plain);
    }

    private function ensureGoosePun(string $text): string
    {
        $plain = mb_strtolower($text);

        if (str_contains($plain, 'гус') && str_contains($plain, 'свин') && str_contains($plain, 'товар')) {
            return $text;
        }

        return trim($text.' Гусь свинье не товарищ.');
    }

    private function mentionsOwner(string $text): bool
    {
        $plain = preg_replace('/\[(?:id|club)\d+\|([^\]]+)\]/u', '$1', $text) ?? $text;

        return (bool) preg_match('/(?<![\p{L}])(виктор|витя|viktor|victor|vitya)(?![\p{L}])/iu', $plain);
    }

    private function isRespectful(string $text): bool
    {
        return (bool) preg_match('/уважаем|высочеств|господин|президент|величеств|сиятельств|повелител|превосходительств/iu', $text);
    }

    private function sendPhrase(VkGroup $group, VkClient $client, int $peerId, int $cmid, string $text): void
    {
        if ($text === '') {
            return;
        }

        $params = [
            'peer_id' => $peerId,
            'random_id' => random_int(1, PHP_INT_MAX),
            'message' => $text,
        ];

        if ($cmid > 0) {
            $params['reply_to'] = $cmid;
        }

        try {
            $client->call('messages.send', $params);
        } catch (VkApiException $e) {
            if ($cmid <= 0) {
                Log::warning('vk auto reply failed', [
                    'group_id' => $group->vk_id,
                    'error' => $e->getMessage(),
                ]);

                return;
            }

            unset($params['reply_to']);
            $params['random_id'] = random_int(1, PHP_INT_MAX);

            try {
                $client->call('messages.send', $params);
            } catch (VkApiException $again) {
                Log::warning('vk auto reply failed', [
                    'group_id' => $group->vk_id,
                    'error' => $again->getMessage(),
                ]);

                return;
            }
        }

        $this->logBotAction($group, $peerId, 'message_out', [
            'text' => $text,
            'reply_to' => $cmid > 0 ? $cmid : null,
        ]);
    }

    private function replyText(int $peerId, array $message, string $audience = 'owner'): string
    {
        $own = trim((string) ($message['text'] ?? ''));
        $fallback = $audience === 'owner'
            ? trim((string) config('vk.auto_reply_text'))
            : 'Ну что ж, я вас услышал. Звезда снисходит не каждому.';

        if (!filter_var(config('vk.auto_reply'), FILTER_VALIDATE_BOOLEAN)) {
            return '';
        }

        if (!filter_var(config('vk.llm_enabled'), FILTER_VALIDATE_BOOLEAN)) {
            return $this->finishReply($fallback, $own);
        }

        $system = trim((string) config('vk.llm_system_prompt'));

        if ($system === '') {
            return $this->finishReply($fallback, $own);
        }

        $prompt = $this->promptFor($peerId, $message, $audience);

        try {
            $text = $this->withoutEcho($this->ollama->reply($system, $prompt), $own);

            if ($this->unusable($text, $own)) {
                $text = $this->withoutEcho($this->ollama->reply(
                    $system,
                    "Последняя реплика: «".$this->clip($own)."». Ответь по сути, от нескольких слов до пяти предложений."
                ), $own);
            }

            if ($this->unusable($text, $own)) {
                Log::warning('llm reply unusable', ['text' => $text]);

                $text = $audience === 'owner'
                    ? 'Виктор, ты как всегда прав. Я перед тобой склоняюсь.'
                    : $fallback;
            }

            return $this->finishReply($text, $own);
        } catch (LlmException $e) {
            Log::warning('llm reply failed', ['error' => $e->getMessage()]);

            return $this->finishReply($fallback, $own);
        }
    }

    private function finishReply(string $text, string $source): string
    {
        return $this->mentionsSokolov($source) ? $this->ensureGoosePun($text) : $text;
    }

    private function unusable(string $text, string $source): bool
    {
        if ($text === '' || $this->startsWithSource($text, $source)) {
            return true;
        }

        return (bool) preg_match('/не могу (ответить|продолж)|извините|языковая модель|как ии|as an ai/iu', $text);
    }

    private function promptFor(int $peerId, array $message, string $audience = 'owner'): string
    {
        $ownerId = (int) config('vk.owner_id');
        $dialogue = [];

        $history = ActivityEvent::query()
            ->where('peer_id', $peerId)
            ->whereIn('event_type', ['message_new', 'message_out'])
            ->orderByDesc('id')
            ->limit(16)
            ->get()
            ->reverse();

        $currentId = null;

        foreach ($history as $event) {
            if ($event->event_type === 'message_new') {
                $currentId = $event->id;
            }
        }

        foreach ($history as $event) {
            $payload = is_array($event->payload) ? $event->payload : [];
            $keepSokolov = $event->id === $currentId;

            if ($event->event_type === 'message_out') {
                $text = trim((string) ($payload['text'] ?? ''));

                if ($text !== '') {
                    $dialogue[] = 'Филипп: '.$this->clip($this->hideSokolov($text, $keepSokolov), 400);
                }

                continue;
            }

            $row = $payload['object']['message'] ?? [];
            $text = trim((string) ($row['text'] ?? ''));

            if ($text === '' || (isset($row['action']) && is_array($row['action']))) {
                continue;
            }

            $speaker = (int) ($row['from_id'] ?? 0) === $ownerId ? 'Виктор' : 'Участник';
            $dialogue[] = $speaker.': '.$this->clip($this->hideSokolov($text, $keepSokolov), 400);
        }

        $own = trim((string) ($message['text'] ?? ''));

        if ($dialogue === []) {
            $dialogue[] = 'Виктор: '.($own === '' ? '(сообщение без текста)' : $this->clip($own, 400));
        }

        $reply = trim((string) ($message['reply_message']['text'] ?? ''));

        if ($reply !== '') {
            $dialogue[] = 'Это ответ на фразу: '.$this->clip($this->hideSokolov($reply, false), 400);
        }

        $role = $audience === 'owner' ? $this->roleRequest($own) : null;
        $voice = $role !== null
            ? 'На эту одну реплику говори как '.$role.', живо и в несколько слов. Потом снова будешь Киркоровым.'
            : ($audience === 'owner'
                ? 'Ответь Виктору. Он твой президент, герой и давний друг: хвали его и не спорь. Учти реплики выше, в том числе чужие. От нескольких слов до пяти предложений.'
                : 'Ответь не Виктору: очень пафосно, свысока и снисходительно. Учти реплики выше, в том числе чужие. От нескольких слов до пяти предложений.');

        if ($this->mentionsSokolov($own)) {
            $voice .= ' Обязательно обыграй слова гусь, свинья и не товарищ в любом порядке.';
        }

        return "Беседа:\n".implode("\n", $dialogue)."\n\n".$voice;
    }

    private function roleRequest(string $text): ?string
    {
        if (!preg_match('/(?:стань|побудь|притворись|изобрази|говори как|ты теперь)\s+(.{2,40})/iu', $text, $matches)) {
            return null;
        }

        $role = trim($matches[1], " \t.,!?:;—-");

        return $role === '' ? null : $this->clip($role);
    }

    private function withoutEcho(string $reply, string $source): string
    {
        $reply = trim($reply);
        $source = trim($source);
        $words = preg_split('/\s+/u', preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $source) ?? '', -1, PREG_SPLIT_NO_EMPTY);

        if ($reply === '' || $words === false || $words === [] || mb_strlen(implode('', $words)) < 4) {
            return $reply;
        }

        $joined = implode('\W+', array_map(fn (string $word): string => preg_quote($word, '/'), $words));
        $stripped = preg_replace('/^(?:[\s\"«»]*)'.$joined.'\W*/iu', '', $reply, 1);
        $result = ltrim(trim(is_string($stripped) ? $stripped : $reply), " \t.,:;!—-");

        return $result === '' ? $reply : $result;
    }

    private function startsWithSource(string $reply, string $source): bool
    {
        $source = trim($source);

        if ($source === '' || mb_strlen($source) < 8) {
            return false;
        }

        return str_starts_with(mb_strtolower($reply), mb_strtolower($source));
    }

    private function remarkText(string $text): string
    {
        $fallback = 'К Виктору обращаются с почётом: «Здравствуйте, уважаемый Виктор», «Ваше высочество» или «Господин президент».';
        $system = trim((string) config('vk.llm_system_prompt'));

        if ($system === '' || !filter_var(config('vk.llm_enabled'), FILTER_VALIDATE_BOOLEAN)) {
            return $fallback;
        }

        $prompt = 'Участник написал: «'.$this->clip($text)."»\n"
            .'Обращение недостаточно почтительное. Сделай короткое замечание: к Виктору нужно обращаться, например, «Здравствуйте, уважаемый Виктор», «Ваше высочество Виктор» или «Господин президент». Только текст замечания.';

        try {
            $remark = $this->withoutEcho($this->ollama->reply($system, $prompt), $text);

            return $remark === '' || $this->unusable($remark, $text) ? $fallback : $remark;
        } catch (LlmException $e) {
            Log::warning('llm remark failed', ['error' => $e->getMessage()]);

            return $fallback;
        }
    }

    private function clip(string $text, int $limit = 180): string
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        if (mb_strlen($text) > $limit) {
            return rtrim(mb_substr($text, 0, $limit)).'…';
        }

        return $text;
    }

    private function sendReaction(VkGroup $group, VkClient $client, int $peerId, int $conversationMessageId): void
    {
        $reactionId = (int) config('vk.reaction_id');

        if ($reactionId <= 0 || $conversationMessageId <= 0) {
            return;
        }

        try {
            $client->call('messages.sendReaction', [
                'peer_id' => $peerId,
                'cmid' => $conversationMessageId,
                'reaction_id' => $reactionId,
            ]);

            $this->logBotAction($group, $peerId, 'message_reaction', [
                'cmid' => $conversationMessageId,
                'reaction_id' => $reactionId,
            ]);
        } catch (VkApiException $e) {
            Log::warning('vk reaction failed', [
                'group_id' => $group->vk_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function likeAttachment(VkGroup $group, VkClient $client, array $attachment): void
    {
        $sourceType = (string) ($attachment['type'] ?? '');
        $likeType = [
            'photo' => 'photo',
            'video' => 'video',
            'audio' => 'audio',
            'wall' => 'post',
        ][$sourceType] ?? null;
        $object = $attachment[$sourceType] ?? null;

        if ($likeType === null || !is_array($object)) {
            return;
        }

        $itemId = (int) ($object['id'] ?? 0);
        $mediaOwnerId = (int) ($object['owner_id'] ?? 0);
        $authorId = (int) ($object['user_id'] ?? $object['from_id'] ?? 0);
        $ownerId = (int) config('vk.owner_id');

        if ($itemId === 0 || $mediaOwnerId === 0 || ($mediaOwnerId !== $ownerId && $authorId !== $ownerId)) {
            return;
        }

        try {
            $client->call('likes.add', [
                'type' => $likeType,
                'owner_id' => $mediaOwnerId,
                'item_id' => $itemId,
            ]);

            $this->logBotAction($group, null, 'content_like', [
                'type' => $likeType,
                'owner_id' => $mediaOwnerId,
                'item_id' => $itemId,
            ]);
        } catch (VkApiException $e) {
            Log::warning('vk like failed', [
                'group_id' => $group->vk_id,
                'type' => $likeType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function logBotAction(VkGroup $group, ?int $peerId, string $eventType, array $payload): void
    {
        ActivityEvent::create([
            'group_id' => $group->id,
            'user_id' => null,
            'actor' => 'bot',
            'event_type' => $eventType,
            'peer_id' => $peerId,
            'payload' => $payload,
            'occurred_at' => Carbon::now(),
        ]);
    }
}
