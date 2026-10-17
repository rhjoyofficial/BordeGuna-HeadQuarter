import { Link } from '@inertiajs/react';

export type PageLink = { url: string | null; label: string; active: boolean };

/** Laravel's paginator links as compact buttons; the "previous" and "next" entities become words. */
export function Pagination({ links, total, noun }: { links: PageLink[]; total?: number; noun?: string }) {
    if (links.length <= 3 && total !== undefined && total <= 25) return null;
    const clean = (label: string) =>
        label
            .replace('&laquo; Previous', 'Previous')
            .replace('Next &raquo;', 'Next')
            .replace(/&[a-z]+;/g, '');
    return (
        <nav className="flex flex-wrap items-center gap-1 text-sm" aria-label="Pagination">
            {links.map((l, i) =>
                l.url ? (
                    <Link
                        key={i}
                        href={l.url}
                        preserveScroll
                        aria-current={l.active ? 'page' : undefined}
                        className={`touch:h-11 touch:min-w-11 inline-flex h-9 min-w-9 items-center justify-center rounded-full border px-3 tabular-nums transition-colors ${l.active ? 'bg-primary text-primary-foreground border-primary' : 'border-glass-border bg-card hover:bg-accent'}`}
                    >
                        {clean(l.label)}
                    </Link>
                ) : (
                    <span key={i} className="text-muted-foreground px-2 py-1 tabular-nums">
                        {clean(l.label)}
                    </span>
                ),
            )}
            {total !== undefined && (
                <span className="text-muted-foreground ml-auto text-xs">
                    {total.toLocaleString('en-US')} {noun ?? 'rows'}
                </span>
            )}
        </nav>
    );
}
