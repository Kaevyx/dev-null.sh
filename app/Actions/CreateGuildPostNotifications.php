<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\CommitStatus;
use App\Enums\NotificationType;
use App\Enums\UserUpdate;
use App\Events\UsersUpdated;
use App\Models\Commit;
use App\Models\Guild;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CreateGuildPostNotifications
{
    /** Newly approved top-level board posts only; hashtag matches and replies are not board submissions. */
    public function handle(Commit ...$commits): void
    {
        $ids = array_map(fn (Commit $commit): string => $commit->id, array_filter($commits,
            fn (Commit $commit): bool => $commit->guild_id !== null && $commit->parent_id === null));
        if ($ids === []) {
            return;
        }

        DB::transaction(function () use ($ids): void {
            // Approval callers may hold models with their old status; read the saved state.
            $posts = Commit::query()->whereKey($ids)->where('status', CommitStatus::Approved)->whereNull('parent_id')->get();
            $guilds = Guild::query()->whereKey($posts->pluck('guild_id'))->orderBy('id')->sharedLock()->get();
            $recipients = [];
            foreach ($posts as $post) {
                $guild = $guilds->find($post->guild_id);
                if (! $guild instanceof Guild || ! $guild->moderator_notifications_enabled || $guild->user_id === null || $guild->user_id === $post->user_id) {
                    continue;
                }

                $recipient = User::query()->find($guild->user_id);
                if (! $recipient instanceof User || ! Commit::query()->whereKey($post->id)->visibleTo($recipient)->withoutMutedAuthorsFor($recipient)->exists()
                    || DB::table('guild_preferences')->where('guild_id', $guild->id)->where('user_id', $recipient->id)->whereNotNull('muted_at')->exists()) {
                    continue;
                }

                $inserted = Notification::query()->insertOrIgnore([
                    'id' => Str::uuid7()->toString(), 'recipient_id' => $recipient->id, 'actor_id' => $post->author_id,
                    'type' => NotificationType::GuildPost->value, 'commit_id' => $post->id, 'guild_id' => $guild->id,
                    'key' => 'guild_post:'.$post->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($inserted > 0) {
                    $recipients[] = $recipient->id;
                }
            }

            if ($recipients !== []) {
                event(new UsersUpdated(array_values(array_unique($recipients)), UserUpdate::Notifications));
            }
        });
    }
}
