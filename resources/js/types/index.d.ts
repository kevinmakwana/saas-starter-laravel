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
    };
    flash: {
        success: string | null;
        upgrade: string | null;
    };
};
