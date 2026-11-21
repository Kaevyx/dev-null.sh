<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\UpdateGuildNotifications;
use App\Exceptions\GuildModeratorRemoved;
use App\Http\Requests\UpdateGuildNotificationsRequest;
use App\Models\Guild;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

final readonly class GuildNotificationController
{
    public function update(UpdateGuildNotificationsRequest $request, Guild $guild, #[CurrentUser] User $user, UpdateGuildNotifications $action): RedirectResponse|JsonResponse
    {
        try {
            $action->handle($user, $guild, $request->boolean('enabled'));
        } catch (GuildModeratorRemoved) {
            return $request->is('api/*') ? response()->json(['message' => 'You no longer moderate this guild.'], 409)
                : back()->withErrors(['enabled' => 'You no longer moderate this guild.']);
        }

        if ($request->is('api/*')) {
            return response()->json(['enabled' => $guild->moderator_notifications_enabled]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $guild->moderator_notifications_enabled ? 'Guild post notifications are on.' : 'Guild post notifications are off.']);

        return back();
    }
}
