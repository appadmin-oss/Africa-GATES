<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Name;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * A voter's message of support, from submission to the public wall.
 *
 * ── THE SHAPE OF THE FEATURE ─────────────────────────────────────────────────
 *
 * A voter can say something about the person they just voted for. It appears on
 * that nominee's page, and it gets its own shareable permalink so it can be posted
 * to Facebook as a card in its own right rather than as a link to a page that
 * happens to contain it.
 *
 * ── WHY IT IS MODERATED, AND WHY NOT BY A HUMAN FIRST ────────────────────────
 *
 * This is user-submitted text attached to a named real person, published on that
 * person's page. Left unmoderated it is a harassment vector aimed at nominees who
 * did not ask to be nominated in the first place.
 *
 * But a queue that holds every message for human review would also kill the
 * feature: the moment worth sharing is the moment just after voting, and "your
 * message will appear within 48 hours" is not that moment. So it follows the
 * pattern the platform already uses for community content — score it, publish the
 * clean ones immediately, hold the borderline ones for a person, refuse the worst
 * outright — by delegating to SpamService, which is the same decision the
 * community moderation queue makes against the same admin-tunable thresholds, so
 * an operator tightening the dial tightens this too.
 *
 * ── AND WHY IT DEGRADES TOWARD HOLDING, NOT PUBLISHING ───────────────────────
 *
 * When the classifier is unavailable — off, out of budget, provider down — the
 * message is held as `pending` rather than published. The asymmetry is deliberate:
 * an unreviewed message that nobody sees is a delay, and an unreviewed message on
 * a nominee's page is a thing that person has to read about themselves. One of
 * those is recoverable by an administrator and the other is not.
 *
 * ── THE AUDITED VOTE PATH IS NOT TOUCHED ─────────────────────────────────────
 *
 * Nothing here runs inside {@see VoteService::castVote()}. The message is written
 * AFTER a vote has succeeded, in its own statement, and a failure to store it can
 * never fail a vote. A vote is the thing that matters; a sentence about it is not
 * worth risking one for.
 */
final class VoteMessageService
{
    /** Hard ceiling. Long enough for a paragraph, short enough not to be an essay. */
    public const MAX_LEN = 400;

    /** Below this many characters there is nothing to moderate and nothing to read. */
    public const MIN_LEN = 3;

    /**
     * Store a message for a nominee.
     *
     * Returns the outcome rather than throwing: every caller is on a path where a
     * vote has ALREADY succeeded, and none of them may fail because of this.
     *
     * @param  array{nominee_id:int, category_id?:?int, vote_id?:?int, donation_id?:?int,
     *               email:string, body:string, name?:?string, show_name?:bool, source?:string} $in
     * @return array{ok:bool, status?:string, id?:int, token?:string, code?:string, message?:string}
     */
    public static function submit(array $in, ?SpamService $spam = null): array
    {
        $body = self::clean((string) ($in['body'] ?? ''));
        if (mb_strlen($body) < self::MIN_LEN) {
            return ['ok' => false, 'code' => 'EMPTY', 'message' => 'Nothing to post.'];
        }

        $nomineeId = (int) ($in['nominee_id'] ?? 0);
        $email     = trim((string) ($in['email'] ?? ''));
        if ($nomineeId <= 0 || $email === '') {
            return ['ok' => false, 'code' => 'BAD_INPUT', 'message' => 'Missing nominee or voter.'];
        }

        $hash = VoteService::voterHash($email);

        // Moderate BEFORE the insert, so a rejected message is stored as rejected
        // rather than briefly existing as publishable.
        [$status, $score, $reason] = self::classify($body, $spam);

        $name = isset($in['name']) && trim((string) $in['name']) !== ''
            ? mb_substr(Name::title(trim((string) $in['name'])), 0, 120)
            : null;

        $row = [
            'nominee_id'       => $nomineeId,
            'category_id'      => isset($in['category_id']) ? (int) $in['category_id'] ?: null : null,
            'vote_id'          => isset($in['vote_id']) ? (int) $in['vote_id'] ?: null : null,
            'donation_id'      => isset($in['donation_id']) ? (int) $in['donation_id'] ?: null : null,
            'voter_email_hash' => $hash,
            'display_name'     => $name,
            'show_name'        => !empty($in['show_name']) && $name !== null ? 1 : 0,
            'body'             => $body,
            'source'           => ($in['source'] ?? 'free') === 'paid' ? 'paid' : 'free',
            'status'           => $status,
            'mod_score'        => $score,
            'mod_reason'       => $reason,
            'share_token'      => self::token(),
            'created_at'       => date('Y-m-d H:i:s'),
        ];

        try {
            // One message per person per nominee, enforced by a unique index. An
            // UPDATE on conflict rather than an error: somebody revising what they
            // wrote is not an error condition, and the re-written text is
            // re-moderated because the old score does not describe the new words.
            $existing = DB::table('gates_vote_messages')
                ->where('nominee_id', $nomineeId)->where('voter_email_hash', $hash)
                ->first();

            if ($existing !== null) {
                $set = array_diff_key($row, ['share_token' => null, 'created_at' => null]);

                // The share token and the original timestamp survive a rewrite: a link
                // already posted to Facebook must keep resolving, and the message is
                // still from when it was first written.
                $set['share_token']  = $existing->share_token ?: self::token();
                // A fresh decision, so the previous moderator's verdict does not
                // vouch for words they never saw.
                $set['moderated_by'] = null;
                $set['moderated_at'] = null;

                // Attribution is only overwritten when a name was actually supplied.
                // Otherwise editing the text through any caller that does not resend
                // the name would silently de-attribute a message the voter chose to
                // put their name on — a surprising way to lose credit for something
                // you said in public.
                if ($name === null) {
                    unset($set['display_name'], $set['show_name']);
                }

                DB::table('gates_vote_messages')->where('id', $existing->id)->update($set);
                return ['ok' => true, 'status' => $status, 'id' => (int) $existing->id,
                        'token' => (string) $set['share_token'], 'code' => 'REPLACED'];
            }

            $id = (int) DB::table('gates_vote_messages')->insertGetId($row);
            return ['ok' => true, 'status' => $status, 'id' => $id, 'token' => (string) $row['share_token']];
        } catch (\Throwable $e) {
            // Logged, not swallowed — but never fatal. The vote already happened.
            error_log('[vote-message] could not store for nominee ' . $nomineeId . ': ' . $e->getMessage());
            return ['ok' => false, 'code' => 'STORE_FAILED', 'message' => 'Could not save your message.'];
        }
    }

    /**
     * Score a message and decide what happens to it.
     *
     * ── IT DELEGATES, AND THAT IS THE POINT ─────────────────────────────────
     *
     * {@see SpamService::evaluate()} is this platform's single content-moderation
     * decision: heuristics first, the AI gateway only for borderline text, the
     * admin thresholds applied inside it, the model able to RAISE a score but never
     * lower it, and a budgeted, kill-switched provider chain behind it. Every one of
     * those is a property this feature wants, and none is worth reimplementing —
     * two different answers to "is this sentence publishable" depending on whether
     * it arrived as a community comment or a vote message would be a bug nobody
     * notices until it matters.
     *
     * Two checks run FIRST because they are cheap, deterministic, and specific to
     * this surface rather than to content in general: a nominee's page is a
     * high-value target for link spam and for harvesting contact details, and
     * neither needs a language model to spot.
     *
     * @return array{0:string, 1:?float, 2:?string} [status, score, reason]
     */
    private static function classify(string $body, ?SpamService $spam = null): array
    {
        if (preg_match('~https?://|www\.~i', $body)) {
            return ['quarantined', null, 'contains a link'];
        }
        if (AiPrivacy::containsContactDetail($body)) {
            return ['quarantined', null, 'contains contact details'];
        }

        try {
            $v      = ($spam ?? new SpamService())->evaluate($body, ['target' => 'vote_message']);
            $score  = isset($v['score'])  ? (float) $v['score'] : null;
            $reason = isset($v['reason']) ? mb_substr((string) $v['reason'], 0, 190) : null;

            return match ($v['decision'] ?? 'quarantine') {
                'allow'  => ['approved',    $score, null],
                'reject' => ['rejected',    $score, $reason],
                default  => ['quarantined', $score, $reason],
            };
        } catch (\Throwable $e) {
            // Held, not published. An unreviewed message nobody sees is a delay; an
            // unreviewed message on a nominee's page is something that person has to
            // read about themselves. Only one of those is recoverable.
            error_log('[vote-message] moderation unavailable: ' . $e->getMessage());
            return ['pending', null, 'moderation unavailable'];
        }
    }

    /** Collapse whitespace, strip markup, cap length. */
    private static function clean(string $s): string
    {
        $s = strip_tags($s);
        $s = preg_replace('/[ \t\x{00A0}]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\n{3,}/', "\n\n", $s) ?? $s;
        return mb_substr(trim($s), 0, self::MAX_LEN);
    }

    /** URL-safe opaque token, so a share link cannot be walked by incrementing. */
    private static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Reads
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The public wall for a nominee: approved messages, newest first.
     *
     * `display_name` is replaced with a generic label unless the voter consented to
     * be named — done HERE rather than in the template, because a template that
     * has the name in scope is one edit away from printing it.
     *
     * @return list<array<string,mixed>>
     */
    public static function wall(int $nomineeId, int $limit = 20, int $offset = 0): array
    {
        try {
            $rows = DB::table('gates_vote_messages')
                ->where('nominee_id', $nomineeId)
                ->where('status', 'approved')
                ->whereNull('deleted_at')
                ->orderByDesc('created_at')->orderByDesc('id')
                ->limit(max(1, min(100, $limit)))->offset(max(0, $offset))
                ->get();
        } catch (\Throwable) {
            return [];
        }

        return $rows->map(static fn ($r) => [
            'id'      => (int) $r->id,
            'body'    => (string) $r->body,
            'name'    => ((int) $r->show_name === 1 && ($r->display_name ?? '') !== '')
                            ? (string) $r->display_name : 'A supporter',
            'named'   => (int) $r->show_name === 1 && ($r->display_name ?? '') !== '',
            'source'  => (string) $r->source,
            'cheers'  => (int) $r->cheers,
            'token'   => (string) ($r->share_token ?? ''),
            'when'    => (string) ($r->created_at ?? ''),
        ])->all();
    }

    public static function countForNominee(int $nomineeId): int
    {
        try {
            return (int) DB::table('gates_vote_messages')
                ->where('nominee_id', $nomineeId)->where('status', 'approved')
                ->whereNull('deleted_at')->count();
        } catch (\Throwable) { return 0; }
    }

    /**
     * One message by its share token, for the permalink that Facebook reads.
     *
     * Only ever returns an APPROVED message: a share link minted at submission time
     * must not become a way to view something a moderator later rejected.
     *
     * @return array<string,mixed>|null
     */
    public static function byToken(string $token): ?array
    {
        if ($token === '') return null;
        try {
            $r = DB::table('gates_vote_messages as m')
                ->join('gates_nominees as n', 'n.id', '=', 'm.nominee_id')
                ->where('m.share_token', $token)
                ->where('m.status', 'approved')
                ->whereNull('m.deleted_at')
                ->select('m.*', 'n.name as nominee_name', 'n.photo_path as nominee_photo',
                         'n.category_id as nominee_category')
                ->first();
        } catch (\Throwable) { return null; }
        if ($r === null) return null;

        return [
            'id'            => (int) $r->id,
            'body'          => (string) $r->body,
            'name'          => ((int) $r->show_name === 1 && ($r->display_name ?? '') !== '')
                                  ? (string) $r->display_name : 'A supporter',
            'token'         => (string) $r->share_token,
            'nominee_id'    => (int) $r->nominee_id,
            'nominee_name'  => (string) $r->nominee_name,
            'nominee_photo' => (string) ($r->nominee_photo ?? ''),
            'when'          => (string) ($r->created_at ?? ''),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Moderation
    // ─────────────────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> the queue: what a person still has to decide */
    public static function queue(int $limit = 50): array
    {
        try {
            return DB::table('gates_vote_messages as m')
                ->leftJoin('gates_nominees as n', 'n.id', '=', 'm.nominee_id')
                ->whereIn('m.status', ['pending', 'quarantined'])
                ->whereNull('m.deleted_at')
                ->orderBy('m.created_at')
                ->limit(max(1, min(200, $limit)))
                ->select('m.*', 'n.name as nominee_name')
                ->get()->map(fn ($r) => (array) $r)->all();
        } catch (\Throwable) { return []; }
    }

    /** Approve or reject. Returns false when the id is unknown. */
    public static function decide(int $id, string $decision, ?int $adminId = null): bool
    {
        $status = $decision === 'approve' ? 'approved' : 'rejected';
        try {
            return DB::table('gates_vote_messages')->where('id', $id)->update([
                'status'       => $status,
                'moderated_by' => $adminId,
                'moderated_at' => date('Y-m-d H:i:s'),
            ]) > 0;
        } catch (\Throwable) { return false; }
    }

    /** Soft-delete, so a withdrawn message leaves an audit trail rather than a hole. */
    public static function withdraw(int $id, ?int $adminId = null): bool
    {
        try {
            return DB::table('gates_vote_messages')->where('id', $id)->update([
                'deleted_at'   => date('Y-m-d H:i:s'),
                'moderated_by' => $adminId,
            ]) > 0;
        } catch (\Throwable) { return false; }
    }
}
