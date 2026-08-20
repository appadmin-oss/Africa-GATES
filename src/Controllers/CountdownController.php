<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\CountdownGif;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /email/countdown.gif — the live countdown for an email hero.
 *
 * ── THE DEADLINE IS NOT A QUERY PARAMETER ────────────────────────────────────
 * The obvious design takes `?to=<timestamp>`, and it is wrong: anybody could then
 * mint a URL claiming voting closes whenever they liked, and the image would render
 * it in Africa GATES type. The deadline is read from the CYCLE instead — either the
 * one named by `?cycle=<id>` or the active voting cycle — so the picture cannot
 * disagree with the ballot it is advertising. It also means a cycle whose close
 * date is moved corrects every email already sitting in an inbox.
 *
 * ── CACHING WOULD DEFEAT THE ENTIRE POINT ────────────────────────────────────
 * A countdown that any layer is allowed to cache is a photograph of a countdown.
 * `no-store` plus the Pragma/Expires pair for older proxies, because the failure is
 * silent and looks like the feature working: every recipient sees whatever time the
 * first recipient saw.
 */
final class CountdownController
{
    public function gif(Request $req, Response $res): Response
    {
        $q     = $req->getQueryParams();
        $close = $this->closesAt(isset($q['cycle']) ? (int) $q['cycle'] : 0);

        // No deadline to show — an unscheduled cycle, or none in voting. Render the closed
        // state rather than 404: a broken image in an email is worse than a calm one, and
        // the recipient cannot act on either.
        $left = $close === null ? 0 : $close->getTimestamp() - Carbon::now()->getTimestamp();

        $gif = CountdownGif::render(max(0, $left));
        $res->getBody()->write($gif);

        return $res
            ->withHeader('Content-Type', 'image/gif')
            ->withHeader('Content-Length', (string) \strlen($gif))
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Disposition', 'inline; filename="countdown.gif"');
    }

    /** The voting close time for a cycle, or for whichever cycle is currently voting. */
    private function closesAt(int $cycleId): ?Carbon
    {
        $row = $cycleId > 0
            ? DB::table('gates_award_cycles')->where('id', $cycleId)->first()
            : DB::table('gates_award_cycles')->where('status', 'voting')
                ->orderBy('voting_close')->first();

        if (!$row) return null;
        $at = $row->voting_close ?? null;
        if ($at === null || $at === '' || $at === '0000-00-00 00:00:00') return null;

        try {
            return Carbon::parse((string) $at);
        } catch (\Throwable) {
            return null;
        }
    }
}
