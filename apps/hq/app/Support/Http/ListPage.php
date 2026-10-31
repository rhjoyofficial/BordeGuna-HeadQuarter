<?php

namespace App\Support\Http;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Str;

/**
 * The one way an HQ list screen pages its rows (owner request 31 Oct: every list gets the same bar).
 *
 * The person picks 20 (default), 100, 200, 500 or All with `?per_page=`; anything else falls back to 20.
 * "All" returns at most `hq.lists.all_ceiling` rows and the paginator says it was capped.
 *
 * The caller passes a query that is ALREADY scoped to what the person may see (visibleTo / scope->restrict),
 * so the count and the pages never include another business's rows. The screen's own ordering is kept and
 * the primary key is added as a tiebreaker, so a row never shows on two pages.
 */
final class ListPage
{
    public const DEFAULT_SIZE = 20;

    /** @var list<string> */
    public const OPTIONS = ['20', '100', '200', '500', 'all'];

    /**
     * @param  Builder<*>|Relation<*, *, *>  $query
     * @param  string  $pageName  the page key; a second list on one screen uses e.g. 'consents_page'
     */
    public static function paginate(Builder|Relation $query, Request $request, string $pageName = 'page', ?string $perPageName = null): ListPaginator
    {
        $perPageName ??= self::perPageNameFor($pageName);
        $option = self::option($request, $perPageName);
        $ceiling = self::ceiling();

        self::addTiebreaker($query);

        $fullTotal = (int) $query->toBase()->getCountForPagination();

        if ($option === 'all') {
            $total = min($fullTotal, $ceiling);
            $perPage = max($total, 1);
            $page = 1;
            $items = (clone $query)->limit($ceiling)->get();
        } else {
            $total = $fullTotal;
            $perPage = (int) $option;
            $lastPage = max((int) ceil($total / $perPage), 1);
            $page = min(max((int) Paginator::resolveCurrentPage($pageName), 1), $lastPage);
            $items = $total > 0 ? (clone $query)->forPage($page, $perPage)->get() : $query->getModel()->newCollection();
        }

        $paginator = new ListPaginator($items, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
        $paginator->perPageOption = $option;
        $paginator->perPageName = $perPageName;
        $paginator->fullTotal = $fullTotal;
        $paginator->ceiling = $ceiling;

        return $paginator->withQueryString();
    }

    /** The size the person asked for, or the default when the value is not one of the offered sizes. */
    public static function option(Request $request, string $perPageName = 'per_page'): string
    {
        $raw = $request->query($perPageName);
        if (is_int($raw)) {
            $raw = (string) $raw;
        }
        if (! is_string($raw)) {
            return (string) self::DEFAULT_SIZE;
        }
        $raw = strtolower(trim($raw));

        return in_array($raw, self::OPTIONS, true) ? $raw : (string) self::DEFAULT_SIZE;
    }

    public static function ceiling(): int
    {
        return max((int) config('hq.lists.all_ceiling', 5000), 1);
    }

    /** 'page' pairs with 'per_page'; 'consents_page' pairs with 'consents_per_page'. */
    public static function perPageNameFor(string $pageName): string
    {
        return $pageName === 'page' ? 'per_page' : Str::beforeLast($pageName, 'page').'per_page';
    }

    /** @param  Builder<*>|Relation<*, *, *>  $query */
    private static function addTiebreaker(Builder|Relation $query): void
    {
        $model = $query->getModel();
        $key = $model->getKeyName();
        $qualified = $model->getQualifiedKeyName();
        $orders = $query->toBase()->orders ?? [];

        $direction = 'desc';
        foreach ($orders as $order) {
            $column = $order['column'] ?? null;
            if ($column === $key || $column === $qualified) {
                return;
            }
            if (isset($order['direction'])) {
                $direction = strtolower((string) $order['direction']) === 'asc' ? 'asc' : 'desc';
            }
        }

        $query->orderBy($qualified, $direction);
    }
}
