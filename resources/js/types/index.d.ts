export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
}

export type RoleName = 'owner' | 'admin' | 'member';

export interface TeamSummary {
    id: number;
    name: string;
}

export interface CurrentTeam extends TeamSummary {
    role: RoleName | null;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
        team: CurrentTeam | null;
        teams: TeamSummary[];
        features: Record<string, boolean>;
        billing: { state: 'grace' | 'overdue'; grace_ends_at: string | null } | null;
        overLimit: { plan: string; items: { key: string; label: string; used: number; limit: number }[] } | null;
    };
    flash: {
        success: string | null;
        upgrade: string | null;
        error: string | null;
    };
};

export interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
}
