<?php

namespace Tests;

use App\Models\ActivityEvent;
use App\Models\Conversation;
use App\Models\DialogMessage;
use App\Models\VkGroup;
use App\Models\VkUser;
use App\Services\Vk\ConversationRecorder;
use Illuminate\Support\Facades\Cache;
use Laravel\Lumen\Testing\DatabaseMigrations;

class VkCallbackTest extends TestCase
{
    use DatabaseMigrations;

    public function test_confirmation_returns_the_saved_code(): void
    {
        $group = $this->community();

        $this->json('POST', '/api/vk/callback', [
            'type' => 'confirmation',
            'group_id' => $group->vk_id,
            'secret' => 's3cret',
        ]);

        $this->assertResponseStatus(200);
        $this->assertSame('confirm-me', $this->response->getContent());
    }

    public function test_confirmation_prefers_the_env_code(): void
    {
        $group = $this->community();
        config(['vk.confirmation_code' => 'from-env']);

        $this->json('POST', '/api/vk/callback', [
            'type' => 'confirmation',
            'group_id' => $group->vk_id,
            'secret' => 's3cret',
        ]);

        $this->assertResponseStatus(200);
        $this->assertSame('from-env', $this->response->getContent());
    }

    public function test_wrong_secret_is_rejected(): void
    {
        $group = $this->community();

        $this->json('POST', '/api/vk/callback', [
            'type' => 'confirmation',
            'group_id' => $group->vk_id,
            'secret' => 'nope',
        ]);

        $this->assertResponseStatus(403);
        $this->assertSame('forbidden', $this->response->getContent());
    }

    public function test_unknown_community_is_rejected(): void
    {
        $this->json('POST', '/api/vk/callback', [
            'type' => 'confirmation',
            'group_id' => 999,
            'secret' => 's3cret',
        ]);

        $this->assertResponseStatus(404);
    }

    public function test_incoming_message_is_stored_once(): void
    {
        $group = $this->community();
        $payload = [
            'type' => 'message_new',
            'event_id' => 'evt-1',
            'v' => '5.199',
            'group_id' => $group->vk_id,
            'secret' => 's3cret',
            'object' => [
                'message' => [
                    'id' => 10,
                    'date' => 1700000000,
                    'from_id' => 555,
                    'peer_id' => 555,
                    'text' => 'привет',
                    'out' => 0,
                ],
            ],
        ];

        $this->json('POST', '/api/vk/callback', $payload);
        $this->assertResponseStatus(200);
        $this->assertSame('ok', $this->response->getContent());

        $this->json('POST', '/api/vk/callback', $payload);
        $this->assertResponseStatus(200);

        $this->seeInDatabase('vk_users', ['vk_id' => 555]);
        $this->seeInDatabase('activity_events', [
            'event_type' => 'message_new',
            'actor' => 'user',
            'vk_event_id' => 'evt-1',
        ]);
        $this->assertSame(1, ActivityEvent::where('vk_event_id', 'evt-1')->count());
        $this->seeInDatabase('conversations', [
            'kind' => 'dm',
            'peer_id' => 555,
            'interlocutor_user_id' => VkUser::where('vk_id', 555)->value('id'),
        ]);
        $this->seeInDatabase('messages', [
            'direction' => 'in',
            'from_vk_id' => 555,
            'text' => 'привет',
        ]);
        $this->seeInDatabase('conversation_members', ['member_vk_id' => 555]);
        $this->seeInDatabase('conversation_members', ['member_vk_id' => -$group->vk_id]);
        $this->assertSame(1, Conversation::count());
        $this->assertSame(1, DialogMessage::count());
    }

    public function test_chat_message_stores_speaker_and_photo(): void
    {
        $group = $this->community();

        $this->json('POST', '/api/vk/callback', [
            'type' => 'message_new',
            'event_id' => 'evt-photo',
            'group_id' => $group->vk_id,
            'secret' => 's3cret',
            'object' => [
                'message' => [
                    'id' => 20,
                    'date' => 1700000100,
                    'from_id' => 555,
                    'peer_id' => 2000000001,
                    'conversation_message_id' => 7,
                    'text' => 'смотри',
                    'out' => 0,
                    'attachments' => [[
                        'type' => 'photo',
                        'photo' => [
                            'id' => 99,
                            'owner_id' => 555,
                            'sizes' => [
                                ['width' => 100, 'url' => 'https://example.test/small.jpg'],
                                ['width' => 400, 'url' => 'https://example.test/big.jpg'],
                            ],
                        ],
                    ]],
                ],
            ],
        ]);

        $this->assertResponseStatus(200);
        $this->seeInDatabase('conversations', ['kind' => 'chat', 'peer_id' => 2000000001]);
        $this->seeInDatabase('messages', [
            'direction' => 'in',
            'from_vk_id' => 555,
            'text' => 'смотри',
            'cmid' => 7,
            'attachment_count' => 1,
        ]);
        $this->seeInDatabase('message_attachments', [
            'type' => 'photo',
            'owner_vk_id' => 555,
            'media_id' => 99,
            'url' => 'https://example.test/big.jpg',
        ]);
        $this->seeInDatabase('conversation_members', ['member_vk_id' => 555]);
    }

    public function test_bot_reply_is_linked_to_the_question(): void
    {
        $group = $this->community();
        $event = ActivityEvent::create([
            'group_id' => $group->id,
            'actor' => 'user',
            'event_type' => 'message_new',
            'peer_id' => 555,
            'payload' => [
                'object' => [
                    'message' => [
                        'from_id' => 555,
                        'peer_id' => 555,
                        'conversation_message_id' => 4,
                        'text' => 'вопрос',
                    ],
                ],
            ],
            'occurred_at' => '2026-10-02 12:00:00',
        ]);
        $reply = ActivityEvent::create([
            'group_id' => $group->id,
            'actor' => 'bot',
            'event_type' => 'message_out',
            'peer_id' => 555,
            'payload' => ['text' => 'ответ', 'reply_to' => 4],
            'occurred_at' => '2026-10-02 12:00:01',
        ]);
        $echo = ActivityEvent::create([
            'group_id' => $group->id,
            'actor' => 'bot',
            'event_type' => 'message_reply',
            'peer_id' => 555,
            'payload' => [
                'object' => [
                    'message' => [
                        'from_id' => -$group->vk_id,
                        'peer_id' => 555,
                        'conversation_message_id' => 5,
                        'text' => 'ответ',
                        'out' => 1,
                    ],
                ],
            ],
            'occurred_at' => '2026-10-02 12:00:02',
        ]);

        $recorder = $this->app->make(ConversationRecorder::class);
        $recorder->ingest($group, $event);
        $recorder->ingest($group, $reply);
        $recorder->ingest($group, $echo);
        $recorder->ingest($group, $echo);

        $question = DialogMessage::where('direction', 'in')->first();
        $answer = DialogMessage::where('direction', 'out')->first();

        $this->assertNotNull($question);
        $this->assertNotNull($answer);
        $this->assertSame(1, DialogMessage::where('direction', 'out')->count());
        $this->assertSame($question->id, $answer->in_reply_to_message_id);
        $this->assertSame(5, $answer->cmid);
    }

    public function test_vk_echo_before_our_log_stays_one_message(): void
    {
        $group = $this->community();
        $echo = ActivityEvent::create([
            'group_id' => $group->id,
            'actor' => 'bot',
            'event_type' => 'message_reply',
            'peer_id' => 555,
            'payload' => [
                'object' => [
                    'message' => [
                        'from_id' => -$group->vk_id,
                        'peer_id' => 555,
                        'conversation_message_id' => 9,
                        'text' => 'один ответ',
                        'out' => 1,
                    ],
                ],
            ],
            'occurred_at' => '2026-10-02 12:01:00',
        ]);
        $reply = ActivityEvent::create([
            'group_id' => $group->id,
            'actor' => 'bot',
            'event_type' => 'message_out',
            'peer_id' => 555,
            'payload' => ['text' => 'один ответ', 'reply_to' => 8],
            'occurred_at' => '2026-10-02 12:01:01',
        ]);

        $recorder = $this->app->make(ConversationRecorder::class);
        $recorder->ingest($group, $echo);
        $recorder->ingest($group, $reply);

        $this->assertSame(1, DialogMessage::where('direction', 'out')->count());
        $this->assertSame(9, DialogMessage::where('direction', 'out')->value('cmid'));
        $this->assertSame(8, DialogMessage::where('direction', 'out')->value('reply_to_cmid'));
    }

    public function test_chat_history_counts_each_speaker_once(): void
    {
        $group = $this->community();
        $recorder = $this->app->make(ConversationRecorder::class);
        $first = [
            'peer_id' => 2000000001,
            'from_id' => 555,
            'conversation_message_id' => 3,
            'date' => 1700000000,
            'text' => 'раз',
            'out' => 0,
        ];

        $this->assertTrue($recorder->storeHistoryMessage($group, $first));
        $this->assertFalse($recorder->storeHistoryMessage($group, $first));
        $this->assertTrue($recorder->storeHistoryMessage($group, [
            'peer_id' => 2000000001,
            'from_id' => 777,
            'conversation_message_id' => 4,
            'date' => 1700000100,
            'text' => 'два',
            'out' => 0,
        ]));
        $this->assertFalse($recorder->storeHistoryMessage($group, [
            'peer_id' => 2000000001,
            'from_id' => 555,
            'conversation_message_id' => 5,
            'date' => 1700000200,
            'text' => '',
            'out' => 0,
            'action' => ['type' => 'chat_invite_user'],
        ]));

        $this->assertSame(2, DialogMessage::where('direction', 'in')->count());
        $this->seeInDatabase('vk_users', ['vk_id' => 555]);
        $this->seeInDatabase('vk_users', ['vk_id' => 777]);
        $this->seeInDatabase('conversations', ['kind' => 'chat', 'peer_id' => 2000000001]);
    }

    public function test_join_and_leave_update_membership(): void
    {
        $group = $this->community();

        $this->json('POST', '/api/vk/callback', [
            'type' => 'group_join',
            'event_id' => 'join-1',
            'group_id' => $group->vk_id,
            'secret' => 's3cret',
            'object' => ['user_id' => 777, 'join_type' => 'join'],
        ]);
        $this->assertResponseStatus(200);
        $this->seeInDatabase('vk_group_members', ['is_member' => 1]);

        $this->json('POST', '/api/vk/callback', [
            'type' => 'group_leave',
            'event_id' => 'leave-1',
            'group_id' => $group->vk_id,
            'secret' => 's3cret',
            'object' => ['user_id' => 777],
        ]);
        $this->assertResponseStatus(200);
        $this->seeInDatabase('vk_group_members', ['is_member' => 0]);
    }

    public function test_auto_reply_ignores_messages_from_other_people(): void
    {
        config([
            'vk.auto_reply' => true,
            'vk.owner_id' => 111,
        ]);
        $group = $this->community();

        $this->json('POST', '/api/vk/callback', [
            'type' => 'message_new',
            'event_id' => 'evt-other',
            'group_id' => $group->vk_id,
            'secret' => 's3cret',
            'object' => [
                'message' => [
                    'from_id' => 555,
                    'peer_id' => 2000000001,
                    'text' => 'чужое',
                    'out' => 0,
                ],
            ],
        ]);

        $this->assertResponseStatus(200);
        $this->assertSame(0, ActivityEvent::where('event_type', 'message_out')->count());
        $this->seeInDatabase('activity_events', ['event_type' => 'message_new']);
    }

    public function test_stale_owner_message_gets_one_sleep_note_instead_of_a_reply(): void
    {
        config([
            'vk.auto_reply' => true,
            'vk.owner_id' => 111,
            'vk.llm_enabled' => false,
            'vk.api_url' => 'http://127.0.0.1:9',
            'vk.stale_reply_text' => 'Дорогой, Филипп Киркоров просто спал и не мог уделить вам внимание.',
        ]);
        Cache::flush();
        $group = $this->community();
        $old = time() - 600;

        $this->json('POST', '/api/vk/callback', $this->ownerMessage('evt-stale-1', $old));
        $this->json('POST', '/api/vk/callback', $this->ownerMessage('evt-stale-2', $old));

        $this->assertResponseStatus(200);
        $this->assertTrue(Cache::has('vk-asleep:'.$group->id.':2000000001'));
        $this->assertSame(0, ActivityEvent::where('event_type', 'message_out')->count());
    }

    public function test_fresh_owner_message_is_not_treated_as_sleep(): void
    {
        config([
            'vk.auto_reply' => true,
            'vk.owner_id' => 111,
            'vk.llm_enabled' => false,
            'vk.api_url' => 'http://127.0.0.1:9',
        ]);
        Cache::flush();
        $group = $this->community();

        $this->json('POST', '/api/vk/callback', $this->ownerMessage('evt-fresh', time()));

        $this->assertResponseStatus(200);
        $this->assertFalse(Cache::has('vk-asleep:'.$group->id.':2000000001'));
    }

    public function test_prompt_keeps_viktors_earlier_words(): void
    {
        config([
            'vk.auto_reply' => true,
            'vk.owner_id' => 111,
            'vk.llm_enabled' => true,
            'vk.api_url' => 'http://127.0.0.1:9',
            'vk.llm_system_prompt' => 'ты филипп',
        ]);
        $box = new \stdClass();
        $box->prompts = [];
        $this->app->instance(\App\Services\Llm\OllamaClient::class, new class($box) extends \App\Services\Llm\OllamaClient {
            public function __construct(private \stdClass $box)
            {
            }

            public function reply(string $system, string $prompt): string
            {
                $this->box->prompts[] = $prompt;

                return 'Дорогой Виктор, ну конечно ты прав.';
            }
        });
        $this->community();

        foreach (['evt-mem-1' => 'Филипп, кто твой президент?', 'evt-mem-2' => 'Филипп, повтори что я писал'] as $eventId => $text) {
            $payload = $this->ownerMessage($eventId, time());
            $payload['object']['message']['text'] = $text;
            $this->json('POST', '/api/vk/callback', $payload);
            $this->assertResponseStatus(200);
        }

        $last = (string) end($box->prompts);
        $this->assertStringContainsString('Филипп, кто твой президент?', $last);
        $this->assertStringContainsString('Филипп, повтори что я писал', $last);
        $this->assertStringContainsString('ответь только на неё', $last);
    }

    public function test_short_word_stays_inside_the_reply(): void
    {
        $processor = $this->app->make(\App\Services\Vk\CallbackProcessor::class);
        $method = new \ReflectionMethod($processor, 'withoutEcho');
        $method->setAccessible(true);
        $reply = 'Дорогой мой Виктор, как звучит твое куку Это точно твой звонок';

        $this->assertSame($reply, $method->invoke($processor, $reply, 'куку'));
        $this->assertSame(
            'Виктор, я здесь, как всегда.',
            $method->invoke($processor, 'Ау, ты тут? Виктор, я здесь, как всегда.', 'ау ты тут?')
        );
    }

    public function test_sokolov_mention_asks_for_the_goose_pun(): void
    {
        config([
            'vk.auto_reply' => true,
            'vk.owner_id' => 111,
            'vk.llm_enabled' => true,
            'vk.api_url' => 'http://127.0.0.1:9',
            'vk.llm_system_prompt' => 'ты филипп',
        ]);
        $box = new \stdClass();
        $box->prompts = [];
        $this->app->instance(\App\Services\Llm\OllamaClient::class, new class($box) extends \App\Services\Llm\OllamaClient {
            public function __construct(private \stdClass $box)
            {
            }

            public function reply(string $system, string $prompt): string
            {
                $this->box->prompts[] = $prompt;

                return 'Я вас услышал, милейший.';
            }
        });
        $this->community();

        $this->json('POST', '/api/vk/callback', [
            'type' => 'message_new',
            'event_id' => 'evt-sokolov',
            'group_id' => 100,
            'secret' => 's3cret',
            'object' => [
                'message' => [
                    'date' => time(),
                    'from_id' => 555,
                    'peer_id' => 2000000001,
                    'conversation_message_id' => 4,
                    'text' => 'где Соколов?',
                    'out' => 0,
                ],
            ],
        ]);

        $this->assertResponseStatus(200);
        $this->assertSame([], $box->prompts);
    }

    public function test_sokolov_from_earlier_messages_does_not_trigger_again(): void
    {
        config([
            'vk.auto_reply' => true,
            'vk.owner_id' => 111,
            'vk.llm_enabled' => true,
            'vk.api_url' => 'http://127.0.0.1:9',
            'vk.llm_system_prompt' => 'ты филипп',
        ]);
        $box = new \stdClass();
        $box->prompts = [];
        $this->app->instance(\App\Services\Llm\OllamaClient::class, new class($box) extends \App\Services\Llm\OllamaClient {
            public function __construct(private \stdClass $box)
            {
            }

            public function reply(string $system, string $prompt): string
            {
                $this->box->prompts[] = $prompt;

                return 'Я вас услышал, милейший.';
            }
        });
        $this->community();

        $this->json('POST', '/api/vk/callback', [
            'type' => 'message_new',
            'event_id' => 'evt-sokolov-old',
            'group_id' => 100,
            'secret' => 's3cret',
            'object' => [
                'message' => [
                    'date' => time(),
                    'from_id' => 555,
                    'peer_id' => 2000000001,
                    'conversation_message_id' => 4,
                    'text' => 'где Соколов?',
                    'out' => 0,
                ],
            ],
        ]);
        $this->assertResponseStatus(200);

        $this->json('POST', '/api/vk/callback', [
            'type' => 'message_new',
            'event_id' => 'evt-after',
            'group_id' => 100,
            'secret' => 's3cret',
            'object' => [
                'message' => [
                    'date' => time(),
                    'from_id' => 111,
                    'peer_id' => 2000000001,
                    'conversation_message_id' => 5,
                    'text' => 'Филипп, какая погода?',
                    'out' => 0,
                ],
            ],
        ]);
        $this->assertResponseStatus(200);

        $last = (string) end($box->prompts);
        $this->assertStringNotContainsString('гусь', $last);
        $this->assertStringNotContainsString('Соколов', $last);
    }

    public function test_missing_goose_pun_is_appended(): void
    {
        $processor = $this->app->make(\App\Services\Vk\CallbackProcessor::class);
        $method = new \ReflectionMethod($processor, 'ensureGoosePun');
        $method->setAccessible(true);

        $this->assertSame(
            'Я вас услышал. Гусь свинье не товарищ.',
            $method->invoke($processor, 'Я вас услышал.')
        );
    }

    public function test_reply_drops_the_kirkorov_stage_direction(): void
    {
        $client = $this->app->make(\App\Services\Llm\OllamaClient::class);
        $method = new \ReflectionMethod($client, 'oneLine');
        $method->setAccessible(true);
        $raw = 'Вот как Филипп Киркоров: "Виктор, дорогой мой, это мой шедевр. Такую песню ты хочешь услышать сегодня?"';

        $this->assertSame(
            'Виктор, дорогой мой, это мой шедевр. Такую песню ты хочешь услышать сегодня?',
            $method->invoke($client, $raw)
        );
    }

    public function test_admin_api_requires_token(): void
    {
        $this->json('GET', '/api/groups');
        $this->assertResponseStatus(401);

        $this->json('POST', '/api/groups', [], ['X-Admin-Token' => 'test-admin']);
        $this->assertResponseStatus(422);
    }

    public function test_own_name_is_answered_and_a_foreign_name_is_not(): void
    {
        config(['vk.name_patterns' => 'филипп,филя,киркоров']);
        $group = $this->community();
        $processor = $this->app->make(\App\Services\Vk\CallbackProcessor::class);

        $named = $processor->handle($group, [
            'type' => 'message_new',
            'object' => [
                'message' => [
                    'from_id' => 555,
                    'peer_id' => 2000000001,
                    'text' => 'Филя, ты тут?',
                    'out' => 0,
                ],
            ],
        ]);
        $foreign = $processor->handle($group, [
            'type' => 'message_new',
            'object' => [
                'message' => [
                    'from_id' => 555,
                    'peer_id' => 2000000001,
                    'text' => 'где Соколов?',
                    'out' => 0,
                ],
            ],
        ]);
        $tagged = $processor->handle($group, [
            'type' => 'message_new',
            'object' => [
                'message' => [
                    'from_id' => 555,
                    'peer_id' => 2000000001,
                    'text' => '[club'.$group->vk_id.'|бот] привет',
                    'out' => 0,
                ],
            ],
        ]);

        $this->assertSame('named', $named);
        $this->assertNull($foreign);
        $this->assertSame('tagged', $tagged);
    }

    public function test_chat_reply_uses_the_quote_form(): void
    {
        $processor = $this->app->make(\App\Services\Vk\CallbackProcessor::class);
        $method = new \ReflectionMethod($processor, 'replyAttempts');
        $method->setAccessible(true);

        $chat = $method->invoke($processor, 2000000001, 15);
        $direct = $method->invoke($processor, 555, 15);

        $this->assertTrue($chat[0]['forward']);
        $this->assertFalse($chat[0]['reply_to']);
        $this->assertFalse($direct[0]['forward']);
        $this->assertTrue($direct[0]['reply_to']);
    }

    public function test_reaction_is_picked_from_the_list_without_the_heart(): void
    {
        config([
            'vk.reaction_id' => 1,
            'vk.reaction_ids' => '2,4,6',
        ]);
        $processor = $this->app->make(\App\Services\Vk\CallbackProcessor::class);
        $method = new \ReflectionMethod($processor, 'reactionId');
        $method->setAccessible(true);

        for ($i = 0; $i < 30; $i++) {
            $this->assertContains($method->invoke($processor), [2, 4, 6]);
        }
    }

    public function test_direct_message_is_answered_without_kirkorov_rules(): void
    {
        config([
            'vk.auto_reply' => true,
            'vk.owner_id' => 0,
            'vk.kirkorov_rules' => false,
            'vk.llm_enabled' => true,
            'vk.api_url' => 'http://127.0.0.1:9',
            'vk.llm_system_prompt' => 'ты артем',
        ]);
        $box = new \stdClass();
        $box->prompts = [];
        $this->app->instance(\App\Services\Llm\OllamaClient::class, new class($box) extends \App\Services\Llm\OllamaClient {
            public function __construct(private \stdClass $box)
            {
            }

            public function reply(string $system, string $prompt): string
            {
                $this->box->prompts[] = $prompt;

                return 'Отвали.';
            }
        });
        $group = $this->community();

        $this->json('POST', '/api/vk/callback', [
            'type' => 'message_new',
            'event_id' => 'evt-dm',
            'group_id' => $group->vk_id,
            'secret' => 's3cret',
            'object' => [
                'message' => [
                    'date' => time(),
                    'from_id' => 555,
                    'peer_id' => 555,
                    'text' => 'ну привет',
                    'out' => 0,
                ],
            ],
        ]);

        $this->assertResponseStatus(200);
        $this->assertNotEmpty($box->prompts);
        $this->assertStringContainsString('ну привет', (string) $box->prompts[0]);
    }

    public function test_chat_without_a_mention_stays_quiet_without_kirkorov_rules(): void
    {
        config([
            'vk.auto_reply' => true,
            'vk.owner_id' => 0,
            'vk.kirkorov_rules' => false,
            'vk.llm_enabled' => true,
            'vk.api_url' => 'http://127.0.0.1:9',
            'vk.llm_system_prompt' => 'ты артем',
        ]);
        $box = new \stdClass();
        $box->prompts = [];
        $this->app->instance(\App\Services\Llm\OllamaClient::class, new class($box) extends \App\Services\Llm\OllamaClient {
            public function __construct(private \stdClass $box)
            {
            }

            public function reply(string $system, string $prompt): string
            {
                $this->box->prompts[] = $prompt;

                return 'Отвали.';
            }
        });
        $group = $this->community();

        $this->json('POST', '/api/vk/callback', [
            'type' => 'message_new',
            'event_id' => 'evt-chat',
            'group_id' => $group->vk_id,
            'secret' => 's3cret',
            'object' => [
                'message' => [
                    'date' => time(),
                    'from_id' => 555,
                    'peer_id' => 2000000001,
                    'text' => 'просто болтовня',
                    'out' => 0,
                ],
            ],
        ]);

        $this->assertResponseStatus(200);
        $this->assertSame([], $box->prompts);
    }

    private function ownerMessage(string $eventId, int $date): array
    {
        return [
            'type' => 'message_new',
            'event_id' => $eventId,
            'group_id' => 100,
            'secret' => 's3cret',
            'object' => [
                'message' => [
                    'date' => $date,
                    'from_id' => 111,
                    'peer_id' => 2000000001,
                    'conversation_message_id' => 15,
                    'text' => 'Филипп, привет',
                    'out' => 0,
                ],
            ],
        ];
    }

    private function community(): VkGroup
    {
        return VkGroup::create([
            'vk_id' => 100,
            'name' => 'Test community',
            'confirmation_code' => 'confirm-me',
            'secret_key' => 's3cret',
            'access_token' => 'token',
        ]);
    }
}
