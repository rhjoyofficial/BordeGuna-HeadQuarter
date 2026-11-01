import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    /**
     * The owner's dated USD to BDT reference rate, for showing a model cost in BDT as an estimate (ruling 1 Nov 2026).
     * `usd_bdt_rate` is null when the rate is missing or stale; the screens then show USD alone with a note.
     */
    money?: {
        usd_bdt_rate: number | null;
        rate_state?: 'valid' | 'missing' | 'expired' | 'not_yet' | 'invalid';
        rate_as_of?: string | null;
        rate_valid_until?: string | null;
        rate_source?: string | null;
    };
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    /** docs/90 A.6: the person's saved language ('en' | 'bn'), or null when none is saved yet. */
    locale?: 'en' | 'bn' | null;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}
