<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\NotificationType;
use App\Enums\UserUpdate;
use App\Events\UsersUpdated;
use App\Exceptions\GuildModeratorRemoved;
use App\Models\Guild;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class UpdateGuildNotifications
{
    public function handle(User $user, Guild $guild, bool $enabled): void
    {
        DB::transaction(function () use ($user, $guild, $enabled): void {
            $fresh = Guild::query()->whereKey($guild->id)->lockForUpdate()->firstOrFail();
            if ($fresh->user_id !== $user->id) {
                throw new GuildModeratorRemoved;
            }

            $fresh->update(['moderator_notifications_enabled' => $enabled]);
            // Disabling is definitive: re-enabling does not resurrect old alerts or backfill posts.
            if (! $enabled) {
                Notification::query()->where('recipient_id', $user->id)->where('guild_id', $guild->id)->where('type', NotificationType::GuildPost)->delete();
            }

            $guild->setRawAttributes($fresh->getAttributes(), sync: true);
            event(new UsersUpdated([$user->id], UserUpdate::Guilds));
            event(new UsersUpdated([$user->id], UserUpdate::Notifications));
        });
    }
}
