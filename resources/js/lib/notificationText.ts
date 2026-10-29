export type NotificationParams = Record<string, string>;

/** The kinds the server stores ("member.joined"); their sentences are in the language files as notifications.kinds.member_joined. */
export function notificationKey(kind: string): string {
    return `notifications.kinds.${kind.replace('.', '_')}`;
}

/** "5 minutes ago", "yesterday": short and in the language of the page. */
export function timeAgo(iso: string, locale: string, now: Date = new Date()): string {
    const seconds = Math.round((new Date(iso).getTime() - now.getTime()) / 1000);
    const format = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });

    for (const [unit, size] of [['day', 86400], ['hour', 3600], ['minute', 60]] as const) {
        if (Math.abs(seconds) >= size) {
            return format.format(Math.trunc(seconds / size), unit);
        }
    }

    return format.format(0, 'second');
}
