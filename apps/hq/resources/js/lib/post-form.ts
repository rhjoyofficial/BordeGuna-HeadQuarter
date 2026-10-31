/**
 * POST a form (or JSON) to HQ from a screen that needs the answer in place, not a page visit: a recording being
 * transcribed, a file being attached. Laravel's session CSRF token travels in the XSRF cookie.
 */
function xsrfToken(): string {
    const match = document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='));
    return match ? decodeURIComponent(match.slice('XSRF-TOKEN='.length)) : '';
}

export type PostResult<T> = { ok: true; data: T } | { ok: false; status: number; error: string; state?: string };

export async function postForm<T = Record<string, unknown>>(url: string, body: FormData | Record<string, unknown>): Promise<PostResult<T>> {
    const isForm = body instanceof FormData;
    try {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
                ...(isForm ? {} : { 'Content-Type': 'application/json' }),
            },
            body: isForm ? body : JSON.stringify(body),
        });
        const json = (await response.json().catch(() => ({}))) as Record<string, unknown>;
        if (!response.ok) {
            const first = json.errors ? (Object.values(json.errors as Record<string, string[]>)[0]?.[0] ?? '') : '';
            return {
                ok: false,
                status: response.status,
                error: String(json.error ?? first ?? json.message ?? 'The request was refused.'),
                state: json.state as string | undefined,
            };
        }
        return { ok: true, data: json as T };
    } catch {
        return { ok: false, status: 0, error: 'HQ could not be reached.' };
    }
}
