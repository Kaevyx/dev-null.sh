import { router } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { useId, useState } from 'react';
import { toast } from 'sonner';
import { cn } from '@/lib/utils';
import { update } from '@/routes/guild-notification';

export function GuildNotifications({
    handle,
    enabled,
}: {
    handle: string;
    enabled: boolean;
}) {
    const id = useId();
    const [processing, setProcessing] = useState(false);
    function change(value: boolean) {
        setProcessing(true);
        router.put(
            update.url(handle),
            { enabled: value },
            {
                only: ['moderatorNotificationsEnabled', 'canModerate'],
                preserveScroll: true,
                onSuccess: () => router.flushAll(),
                onError: (errors) =>
                    toast.error(
                        errors.enabled ??
                            'Could not update guild notifications.',
                    ),
                onFinish: () => setProcessing(false),
            },
        );
    }
    return (
        <section
            className="mt-4 rounded-lg border bg-muted/30 p-4"
            aria-labelledby={`${id}-label`}
        >
            <div className="flex items-center justify-between gap-4">
                <label
                    id={`${id}-label`}
                    htmlFor={id}
                    className="flex items-center gap-2 text-sm font-medium"
                >
                    <Bell aria-hidden className="size-4 shrink-0" />
                    Notify me about new board posts
                </label>
                <button
                    type="button"
                    role="switch"
                    aria-labelledby={`${id}-label`}
                    id={id}
                    aria-checked={enabled}
                    onClick={() => change(!enabled)}
                    disabled={processing}
                    aria-describedby={`${id}-help`}
                    className={cn(
                        'inline-flex h-6 w-11 shrink-0 items-center rounded-full border border-transparent p-0.5 transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:opacity-50',
                        enabled ? 'bg-primary' : 'bg-muted-foreground/30',
                    )}
                >
                    <span
                        className={cn(
                            'size-4.5 rounded-full bg-background shadow-sm transition-transform motion-reduce:transition-none',
                            enabled && 'translate-x-5',
                        )}
                    />
                </button>
            </div>
            <p id={`${id}-help`} className="mt-2 text-sm text-muted-foreground">
                As this guild’s moderator, receive grouped alerts in
                Notifications when other people’s board posts are approved.
                Replies and hashtag-only posts are excluded. No browser push
                alerts.
            </p>
        </section>
    );
}
