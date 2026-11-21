<?php

declare(strict_types=1);

use App\Actions\CreateGuildPostNotifications;
use App\Actions\DeleteGuildModerator;
use App\Enums\NotificationType;
use App\Enums\UserUpdate;
use App\Events\UsersUpdated;
use App\Models\Commit;
use App\Models\Guild;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
});

it('loads distinct moderators and profiles once across a batch of guild posts', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $guilds = [
        Guild::factory()->create(['user_id' => $first->id, 'moderator_notifications_enabled' => true]),
        Guild::factory()->create(['user_id' => $first->id, 'moderator_notifications_enabled' => true]),
        Guild::factory()->create(['user_id' => $second->id, 'moderator_notifications_enabled' => true]),
    ];
    $posts = [];
    foreach ($guilds as $guild) {
        $posts = [...$posts, ...Commit::factory()->count(4)->create(['guild_id' => $guild->id])->all()];
    }

    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        resolve(CreateGuildPostNotifications::class)->handle(...$posts);
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
    }

    foreach (['users', 'profiles'] as $table) {
        $reads = array_filter($queries, fn (array $query): bool => preg_match('/^select\s+(?:"'.$table.'"\.\*|\*)\s+from\s+"'.$table.'"\s/i', $query['query']) === 1);
        expect($reads)->toHaveCount(1);
    }

    expect(Notification::query()->where('type', NotificationType::GuildPost)->where('recipient_id', $first->id)->count())->toBe(8)
        ->and(Notification::query()->where('type', NotificationType::GuildPost)->where('recipient_id', $second->id)->count())->toBe(4);
});

it('refreshes the removed moderators unread notifications immediately', function (): void {
    $guild = Guild::factory()->create(['moderator_notifications_enabled' => true]);
    $ownerId = $guild->user_id;
    resolve(CreateGuildPostNotifications::class)->handle(Commit::factory()->create(['guild_id' => $guild->id]));
    expect(Notification::query()->where('recipient_id', $ownerId)->relevant()->unread()->count())->toBe(1);
    Event::fake([UsersUpdated::class]);

    resolve(DeleteGuildModerator::class)->handle($guild);

    Event::assertDispatched(UsersUpdated::class, fn (UsersUpdated $event): bool => $event->update === UserUpdate::Notifications && $event->userIds === [$ownerId]);
    expect(Notification::query()->where('recipient_id', $ownerId)->relevant()->unread()->count())->toBe(0);
});

it('does not announce a notification change when removal is a no-op', function (bool $paused): void {
    $guild = Guild::factory()->create();
    $replacement = $paused ? null : User::factory()->create()->id;
    Guild::query()->whereKey($guild->id)->update(['user_id' => $replacement]);
    if ($paused) {
        $guild->refresh();
    }

    Event::fake([UsersUpdated::class]);

    resolve(DeleteGuildModerator::class)->handle($guild);

    Event::assertNotDispatched(UsersUpdated::class, fn (UsersUpdated $event): bool => $event->update === UserUpdate::Notifications);
    expect($guild->refresh()->user_id)->toBe($replacement);
})->with([true, false]);
