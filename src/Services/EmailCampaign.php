<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use AfricaGates\Support\Slug;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * A campaign an operator can edit, without a deploy and without free-form HTML.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * STRUCTURED BLOCKS, NOT A DOCUMENT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `HANDOFF.md` §6 states the constraint this class exists to honour: an editable campaign
 * cannot be free-form HTML. The fluid-hybrid wrapper, the MSO conditionals, the styled alt
 * text, `role="presentation"` on every layout table, the absence of a CSP nonce — all of
 * it is invisible to whoever is typing, and a rich-text editor emits `<div>`s and inline
 * styles that Outlook drops on the floor. {@see \Tests\Unit\EmailInboxCompatTest} holds
 * twelve of those properties and a WYSIWYG would break most of them in an afternoon.
 *
 * So the skeleton stays in `templates/emails/campaign.twig`, and what is editable is:
 *
 *   - the SUBJECT and PREHEADER, as plain text;
 *   - an ordered list of TYPED BLOCKS, each with named text fields and nothing else.
 *
 * Every block's text is escaped on the way out. There is no block type that renders raw
 * markup, and adding one would undo the whole design.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * LINKS ARE CHOSEN FROM A LIST, NOT TYPED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * An `ask` or `button` block names its destination by KEY — `vote_url`, `events_url`,
 * `site_url` — and the renderer resolves it per recipient. Three reasons, and the third is
 * the one that matters:
 *
 *   1. `vote_url` is different for every recipient. A typed URL could not be.
 *   2. A mistyped absolute URL is a dead CTA in eight hundred inboxes.
 *   3. Free-text URLs in a template a non-developer edits is an open redirect with a
 *      mailing list attached. The one place a DB row can cause an outbound link should be
 *      a whitelist.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE SLUG IS THE SAFETY MECHANISM
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_broadcast_log` has `UNIQUE(campaign, email_hash)`, and that is the only reason an
 * interrupted send can be resumed rather than repeated. The campaign's slug IS that key.
 * So it is set once, on create, and never editable afterwards — renaming one mid-send
 * would re-mail everybody already done. {@see NomineeBroadcast} does the resolving; this
 * class never builds its own recipient query, because two of those drift and the way they
 * drift on a mail sender is that one mails somebody the other already did.
 */
final class EmailCampaign
{
    /**
     * The block types, and the fields each one carries.
     *
     * `link` fields hold a KEY into {@see NomineeBroadcast::vars()}, never a URL.
     *
     * @var array<string, array{label:string, fields:array<string,array{label:string, max:int, link?:bool, multiline?:bool}>}>
     */
    public const BLOCKS = [
        'hero' => [
            'label'  => 'Headline',
            'fields' => [
                'headline'   => ['label' => 'Headline', 'max' => 90],
                // Rendered in the brand green, after the headline. The shipped campaign's
                // "Finish strong" is headline "Finish" + accent "strong". Explicit rather
                // than auto-styling the last word, which guesses wrong on most headlines.
                'accent'     => ['label' => 'Last word in green', 'max' => 30],
                'standfirst' => ['label' => 'Standfirst', 'max' => 300, 'multiline' => true],
            ],
        ],
        'paragraph' => [
            'label'  => 'Paragraph',
            'fields' => ['text' => ['label' => 'Text', 'max' => 1200, 'multiline' => true]],
        ],
        'quote' => [
            'label'  => 'Pull quote',
            'fields' => [
                'text'   => ['label' => 'Quote', 'max' => 400, 'multiline' => true],
                'source' => ['label' => 'Attribution', 'max' => 120],
            ],
        ],
        'heading' => [
            'label'  => 'Section label',
            'fields' => ['text' => ['label' => 'Label', 'max' => 120]],
        ],
        'ask' => [
            'label'  => 'Numbered ask',
            'fields' => [
                'title'      => ['label' => 'Title', 'max' => 120],
                'text'       => ['label' => 'Body', 'max' => 600, 'multiline' => true],
                'link_label' => ['label' => 'Link text', 'max' => 60],
                'link'       => ['label' => 'Links to', 'max' => 32, 'link' => true],
            ],
        ],
        'button' => [
            'label'  => 'Button',
            'fields' => [
                'label'           => ['label' => 'Button text', 'max' => 60],
                'link'            => ['label' => 'Links to', 'max' => 32, 'link' => true],
                // The quieter link under the button. The shipped campaign had one, and a
                // single CTA with no alternative loses the reader who wants the other thing.
                'secondary_label' => ['label' => 'Secondary link text (optional)', 'max' => 60],
                'secondary_link'  => ['label' => 'Secondary links to', 'max' => 32, 'link' => true],
            ],
        ],
        'callout' => [
            'label'  => 'Dark callout',
            'fields' => ['text' => ['label' => 'Text', 'max' => 300, 'multiline' => true]],
        ],
        'signoff' => [
            'label'  => 'Sign-off',
            'fields' => [
                'text'      => ['label' => 'Closing line', 'max' => 400, 'multiline' => true],
                'salutation'=> ['label' => 'Valediction', 'max' => 90],
                'signature' => ['label' => 'Signed', 'max' => 90],
            ],
        ],
        'divider' => ['label' => 'Divider', 'fields' => []],
    ];

    /**
     * Where a link block may point. Keys into {@see NomineeBroadcast::vars()}.
     *
     * @var array<string,string>
     */
    public const LINKS = [
        'vote_url'   => 'The nominee’s own voting page',
        'events_url' => 'The events page',
        'site_url'   => 'The home page',
    ];

    /**
     * Placeholders an operator may type into any text field, with the variable each one
     * resolves to per recipient.
     *
     * ── WHY THE FALLBACK SYNTAX EXISTS ───────────────────────────────────────
     *
     * `{first_name|Friend}` — the part after the pipe is used when the value is empty.
     * That is not a nicety. `first_name` comes from splitting a nominee's name, and
     * `category_name` is absent for a nominee not attached to a category, so both are
     * genuinely blank sometimes. The old fixed template handled it with Twig conditionals
     * — "{% if first_name %}{{ first_name }}, you're{% else %}You're{% endif %}" — which
     * is exactly the kind of thing an operator cannot write and must not have to. A
     * fallback keeps the sentence grammatical without anyone thinking about it.
     *
     * @var array<string,string>
     */
    public const PLACEHOLDERS = [
        'first_name'    => 'The nominee’s first name',
        'category_name' => 'Their award category',
        'closes_human'  => 'When their voting closes',
    ];

    public const STATUSES = ['draft' => 'Draft', 'approved' => 'Approved', 'sent' => 'Sent'];

    // ══ reading ══════════════════════════════════════════════════════════════

    /** @return list<object> */
    public static function all(): array
    {
        try {
            return DB::table('gates_email_campaigns')->orderByDesc('id')->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public static function find(int $id): ?object
    {
        try {
            return DB::table('gates_email_campaigns')->where('id', $id)->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Saved states, newest first — the audit trail §6 asks for.
     *
     * "A campaign that went to eight hundred people needs to be reconstructable." Not
     * roughly: the exact words, because the question that arrives later is always "what
     * did you actually send me".
     *
     * @return list<object>
     */
    public static function versions(int $campaignId, int $limit = 10): array
    {
        try {
            return DB::table('gates_email_campaign_versions')
                ->where('campaign_id', $campaignId)
                ->orderByDesc('id')->limit(max(1, $limit))->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * How many people this campaign has actually reached, from the send log.
     *
     * The log is the authority, not a counter this class keeps: it holds one row per
     * (campaign, address) under a unique key, so it is the only number that survives an
     * interrupted run being resumed.
     */
    public static function sentCount(string $slug): int
    {
        try {
            return (int) DB::table('gates_broadcast_log')
                ->where('campaign', $slug)->where('status', 'sent')->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return list<array<string,mixed>> */
    public static function blocksOf(?object $campaign): array
    {
        if (!$campaign) return [];
        $raw = json_decode((string) ($campaign->blocks_json ?? '[]'), true);
        return self::clean(is_array($raw) ? $raw : []);
    }

    // ══ writing ══════════════════════════════════════════════════════════════

    /**
     * Create a campaign. The slug is fixed here for good — see the class note.
     *
     * @return array{ok:bool, id:int, message:string}
     */
    public static function create(string $name, string $subject, int $by = 0): array
    {
        $name    = trim($name);
        $subject = trim($subject);
        if ($name === '')    return ['ok' => false, 'id' => 0, 'message' => 'Give the campaign a name.'];
        if ($subject === '') return ['ok' => false, 'id' => 0, 'message' => 'Give the campaign a subject line.'];

        $slug = self::slugFor($name);
        if ($slug === '') return ['ok' => false, 'id' => 0, 'message' => 'That name has no letters or digits in it.'];

        $now = Carbon::now()->toDateTimeString();
        try {
            if (DB::table('gates_email_campaigns')->where('slug', $slug)->exists()) {
                return ['ok' => false, 'id' => 0, 'message' => 'A campaign with that name already exists. '
                    . 'Names become the send key, which has to be unique — edit the existing one, or pick another name.'];
            }
            $id = (int) DB::table('gates_email_campaigns')->insertGetId([
                'slug' => $slug, 'name' => mb_substr($name, 0, 160), 'subject' => mb_substr($subject, 0, 200),
                'preheader' => '', 'blocks_json' => json_encode(self::starter()),
                'status' => 'draft', 'updated_by' => $by ?: null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => 0, 'message' => 'Could not create it: ' . $e->getMessage()];
        }

        return ['ok' => true, 'id' => $id, 'message' => 'Created. Edit the copy, then preview it.'];
    }

    /**
     * Save subject, preheader and blocks.
     *
     * ── THE SAVE IS WHERE THE INBOX RULES ARE ENFORCED ───────────────────────
     *
     * It renders the campaign against a representative recipient and runs
     * {@see EmailInboxGuard} over the result, and REFUSES if anything is wrong. §6 asks for
     * this in CI; doing it here is strictly better, because the person who can fix a
     * too-long campaign is the person who just wrote it, and CI does not see the row at all.
     *
     * A sent campaign is frozen. Editing the copy of something already in eight hundred
     * inboxes does not change those inboxes — it only destroys the record of what was sent.
     *
     * @param  list<array<string,mixed>> $blocks
     * @return array{ok:bool, message:string, problems:list<string>}
     */
    public static function save(int $id, string $subject, string $preheader, array $blocks, int $by = 0): array
    {
        $c = self::find($id);
        if (!$c) return ['ok' => false, 'message' => 'No such campaign.', 'problems' => []];
        if ((string) $c->status === 'sent') {
            return ['ok' => false, 'problems' => [],
                    'message' => 'That campaign has been sent. Its copy is the record of what went out, '
                               . 'so it cannot be edited — duplicate it if you want to send something similar.'];
        }

        $subject = trim($subject);
        if ($subject === '') return ['ok' => false, 'message' => 'A campaign needs a subject line.', 'problems' => []];

        $blocks = self::clean($blocks);
        if ($blocks === []) {
            return ['ok' => false, 'message' => 'A campaign with no blocks would arrive empty.', 'problems' => []];
        }

        // Render it as somebody would actually receive it, then check THAT.
        $probe    = self::render($subject, $preheader, $blocks, self::sampleVars());
        $problems = EmailInboxGuard::problems($probe);
        if ($problems !== []) {
            return ['ok' => false, 'problems' => $problems,
                    'message' => 'Not saved — this would not render properly in an inbox.'];
        }

        $now = Carbon::now()->toDateTimeString();
        try {
            // The version first. If the update fails the history is merely ahead, which is
            // recoverable; the other order can lose the state that was replaced.
            DB::table('gates_email_campaign_versions')->insert([
                'campaign_id' => $id, 'subject' => mb_substr($subject, 0, 200),
                'preheader' => mb_substr(trim($preheader), 0, 200),
                'blocks_json' => json_encode($blocks), 'saved_by' => $by ?: null, 'saved_at' => $now,
            ]);
            DB::table('gates_email_campaigns')->where('id', $id)->update([
                'subject' => mb_substr($subject, 0, 200),
                'preheader' => mb_substr(trim($preheader), 0, 200),
                'blocks_json' => json_encode($blocks),
                // Any edit un-approves. An approval is of specific words.
                'status' => 'draft', 'approved_by' => null, 'approved_at' => null,
                'updated_by' => $by ?: null, 'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not save: ' . $e->getMessage(), 'problems' => []];
        }

        return ['ok' => true, 'problems' => [],
                'message' => 'Saved. ' . number_format(strlen($probe)) . ' bytes rendered, against a '
                           . number_format(EmailInboxGuard::MAX_BYTES) . ' byte limit.'];
    }

    /**
     * Approve a campaign for sending.
     *
     * A separate act from saving, and by a person rather than a flag on the same form.
     * §6 raises two-person approval as worth considering; this is the half that can be
     * built without inventing a second-approver model — the point being that reaching
     * "send" takes a deliberate step whose author is recorded.
     *
     * @return array{ok:bool, message:string}
     */
    public static function approve(int $id, int $by = 0): array
    {
        $c = self::find($id);
        if (!$c) return ['ok' => false, 'message' => 'No such campaign.'];
        if ((string) $c->status === 'sent') return ['ok' => false, 'message' => 'That campaign has already been sent.'];

        try {
            DB::table('gates_email_campaigns')->where('id', $id)->update([
                'status' => 'approved', 'approved_by' => $by ?: null,
                'approved_at' => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not approve: ' . $e->getMessage()];
        }
        return ['ok' => true, 'message' => 'Approved. The send button is now live — read the plan before pressing it.'];
    }

    /** Record that a send happened. */
    public static function markSent(int $id, int $count): void
    {
        try {
            DB::table('gates_email_campaigns')->where('id', $id)->update([
                'status' => 'sent', 'sent_at' => Carbon::now()->toDateTimeString(), 'sent_count' => max(0, $count),
            ]);
        } catch (\Throwable) {
            // The send happened; gates_broadcast_log is the authority on who got it.
        }
    }

    // ══ blocks ═══════════════════════════════════════════════════════════════

    /**
     * Coerce whatever arrived into valid, typed, plain-text blocks.
     *
     * This is the boundary. Anything past it is trusted by the renderer, so everything
     * unknown is dropped rather than passed through: an unrecognised type, a field the type
     * does not declare, a link key that is not in {@see LINKS}. `strip_tags` runs on every
     * value because the block is text and the template supplies the markup — a pasted
     * `<div style=…>` is exactly what this design exists to keep out.
     *
     * @param  list<array<string,mixed>>|array<mixed> $in
     * @return list<array<string,mixed>>
     */
    public static function clean(array $in): array
    {
        $out = [];
        foreach ($in as $b) {
            if (!is_array($b)) continue;
            $type = (string) ($b['type'] ?? '');
            if (!isset(self::BLOCKS[$type])) continue;

            $row = ['type' => $type];
            $any = $type === 'divider';

            foreach (self::BLOCKS[$type]['fields'] as $name => $spec) {
                $v = (string) ($b[$name] ?? '');

                if (!empty($spec['link'])) {
                    // A destination is chosen, never typed. Anything else becomes the safe
                    // default rather than a dead or hostile link.
                    $row[$name] = isset(self::LINKS[$v]) ? $v : 'vote_url';
                    continue;
                }

                // Text, and only text. Newlines survive for multiline fields because the
                // renderer turns them into separate paragraphs; everything else collapses.
                $v = strip_tags($v);
                $v = !empty($spec['multiline'])
                    ? trim(preg_replace('/[ \t]+/u', ' ', preg_replace('/\R/u', "\n", $v) ?? '') ?? '')
                    : trim(preg_replace('/\s+/u', ' ', $v) ?? '');

                $row[$name] = mb_substr($v, 0, (int) $spec['max']);
                if ($row[$name] !== '') $any = true;
            }

            // A block with nothing in it renders as vertical space nobody asked for.
            if ($any) $out[] = $row;
        }
        return $out;
    }

    /** The copy a new campaign starts from — the shipped "final hours" shape. */
    public static function starter(): array
    {
        return [
            ['type' => 'hero', 'headline' => 'Finish', 'accent' => 'strong',
             'standfirst' => "{first_name|Friend}, you're in the final stretch of Africa GATES voting"
                           . " in {category_name|your category}."],
            ['type' => 'quote', 'text' => 'The end of a matter is better than its beginning.',
             'source' => 'The Preacher'],
            ['type' => 'paragraph',
             'text' => "This is no longer just about how the journey began — it's about how we choose to finish it. "
                     . "You've come this far because your work, influence, and contribution to your community have been "
                     . "seen and recognised.\n"
                     . "Now, help us make Africa GATES one of the most remarkable community experiences "
                     . "we've ever created."],
            ['type' => 'heading', 'text' => "Two things we're asking of you"],
            ['type' => 'ask', 'title' => 'Mobilise your supporters',
             'text' => 'Rally your fans, friends, family, colleagues and community to vote for you and help secure '
                     . 'your place in this historic moment.',
             'link_label' => 'Share your voting link', 'link' => 'vote_url'],
            ['type' => 'ask', 'title' => 'Fill the room',
             'text' => 'Encourage your supporters to get their event tickets and physically join us — the people who '
                     . 'believed in you should be there to celebrate with you.',
             'link_label' => 'Get event tickets', 'link' => 'events_url'],
            ['type' => 'callout', 'text' => 'Every vote matters. Every supporter matters. Every ticket matters.'],
            ['type' => 'button', 'label' => 'Vote & Share Your Link', 'link' => 'vote_url',
             'secondary_label' => 'Get event tickets', 'secondary_link' => 'events_url'],
            ['type' => 'signoff',
             'text' => 'Let us not simply participate in Africa GATES. Let us make history together.',
             'salutation' => 'With appreciation and anticipation,', 'signature' => 'The Africa GATES Team'],
        ];
    }

    // ══ rendering ════════════════════════════════════════════════════════════

    /**
     * The campaign as HTML for one recipient.
     *
     * A bare Twig environment, for the same reason {@see NomineeBroadcast::html()} uses
     * one: the mail template needs no `asset()` and no `csp_nonce`, and not depending on
     * the app's extensions keeps it renderable from a console with no request in flight.
     *
     * @param list<array<string,mixed>> $blocks
     * @param array<string,mixed>       $vars
     */
    public static function render(string $subject, string $preheader, array $blocks, array $vars): string
    {
        static $twig = null;
        $twig ??= new Environment(
            new FilesystemLoader(\dirname(__DIR__, 2) . '/templates'),
            ['autoescape' => 'html']
        );

        return $twig->render('emails/campaign.twig', $vars + [
            'subject'   => $subject,
            'preheader' => $preheader,
            // Resolved here, so the template never has to know that a link is a key.
            'blocks'    => self::resolve($blocks, $vars),
        ]);
    }

    /** Render a stored campaign for one recipient. @param array<string,mixed> $vars */
    public static function renderFor(object $campaign, array $vars): string
    {
        return self::render(
            (string) $campaign->subject, (string) ($campaign->preheader ?? ''),
            self::blocksOf($campaign), $vars
        );
    }

    /**
     * Turn each block's link KEY into this recipient's URL.
     *
     * @param  list<array<string,mixed>> $blocks
     * @param  array<string,mixed>       $vars
     * @return list<array<string,mixed>>
     */
    private static function resolve(array $blocks, array $vars): array
    {
        // Asks are numbered by how many asks precede them, not by position in the list. A
        // paragraph dropped between two of them must not turn "01, 02" into "01, 03".
        $ask = 0;

        foreach ($blocks as $i => $b) {
            if ((string) $b['type'] === 'ask') {
                $ask++;
                $blocks[$i]['n'] = str_pad((string) $ask, 2, '0', STR_PAD_LEFT);
            }

            foreach (self::BLOCKS[(string) $b['type']]['fields'] ?? [] as $name => $spec) {
                if (!empty($spec['link'])) {
                    $key = (string) ($b[$name] ?? 'vote_url');
                    $url = (string) ($vars[$key] ?? $vars['site_url'] ?? '');
                    // 'link' is the block's main destination; anything else keeps its own
                    // name so a block can carry two (see the button's secondary link).
                    $blocks[$i][$name === 'link' ? 'href' : $name . '_href'] = $url;
                    continue;
                }
                // Placeholders resolve to RAW values. Twig escapes the finished string on
                // output, so a nominee called "Ade & Sons" is escaped once and correctly —
                // substituting escaped values here would double-escape them.
                if (isset($blocks[$i][$name])) {
                    $blocks[$i][$name] = self::fill((string) $blocks[$i][$name], $vars);
                }
            }
            // Multiline text becomes real paragraphs. Doing it here rather than with a
            // `nl2br` in Twig keeps the markup a table of <p>s, which is what Outlook wants.
            if (isset($blocks[$i]['text']) && str_contains((string) $blocks[$i]['text'], "\n")) {
                $blocks[$i]['paras'] = array_values(array_filter(
                    array_map('trim', preg_split('/\n+/', (string) $blocks[$i]['text']) ?: [])
                ));
            }
        }
        return $blocks;
    }

    /**
     * The campaign as plain text, built from the blocks.
     *
     * ── WHY NOT strip_tags(THE HTML) ─────────────────────────────────────────
     *
     * {@see NomineeBroadcast::plain()} says it for the fixed template: "a stripped campaign
     * is style rules and link text with no sentences in it, and that is the version a
     * plain-text client shows." So it hand-wrote the prose — correct for a campaign whose
     * words never change, and wrong the moment they can, because a hand-written alternative
     * would keep sending the OLD copy after an edit. A plain-text part that contradicts the
     * HTML part is worse than a clumsy one.
     *
     * Blocks are already plain text, so this needs neither. It walks the same list in the
     * same order and writes sentences, with each link spelled out — a text client cannot
     * show a link label over a URL, so the URL has to be visible.
     *
     * @param list<array<string,mixed>> $blocks
     * @param array<string,mixed>       $vars
     */
    public static function plainOf(array $blocks, array $vars): string
    {
        $out = [];

        foreach (self::resolve(self::clean($blocks), $vars) as $b) {
            switch ((string) $b['type']) {
                case 'hero':
                    $h = trim(($b['headline'] ?? '') . ' ' . ($b['accent'] ?? ''));
                    if ($h !== '')                        $out[] = $h;
                    if (($b['standfirst'] ?? '') !== '')   $out[] = (string) $b['standfirst'];
                    break;

                case 'quote':
                    $out[] = '"' . ($b['text'] ?? '') . '"'
                           . (($b['source'] ?? '') !== '' ? ' — ' . $b['source'] : '');
                    break;

                case 'heading':
                    $out[] = mb_strtoupper((string) ($b['text'] ?? ''));
                    break;

                case 'paragraph':
                case 'callout':
                    foreach ($b['paras'] ?? [$b['text'] ?? ''] as $para) {
                        if (trim((string) $para) !== '') $out[] = (string) $para;
                    }
                    break;

                case 'ask':
                    $line = ltrim((string) ($b['n'] ?? ''), '0') . '. ' . ($b['title'] ?? '');
                    if (($b['text'] ?? '') !== '') $line .= ' — ' . $b['text'];
                    if (($b['link_label'] ?? '') !== '') $line .= "\n   " . ($b['href'] ?? '');
                    $out[] = $line;
                    break;

                case 'button':
                    $out[] = ($b['label'] ?? 'Open') . ': ' . ($b['href'] ?? '');
                    if (($b['secondary_label'] ?? '') !== '') {
                        $out[] = $b['secondary_label'] . ': ' . ($b['secondary_link_href'] ?? '');
                    }
                    break;

                case 'signoff':
                    foreach ($b['paras'] ?? [$b['text'] ?? ''] as $para) {
                        if (trim((string) $para) !== '') $out[] = (string) $para;
                    }
                    if (($b['salutation'] ?? '') !== '') $out[] = (string) $b['salutation'];
                    if (($b['signature'] ?? '') !== '')  $out[] = (string) $b['signature'];
                    break;

                case 'divider':
                    $out[] = '—';
                    break;
            }
        }

        // The deadline and the unsubscribe are not blocks — they belong to the chrome, and
        // a plain-text part still has to carry a working opt-out.
        if (($vars['closes_human'] ?? '') !== '') $out[] = 'Voting closes ' . $vars['closes_human'] . '.';
        if (($vars['unsubscribe_url'] ?? '') !== '') $out[] = 'Unsubscribe: ' . $vars['unsubscribe_url'];

        return implode("\n\n", $out);
    }

    /** Plain text for a stored campaign. @param array<string,mixed> $vars */
    public static function plainFor(object $campaign, array $vars): string
    {
        return self::plainOf(self::blocksOf($campaign), $vars);
    }

    /**
     * Substitute `{key}` and `{key|fallback}` against this recipient's values.
     *
     * Only keys in {@see PLACEHOLDERS} resolve. An unknown one is left exactly as typed
     * rather than blanked, so a stray brace shows up in the preview as itself instead of
     * silently eating a word — and {@see EmailInboxGuard} is looking for Twig tags, not
     * these, so this is the only thing that can consume them.
     *
     * @param array<string,mixed> $vars
     */
    public static function fill(string $text, array $vars): string
    {
        if (!str_contains($text, '{')) return $text;

        return (string) preg_replace_callback(
            '/\{([a-z_]+)(?:\|([^}]*))?\}/',
            static function (array $m) use ($vars): string {
                $key = $m[1];
                if (!isset(self::PLACEHOLDERS[$key])) return $m[0];

                $v = trim((string) ($vars[$key] ?? ''));
                return $v !== '' ? $v : trim((string) ($m[2] ?? ''));
            },
            $text
        );
    }

    /**
     * A representative recipient, for previews and for the save-time render check.
     *
     * Deliberately LONG values — a name and category at the top of their range, so a
     * campaign that passes the size check here passes it for a real recipient too. A probe
     * built from short sample data is a probe that approves a campaign which then clips.
     *
     * @return array<string,mixed>
     */
    public static function sampleVars(): array
    {
        $site = rtrim((string) (Env::get('APP_URL', '') ?: 'https://afg.afrovanguard.org.ng'), '/');

        return [
            'first_name'      => 'Chidiebere-Oluwaseun',
            'category_name'   => 'Community Education and Youth Development Excellence',
            'closes_human'    => 'Fri 29 Aug 2026, 23:59 WAT',
            'countdown_url'   => $site . '/email/countdown.gif?c=1',
            'countdown_alt'   => 'Voting closes Fri 29 Aug 2026, 23:59 WAT',
            'vote_url'        => $site . '/vote/community-education/1234-chidiebere-oluwaseun-adeyemi-nwosu',
            'events_url'      => $site . '/events',
            'site_url'        => $site,
            'unsubscribe_url' => $site . '/email/unsubscribe?e=' . str_repeat('a', 44) . '&t=' . str_repeat('b', 64),
            'postal_address'  => (string) Env::get('MAIL_POSTAL_ADDRESS', 'Afrovanguard, Lagos, Nigeria'),
        ];
    }

    /**
     * A slug that is safe as a `gates_broadcast_log` campaign key.
     *
     * {@see \AfricaGates\Support\Slug::make()} rather than a local expression. The
     * obvious `preg_replace('/[^a-z0-9]+/', '-', …)` DELETES accented letters instead of
     * folding them — "Ọláiyá" becomes "l-iy" — and `SlugTest` guards the whole of `src/`
     * against that spelling precisely because it had already shipped four times. A
     * campaign name is Nigerian more often than not.
     */
    public static function slugFor(string $name): string
    {
        return Slug::make($name, 64);
    }
}
