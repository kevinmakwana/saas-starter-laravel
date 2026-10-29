export type NotificationParams = Record<string, string>;

// One sentence per kind of notification. The server stores the kind and its values, not the wording.
const sentences: Record<string, (p: NotificationParams) => string> = {
    'member.joined': (p) => `${p.name} joined ${p.team}.`,
    'member.removed': (p) => `You were removed from ${p.team}.`,
    'team.deleted': (p) => `${p.by} deleted ${p.team}.`,
    'plan.changed': (p) => `${p.team} is now on the ${p.plan} plan.`,
    'payment.failed': (p) => `A payment for ${p.team} failed. Update the payment method before the grace period ends.`,
    'trial.ending': (p) => `The free trial of ${p.team} ends on ${p.date}.`,
};

export function notificationText(kind: string, params: NotificationParams): string {
    return (sentences[kind] ?? (() => 'You have a new notification.'))(params);
}

/** "5 minutes ago", "yesterday": short and in the browser's language. */
export function timeAgo(iso: string, now: Date = new Date()): string {
    const seconds = Math.round((new Date(iso).getTime() - now.getTime()) / 1000);
    const format = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

    for (const [unit, size] of [['day', 86400], ['hour', 3600], ['minute', 60]] as const) {
        if (Math.abs(seconds) >= size) {
            return format.format(Math.trunc(seconds / size), unit);
        }
    }

    return format.format(0, 'second');
}
