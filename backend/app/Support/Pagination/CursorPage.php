<?php

declare(strict_types=1);

namespace App\Support\Pagination;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * A keyset-paginated page: at most $limit rows, plus a cursor for whatever
 * came after them. Fetched in a single query by asking for one row past the
 * limit and trimming it back off, rather than a second COUNT query to tell
 * "exactly this many" from "at least this many" — the trick Step 9's own
 * ReplayDeliveries::matchedDeliveries() used for range replay, generalized
 * here at its second use: Ledger's own message and attempt lists build a
 * page the identical way.
 *
 * The caller is responsible for the query's own ordering and cursor
 * predicate (KeysetCursor::applyTo()) — this class only knows how to turn
 * "one query, one limit" into "one page, one next cursor".
 *
 * @template TModel of Model
 */
final readonly class CursorPage
{
    /**
     * @param  Collection<int, TModel>  $items
     */
    private function __construct(public Collection $items, public ?KeysetCursor $next) {}

    /**
     * @template TQueryModel of Model
     *
     * @param  Builder<TQueryModel>  $query  already ordered by the caller, ascending on (timestamp, tiebreak)
     * @param  Closure(TQueryModel): KeysetCursor  $cursorFor  the resume point built from the last row kept
     * @return self<TQueryModel>
     */
    public static function fetch(Builder $query, int $limit, Closure $cursorFor): self
    {
        $matched = $query->limit($limit + 1)->get();

        if ($matched->count() <= $limit) {
            /** @var self<TQueryModel> */
            return new self($matched, null);
        }

        $page = $matched->slice(0, $limit)->values();

        $last = $page->last();
        assert($last !== null);

        /** @var TQueryModel $last */
        /** @var self<TQueryModel> */
        return new self($page, $cursorFor($last));
    }
}
