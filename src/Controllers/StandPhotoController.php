<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Admin\Services\UploadService;
use AfricaGates\Services\{OrgAuth, RateLimitService, StandApplication, StandCall, StandPhotos};
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * A vendor's photographs of what they sell: add, remove, reorder.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE SCOPE CHECK IS THE WHOLE CONTROLLER
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every method here does the same three things before it does anything else: is somebody
 * signed in, is this application theirs, and is the call still open. The rest is a
 * handful of lines calling {@see StandPhotos}.
 *
 * That is deliberate. An application id is a small integer in a URL, and the only thing
 * standing between a vendor and every other vendor's photographs is {@see mine()}. It
 * checks `org_id` against the SESSION's org — never against anything in the request —
 * because a scope taken from the request is not a scope.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND WHY THERE IS NO GET
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The form promises that nothing is published while the call is running, and a photograph
 * is a file on a web server: a route that serves one is a route that can be guessed at.
 * These photographs are read by the vendor's own dashboard and by the admin screens, both
 * of which already hold the row. Until an offer is accepted there is no public reader at
 * all, and the way to have no public reader is to write none.
 */
final class StandPhotoController
{
    public function __construct(
        private readonly ?UploadService     $uploads   = null,
        private readonly ?RateLimitService  $rateLimit = null,
    ) {}

    /** @param array<string,mixed> $body */
    private function json(Response $res, array $body, int $status = 200): Response
    {
        $res->getBody()->write(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $res->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    /**
     * The application, if it belongs to the caller and can still be edited.
     *
     * @return array{0:?object, 1:?Response} the row, or the refusal to return instead
     */
    private function mine(Request $req, Response $res, array $args): array
    {
        $orgId = OrgAuth::orgId();
        if ($orgId < 1) {
            return [null, $this->json($res, ['ok' => false, 'code' => 'SIGN_IN',
                'message' => 'Sign in to change your application.'], 401)];
        }

        $id  = (int) ($args['application'] ?? 0);
        $app = $id > 0 ? StandApplication::find($id) : null;

        // 404 and not 403 for somebody else's row. A refusal that distinguishes "no such
        // application" from "not yours" tells a stranger which ids exist.
        if (!$app || (int) $app->org_id !== $orgId) {
            return [null, $this->json($res, ['ok' => false,
                'message' => 'That application is not on this account.'], 404)];
        }

        // ── AND THE CALL HAS TO STILL BE OPEN ───────────────────────────────
        //
        // Photographs are part of what was judged. Adding, removing or reordering them
        // after the deadline changes the thing the scorers read, after they read it —
        // which is the same act as editing an answer once the marking has started.
        try {
            $call = DB::table('gates_stand_calls')->where('id', (int) $app->call_id)->first();
        } catch (\Throwable) {
            $call = null;
        }
        if (!$call || !StandCall::isAccepting($call)) {
            return [null, $this->json($res, ['ok' => false, 'code' => 'CLOSED',
                'message' => 'Applications for this event have closed, so the photographs are '
                           . 'now part of the record and cannot be changed.'], 409)];
        }

        return [$app, null];
    }

    /** Per-org, because the cost being limited is disk and re-encoding, not requests. */
    private function throttled(int $orgId): bool
    {
        if ($this->rateLimit === null) return false;
        return !$this->rateLimit->check('org:' . $orgId, 'stand_photo', 40, 3600);
    }

    // ────────────────────────────────────────────────────────────────────────

    public function add(Request $req, Response $res, array $args = []): Response
    {
        [$app, $stop] = $this->mine($req, $res, $args);
        if ($stop) return $stop;

        if ($this->throttled((int) $app->org_id)) {
            return $this->json($res, ['ok' => false,
                'message' => 'That is a lot of photographs in one hour. Try again shortly.'], 429);
        }

        $file = $req->getUploadedFiles()['photo'] ?? null;
        if (!$file || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return $this->json($res, ['ok' => false, 'message' => 'Choose a photograph.'], 422);
        }

        $r = StandPhotos::add((int) $app->id, (int) $app->org_id, $file, $this->uploads);

        return $this->json($res, $r + ['count' => StandPhotos::count((int) $app->id)],
                           $r['ok'] ? 200 : 422);
    }

    public function remove(Request $req, Response $res, array $args = []): Response
    {
        [$app, $stop] = $this->mine($req, $res, $args);
        if ($stop) return $stop;

        $r = StandPhotos::remove((int) $app->id, (int) ($args['photo'] ?? 0));

        return $this->json($res, $r + ['count' => StandPhotos::count((int) $app->id)],
                           $r['ok'] ? 200 : 404);
    }

    public function order(Request $req, Response $res, array $args = []): Response
    {
        [$app, $stop] = $this->mine($req, $res, $args);
        if ($stop) return $stop;

        $ids = (array) (((array) $req->getParsedBody())['ids'] ?? []);
        $ok  = StandPhotos::reorder((int) $app->id, array_map('intval', $ids));

        return $this->json($res, [
            'ok'      => $ok,
            'message' => $ok ? 'Order saved.' : 'Could not save that order.',
            'photos'  => StandPhotos::forApplication((int) $app->id),
        ], $ok ? 200 : 422);
    }
}
