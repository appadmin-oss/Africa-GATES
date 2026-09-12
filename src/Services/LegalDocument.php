<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Citation;
use AfricaGates\Support\CookieRegistry;
use AfricaGates\Support\DocText;
use AfricaGates\Support\Html;
use AfricaGates\Support\Slug;

/**
 * A legal document as something a reader can cite, copy and keep.
 *
 * ── THE PROBLEM THIS SOLVES ──────────────────────────────────────────────────
 *
 * The terms and the privacy policy are admin-authored HTML in `gates_legal_docs`,
 * and giving them the same Copy / Download / Cite the philosophy has looked like a
 * one-liner: run {@see DocText} over `body_html` and serve it.
 *
 * It is not, because on /privacy the rendered page is NOT `body_html`. It is
 * `body_html` plus an automated-processing disclosure generated from
 * {@see AiPrivacy::disclosure()} — a section that exists precisely because
 * hand-writing "we send nomination text to a third-party model" into the editable
 * body would go stale the first time a capability changed. A download of the body
 * alone would therefore have silently omitted the AI disclosure from the privacy
 * policy: the one section a reader most plausibly downloaded the document to keep.
 *
 * So {@see bodyHtml()} is the single source, and the page renders it too. The
 * disclosure markup moved out of `legal.twig` and into {@see disclosureHtml()} for
 * exactly that reason — one builder, so the page and the file cannot differ.
 *
 * ── ON "VERSION" FOR A POLICY ────────────────────────────────────────────────
 *
 * There is no version column on the table and one should not be invented here.
 * Policy documents are cited by effective date, not by semver, so the date IS the
 * version and every citation format says so. `updated_at` is the machine-readable
 * source; `updated_label` is what an administrator typed for humans, and the two
 * can legitimately differ ("8 August 2026" vs a timestamp), so the label is never
 * used where a date needs parsing.
 */
final class LegalDocument
{
    public const AUTHOR    = 'Africa GATES';
    public const PUBLISHER = 'Africa GATES — An Afrovanguard Initiative';

    /**
     * The document's full body: the authored HTML plus anything generated.
     *
     * Sanitized here rather than in the template, because two callers now render it
     * and only one of them is Twig.
     */
    public static function bodyHtml(array $doc): string
    {
        $html = Html::sanitize((string) ($doc['body_html'] ?? ''));

        if (($doc['slug'] ?? '') === 'privacy') {
            $html .= self::disclosureHtml();
            $html .= self::voiceHtml();
        }
        if (($doc['slug'] ?? '') === 'cookies') {
            $html .= self::cookiesHtml();
        }
        return $html;
    }

    /**
     * The automated-processing disclosure, generated from the capability registry.
     *
     * Was inline in `legal.twig`. It is here so the .txt and .md editions carry it
     * too — see the class docblock. Emits the same restricted tag set the sanitizer
     * allows, so it survives {@see Html::sanitize()} unchanged and needs no
     * exemption.
     */
    public static function disclosureHtml(?array $groups = null): string
    {
        // The parameter exists so the escaping can be tested with a hostile string.
        // When this was a Twig loop, autoescape was the seam and a test could inject
        // through the template variable; now the escaping is htmlspecialchars in this
        // method, so the injection point has to be here or that test can only assert
        // against whatever the real registry happens to contain today.
        $groups ??= AiPrivacy::disclosure();
        if ($groups === []) return '';

        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $h = [];

        $h[] = '<h2 id="automated-processing">Automated processing (AI)</h2>';
        $h[] = '<p>Some parts of this platform send text to a third-party language model to help '
             . 'with moderation, triage and optional writing suggestions. This section is generated '
             . 'from the platform&rsquo;s own configuration, so it always describes what the code '
             . 'actually does.</p>';
        $h[] = '<p><strong>Three things are true of every item below.</strong> A model&rsquo;s output '
             . 'is advisory: it never approves, rejects, ranks or decides anything on its own &mdash; '
             . 'a person makes every decision that affects a nomination. Contact details are replaced '
             . 'with placeholders such as <code>[email]</code> and <code>[phone]</code> before text is '
             . 'sent, where noted. And nominee email addresses and phone numbers collected on the '
             . 'nomination form are never sent to a model at all.</p>';
        $h[] = '<p>Names <em>are</em> sent, because a feature that cannot see who was nominated could '
             . 'not do its job. We would rather say so plainly than imply otherwise.</p>';

        foreach ($groups as $group) {
            // The label comes from AiPrivacy::providerLabel() so company names are
            // spelled the way the companies spell them — `|capitalize` once rendered
            // "openai" as "Openai" in a published legal notice.
            $h[] = '<h3>Sent to ' . $e((string) $group['label'])
                 . (($group['primary'] ?? false) ? '' : ' <small>(only when the primary provider is unavailable)</small>')
                 . '</h3>';
            $h[] = '<ul>';
            foreach ((array) ($group['capabilities'] ?? []) as $cap) {
                $line = '<li><strong>' . $e((string) ($cap['purpose'] ?? '')) . '</strong><br>'
                      . $e((string) ($cap['sends'] ?? ''));
                // Per FEATURE, not per provider. One provider can be the pin for one
                // feature and the standby for another — Google reads uploaded documents
                // because nothing else can, and also stands in for Groq elsewhere — so a
                // heading-level caveat would be wrong about half the list beneath it. The
                // note is only printed where the heading did not already say it.
                if (($group['primary'] ?? false) && !($cap['primary'] ?? true)) {
                    $line .= ' <em>This one only comes here when the usual provider is unavailable.</em>';
                }
                if (!($cap['minimised'] ?? false)) {
                    $line .= ' <em>Contact details in this request are not altered, because the '
                           . 'request is made by an administrator about data they already hold.</em>';
                }
                $h[] = $line . '</li>';
            }
            $h[] = '</ul>';
        }

        $h[] = '<p>' . (AiPrivacy::currentlyActive()
                ? 'These features are currently switched on.'
                : 'These features are currently switched off, so no text is being sent to any model '
                . 'right now.')
             . ' An administrator can disable any of them individually or all of them at once.</p>';
        $h[] = '<p>What we cannot yet tell you: how long each provider retains what it receives, and '
             . 'whether they use it to train their models. That is governed by the provider&rsquo;s own '
             . 'terms rather than ours, and we would rather leave this paragraph honest than fill it '
             . 'with an assurance we have not verified. If that matters to your decision to nominate, '
             . 'email <a href="mailto:privacy@afrovanguard.org.ng">privacy@afrovanguard.org.ng</a> and '
             . 'we will tell you what we know.</p>';

        return implode("\n", $h);
    }

    /**
     * What is actually set, generated from the registry rather than written down.
     *
     * ── THE BUG THIS EXISTS BECAUSE OF ───────────────────────────────────────
     *
     * The authored cookie policy said, in bold, "We set ONE cookie", and listed it in a
     * one-row table. There were three: the session, and the shop's `ag_region` and
     * `ag_currency`, which are written by `document.cookie` and last a year. The same
     * document said, also in bold, "We run no analytics", while {@see VisitTracker}
     * recorded every arrival's source, campaign, landing page, device and country into a
     * table an operator reads every week.
     *
     * Neither sentence was ever a lie somebody told; both were true when they were typed
     * and outlived the code by a release. That is this repository's most expensive shape
     * of fault and it has no fix at the level of "correct the wording", so the factual
     * half of this page is no longer wording. It is built from
     * {@see \AfricaGates\Support\CookieRegistry} and {@see CookiePrefs} on every render,
     * and a cookie added without a registry entry fails `CookieRegistryTest`.
     *
     * ── AND IT IS HERE, NOT IN THE TEMPLATE ──────────────────────────────────
     *
     * Same reason as the AI disclosure above: `/cookies.txt` and `/cookies.md` are real
     * routes, and a downloaded policy that silently omitted the list of cookies would be
     * missing the one section somebody downloaded it for.
     *
     * @param list<array<string,mixed>>|null $cookies injectable so the escaping can be
     *        tested with a hostile value rather than with whatever the registry holds today
     */
    public static function cookiesHtml(?array $cookies = null, ?array $storage = null): string
    {
        $cookies ??= CookieRegistry::cookies();
        $storage ??= CookieRegistry::storage();

        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $h = [];

        $h[] = '<h2 id="what-is-set">What is set, right now</h2>';
        $h[] = '<p>This section is generated from the platform&rsquo;s own configuration every '
             . 'time the page is drawn, so it describes what the code actually sets rather than '
             . 'what somebody wrote down when it was last reviewed.</p>';

        if ($cookies !== []) {
            $h[] = '<table>';
            $h[] = '<thead><tr><th>Name</th><th>Why</th><th>How long</th><th>Kind</th></tr></thead>';
            $h[] = '<tbody>';
            foreach ($cookies as $c) {
                $kind = (string) ($c['category'] ?? '');
                $word = $kind === CookieRegistry::ESSENTIAL
                      ? 'Essential'
                      : ($kind === CookieRegistry::PREFERENCE ? 'Your choice' : 'Counting');
                // WHO writes it, because a reader who blocks scripts will genuinely not have
                // the two shop cookies, and a table that implied otherwise would be wrong for
                // them specifically.
                $by = ((string) ($c['set_by'] ?? '')) === 'browser'
                    ? ' It is written by the page itself, only when you use that control.'
                    : '';
                $h[] = '<tr><td><code>' . $e((string) ($c['name'] ?? '')) . '</code></td>'
                     . '<td>' . $e((string) ($c['purpose'] ?? '')) . $e($by) . '</td>'
                     . '<td>' . $e((string) ($c['lifetime'] ?? '')) . '</td>'
                     . '<td>' . $e($word) . '</td></tr>';
            }
            $h[] = '</tbody></table>';
        }

        $h[] = '<p>All of them are marked <code>SameSite=Lax</code>, and <code>Secure</code> on '
             . 'an encrypted connection. The two set by the server are <code>HttpOnly</code>, '
             . 'which means scripts on the page cannot read them.</p>';

        if ($storage !== []) {
            $h[] = '<h2 id="browser-storage">Things kept in your browser, which are not cookies</h2>';
            $h[] = '<p>Some pages remember small things using your browser&rsquo;s own storage. It '
                 . 'never leaves your device, and it is never sent to us:</p>';
            $h[] = '<ul>';
            foreach ($storage as $s) {
                $h[] = '<li><code>' . $e((string) ($s['key'] ?? '')) . '</code> &mdash; '
                     . $e((string) ($s['purpose'] ?? '')) . '</li>';
            }
            $h[] = '</ul>';
            $h[] = '<p>Clearing your browser&rsquo;s site data removes all of it. Nothing important '
                 . 'depends on it.</p>';
        }

        $h[] = self::arrivalsHtml();

        return implode("\n", $h);
    }

    /**
     * The counting, described as it is configured — including when it is switched off.
     *
     * Split out of {@see cookiesHtml()} because it is the part that answers a different
     * question. The table above says what is STORED on the device; this says what is
     * OBSERVED about the visit, and those are not the same thing — this platform stores
     * nothing extra to do the counting, which is precisely why there is no banner and
     * precisely why saying "we set one cookie" was not a defence of anything.
     */
    private static function arrivalsHtml(): string
    {
        $h = [];
        $h[] = '<h2 id="counting-arrivals">Counting arrivals</h2>';

        if (!VisitTracker::enabled()) {
            $h[] = '<p>We are <strong>not counting arrivals at all</strong> at the moment. An '
                 . 'administrator has switched it off, and nothing on this site is recording '
                 . 'where visitors came from. The rest of this section describes what would be '
                 . 'recorded if it were switched back on.</p>';
        }

        $h[] = '<p>We keep <strong>one row for each visit</strong> &mdash; not one per page you '
             . 'open &mdash; so that whoever shared a link can find out whether it worked. It '
             . 'records where you came from (a campaign tag, or the site that linked here), the '
             . 'page you landed on, roughly what kind of device it was, and whether the visit '
             . 'led to a vote, a nomination or a ticket.</p>';
        $h[] = '<p><strong>It is ours alone.</strong> There is no Google Analytics here, no '
             . 'advertising pixel, no third-party tag of any kind: nothing on this site reports '
             . 'your visit to another company. And it stores nothing new on your device &mdash; '
               . 'it uses the session cookie in the table above, which is already there.</p>';
        $h[] = '<p><strong>What is never kept:</strong> your IP address (only a hash of it, '
             . 're-scrambled with a new secret every day, so the same visitor cannot be followed '
             . 'from one day to the next), the full address of the page that linked here (the '
             . 'site name only, because a full address carries search terms), the query string of '
             . 'the page you landed on (links here carry passes and sign-in tokens), and your '
             . 'browser&rsquo;s identification string. Your country is recorded only when the '
             . 'network in front of us has already worked it out; we never look it up.</p>';
        $h[] = '<p>Arrivals are deleted after ' . (int) VisitTracker::keepDays() . ' days.</p>';

        if (CookiePrefs::mode() === CookiePrefs::MODE_CONSENT) {
            $h[] = '<p><strong>We ask first.</strong> Nothing is counted until you agree, and you '
                 . 'are asked once. You can change your mind at any time on this page.</p>';
        } else {
            $h[] = '<p><strong>You have not been shown a consent banner</strong>, and this is why: '
                 . 'a banner exists to ask permission to store something on your device, and the '
                 . 'counting stores nothing. It is first-party, it reaches no one else, it holds '
                 . 'no identifier that survives the day, and it is reported only as totals. '
                 . 'We would rather give you a switch that works than an overlay you have to '
                 . 'dismiss before you can read the page.</p>';
        }

        $h[] = '<p>Either way, <strong>you can say no</strong>, and the control is at the top of '
             . 'this page. If your browser sends <code>Do Not Track</code> or Global Privacy '
             . 'Control we treat that as a no as well, and we honour it even over a yes you gave '
             . 'us here &mdash; the specification would let us do the opposite, and we would '
             . 'rather not.</p>';

        return implode("\n", $h);
    }

    /**
     * The voice section of the privacy notice.
     *
     * Its own heading rather than a bullet under the model providers, because it is the only
     * place on this platform where a recording of somebody's VOICE leaves the server, and
     * burying that under "automated processing (AI)" would be technically complete and
     * practically misleading. Somebody deciding whether to press a microphone button is owed
     * the answer in a place they will find it.
     *
     * @param array<string,mixed>|null $group
     */
    public static function voiceHtml(?array $group = null, ?bool $active = null): string
    {
        // Both parameters exist so the escaping and the on/off wording can be tested with a
        // hostile string and with both states, without depending on whatever key happens to
        // be configured on the machine running the suite.
        $group  = $group ?? AiPrivacy::voiceDisclosure();
        $active = $active ?? AiPrivacy::voiceActive();

        $caps = (array) ($group['capabilities'] ?? []);
        if ($caps === []) return '';

        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $h = [];

        $h[] = '<h2 id="voice">If you speak your answers instead of typing them</h2>';
        $h[] = '<p>A nominee describing their own work can have each question <strong>read out '
             . 'loud</strong>, and can <strong>answer by talking</strong> rather than typing. That is '
             . 'there because a keyboard on a phone is a real barrier for some of the people these '
             . 'awards exist to find, and losing a nomination to a barrier of ours would be our '
             . 'failure and not theirs. It is entirely optional: the same questions can be typed, '
             . 'and nothing is treated differently for having been spoken.</p>';
        $h[] = '<p>To do it, ' . $e((string) ($group['label'] ?? '')) . ' is used as the speech '
             . 'service, and this is what they receive:</p>';
        $h[] = '<ul>';
        foreach ($caps as $cap) {
            $h[] = '<li><strong>' . $e((string) ($cap['purpose'] ?? '')) . '</strong><br>'
                 . $e((string) ($cap['sends'] ?? '')) . '</li>';
        }
        $h[] = '</ul>';
        $h[] = '<p><strong>The words that end up in your submission are yours.</strong> A '
             . 'transcription is put on your screen, not into your answer &mdash; you read it, correct '
             . 'anything it misheard, and press send yourself, so the sentences a judging panel reads '
             . 'as yours are ones you approved.</p>';
        // Two different answers to "do you keep it", and printing only one of them would make
        // the other a lie. The distinction is the whole point of saying anything here.
        $h[] = '<p><strong>Whether the recording is kept depends on which recording it is.</strong> '
             . 'An <em>answer</em> you speak is passed straight from your request to the speech '
             . 'service and is never written to our server &mdash; there is no file of it here to '
             . 'lose, leak or hand to anybody. The short <em>introduction</em> you may choose to '
             . 'record of yourself is different: that recording IS the thing the judges are meant to '
             . 'hear, so we keep it. It is stored on our own server rather than at a public web '
             . 'address, you can delete it or record it again at any point before you send your '
             . 'questionnaire, and it reaches a panel only after you have agreed that they may hear '
             . 'it.</p>';
        $h[] = '<p>' . ($active
                ? 'Spoken questions and answers are currently switched on.'
                : 'Spoken questions and answers are currently switched off, so no audio and no '
                . 'question text is being sent to any speech service right now.')
             . ' Turning this off is a single setting, and the questionnaire works exactly the same '
             . 'way without it.</p>';
        $h[] = '<p>As with the model providers above, how long the speech service retains what it '
             . 'receives is governed by their terms rather than ours. Email '
             . '<a href="mailto:privacy@afrovanguard.org.ng">privacy@afrovanguard.org.ng</a> and we '
             . 'will tell you what we know rather than what sounds reassuring.</p>';

        return implode("\n", $h);
    }

    /**
     * The effective date, as an ISO string.
     *
     * From `updated_at`, never from `updated_label`: the label is free text an
     * administrator typed and may not parse, and a citation with an unparseable date
     * is worse than one with a plain date.
     */
    public static function effectiveDate(array $doc): string
    {
        $ts = strtotime((string) ($doc['updated_at'] ?? ''));
        return date('Y-m-d', $ts ?: time());
    }

    /**
     * Anchor every <h2> and collect the contents, in ONE pass.
     *
     * ── WHY ONE PASS AND NOT TWO METHODS ────────────────────────────────────
     *
     * The first version had `outline()` compute ids and `bodyWithAnchors()` compute
     * them again, and they disagreed on the very first document that contained a
     * heading with an id already on it: the contents linked to
     * `#automated-processing-ai` while the body carried `#automated-processing`, so
     * the one generated section in the whole policy had a dead contents entry.
     *
     * Two functions deriving the same identifier from the same string is a drift
     * waiting to happen. So the body and the outline come out of one walk, and the
     * two public methods below are views on it.
     *
     * @return array{body:string, outline:list<array{id:string,title:string}>}
     */
    private static function walk(array $doc): array
    {
        $seen    = [];
        $outline = [];

        $body = (string) preg_replace_callback(
            '#<h2\b([^>]*)>(.*?)</h2>#is',
            static function (array $m) use (&$seen, &$outline): string {
                $attrs = $m[1];
                $title = DocText::inline($m[2]);
                if ($title === '') return $m[0];

                // An id already on the heading wins, and one is: the generated AI
                // disclosure writes its own `id="automated-processing"` and is appended
                // AFTER Html::sanitize() (which strips id attributes from authored
                // markup, so an author cannot set one). Recomputing over the top of it
                // would break every existing link to that section.
                if (preg_match('/\bid\s*=\s*["\']([^"\']+)["\']/i', $attrs, $has)) {
                    $id = $has[1];
                    $seen[$id] = true;
                    $outline[] = ['id' => $id, 'title' => $title];
                    return $m[0];
                }

                $id = self::anchor($title, $seen);
                $outline[] = ['id' => $id, 'title' => $title];
                return '<h2' . $attrs . ' id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '">'
                     . $m[2] . '</h2>';
            },
            self::bodyHtml($doc)
        );

        return ['body' => $body, 'outline' => $outline];
    }

    /** Contents entries, derived from the <h2>s the body actually carries. */
    public static function outline(array $doc): array
    {
        return self::walk($doc)['outline'];
    }

    /** The body, with an id on every <h2> so the contents can link to it. */
    public static function bodyWithAnchors(array $doc): string
    {
        return self::walk($doc)['body'];
    }

    /**
     * A heading's anchor.
     *
     * {@see Slug::make()} rather than a local `[^a-z0-9]+` replacement, because that
     * expression DELETES accented letters instead of folding them: a heading like
     * "Frais et rémunération" would have become `frais-et-r-mun-ration`. Slug folds
     * first, and SlugTest fails the build on any file that reintroduces the ASCII
     * version — which is how this was caught here.
     *
     * @param array<string,bool> $seen mutated, so repeated headings get -2, -3…
     */
    private static function anchor(string $title, array &$seen): string
    {
        $base = Slug::make($title, 60);
        if ($base === '') $base = 'section';
        $id = $base;
        $n  = 1;
        while (isset($seen[$id])) { $n++; $id = $base . '-' . $n; }
        $seen[$id] = true;
        return $id;
    }

    /** @return list<array{id:string, label:string, text:string}> */
    public static function citations(array $doc, string $url, ?string $accessed = null): array
    {
        $date = self::effectiveDate($doc);

        return Citation::formats([
            'title'     => (string) ($doc['title'] ?? 'Legal document'),
            'author'    => self::AUTHOR,
            'publisher' => self::PUBLISHER,
            // The effective date IS the version for a policy — see the class docblock.
            'version'   => $date,
            'published' => $date,
            'updated'   => $date,
            'url'       => $url,
            'accessed'  => $accessed ?? date('Y-m-d'),
            'key'       => (string) ($doc['slug'] ?? 'legal'),
        ]);
    }

    public static function fileStem(array $doc): string
    {
        $slug = preg_replace('/[^a-z0-9-]+/', '-', strtolower((string) ($doc['slug'] ?? 'document'))) ?? 'document';
        return 'africa-gates-' . trim($slug, '-') . '-' . self::effectiveDate($doc);
    }

    /** The .txt edition: a header, the body, and how to cite it. */
    public static function plainText(array $doc, string $url, ?string $accessed = null): string
    {
        $title = (string) ($doc['title'] ?? 'Legal document');
        $out   = [];
        $out[] = mb_strtoupper($title);
        $out[] = str_repeat('=', 78);
        $out[] = self::PUBLISHER;
        $out[] = 'Effective ' . self::effectiveDate($doc)
               . (($doc['updated_label'] ?? '') !== '' ? ' (' . $doc['updated_label'] . ')' : '');
        $out[] = $url;
        $out[] = '';
        $out[] = DocText::toText(self::bodyHtml($doc));
        $out[] = str_repeat('=', 78);
        $out[] = 'HOW TO CITE';
        $out[] = '';
        foreach (self::citations($doc, $url, $accessed) as $c) {
            $out[] = $c['label'] . ':';
            $out[] = wordwrap($c['text'], 78, "\n", false);
            $out[] = '';
        }
        $out[] = 'An initiative of Afrovanguard, Lagos, Nigeria, and governed by the laws of the';
        $out[] = 'Federal Republic of Nigeria. Provided for transparency — this document does not';
        $out[] = 'constitute legal advice.';

        return rtrim(implode("\n", $out)) . "\n";
    }

    /** The .md edition. */
    public static function markdown(array $doc, string $url, ?string $accessed = null): string
    {
        $title = (string) ($doc['title'] ?? 'Legal document');
        $date  = self::effectiveDate($doc);
        $out   = [];
        $out[] = '# ' . $title;
        $out[] = '';
        $out[] = '| | |';
        $out[] = '|---|---|';
        $out[] = '| Publisher | ' . self::PUBLISHER . ' |';
        $out[] = '| Effective | ' . $date . ' |';
        $out[] = '| Canonical URL | <' . $url . '> |';
        $out[] = '';
        $out[] = DocText::toMarkdown(self::bodyHtml($doc));
        $out[] = '---';
        $out[] = '';
        $out[] = '## How to cite';
        $out[] = '';
        foreach (self::citations($doc, $url, $accessed) as $c) {
            $out[] = '**' . $c['label'] . '**';
            $out[] = '';
            $out[] = $c['id'] === 'bibtex' ? "```bibtex\n" . $c['text'] . "\n```" : $c['text'];
            $out[] = '';
        }
        $out[] = '---';
        $out[] = '';
        $out[] = '_An initiative of Afrovanguard, Lagos, Nigeria, and governed by the laws of the '
               . 'Federal Republic of Nigeria. Provided for transparency — this document does not '
               . 'constitute legal advice._';

        return rtrim(implode("\n", $out)) . "\n";
    }
}
