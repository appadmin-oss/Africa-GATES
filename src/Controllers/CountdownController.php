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
 * one named by `?cycle=<id>` or whichever cycle is currently voting — so the picture
 * cannot disagree with the ballot it is advertising. It also means a cycle whose
 * close date is moved corrects every email already sitting in an inbox.
 *
 * ── WHY `?cycle=` AND DELIBERATELY NOT `?nominee=` ───────────────────────────
 * Cycles are per PROGRAMME (gates_award_cycles.programme_id), and voting is only
 * mutually exclusive with nominations *within* a programme — so several cycles can
 * be in `voting` at the same time, and a global "the voting cycle" hands a Choral
 * nominee whichever programme happens to close soonest. The per-recipient email
 * therefore passes its nominee's own cycle.
 *
 * It passes the CYCLE and not the nominee, even though the nominee id would be
 * tidier to thread through. A nominee id in an image URL is a recipient identifier,
 * and an image URL that identifies its recipient is an open-tracking pixel however
 * politely it is described — which is the specific thing this endpoint exists to
 * avoid doing to people. A cycle id is shared by every nominee in the programme and
 * identifies nobody.
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

    /**
     * The voting close time for a cycle, or for whichever cycle is currently voting.
     *
     * Both dates are read and compared in the process timezone, which Clock::boot() pins
     * from APP_TIMEZONE (UTC by convention) at every entrypoint. Same zone on both sides
     * of the subtraction is the whole requirement — see the DB_TIMEZONE note in
     * .env.example for what happens when it is not.
     */
    private function closesAt(int $cycleId): ?Carbon
    {
        if ($cycleId > 0) {
            // The window is checked even for an explicitly named cycle. Without it,
            // ?cycle=<a cycle still in nominations> rendered "voting closes in 19 days"
            // — a countdown to a ballot nobody can cast on yet — and ?cycle=<upcoming>
            // counted down to a vote that had not opened. An id in a URL says which
            // cycle to read, never that its vote is live.
            return self::closeOf(
                DB::table('gates_award_cycles')->where('id', $cycleId)->first(),
                true
            );
        }

        // No cycle named: the soonest-closing one actually in voting.
        $row = DB::table('gates_award_cycles')
            ->where('status', 'voting')
            ->whereNotNull('voting_close')
            ->orderBy('voting_close')
            ->first();
        if ($row) return self::closeOf($row);

        // Nothing flagged `voting`, but a voting WINDOW may still be open: phases are
        // advanced by an hourly job, so a cycle can sit past its voting_open with a stale
        // status for up to an hour. Falling straight through to the closed state during
        // that window would tell people voting had ended while the ballot was live.
        $now = Carbon::now()->toDateTimeString();
        $row = DB::table('gates_award_cycles')
            ->whereNotNull('voting_close')
            ->where('voting_close', '>', $now)
            ->where(function ($q) use ($now) {
                $q->whereNull('voting_open')->orWhere('voting_open', '<=', $now);
            })
            ->orderBy('voting_close')
            ->first();

        return $row ? self::closeOf($row) : null;
    }

    /**
     * `voting_close` off a cycle row, as a Carbon, or null when it is not a usable date.
     *
     * @param bool $requireOpen also require that the voting window has actually STARTED.
     *                          The fallback queries already filter on it in SQL; the
     *                          explicit-id path has nothing else to check it.
     */
    private static function closeOf(?object $row, bool $requireOpen = false): ?Carbon
    {
        $at = $row->voting_close ?? null;
        // '0000-00-00 00:00:00' is what a legacy MySQL row holds instead of NULL, and
        // Carbon parses it to year zero rather than throwing — which would render a
        // countdown of about two thousand years.
        if ($at === null || $at === '' || \str_starts_with((string) $at, '0000-00-00')) return null;

        try {
            $c = Carbon::parse((string) $at);
        } catch (\Throwable) {
            return null;
        }

        if ($c->year <= 1970) return null;

        if ($requireOpen && !self::votingHasStarted($row)) return null;

        return $c;
    }

    /**
     * Has voting demonstrably STARTED on this cycle?
     *
     * Positive evidence is required, and there are two independent kinds because either
     * one can be the stale half:
     *
     *   · `voting_open` is in the past — the declared window has begun. This is what
     *     covers the gap where the hourly `cycles:advance` job has not yet moved a
     *     cycle's status, so the ballot is live while the row still says `nominations`.
     *   · `status` is already 'voting' — an operator moved it by hand and the window
     *     dates were never filled in.
     *
     * An absent `voting_open` on a cycle that is NOT in voting is not evidence of
     * anything, and an earlier version read it as "no declared start, so trust the close
     * date". That rendered "voting closes in 19 days" for a programme still taking
     * NOMINATIONS — a countdown to a ballot nobody could cast on. A close date on its
     * own says when voting would end, never that it has begun.
     */
    private static function votingHasStarted(object $row): bool
    {
        $open = $row->voting_open ?? null;
        if ($open !== null && $open !== '' && !\str_starts_with((string) $open, '0000-00-00')) {
            try {
                if (!Carbon::parse((string) $open)->isFuture()) return true;
                // A start date in the future is explicit: voting has NOT begun, whatever
                // the status column happens to say.
                return false;
            } catch (\Throwable) {
                // Unparseable — fall through to the status check.
            }
        }

        return \strtolower((string) ($row->status ?? '')) === 'voting';
    }
}
