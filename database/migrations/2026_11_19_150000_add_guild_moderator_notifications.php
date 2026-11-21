<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guilds', function (Blueprint $table): void {
            $table->boolean('moderator_notifications_enabled')->default(false);
        });
        Schema::table('notifications', function (Blueprint $table): void {
            $table->foreignUuid('guild_id')->nullable()->constrained()->cascadeOnDelete();
            $table->index('guild_id');
        });
    }
};
