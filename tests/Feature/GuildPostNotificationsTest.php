<?php

declare(strict_types=1);

use App\Actions\CreateCommit;
use App\Actions\CreateExport;
use App\Actions\CreateGuild;
use App\Actions\CreateGuildPostNotifications;
use App\Actions\DeleteGuildModerator;
use App\Actions\UpdateCommitStatuses;
use App\Actions\UpdateGuildNotifications;
use App\Enums\CommitStatus;
use App\Enums\Feature;
use App\Enums\NotificationType;
use App\Exceptions\GuildModeratorRemoved;
use App\Models\Commit;
use App\Models\Guild;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\User;
use App\Queries\NotificationGroupsQuery;
use App\Services\Features;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->withoutVite();
    Queue::fake();
    resolve(Features::class)->update(Feature::Guilds, true, false);
});

it('is off by default and lets only the current moderator opt in with a real boolean', function (): void {
    $guild = Guild::factory()->create();
    $owner = User::query()->findOrFail($guild->user_id);
    expect($guild->moderator_notifications_enabled)->toBeFalse();
    $this->actingAs(User::factory()->create())->put(route('guild-notification.update', $guild->handle), ['enabled' => true])->assertNotFound();
    $this->actingAs($owner)->putJson(route('guild-notification.update', $guild->handle), ['enabled' => 'yes'])->assertUnprocessable();
    $this->fromRoute('guild.show', $guild->handle)->put(route('guild-notification.update', $guild->handle), ['enabled' => true])->assertRedirect();
    expect($guild->refresh()->moderator_notifications_enabled)->toBeTrue();
    $this->get(route('guild.show', $guild->handle))->assertInertia(fn ($page) => $page->where('moderatorNotificationsEnabled', true));
    $this->actingAs(User::factory()->create())->get(route('guild.show', $guild->handle))->assertInertia(fn ($page) => $page->where('moderatorNotificationsEnabled', null));
});

it('honours feature availability and signed API ownership', function (): void {
    $guild = Guild::factory()->create();
    $owner = User::query()->findOrFail($guild->user_id);
    $key = apiKeyPairFor($owner);
    signedApiRequest($key, 'PUT', route('api-guild-notification.update', $guild->handle), ['enabled' => true])->assertOk()->assertJsonPath('enabled', true);
    signedApiRequest(apiKeyPairFor(User::factory()->create()), 'PUT', route('api-guild-notification.update', $guild->handle), ['enabled' => false])->assertNotFound();
    $this->putJson(route('api-guild-notification.update', $guild->handle), ['enabled' => false])->assertUnauthorized();
    resolve(Features::class)->update(Feature::Guilds, false, false);
    signedApiRequest($key, 'PUT', route('api-guild-notification.update', $guild->handle), ['enabled' => false])->assertNotFound();
});

it('announces immediate approval and delayed moderation approval exactly once', function (): void {
    config(['app.admins' => ['trusted-poster']]);
    $guild = Guild::factory()->create(['moderator_notifications_enabled' => true]);
    $trusted = User::factory()->create(['handle' => 'trusted-poster']);
    $approved = resolve(CreateCommit::class)->handle($trusted, 'A new board post.', guild: $guild);
    $pending = resolve(CreateCommit::class)->handle(User::factory()->create(), 'Waiting for approval.', guild: $guild);
    expect($approved->status)->toBe(CommitStatus::Approved)->and($pending->status)->toBe(CommitStatus::Pending)
        ->and(Notification::query()->where('type', NotificationType::GuildPost)->count())->toBe(1);
    resolve(UpdateCommitStatuses::class)->handle([$pending->id], CommitStatus::Approved);
    resolve(CreateGuildPostNotifications::class)->handle($approved, $pending);

    expect(Notification::query()->where('type', NotificationType::GuildPost)->count())->toBe(2);
    $notice = Notification::query()->where('type', NotificationType::GuildPost)->where('commit_id', $pending->id)->sole();
    expect($notice->recipient_id)->toBe($guild->user_id)->and($notice->guild_id)->toBe($guild->id);
});

it('does not backfill when enabling and includes older pending posts only when newly approved', function (): void {
    $guild = Guild::factory()->create();
    $owner = User::query()->findOrFail($guild->user_id);
    $past = Commit::factory()->create(['guild_id' => $guild->id]);
    $pending = Commit::factory()->pending()->create(['guild_id' => $guild->id, 'created_at' => now()->subDay()]);
    resolve(UpdateGuildNotifications::class)->handle($owner, $guild, true);
    expect(Notification::query()->count())->toBe(0);
    resolve(UpdateCommitStatuses::class)->handle([$pending->id], CommitStatus::Approved);
    expect(Notification::query()->where('type', NotificationType::GuildPost)->pluck('commit_id')->all())->toBe([$pending->id]);
});

it('excludes own posts, replies, hashtag-only matches and unapproved posts', function (): void {
    $guild = Guild::factory()->create(['moderator_notifications_enabled' => true, 'handle' => 'rust']);
    $posts = [
        Commit::factory()->create(['guild_id' => $guild->id, 'user_id' => $guild->user_id, 'author_id' => $guild->user_id]),
        Commit::factory()->create(['guild_id' => $guild->id, 'parent_id' => Commit::factory()->create()->id]),
        Commit::factory()->create(['body' => 'A #rust post.', 'guild_id' => null]),
        Commit::factory()->pending()->create(['guild_id' => $guild->id]),
        Commit::factory()->rejected()->create(['guild_id' => $guild->id]),
    ];
    resolve(CreateGuildPostNotifications::class)->handle(...$posts);
    expect(Notification::query()->where('type', NotificationType::GuildPost)->count())->toBe(0);
});

it('groups new approved posts by guild and exposes them consistently in the signed API', function (): void {
    $guild = Guild::factory()->create(['moderator_notifications_enabled' => true]);
    $other = Guild::factory()->create(['user_id' => $guild->user_id, 'moderator_notifications_enabled' => true]);
    $posts = Commit::factory()->count(3)->create(['guild_id' => $guild->id]);
    $fourth = Commit::factory()->create(['guild_id' => $other->id]);
    resolve(CreateGuildPostNotifications::class)->handle(...[...$posts, $fourth]);
    $owner = User::query()->findOrFail($guild->user_id);
    $groups = resolve(NotificationGroupsQuery::class)->paginate($owner, 'guilds');
    expect($groups->count())->toBe(2)->and($groups->getCollection()->pluck('event_count')->sort()->values()->all())->toBe([1, 3]);
    $this->actingAs($owner)->get(route('notification.index', ['type' => 'guilds']))->assertInertia(fn ($page) => $page->has('notifications.data', 2));
    $key = apiKeyPairFor($owner);
    $response = signedApiRequest($key, 'GET', route('api-notification.index', ['type' => 'guilds']))->assertOk()->assertJsonCount(2, 'data');
    expect(array_column($response->json('data'), 'guild'))->each->not->toBeNull();
    $notice = Notification::query()->where('commit_id', $posts[0]->id)->sole();
    signedApiRequest($key, 'GET', route('api-notification-group.show', $notice))->assertOk()->assertJsonCount(3, 'data');
    expect(resolve(NotificationGroupsQuery::class)->unreadCount($owner, 'guilds'))->toBe(0);
});

it('never announces a moderators own organization-authored board post', function (): void {
    $guild = Guild::factory()->create(['moderator_notifications_enabled' => true]);
    $organization = Organization::factory()->create();
    $post = Commit::factory()->create(['guild_id' => $guild->id, 'user_id' => $guild->user_id, 'author_id' => $organization->id]);
    resolve(CreateGuildPostNotifications::class)->handle($post);
    expect(Notification::query()->where('type', NotificationType::GuildPost)->count())->toBe(0);
});

it('honours guild muting when creating and reading notifications', function (): void {
    $guild = Guild::factory()->create(['moderator_notifications_enabled' => true]);
    $owner = User::query()->findOrFail($guild->user_id);
    resolve(CreateGuildPostNotifications::class)->handle(Commit::factory()->create(['guild_id' => $guild->id]));
    $guild->muters()->attach($owner, ['muted_at' => now()]);
    expect($owner->notifications()->relevant()->count())->toBe(0);
    resolve(CreateGuildPostNotifications::class)->handle(Commit::factory()->create(['guild_id' => $guild->id]));
    expect($owner->notifications()->count())->toBe(1);
});

it('suppresses creation and subsequent display across author mutes and blocks', function (string $kind): void {
    $guild = Guild::factory()->create(['moderator_notifications_enabled' => true]);
    $owner = User::query()->findOrFail($guild->user_id);
    $post = Commit::factory()->create(['guild_id' => $guild->id]);
    $author = User::query()->findOrFail($post->user_id);
    resolve(CreateGuildPostNotifications::class)->handle($post);
    if ($kind === 'mute') {
        $owner->muting()->attach($author);
    } elseif ($kind === 'block') {
        $owner->blocking()->attach($author);
    } else {
        $author->blocking()->attach($owner);
    }

    expect($owner->notifications()->relevant()->count())->toBe(0);
    $new = Commit::factory()->for($author)->create(['guild_id' => $guild->id]);
    resolve(CreateGuildPostNotifications::class)->handle($new);
    expect($owner->notifications()->count())->toBe(1);
})->with(['mute', 'block', 'blocked-by']);

it('hides deleted or rejected posts and revokes access after moderator ownership ends', function (): void {
    $guild = Guild::factory()->create(['moderator_notifications_enabled' => true]);
    $owner = User::query()->findOrFail($guild->user_id);
    $post = Commit::factory()->create(['guild_id' => $guild->id]);
    resolve(CreateGuildPostNotifications::class)->handle($post);
    $notice = $owner->notifications()->sole();
    $post->delete();
    expect($owner->notifications()->relevant()->count())->toBe(0);
    $post->restore();
    $post->update(['status' => CommitStatus::Rejected]);

    expect($owner->notifications()->relevant()->count())->toBe(0);
    $post->update(['status' => CommitStatus::Approved]);
    $guild->update(['user_id' => User::factory()->create()->id]);
    expect($owner->notifications()->relevant()->count())->toBe(0);
    $this->actingAs($owner)->get(route('notification-group.show', $notice))->assertNotFound();
});

it('clears alerts when disabled and does not resurrect them on re-enable', function (): void {
    $guild = Guild::factory()->create(['moderator_notifications_enabled' => true]);
    $owner = User::query()->findOrFail($guild->user_id);
    resolve(CreateGuildPostNotifications::class)->handle(Commit::factory()->create(['guild_id' => $guild->id]));
    resolve(UpdateGuildNotifications::class)->handle($owner, $guild, false);
    expect($owner->notifications()->count())->toBe(0)->and($guild->moderator_notifications_enabled)->toBeFalse();
    resolve(UpdateGuildNotifications::class)->handle($owner, $guild, true);
    expect($owner->notifications()->count())->toBe(0);
});

it('checks fresh ownership under the preference lock and resets opt-in when a guild is reclaimed', function (): void {
    $guild = Guild::factory()->create(['moderator_notifications_enabled' => true]);
    $owner = User::query()->findOrFail($guild->user_id);
    Guild::query()->whereKey($guild->id)->update(['user_id' => null]);
    expect(fn () => resolve(UpdateGuildNotifications::class)->handle($owner, $guild, false))->toThrow(GuildModeratorRemoved::class);
    $next = User::factory()->create();
    $claimed = resolve(CreateGuild::class)->handle($next, $guild->handle, $guild->name, $guild->description, $guild->emoji, null);
    expect($claimed->moderator_notifications_enabled)->toBeFalse();
    $claimed->update(['moderator_notifications_enabled' => true]);
    resolve(DeleteGuildModerator::class)->handle($claimed);
    expect($claimed->refresh()->moderator_notifications_enabled)->toBeFalse()->and($claimed->user_id)->toBeNull();
});

it('exports the private preference and notification guild context only to their owner', function (): void {
    $guild = Guild::factory()->create(['moderator_notifications_enabled' => true]);
    $owner = User::query()->findOrFail($guild->user_id);
    resolve(CreateGuildPostNotifications::class)->handle(Commit::factory()->create(['guild_id' => $guild->id]));
    $export = resolve(CreateExport::class)->handle($owner);
    expect($export['guilds'][0]['moderator_notifications_enabled'])->toBeTrue()
        ->and(iterator_to_array($export['notifications'])[0]['guild'])->toBe($guild->handle);
    expect(resolve(CreateExport::class)->handle(User::factory()->create())['guilds'])->toBe([]);
});
