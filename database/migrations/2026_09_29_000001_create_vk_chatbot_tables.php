<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vk_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vk_id')->unique();
            $table->string('name');
            $table->string('screen_name')->nullable();
            $table->text('description')->nullable();
            $table->string('type', 32)->nullable();
            $table->text('photo_url')->nullable();
            $table->unsignedInteger('members_count')->default(0);
            $table->unsignedTinyInteger('is_closed')->default(0);
            $table->string('confirmation_code');
            $table->string('secret_key', 50);
            $table->text('access_token');
            $table->json('raw')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('vk_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vk_id')->unique();
            $table->string('first_name')->default('');
            $table->string('last_name')->default('');
            $table->string('screen_name')->nullable();
            $table->text('photo_url')->nullable();
            $table->unsignedTinyInteger('sex')->nullable();
            $table->string('city')->nullable();
            $table->boolean('can_write_private_message')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });

        Schema::create('vk_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('vk_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('vk_users')->cascadeOnDelete();
            $table->string('role', 32)->default('member');
            $table->boolean('is_member')->nullable();
            $table->boolean('messages_allowed')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['group_id', 'user_id']);
        });

        Schema::create('activity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->nullable()->constrained('vk_groups')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('vk_users')->nullOnDelete();
            $table->string('actor', 16);
            $table->string('event_type', 64);
            $table->string('vk_event_id', 128)->nullable()->unique();
            $table->unsignedBigInteger('peer_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['group_id', 'occurred_at']);
            $table->index(['event_type', 'actor']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_events');
        Schema::dropIfExists('vk_group_members');
        Schema::dropIfExists('vk_users');
        Schema::dropIfExists('vk_groups');
    }
};
