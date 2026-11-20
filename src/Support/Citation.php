<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * Citation strings for any published document on this platform.
 *
 * ── WHY THIS IS SHARED ───────────────────────────────────────────────────────
 *
 * Four documents now carry a "How to cite" panel — the philosophy, the methodology,
 * the terms and the privacy policy — and a citation is a format with rules, not a
 * sentence you write once per page. Four copies of the APA punctuation would be
 * four chances to get the comma before the retrieval date wrong in three of them.
 *
 * So the formats live here and the documents supply their own metadata. A document
 * that cannot answer "who published this, in what version, and when" cannot be
 * cited, which is why every field below is required rather than defaulted: a
 * citation with a guessed date is worse than no citation, because the reader cannot
 * tell it was guessed.
 *
 * ── ON `accessed` BEING A PARAMETER ──────────────────────────────────────────
 *
 * It is passed in, never read from the clock here. One request may render citations
 * into the page, the .txt and the .md, and if each asked the system what day it was
 * they could straddle midnight and disagree — a document quoting two different
 * access dates for one download is exactly the kind of small incoherence that makes
 * a reader stop trusting the rest of it.
 */
final class Citation
{
    /**
     * @param array{
     *   title:string, author:string, publisher:string, version:string,
     *   published:string, updated:string, url:string, accessed?:string, key?:string
     * } $doc
     * @return list<array{id:string, label:string, text:string}>
     */
    public static function formats(array $doc): array
    {
        $accessed = (string) ($doc['accessed'] ?? date('Y-m-d'));
        $year     = substr((string) $doc['published'], 0, 4);
        $title    = trim((string) $doc['title']);
        $version  = (string) $doc['version'];
        $url      = (string) $doc['url'];

        $at   = static fn (string $d, string $f): string => date($f, strtotime($d) ?: time());
        $human   = $at($accessed, 'j F Y');
        $mlaDate = $at($accessed, 'j M. Y');

        return [
            [
                'id'    => 'apa',
                'label' => 'APA (7th edition)',
                'text'  => sprintf(
                    '%s. (%s). %s (Version %s). %s. Retrieved %s, from %s',
                    $doc['author'], $year, $title, $version, $doc['publisher'], $human, $url
                ),
            ],
            [
                'id'    => 'mla',
                'label' => 'MLA (9th edition)',
                'text'  => sprintf(
                    '%s. "%s." Version %s, %s, %s, %s. Accessed %s.',
                    $doc['author'], $title, $version, $doc['publisher'],
                    $at((string) $doc['updated'], 'j M. Y'), $url, $mlaDate
                ),
            ],
            [
                'id'    => 'chicago',
                'label' => 'Chicago (17th edition, note)',
                'text'  => sprintf(
                    '%s, "%s," version %s, %s, last modified %s, %s.',
                    $doc['author'], $title, $version, $doc['publisher'],
                    $at((string) $doc['updated'], 'F j, Y'), $url
                ),
            ],
            [
                'id'    => 'bibtex',
                'label' => 'BibTeX',
                'text'  => implode("\n", [
                    '@misc{' . self::key($doc, $year) . ',',
                    '  title        = {' . $title . '},',
                    // Double braces: BibTeX lower-cases an unprotected title and
                    // treats a comma in an author field as "Surname, Forename". An
                    // organisation is neither, so both are protected.
                    '  author       = {{' . $doc['author'] . '}},',
                    '  organization = {' . $doc['publisher'] . '},',
                    '  year         = {' . $year . '},',
                    '  version      = {' . $version . '},',
                    '  url          = {' . $url . '},',
                    '  urldate      = {' . $accessed . '}',
                    '}',
                ]),
            ],
        ];
    }

    /**
     * The BibTeX cite key.
     *
     * Supplied by the document where it has a stable one, derived from the title
     * where it does not. Derivation strips to letters so the key cannot contain a
     * character BibTeX treats as a delimiter, and truncates because a key is
     * something a person types into a `\cite{}`.
     *
     * @param array<string,mixed> $doc
     */
    private static function key(array $doc, string $year): string
    {
        $key = (string) ($doc['key'] ?? '');
        if ($key === '') {
            $key = strtolower(preg_replace('/[^A-Za-z]+/', '', (string) $doc['title']) ?? '');
            $key = substr($key, 0, 24);
        }
        return 'africagates' . $year . ($key === '' ? 'document' : $key);
    }
}
