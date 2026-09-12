<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * One place for the "count then fetch this page" dance the admin lists all
 * repeated by hand: clone the (already-filtered, already-ordered) query for the
 * total, clamp the requested page into range, then fetch just that slice.
 * Pairs with {@see Filters} (which supplies clampPage/pageWindow/qs).
 */
final class Paginator
{
    /**
     * @param object $query a built Illuminate query builder — filters AND ordering
     *                      already applied (this fetches the slice as-is).
     * @return array{rows:\Illuminate\Support\Collection, total:int, pages:int, page:int, per:int, window:int[]}
     */
    public static function paginate(object $query, int $page, int $per): array
    {
        $per   = max(1, $per);
        $total = (int) (clone $query)->count();
        $pages = max(1, (int) ceil($total / $per));
        $page  = Filters::clampPage($page, $pages);
        $rows  = (clone $query)->offset(($page - 1) * $per)->limit($per)->get();

        return [
            'rows'   => $rows,
            'total'  => $total,
            'pages'  => $pages,
            'page'   => $page,
            'per'    => $per,
            'window' => Filters::pageWindow($page, $pages),
        ];
    }
}
