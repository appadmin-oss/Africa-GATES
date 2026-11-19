<?php
declare(strict_types=1);
namespace AfricaGates\Admin\Services;

use AfricaGates\Support\Like;
use Illuminate\Database\Capsule\Manager as DB;

/** Search and pagination shared by the event administration list. */
final class EventDirectory
{
    public static function page(array $input): array
    {
        $search = mb_substr(trim((string) ($input['q'] ?? '')), 0, 160);
        $status = in_array($input['status'] ?? '', ['draft', 'published'], true) ? $input['status'] : '';
        $query = DB::table('gates_site_events');
        if ($status !== '') $query->where('status', $status);
        if ($search !== '') $query->where(function ($q) use ($search) {
            $q->whereRaw(Like::clause('title'), [Like::contains($search)])
                ->orWhereRaw(Like::clause('location'), [Like::contains($search)])
                ->orWhereRaw(Like::clause('venue'), [Like::contains($search)]);
        });
        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / 20));
        $page = min($pages, max(1, (int) ($input['page'] ?? 1)));
        $url = fn (int $p) => '/admin/events?' . http_build_query(['q' => $search, 'status' => $status, 'page' => $p]);
        $rows = $query->orderByDesc('event_date')->orderByDesc('id')->offset(($page - 1) * 20)->limit(20)->get()->map(fn ($r) => (array) $r)->all();
        // Registrations per event in ONE query: "how full is it" is the question an
        // organiser opens this list with, and the delete guard needs the same count.
        if ($rows && \AfricaGates\Support\SchemaHas::table('gates_event_registrations')) {
            $n = DB::table('gates_event_registrations')->whereIn('event_id', array_column($rows, 'id'))
                ->groupBy('event_id')->selectRaw("event_id, COUNT(*) as k, SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as c")
                ->get()->keyBy('event_id')->all();
            foreach ($rows as &$r) {
                // Any registration at all keeps an event from deletion; only a confirmed one
                // is somebody coming.
                $r['registrations'] = (int) ($n[$r['id']]->k ?? 0);
                $r['confirmed'] = (int) ($n[$r['id']]->c ?? 0);
            }
            unset($r);
        }
        return ['rows' => $rows,
            'q' => $search, 'status_filter' => $status, 'total' => $total, 'page' => $page, 'pages' => $pages,
            'previous' => $page > 1 ? $url($page - 1) : null, 'next' => $page < $pages ? $url($page + 1) : null];
    }
}
