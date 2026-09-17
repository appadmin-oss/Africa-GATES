<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Citation;

/**
 * Identity and citation for the methodology page (/integrity).
 *
 * ── WHY THIS IS METADATA AND NOT PROSE ───────────────────────────────────────
 *
 * {@see CommunityVotingPhilosophy} holds its prose, because that document has to
 * leave in four shapes and one source is the only way four renderings agree. The
 * methodology is different: its sections are built around tables of live figures
 * read from {@see RuleEngine}, and a table of settings is a thing HTML does well
 * and a plain-text file does badly. Its prose therefore stays in
 * `templates/pages/integrity.twig` and this class carries only what makes the page
 * CITABLE — a title, an author, a version and two dates.
 *
 * ── WHICH IS WHY /integrity HAS NO DOWNLOAD BUTTON ───────────────────────────
 *
 * It has Cite, and it does not have Copy or Download, and that is deliberate. A
 * Download that handed the reader a different document from the one they were
 * looking at would be worse than no Download: they would file it, cite it, and
 * find out later. The philosophy has both because its .txt IS its page. When the
 * methodology's prose moves into PHP the way the philosophy's has, this class
 * grows plainText()/markdown() and the page gets the full toolbar — until then the
 * missing buttons are an honest signal rather than an oversight.
 *
 * ── VERSIONING ───────────────────────────────────────────────────────────────
 *
 * Bump VERSION and UPDATED together when the METHOD changes — a new eligibility
 * rule, a different tiebreak, a changed quorum. Do NOT bump them when a figure
 * moves: an operator changing the community/judge split in Settings has not
 * revised the methodology, because the methodology's claim is "the split is
 * whatever the engine says", and that claim is unchanged. The page reads the
 * engine live, so it reports the new figure without a new version.
 */
final class MethodologyDocument
{
    public const VERSION   = '1.0';
    public const PUBLISHED = '2026-08-08';
    public const UPDATED   = '2026-08-08';

    public const TITLE    = 'How the Cultural Power Index is Scored';
    public const SUBTITLE = 'The Africa GATES recognition methodology, end to end';
    public const AUTHOR   = 'Africa GATES Integrity Centre';
    public const PUBLISHER = 'Africa GATES — An Afrovanguard Initiative';
    public const PATH     = '/integrity';

    /** @return list<array{id:string, label:string, text:string}> */
    public static function citations(string $url, ?string $accessed = null): array
    {
        return Citation::formats([
            'title'     => self::TITLE,
            'author'    => self::AUTHOR,
            'publisher' => self::PUBLISHER,
            'version'   => self::VERSION,
            'published' => self::PUBLISHED,
            'updated'   => self::UPDATED,
            'url'       => $url,
            'accessed'  => $accessed ?? date('Y-m-d'),
            'key'       => 'methodology',
        ]);
    }
}
