<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('vk_groups')->cascadeOnDelete();
            $table->unsignedBigInteger('peer_id');
            $table->string('kind', 8);
            $table->string('title')->nullable();
            $table->foreignId('interlocutor_user_id')->nullable()->constrained('vk_users')->nullOnDelete();
            $table->unsignedInteger('members_count')->default(0);
            $table->unsignedBigInteger('last_read_cmid')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamp('members_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['group_id', 'peer_id']);
        });

        Schema::create('conversation_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->bigInteger('member_vk_id');
            $table->foreignId('user_id')->nullable()->constrained('vk_users')->nullOnDelete();
            $table->string('display_name')->nullable();
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_current')->default(true);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'member_vk_id']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('vk_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('vk_users')->nullOnDelete();
            $table->bigInteger('from_vk_id');
            $table->string('direction', 3);
            $table->unsignedBigInteger('cmid')->nullable();
            $table->unsignedBigInteger('vk_message_id')->nullable();
            $table->unsignedBigInteger('reply_to_cmid')->nullable();
            $table->foreignId('in_reply_to_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->text('text')->nullable();
            $table->unsignedInteger('attachment_count')->default(0);
            $table->foreignId('activity_event_id')->nullable()->unique()->constrained('activity_events')->nullOnDelete();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'occurred_at']);
            $table->index(['user_id', 'occurred_at']);
            $table->index(['direction', 'occurred_at']);
            $table->unique(['conversation_id', 'direction', 'cmid']);
        });

        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->string('type', 32);
            $table->bigInteger('owner_vk_id')->nullable();
            $table->bigInteger('media_id')->nullable();
            $table->string('title')->nullable();
            $table->text('url')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['message_id', 'type']);
        });

        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW bot_card AS
            SELECT
                g.vk_id,
                g.name,
                g.screen_name,
                g.members_count AS subscribers,
                (SELECT COUNT(*) FROM conversations c WHERE c.group_id = g.id AND c.kind = 'dm') AS direct_dialogs,
                (SELECT COUNT(*) FROM conversations c WHERE c.group_id = g.id AND c.kind = 'chat') AS group_chats,
                (SELECT COUNT(*) FROM messages m WHERE m.group_id = g.id AND m.direction = 'in') AS questions,
                (SELECT COUNT(*) FROM messages m WHERE m.group_id = g.id AND m.direction = 'out') AS answers,
                (SELECT COUNT(*) FROM messages m WHERE m.group_id = g.id AND m.direction = 'in' AND EXISTS (
                    SELECT 1 FROM messages r WHERE r.in_reply_to_message_id = m.id
                )) AS answered
            FROM vk_groups g
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW dialog_summary AS
            SELECT
                c.id,
                g.name AS bot,
                c.kind,
                c.peer_id,
                CASE
                    WHEN c.kind = 'dm' THEN NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), '')
                    ELSE COALESCE(c.title, CONCAT('Беседа ', c.peer_id))
                END AS title,
                c.members_count,
                (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id AND m.direction = 'in') AS inbound,
                (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id AND m.direction = 'out') AS outbound,
                (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id AND m.direction = 'in' AND EXISTS (
                    SELECT 1 FROM messages r WHERE r.in_reply_to_message_id = m.id
                )) AS answered,
                (SELECT COUNT(*) FROM message_attachments a JOIN messages m ON m.id = a.message_id WHERE m.conversation_id = c.id) AS attachments,
                c.last_message_at
            FROM conversations c
            JOIN vk_groups g ON g.id = c.group_id
            LEFT JOIN vk_users u ON u.id = c.interlocutor_user_id
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW chat_roster AS
            SELECT
                g.name AS bot,
                c.kind,
                COALESCE(c.title, CONCAT('Беседа ', c.peer_id)) AS dialog,
                c.peer_id,
                cm.member_vk_id,
                COALESCE(cm.display_name, NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), '')) AS person,
                cm.is_admin,
                cm.is_current,
                cm.last_message_at
            FROM conversation_members cm
            JOIN conversations c ON c.id = cm.conversation_id
            JOIN vk_groups g ON g.id = c.group_id
            LEFT JOIN vk_users u ON u.id = cm.user_id
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW message_feed AS
            SELECT
                m.id,
                g.name AS bot,
                c.kind,
                CASE
                    WHEN c.kind = 'dm' THEN NULLIF(TRIM(CONCAT(COALESCE(iu.first_name, ''), ' ', COALESCE(iu.last_name, ''))), '')
                    ELSE COALESCE(c.title, CONCAT('Беседа ', c.peer_id))
                END AS dialog,
                m.direction,
                CASE
                    WHEN m.direction = 'out' THEN g.name
                    ELSE COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''), CONCAT('id', m.from_vk_id))
                END AS speaker,
                m.text,
                m.attachment_count,
                (SELECT GROUP_CONCAT(a.type ORDER BY a.id SEPARATOR ',') FROM message_attachments a WHERE a.message_id = m.id) AS attachment_types,
                m.in_reply_to_message_id,
                m.occurred_at
            FROM messages m
            JOIN conversations c ON c.id = m.conversation_id
            JOIN vk_groups g ON g.id = m.group_id
            LEFT JOIN vk_users u ON u.id = m.user_id
            LEFT JOIN vk_users iu ON iu.id = c.interlocutor_user_id
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW daily_activity AS
            SELECT
                g.name AS bot,
                DATE(m.occurred_at) AS day,
                m.direction,
                COUNT(*) AS messages,
                SUM(m.attachment_count > 0) AS with_media,
                COUNT(DISTINCT m.user_id) AS speakers
            FROM messages m
            JOIN vk_groups g ON g.id = m.group_id
            GROUP BY g.name, DATE(m.occurred_at), m.direction
        SQL);
    }

    public function down(): void
    {
        foreach (['daily_activity', 'message_feed', 'chat_roster', 'dialog_summary', 'bot_card'] as $view) {
            DB::statement('DROP VIEW IF EXISTS '.$view);
        }

        Schema::dropIfExists('message_attachments');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_members');
        Schema::dropIfExists('conversations');
    }
};
