<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * THE PLATFORM'S NAME, ONE WAY — "Africa GATES" (owner, 16 Oct 2026).
 *
 * "African G8" reached the public as the kicker and summary of the paid-voting help
 * article, and the owner wants it gone and kept gone wherever an award speaks for itself:
 * its details and its terms. Those two are typed by operators, pasted from documents that
 * predate the name, and drafted by a model, so the rule lives here and runs at three
 * points: when award text is SAVED, when it is RENDERED (a document survives the code
 * that wrote it — an import, a restore), and once over stored rows
 * (`2027_03_20_brand_name_repair.php`).
 *
 * It rewrites only the spellings of the retired name — "Africa G8", "African G8",
 * "AfricaG8", "Africa-G8", any case — and "African GATES", the half-right one. It never
 * touches an address or a domain ("africagates.org", "info@africa-g8.org" stays as typed,
 * because a rewritten link is a broken one), never touches "G8" on its own (the Group of Eight is a real thing a nominee's citation may
 * mention) and never re-cases a correct "Africa GATES".
 */
final class Brand
{
    public const NAME = 'Africa GATES';

    /**
     * The retired spellings. Any "Africa(n) G8", joined or not; and "African GATES" only with
     * a space between — never a joined "africagates" or "africa-gates", which is how an
     * email address or a domain spells this platform and must stay byte for byte.
     */
    private const RETIRED = '/(?<![\w@.\/-])(?:African?[\s\-]*G8|African\s+GATES)(?![\w.-]*\.[a-z]{2,})\b/iu';

    public static function normalise(?string $text): string
    {
        $t = (string) $text;
        if ($t === '') return '';
        return (string) preg_replace_callback(self::RETIRED, static function (array $m): string {
            return $m[0] === self::NAME ? $m[0] : self::NAME;
        }, $t);
    }

    /** Does this text still carry a retired spelling? For sweeps and the repair. */
    public static function needsRepair(?string $text): bool
    {
        return self::normalise($text) !== (string) $text;
    }
}
