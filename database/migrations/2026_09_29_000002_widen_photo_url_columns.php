<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE vk_groups MODIFY photo_url TEXT NULL');
        DB::statement('ALTER TABLE vk_users MODIFY photo_url TEXT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE vk_groups MODIFY photo_url VARCHAR(255) NULL');
        DB::statement('ALTER TABLE vk_users MODIFY photo_url VARCHAR(255) NULL');
    }
};
