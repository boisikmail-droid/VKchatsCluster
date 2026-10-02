<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW user_activity AS
            SELECT
                u.vk_id,
                COALESCE(
                    NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''),
                    CONCAT('id', u.vk_id)
                ) AS person,
                u.screen_name,
                c.kind,
                c.peer_id,
                CASE
                    WHEN c.kind = 'dm' THEN 'личная'
                    ELSE COALESCE(c.title, CONCAT('Беседа ', c.peer_id))
                END AS dialog,
                COUNT(*) AS messages,
                SUM(m.occurred_at >= (UTC_TIMESTAMP() - INTERVAL 7 DAY)) AS messages_7d,
                SUM(m.occurred_at >= (UTC_TIMESTAMP() - INTERVAL 30 DAY)) AS messages_30d,
                SUM(m.attachment_count > 0) AS with_media,
                MIN(m.occurred_at) AS first_message_at,
                MAX(m.occurred_at) AS last_message_at
            FROM messages m
            JOIN conversations c ON c.id = m.conversation_id
            JOIN vk_users u ON u.id = m.user_id
            WHERE m.direction = 'in'
            GROUP BY u.id, c.id
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS user_activity');
    }
};
