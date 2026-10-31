<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\NominationStatus as NS;
use AfricaGates\Support\SchemaHas;
use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * "Ask for more" — the review desk's third decision, and the nominator's way back.
 *
 * A reviewer who cannot decide yet writes what is missing; the nomination moves to
 * `needs_details` (the list's Asked tab) and the nominator is emailed ONE link. The
 * link opens a page that takes what was asked for — links and files, the same kinds
 * the nomination form takes — and sends the nomination straight back to the queue.
 *
 * The page is the whole point. An ask with no way to answer it is a rejection that
 * tells nobody it is one: the nomination would sit in Asked for ever while the
 * nominator waited for a decision that could never come.
 *
 * Only the token's sha256 is stored, so the database never holds a working link. The
 * link lasts {@see TTL_DAYS} days and the email states the date it stops, read from the
 * same constant. Nothing closes the nomination when it lapses — the reviewer still
 * holds it in Asked and decides — so the email promises nothing about closing.
 */
final class NominationAsk
{
    public const TTL_DAYS = 30;
    public const MAX_MESSAGE = 1200;
    public const MAX_LINKS = 3;

    /** Do the columns this needs exist yet? Asked before any write, never assumed. */
    public static function available(): bool
    {
        foreach (['asked_message', 'asked_at', 'ask_token_hash'] as $c) {
            if (!SchemaHas::column('gates_nominations', $c)) return false;
        }
        return true;
    }

    /**
     * Ask the nominator for more. Mail goes BEFORE the decision is kept: if it does not
     * leave, nothing changes — an "asked" nobody received is the dead end this exists
     * to prevent.
     *
     * @return array{ok:bool, error?:string}
     */
    public static function ask(object $nom, string $message, ?OtpService $mailer): array
    {
        $message = trim(mb_substr($message, 0, self::MAX_MESSAGE));
        if ((string) ($nom->status ?? '') !== NS::PENDING) return ['ok' => false, 'error' => 'Only a nomination waiting for review can be asked about.'];
        if ($message === '') return ['ok' => false, 'error' => 'Write what is missing first. The nominator sees it.'];
        $email = trim((string) ($nom->nominator_email ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'The nominator left no email address, so they cannot be asked. Approve, reject or skip it.'];
        }
        if (!self::available()) return ['ok' => false, 'error' => 'The database needs its latest update before this works.'];
        if ($mailer === null) return ['ok' => false, 'error' => 'Email is not set up, so the request could not be sent.'];

        $token = bin2hex(random_bytes(32));
        $closes = date('j F Y', time() + self::TTL_DAYS * 86400);
        $name = (string) ($nom->nominator_name ?? '');
        $r = ['success' => false];
        try {
            $r = Mail\Emails::send($mailer, $email, 'asked', [
                'nominee'       => (string) $nom->nominee_name,
                'first_name'    => DoorWelcome::firstName($name) ?: $name,
                'reviewer_note' => $message,
                'fix_url'       => self::url($token),
                'closes_on'     => $closes,
                'site_url'      => SiteUrl::base(),
            ]);
        } catch (\Throwable $e) {
            error_log('[nomination-ask] mail: ' . $e->getMessage());
        }
        if (!($r['success'] ?? false)) {
            return ['ok' => false, 'error' => 'The email did not send, so nothing changed. Check Settings → Email and try again.'];
        }

        DB::table('gates_nominations')->where('id', (int) $nom->id)->where('status', NS::PENDING)->update([
            'status'         => NS::NEEDS_DETAILS,
            'asked_message'  => $message,
            'asked_at'       => date('Y-m-d H:i:s'),
            'ask_token_hash' => hash('sha256', $token),
        ]);
        return ['ok' => true];
    }

    public static function url(string $token): string
    {
        return rtrim(SiteUrl::base(), '/') . '/nominate/answer/' . $token;
    }

    /** The nomination a link answers, while the link still works; null otherwise. */
    public static function find(string $token): ?object
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token) || !self::available()) return null;
        try {
            $n = DB::table('gates_nominations')->where('ask_token_hash', hash('sha256', $token))
                ->where('status', NS::NEEDS_DETAILS)->first();
        } catch (\Throwable) {
            return null;
        }
        if (!$n) return null;
        $at = strtotime((string) ($n->asked_at ?? '')) ?: 0;
        return ($at > 0 && $at + self::TTL_DAYS * 86400 >= time()) ? $n : null;
    }

    /** When a link stops working, for the page that shows it. */
    public static function closesOn(object $nom): string
    {
        $at = strtotime((string) ($nom->asked_at ?? '')) ?: time();
        return date('j F Y', $at + self::TTL_DAYS * 86400);
    }

    /**
     * Keep what the nominator added and put the nomination back in the queue.
     * Links are checked here, not only in the form; files arrive already stored.
     *
     * @param list<string> $links
     * @param list<array{path:string,label:?string,mime:?string,bytes:?int,sha256?:?string}> $files
     * @return int how many pieces were kept
     */
    public static function answer(object $nom, array $links, array $files): int
    {
        $kept = 0;
        $now = date('Y-m-d H:i:s');
        $hasEvidence = SchemaHas::table('gates_nomination_evidence');
        foreach (array_slice($links, 0, self::MAX_LINKS) as $u) {
            $u = trim($u);
            if (!NominationRules::isHttpUrl($u)) continue;
            if ($hasEvidence) {
                DB::table('gates_nomination_evidence')->insert([
                    'nomination_id' => (int) $nom->id, 'kind' => NominationRules::EVIDENCE_LINK,
                    'url' => mb_substr($u, 0, 600), 'label' => mb_substr((string) (parse_url($u, PHP_URL_HOST) ?: ''), 0, 200),
                    'created_at' => $now,
                ]);
                $kept++;
            }
        }
        foreach ($files as $f) {
            if (AwardService::recordEvidenceFile((int) $nom->id, $f['path'], $f['label'], $f['mime'], $f['bytes'], $f['sha256'] ?? null)) $kept++;
        }
        if ($kept === 0) return 0;

        // Back to the queue, and the link is spent: one answer per ask.
        DB::table('gates_nominations')->where('id', (int) $nom->id)->where('status', NS::NEEDS_DETAILS)
            ->update(['status' => NS::PENDING, 'ask_token_hash' => null]);
        // The AI's reading was of the nomination BEFORE this answer — it is exactly the
        // reading whose gap the reviewer asked about. Read again, off the request path.
        try { NominationTriageService::enqueue((int) $nom->id); } catch (\Throwable) {}
        try { NominationCategoryFit::enqueue((int) $nom->id); } catch (\Throwable) {}
        return $kept;
    }
}
