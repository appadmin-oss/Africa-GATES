<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * Rich HTML into the two portable shapes a published document has to leave in.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────────
 *
 * The terms and the privacy policy are authored in the admin rich-text editor and
 * stored as sanitized HTML (`gates_legal_docs.body_html`). Giving those pages the
 * same Copy and Download the philosophy has means turning that HTML into text and
 * into Markdown — and `strip_tags()` cannot do it. Run it over a policy and you get
 * every heading, paragraph and list item concatenated into one wall with no
 * boundaries, which is a legal document rendered unreadable at exactly the moment
 * somebody wanted to keep a copy of it.
 *
 * So this walks the block structure and emits real boundaries: blank lines between
 * paragraphs, underlines under headings, hanging markers on list items.
 *
 * ── WHAT IT DELIBERATELY DOES NOT DO ─────────────────────────────────────────
 *
 * It is not a general HTML→Markdown converter and should not grow into one. The
 * input is a KNOWN, SANITIZED subset — {@see Html::sanitize()} decides what that
 * subset is — so the tag list below is the whole domain. Tables, images, iframes and
 * nested lists are not handled because the sanitizer does not let them through; if
 * that ever changes, this needs to change with it rather than silently dropping
 * content, which is why unknown block tags fall through to their text rather than
 * being skipped.
 */
final class DocText
{
    /** Block-level tags that force a boundary; everything else is inline. */
    /**
     * The tags that end a block of text.
     *
     * ── `td` AND `th` WERE MISSING, AND THE POLICY DOWNLOADS WERE MUSH ───────
     *
     * `tr` was here and the cells were not, so every cell in a row concatenated into one
     * run: `/cookies.txt` published "NameWhyHow longKind" and then "PHPSESSIDIdentifies
     * your session, so you stay signed in…Until you close your browser, or sign outEssential".
     * The cookie policy has carried a table since the legal pages shipped, so both
     * downloadable editions of a published legal document have been illegible that whole
     * time — and nothing showed it, because the HTML page renders perfectly.
     */
    private const BLOCKS = ['h1','h2','h3','h4','h5','h6','p','ul','ol','li','blockquote',
                            'div','section','table','tr','th','td'];

    /**
     * Inline HTML to a clean single-line string.
     *
     * Entities are decoded BEFORE tags are stripped. The bodies are authored for
     * HTML and are full of `&rsquo;`, `&mdash;` and `&#8358;`; a clipboard holding a
     * literal `&rsquo;` is a broken paste, not a document.
     */
    public static function inline(string $html): string
    {
        $s = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/[ \t\r\n]+/u', ' ', strip_tags($s)) ?? '');
    }

    /**
     * The document as plain text, wrapped to a readable measure.
     *
     * @param int $width columns to wrap at; 0 leaves lines unwrapped
     */
    public static function toText(string $html, int $width = 78): string
    {
        $out   = [];
        $cells = [];      // cells of the row being read
        $head  = [];      // the table's first row, reused as the labels below

        foreach (self::blocks($html) as [$tag, $text, $index]) {
            // NOT `if ($text === '') continue;` first: a row boundary and a table boundary
            // are deliberately empty, and skipping them threw every table away silently —
            // the version before this one dropped the cookie table out of /cookies.txt
            // entirely while every other section rendered perfectly.
            $wrap = static fn (string $s, int $indent = 0): string =>
                $width > 0 ? wordwrap($s, max(20, $width - $indent), "\n", false) : $s;

            // ── A TABLE IS RENDERED AS RECORDS, NOT AS COLUMNS ───────────────
            //
            // Column alignment in plain text needs a width the reader's terminal, mail
            // client or screen reader may not have, and a wrapped cell destroys it
            // silently. The cookie table's `why` column is a paragraph, so aligned columns
            // were never going to survive anyway.
            //
            // So the header row is remembered and each later row is printed as
            // `Label: value` lines — which is what somebody reading a policy actually
            // wants, and is what a screen reader announces for a data table in any case.
            if ($tag === 'th' || $tag === 'td') { $cells[] = $text; continue; }
            if ($tag === 'tr') {
                if ($cells === []) continue;
                if ($head === []) { $head = $cells; $cells = []; continue; }
                foreach ($cells as $i => $cell) {
                    $label = trim((string) ($head[$i] ?? ''));
                    $line  = ($label !== '' ? $label . ': ' : '') . $cell;
                    $lines = explode("\n", $wrap($line, 4));
                    $out[] = '  ' . array_shift($lines);
                    foreach ($lines as $l) $out[] = '    ' . $l;
                }
                $out[]  = '';
                $cells  = [];
                continue;
            }
            // A new table forgets the last one's headings.
            if ($tag === 'table') { $head = []; $cells = []; continue; }

            if ($text === '') continue;

            switch ($tag) {
                case 'h1':
                    $out[] = mb_strtoupper($text);
                    $out[] = str_repeat('=', min($width ?: 78, max(8, mb_strlen($text))));
                    $out[] = '';
                    break;
                case 'h2':
                    $out[] = mb_strtoupper($text);
                    $out[] = str_repeat('-', min($width ?: 78, max(8, mb_strlen($text))));
                    $out[] = '';
                    break;
                case 'h3': case 'h4': case 'h5': case 'h6':
                    $out[] = $text;
                    $out[] = '';
                    break;
                case 'li':
                    // A numbered marker where the list said so, a dash where it did not.
                    $marker = $index > 0 ? '  ' . $index . '. ' : '  - ';
                    $body   = $wrap($text, 4);
                    $lines  = explode("\n", $body);
                    $out[]  = $marker . array_shift($lines);
                    foreach ($lines as $l) $out[] = str_repeat(' ', mb_strlen($marker)) . $l;
                    break;
                case 'blockquote':
                    $out[] = $wrap('"' . $text . '"', 4);
                    $out[] = '';
                    break;
                case 'ul': case 'ol':
                    // The <li>s were emitted individually; the list itself just closes.
                    $out[] = '';
                    break;
                default:
                    $out[] = $wrap($text);
                    $out[] = '';
            }
        }
        return self::tidy($out);
    }

    /** The document as Markdown. */
    public static function toMarkdown(string $html): string
    {
        $out   = [];
        $cells = [];
        $head  = false;   // has this table's header separator been written?

        foreach (self::blocks($html) as [$tag, $text, $index]) {
            // Markdown HAS a table, so this one is a table — but the pipe is its cell
            // separator, so a cell containing one would split the row. Escaped, not
            // stripped: a policy may legitimately quote a `|`.
            if ($tag === 'th' || $tag === 'td') {
                $cells[] = str_replace('|', '\\|', $text);
                continue;
            }
            if ($tag === 'tr') {
                if ($cells === []) continue;
                $out[] = '| ' . implode(' | ', $cells) . ' |';
                if (!$head) {
                    $out[] = '|' . str_repeat(' --- |', count($cells));
                    $head  = true;
                }
                $cells = [];
                continue;
            }
            if ($tag === 'table') { $out[] = ''; $head = false; $cells = []; continue; }

            if ($text === '') continue;
            switch ($tag) {
                case 'h1': $out[] = '# '     . $text; $out[] = ''; break;
                case 'h2': $out[] = '## '    . $text; $out[] = ''; break;
                case 'h3': $out[] = '### '   . $text; $out[] = ''; break;
                case 'h4': $out[] = '#### '  . $text; $out[] = ''; break;
                case 'h5': case 'h6': $out[] = '##### ' . $text; $out[] = ''; break;
                case 'li': $out[] = ($index > 0 ? $index . '. ' : '- ') . $text; break;
                case 'blockquote': $out[] = '> ' . $text; $out[] = ''; break;
                case 'ul': case 'ol': $out[] = ''; break;
                default: $out[] = $text; $out[] = ''; break;
            }
        }
        return self::tidy($out);
    }

    /**
     * Split HTML into [tag, text, ordinalWithinOrderedList] triples, in document order.
     *
     * Regex rather than DOMDocument on purpose: the input is a sanitized subset, and
     * DOMDocument on a fragment either warns about missing <html> or silently
     * rearranges it. What matters here is the sequence of block boundaries, which the
     * tag stream gives directly.
     *
     * @return list<array{0:string, 1:string, 2:int}>
     */
    private static function blocks(string $html): array
    {
        // Normalise <br> to a boundary before anything else — inside a paragraph it
        // is the author's line break and dropping it would run two lines together.
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;

        $parts = preg_split(
            '#<(/?)(' . implode('|', self::BLOCKS) . ')\b[^>]*>#i',
            $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        ) ?: [];

        $out = [];
        $stack = [];            // open block tags
        $ordered = [];          // counters for nested <ol>
        $buffer = '';

        $flush = function (?string $tag) use (&$buffer, &$out, &$ordered): void {
            $text = self::inline($buffer);
            $buffer = '';
            if ($text === '' || $tag === null) return;
            $n = 0;
            // A negative counter is the sentinel for <ul>: an unordered item takes a
            // dash, and only an <ol> item consumes a number.
            if ($tag === 'li' && $ordered !== []) {
                $key = count($ordered) - 1;
                if ($ordered[$key] >= 0) {
                    $ordered[$key]++;
                    $n = $ordered[$key];
                }
            }
            $out[] = [$tag, $text, $n];
        };

        for ($i = 0; $i < count($parts); $i += 3) {
            $buffer .= $parts[$i];

            $closing = ($parts[$i + 1] ?? null) === '/';
            $tag     = isset($parts[$i + 2]) ? strtolower((string) $parts[$i + 2]) : null;
            if ($tag === null) continue;

            if (!$closing) {
                // Text sitting before this open tag belongs to whatever is already open.
                $flush($stack === [] ? null : end($stack));
                $stack[] = $tag;
                if ($tag === 'ol') $ordered[] = 0;
                if ($tag === 'ul') $ordered[] = -1;
            } else {
                $flush($tag);
                // Pop to the matching open tag, tolerating unclosed inline nesting.
                while ($stack !== [] && array_pop($stack) !== $tag) { /* discard */ }
                if ($tag === 'ol' || $tag === 'ul') {
                    array_pop($ordered);
                    $out[] = [$tag, '—', 0];   // a boundary marker; text is unused
                }
                // ── A ROW BOUNDARY IS EMITTED ON THE CLOSE, NOT BY $flush ────
                //
                // The same shape as the list markers above, and for a reason found the
                // hard way: an OPENING tag flushes whatever is already on the stack, so
                // `<tr><th>` flushes with the tag `tr` and no text. Letting $flush emit an
                // empty `tr` therefore produced a row boundary before every single cell,
                // and the cookie table came out as one column of one-cell rows — a
                // different kind of illegible from the one being fixed.
                if ($tag === 'tr' || $tag === 'table') {
                    $out[] = [$tag, '—', 0];
                }
            }
        }
        $flush($stack === [] ? 'p' : end($stack));

        return $out;
    }

    /** Collapse runs of blank lines and finish with exactly one newline. */
    private static function tidy(array $lines): string
    {
        $s = implode("\n", $lines);
        $s = preg_replace("/\n{3,}/", "\n\n", $s) ?? $s;
        return rtrim($s) . "\n";
    }
}
