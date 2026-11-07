import { formatNumber, useT } from '@/lib/i18n';
import { Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useId } from 'react';

/**
 * The paging facts App\Support\Http\ListPage sends with every list screen. A Laravel paginator object
 * (with `data` and `links`) satisfies it too, so a screen can pass either the paginator or its meta prop.
 */
export type ListMeta = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    page_name?: string;
    per_page_name?: string;
    per_page_option?: string;
    per_page_options?: string[];
    full_total?: number;
    capped?: boolean;
};

const DEFAULT_OPTIONS = ['20', '100', '200', '500', 'all'];

/** 1 … 7 8 9 … 40: the first and last page, the pages around the current one, and gaps between. */
export function pageWindow(current: number, last: number): (number | 'gap')[] {
    if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1);
    let start = Math.max(2, current - 1);
    let end = Math.min(last - 1, current + 1);
    if (current <= 3) {
        start = 2;
        end = 4;
    } else if (current >= last - 2) {
        start = last - 3;
        end = last - 1;
    }
    const out: (number | 'gap')[] = [1];
    if (start > 2) out.push('gap');
    for (let p = start; p <= end; p++) out.push(p);
    if (end < last - 1) out.push('gap');
    out.push(last);
    return out;
}

/** The current URL with some query keys set (or removed with null), as a relative href. */
function hrefWith(current: string, changes: Record<string, string | null>): string {
    const url = new URL(current, 'http://hq.local');
    for (const [key, value] of Object.entries(changes)) {
        if (value === null) url.searchParams.delete(key);
        else url.searchParams.set(key, value);
    }
    return url.pathname + url.search;
}

const cell =
    'inline-flex h-11 min-w-11 items-center justify-center gap-1 rounded-full border px-3 tabular-nums transition-colors sm:h-9 sm:min-w-9 touch:h-11 touch:min-w-11 focus-visible:ring-ring focus-visible:ring-offset-background focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none';
const idle = 'border-glass-border bg-card hover:bg-accent text-foreground';
const off = 'border-glass-border text-muted-foreground cursor-not-allowed opacity-50';

/**
 * The one pagination bar of every HQ list (owner request 31 Oct, modelled on the CarryBee orders page):
 * "Showing [20] (1-20) of 59" on the left, "Previous 1 2 3 Next" on the right.
 */
export function Pagination({ meta }: { meta: ListMeta }) {
    const t = useT();
    const { url } = usePage();
    const selectId = useId();

    const pageName = meta.page_name ?? 'page';
    const perPageName = meta.per_page_name ?? (pageName === 'page' ? 'per_page' : pageName.replace(/page$/, 'per_page'));
    const option = meta.per_page_option ?? String(meta.per_page);
    const options = meta.per_page_options ?? DEFAULT_OPTIONS;
    const current = meta.current_page;
    const last = Math.max(meta.last_page, 1);
    const fullTotal = meta.full_total ?? meta.total;

    if (last <= 1 && meta.total <= 20 && option === '20' && !meta.capped) return null;

    const pageHref = (page: number) => hrefWith(url, { [pageName]: page === 1 ? null : String(page) });
    const changeSize = (value: string) =>
        router.get(
            hrefWith(url, { [perPageName]: value === '20' ? null : value, [pageName]: null }),
            {},
            { preserveScroll: true, preserveState: true },
        );

    return (
        <div className="flex flex-col gap-2 text-sm">
            <nav aria-label={t('Pagination')} className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                <div className="text-muted-foreground flex flex-wrap items-center gap-2">
                    <label htmlFor={selectId}>
                        {t('Showing')}
                        <span className="sr-only"> ({t('Rows per page')})</span>
                    </label>
                    <select
                        id={selectId}
                        value={option}
                        onChange={(e) => changeSize(e.target.value)}
                        className="border-glass-border bg-card text-foreground focus-visible:ring-ring touch:h-11 h-11 rounded-full border px-3 tabular-nums focus-visible:ring-2 focus-visible:outline-none sm:h-9"
                    >
                        {options.map((o) => (
                            <option key={o} value={o}>
                                {o === 'all' ? t('All') : o}
                            </option>
                        ))}
                    </select>
                    <span className="tabular-nums">
                        ({formatNumber(meta.from ?? 0)}-{formatNumber(meta.to ?? 0)}) {t('of {total}', { total: fullTotal })}
                    </span>
                </div>

                <ul className="flex flex-wrap items-center gap-1">
                    <li>
                        {current > 1 ? (
                            <Link href={pageHref(current - 1)} preserveScroll preserveState rel="prev" className={`${cell} ${idle}`}>
                                <ChevronLeft className="size-4" aria-hidden />
                                {t('Previous')}
                            </Link>
                        ) : (
                            <span aria-disabled="true" className={`${cell} ${off}`}>
                                <ChevronLeft className="size-4" aria-hidden />
                                {t('Previous')}
                            </span>
                        )}
                    </li>
                    {pageWindow(current, last).map((p, i) =>
                        p === 'gap' ? (
                            <li key={`gap-${i}`} aria-hidden className="text-muted-foreground px-1">
                                …
                            </li>
                        ) : (
                            <li key={p}>
                                <Link
                                    href={pageHref(p)}
                                    preserveScroll
                                    preserveState
                                    aria-label={t('Page {page}', { page: p })}
                                    aria-current={p === current ? 'page' : undefined}
                                    className={`${cell} ${p === current ? 'bg-primary text-primary-foreground border-primary font-semibold' : idle}`}
                                >
                                    {formatNumber(p)}
                                </Link>
                            </li>
                        ),
                    )}
                    <li>
                        {current < last ? (
                            <Link href={pageHref(current + 1)} preserveScroll preserveState rel="next" className={`${cell} ${idle}`}>
                                {t('Next')}
                                <ChevronRight className="size-4" aria-hidden />
                            </Link>
                        ) : (
                            <span aria-disabled="true" className={`${cell} ${off}`}>
                                {t('Next')}
                                <ChevronRight className="size-4" aria-hidden />
                            </span>
                        )}
                    </li>
                </ul>
            </nav>
            {meta.capped && (
                <p role="status" className="rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-amber-800 dark:text-amber-200">
                    {t('Showing the first {shown} of {total}. Narrow the filters.', { shown: meta.total, total: fullTotal })}
                </p>
            )}
        </div>
    );
}
