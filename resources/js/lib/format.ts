/** "512 B", "1.5 MB": a file size a person can read. */
export function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const units = ['KB', 'MB', 'GB'];
    let value = bytes / 1024;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value >= 10 ? Math.round(value) : Math.round(value * 10) / 10} ${units[unit]}`;
}

/** A storage allowance in MB as "25 MB" or "1 GB"; null means unlimited. */
export function formatMegabytes(mb: number | null): string {
    if (mb === null) {
        return 'Unlimited';
    }

    return mb >= 1000 && mb % 1000 === 0 ? `${mb / 1000} GB` : `${mb} MB`;
}
