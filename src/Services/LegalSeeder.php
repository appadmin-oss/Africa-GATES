<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * The Terms of Participation and the Privacy Policy, and how they get installed.
 *
 * ── WHY THE CONTENT LIVES HERE AND NOT IN THE COMMAND ────────────────────────
 *
 * It started in `LegalSeedCommand`, which was fine until the first question after
 * shipping it was "I do not have SSH". This platform deploys to shared cPanel
 * hosting where there is frequently no shell at all, which is why
 * `/__setup/migrate` and `/__setup/assets` exist — a step that cannot be run on
 * the host will not be run.
 *
 * So the documents and the install logic sit in a service, and BOTH front ends
 * call it: `php bin/console legal:seed` for anyone with a shell, and the
 * token-gated `GET /__setup/legal` for everyone else. Two ways in, one behaviour.
 *
 * ── IT WILL NOT OVERWRITE YOUR EDITS ─────────────────────────────────────────
 *
 * A document that already exists is SKIPPED unless `$force` is true. That is the
 * important behaviour: the whole point of these living in the database is that an
 * administrator can revise them, and a seeder that clobbered a revised policy
 * every time somebody hit the URL would silently undo legal review. Worse here
 * than on the CLI, because a URL can be opened by accident, bookmarked, or
 * prefetched.
 *
 * ── WHAT THE PROSE DELIBERATELY DOES NOT SAY ─────────────────────────────────
 *
 * No percentages. Not the community/judge split, not the purchased-vote ceiling,
 * not the community-return share. Every one of those is a RuleEngine setting an
 * operator can change per programme and per cycle, and this content is STATIC HTML
 * in a database column — there is no token resolution on the way out, so a figure
 * typed here would freeze at the moment it was written while the engine moved on.
 * A stale figure in the terms is materially worse than one on a marketing page,
 * because the terms are what a disputed result gets argued against.
 *
 * So the documents describe the MECHANISM and point at `/integrity` for the
 * numbers, which is read live from the same engine the scorer uses. That is not a
 * dodge; it is the only way a static document can promise something true.
 * `LegalDocumentTest` fails the build if a percentage appears here.
 *
 * ── AND WHAT IT CANNOT BE ────────────────────────────────────────────────────
 *
 * A faithful, plain-language description of what the platform actually does,
 * written from the code. NOT legal advice, and not reviewed by counsel. The NDPA
 * specifics, the consumer-protection wording and the limitation-of-liability
 * language in particular want a lawyer before this is relied on.
 */
final class LegalSeeder
{
    /**
     * Install the documents.
     *
     * @param  bool $force replace documents that already exist
     * @return array{written:list<string>, kept:list<string>, failed:array<string,string>}
     */
    public static function install(bool $force = false, ?string $only = null): array
    {
        $out = ['written' => [], 'kept' => [], 'failed' => []];

        foreach (self::documents() as $slug => $doc) {
            if ($only !== null && $only !== '' && $slug !== $only) continue;

            $exists = LegalService::find($slug) !== null;
            if ($exists && !$force) {
                $out['kept'][] = $slug;
                continue;
            }

            try {
                DB::table('gates_legal_docs')->updateOrInsert(['slug' => $slug], [
                    'title'         => $doc['title'],
                    'body_html'     => $doc['body'],
                    'updated_label' => date('j F Y'),
                    'is_published'  => 1,
                    'sort_order'    => $doc['sort'],
                    'updated_at'    => date('Y-m-d H:i:s'),
                ]);
                $out['written'][] = $slug;
            } catch (\Throwable $e) {
                // Reported, never swallowed: a legal document that failed to install
                // is not something to discover from a blank page months later.
                $out['failed'][$slug] = $e->getMessage();
            }
        }

        return $out;
    }

    /**
     * The documents.
     *
     * Kept in one place so `legal:seed` and any future test read the same bytes.
     * Tags are limited to what {@see \AfricaGates\Support\Html::sanitize()} allows,
     * so nothing here is stripped on the way to the page.
     *
     * @return array<string, array{title:string, sort:int, body:string}>
     */
    public static function documents(): array
    {
        return [
            'terms'   => ['title' => 'Terms of Participation', 'sort' => 1, 'body' => self::terms()],
            'privacy' => ['title' => 'Privacy Policy',         'sort' => 2, 'body' => self::privacy()],
            // `/cookies` has been a route since the legal pages shipped and there was no
            // document behind it, so it answered 404 — a published link to a policy that
            // does not exist reads worse than having no link.
            'cookies' => ['title' => 'Cookies',                'sort' => 3, 'body' => self::cookies()],
            // The platform takes money in four places and had no published position on
            // getting it back. There is a whole RefundService with specific, defensible
            // rules; none of them were written down anywhere a payer could read.
            'refunds' => ['title' => 'Refunds and Cancellations', 'sort' => 4, 'body' => self::refunds()],
        ];
    }

    /**
     * ── WHY THIS DOCUMENT IS SHORT ───────────────────────────────────────────
     *
     * Because the true answer is short. This platform sets ONE cookie — the PHP session —
     * and runs no analytics, no advertising and no third-party trackers of any kind.
     *
     * The temptation with a cookie policy is to paste in the standard four categories and a
     * consent banner. Doing that here would describe a site we are not running, and the
     * banner would be asking permission for something we do not do. A strictly necessary
     * session cookie needs no consent under the ePrivacy rules and the NDPA, which is
     * exactly why there is no banner — stated, because its absence otherwise looks like an
     * oversight rather than a consequence.
     *
     * Everything in here is checkable against the code: see `session_set_cookie_params()`
     * in public/index.php for the cookie, and the `afg_*` keys in the templates for the
     * browser storage.
     */
    private static function cookies(): string
    {
        return <<<'HTML'
<h2>The short version</h2>
<p>We set <strong>one cookie</strong>. It keeps you signed in and keeps your place while you
   move around the site. We run <strong>no analytics, no advertising and no third-party
   trackers</strong> — nothing on this site reports your visit to another company.</p>
<p>That is why you have not been shown a consent banner. A banner would be asking your
   permission for something we are not doing.</p>

<h2>The cookie we set</h2>
<table>
  <thead><tr><th>Name</th><th>What it does</th><th>How long it lasts</th></tr></thead>
  <tbody>
    <tr>
      <td><code>PHPSESSID</code></td>
      <td>Identifies your session so you stay signed in, so a form you are halfway through
          is not lost, and so we can tell a real submission from a forged one. It holds a
          reference to data kept on our own server; it does not contain your details.</td>
      <td>Seven days, or until you sign out</td>
    </tr>
  </tbody>
</table>
<p>It is marked <code>HttpOnly</code> (scripts on the page cannot read it),
   <code>SameSite=Lax</code> (it is not sent when another site links to us in a way that
   could act on your behalf) and <code>Secure</code> in production (it travels only over
   an encrypted connection).</p>

<h2>Things kept in your browser, which are not cookies</h2>
<p>Some pages remember small things using your browser's own storage.
   It never leaves your device, and it is never sent to us:</p>
<ul>
  <li>Your shopping basket, so it survives a reload.</li>
  <li>Which programmes you have already voted in, so the page can stop offering.</li>
  <li>A message you started writing for a nominee, so a mistaken tap does not lose it.</li>
  <li>Whether you have seen the introduction, so it is not shown twice.</li>
</ul>
<p>Clearing your browser's site data removes all of it. Nothing important depends on it.</p>

<h2>Turning it off</h2>
<p>Every browser lets you block or delete cookies. If you block ours you can still read the
   site, but you will not be able to sign in, vote, or complete a payment — the cookie is
   what tells us one page of your visit is connected to the next.</p>

<h2>Payments</h2>
<p>When you pay, you are handed to a payment provider on their own page. What they set while
   you are there is governed by their policy, not ours. We never see or store your card
   details.</p>

<h2>Changes</h2>
<p>If we ever add anything that tracks you, this page changes first and you will be asked
   before it runs. We would rather tell you than be found out.</p>
HTML;
    }

    /**
     * ── WRITTEN FROM WHAT THE CODE ACTUALLY DOES ─────────────────────────────
     *
     * {@see \AfricaGates\Services\RefundService} refunds exactly one situation
     * automatically — a paid vote that could not be minted — and refuses every other case
     * to a human on purpose, with guards (a grace window, per-order and per-day ceilings, a
     * claim stamped before the gateway is called) that are structural rather than a promise
     * to be careful.
     *
     * That is an unusually defensible policy and it was written down nowhere a payer could
     * read it. Publishing the vague version — "refunds at our discretion" — would have been
     * both less true and less reassuring than the real one.
     */
    private static function refunds(): string
    {
        return <<<'HTML'
<h2>What you can pay for here</h2>
<ul>
  <li><strong>Votes</strong> beyond your free one, in programmes where extra votes are sold.</li>
  <li><strong>Event tickets.</strong></li>
  <li><strong>Merchandise</strong> from the shop.</li>
  <li><strong>Contributions</strong> to the programme, which are gifts rather than purchases.</li>
</ul>

<h2>Votes that could not be counted are refunded automatically</h2>
<p>If you pay for votes and they cannot be applied — the round closed, the nominee was
   withdrawn, something failed at our end — <strong>you do not have to ask</strong>. Our
   system finds those payments on its own and returns them to the card or account you paid
   from.</p>
<p>This is the only refund that happens without a person, and it is deliberate: it is the
   one case where there is nothing to weigh. You paid for something specific, it did not
   happen, the money is not ours.</p>
<p>It waits about two hours after your payment before acting, because a vote that is merely
   slow to be counted is not a vote that failed. After that it usually reaches you within a
   few hours. Your bank may take a few days longer to show it.</p>

<h2>Everything else is decided by a person</h2>
<p>Ask us, and a person will read it. We are not going to pretend an automatic rule can
   handle a duplicate charge, a mistaken amount or a change of circumstances fairly.</p>
<table>
  <thead><tr><th>What happened</th><th>Where we stand</th></tr></thead>
  <tbody>
    <tr><td>You were charged twice for the same thing</td>
        <td>Refunded in full. Tell us the reference and we will find it.</td></tr>
    <tr><td>You paid and got nothing</td>
        <td>Refunded in full.</td></tr>
    <tr><td><strong>An event is cancelled or moved</strong> by us</td>
        <td>Refunded in full, without being asked. If it is moved, you may keep the ticket
            for the new date instead.</td></tr>
    <tr><td>You cannot attend an event</td>
        <td>Refunded if you tell us <strong>more than seven days</strong> before it.
            Inside seven days we have already paid for your place, so we will try to
            transfer the ticket to somebody else instead.</td></tr>
    <tr><td>Merchandise arrived damaged or wrong</td>
        <td>Replaced or refunded, including what you paid for delivery. Tell us within
            fourteen days of it arriving.</td></tr>
    <tr><td>You changed your mind about merchandise</td>
        <td>Refunded if it is unused and comes back to us within fourteen days. Return
            postage is yours unless the item was wrong.</td></tr>
    <tr><td>Votes you chose to buy and that were counted</td>
        <td><strong>Not refundable.</strong> They were applied and they affected a result
            other people can see. Taking them back afterwards would change a public tally
            that has already been read.</td></tr>
    <tr><td>Contributions to the programme</td>
        <td>Gifts, so not refundable as a rule — but tell us if you contributed by mistake
            or for more than you meant, and we will put it right. We would rather return
            money than keep money somebody did not intend to give.</td></tr>
  </tbody>
</table>

<h2>How to ask</h2>
<p>Email <a href="mailto:support@afrovanguard.org.ng">support@afrovanguard.org.ng</a> with the
   payment reference from your receipt. We reply within <strong>three working days</strong>
   and tell you either way, with a reason.</p>
<p>Please come to us before asking your bank to reverse a charge. A chargeback costs us a
   fee and closes the account it was made from, so if you are owed money we would rather
   simply send it back.</p>

<h2>What we never do</h2>
<ul>
  <li>We never keep money for something that did not happen.</li>
  <li>We never charge a fee for issuing a refund.</li>
  <li>We never refund to a different account from the one that paid, because that is how
      stolen cards are laundered.</li>
</ul>
HTML;
    }

    private static function terms(): string
    {
        return <<<'HTML'
<h2>Who we are</h2>
<p>Africa GATES is a continental cultural-recognition programme operated by <strong>Afrovanguard</strong>, Lagos, Nigeria. It recognises African excellence through the Cultural Power Index (CPI), combining verified community votes with independent expert judging.</p>
<p>These terms apply to everyone who uses the platform: nominators, nominees, voters, supporters who contribute, and members. By nominating, voting, contributing or registering, you accept them.</p>

<h2>What Africa GATES is, and is not</h2>
<p>It is a recognition programme. Nominations are reviewed by people, shortlisted nominees are voted on by the public and scored by an independent panel, and results are computed, sealed and published.</p>
<p>It is <strong>not</strong> a lottery, a competition of chance, an investment product, or a scheme in which money is promised to grow. See <em>This is not a pyramid or investment scheme</em> below, which is not boilerplate — it is the single most important thing to understand before contributing.</p>

<h2>Nominating someone</h2>
<p>Anyone may nominate. A nomination is a claim you are making about another person in public, so we ask you to make it carefully.</p>
<ul>
<li>Every nomination enters a moderation queue and must clear <strong>human review</strong> before it appears anywhere on the platform. Nothing is published automatically.</li>
<li>You must have the nominee&rsquo;s permission before submitting their email address or phone number. We contact nominees on the details you provide.</li>
<li>Nominations that are fabricated, duplicated to inflate a field, or submitted to harass someone are removed, and repeat submitters may be blocked.</li>
<li>A nominee may ask to be withdrawn at any point before results are sealed. Write to <a href="mailto:integrity@afrovanguard.org.ng">integrity@afrovanguard.org.ng</a>.</li>
</ul>
<p>Unverified profiles score nothing. No votes or impact points attach to a profile until it has cleared review, so a fabricated entry cannot accumulate a standing while it waits.</p>

<h2>Voting &mdash; free</h2>
<p>Free voting is open to the public and is the vote that determines a nominee&rsquo;s community score.</p>
<ul>
<li>Every vote requires a <strong>one-time code</strong> sent to a working email address. An unconfirmed code is not a vote.</li>
<li><strong>One identity, one vote, per category.</strong> You may vote in as many categories as you wish, but only once in each.</li>
<li>Every attempt is risk-scored before anything is recorded. Low-risk votes are counted as cast; higher-risk attempts may be held for a person to review; the highest are refused before a vote exists. Being held for review is not an accusation &mdash; shared office networks and campus wifi produce the same pattern a vote farm does, which is exactly why a person looks at it.</li>
<li>Disposable and throwaway email domains are refused at the moment a code is requested.</li>
<li>A total can go down. Votes later found not to be genuine are removed. A falling number is usually the integrity system working.</li>
</ul>

<h2>Voting &mdash; contributions</h2>
<p>Supporters may also contribute to the programme in a nominee&rsquo;s name. A contribution adds to that nominee&rsquo;s <strong>public tally</strong> and helps fund the judging panel, the verification work, communications, production and the platform itself.</p>
<p>A contribution is a <strong>participatory contribution toward a recognition process</strong>. It is not the purchase of an award, and it is not a donation to the nominee personally, though a share may return to them &mdash; see <em>The community return</em>.</p>

<h2>What a contribution does, and does not, do</h2>
<p>This is stated plainly because it is the fair question to ask.</p>
<p><strong>It does:</strong></p>
<ul>
<li>Add to the nominee&rsquo;s public tally, shown on their page and on the leaderboard.</li>
<li>Place the supporter&rsquo;s name on the nominee&rsquo;s supporters list, if the supporter chose to be named.</li>
<li>Set aside a share for the nominee once they qualify.</li>
<li>Fund the programme.</li>
</ul>
<p><strong>It does not:</strong></p>
<ul>
<li><strong>Move the Cultural Power Index.</strong> The community component of the score is computed over organic, code-verified votes only. Purchased votes are held in a separate column the scorer does not read.</li>
<li><strong>Break a tie.</strong> Where two nominees finish level, the tiebreak is organic votes. One verified supporter outranks any number of purchased votes.</li>
<li><strong>Run without a ceiling.</strong> Purchased votes on a nominee are capped as a proportion of the organic support that nominee has earned, so money can amplify a real following and cannot manufacture one.</li>
<li><strong>Buy consideration from a judge</strong>, who cannot see it.</li>
</ul>
<p>Money can make a nominee look more popular. It cannot make them win. The current weightings, the ceiling and the judging quorum are published at <a href="/integrity">/integrity</a>, read live from the scoring configuration rather than restated here, so that page is always the authoritative figure.</p>

<h2>The community return</h2>
<p>Where a nominee&rsquo;s supporters raise contributions in their name, a share is set aside for the nominee &mdash; win or lose, because they raised it either way.</p>
<ul>
<li>A nominee begins earning only after reaching a <strong>qualifying-support threshold</strong>, counted in votes.</li>
<li><strong>No single supporter can carry the threshold.</strong> One supporter&rsquo;s contribution counts only up to a capped proportion of it, so qualifying requires support from several different verified people however much any one person spends.</li>
<li>Crossing the threshold does not reach backwards. Earlier contributions stay unshared.</li>
<li>The share is held as an <strong>append-only ledger</strong>. If a contribution is refunded or charged back, the share accrued on it is reversed and the reversal stays on the record beside the original.</li>
<li>Nothing is payable until the cycle has ended and its results have been announced. A nominee cannot cash out mid-race.</li>
</ul>
<p>The current share, threshold and per-supporter cap are published at <a href="/integrity">/integrity</a>. Eligibility requirements and payment arrangements are communicated to qualifying nominees directly.</p>

<h2>How winners are decided</h2>
<p>The Cultural Power Index combines verified community votes with an independent judging panel. Neither alone decides a winner.</p>
<ul>
<li>The community component is <strong>normalised within each category</strong>, against that category&rsquo;s strongest vote count, so a nominee in a small field is not penalised for being in one.</li>
<li>Judges score each shortlisted nominee on equally-weighted criteria and <strong>cannot see the vote tally</strong> while scoring.</li>
<li>A nominee must have a minimum number of <strong>complete</strong> judge scorecards to be eligible to win. A partial scorecard counts as nothing. Where no nominee in a category reaches the quorum, the award is not given.</li>
<li>Judges recuse themselves from nominees they are connected to, and their own votes in a category they judge are excluded.</li>
<li>Once a category opens for voting, the shortlist is <strong>frozen</strong>. Nobody can be added.</li>
<li>Every computed standing is sealed with a fingerprint of itself and of the record before it, so an old result cannot be quietly edited. The chain is re-verified daily.</li>
</ul>
<p>Leading the public vote makes a nominee the front-runner. It does not by itself make them eligible to be declared the winner. The full method is at <a href="/integrity">/integrity</a>; the reasoning behind it is at <a href="/philosophy">/philosophy</a>.</p>

<h2>Payments, failures and refunds</h2>
<p>Payments pass through a bank and a payment gateway before reaching us, and any of those steps can fail after a card has been charged.</p>
<ul>
<li>All payments are verified <strong>server-side with the gateway</strong> before any vote is recorded. We do not take a browser&rsquo;s word for it.</li>
<li><strong>If the ballot was open when you paid, the votes are yours</strong> even if the confirmation reached us late. We use the time you started the payment, not the moment our system noticed it.</li>
<li>Card contribution closes a short time before free voting does, so payments already in flight have time to land before the ballot shuts.</li>
<li><strong>Where votes genuinely cannot be delivered</strong> &mdash; the nominee left the ballot, or a payment confirmed long after voting closed &mdash; the order is refunded. We do not keep money for votes we did not deliver.</li>
<li>Every order carries a reference beginning <code>AFG-</code>. Give it to the <a href="/support/assistant">support assistant</a> and it re-checks the payment with the gateway directly, adding the votes on the spot if the money arrived.</li>
<li>Contributions are otherwise non-refundable once the corresponding votes have been delivered, because the recognition process they funded has taken place.</li>
</ul>

<h2>Prohibited conduct</h2>
<p>The following are grounds for removing votes, disqualifying a nominee, closing an account, and where appropriate reporting to the authorities.</p>
<ul>
<li>Vote manipulation of any kind: automated voting, bulk or generated email addresses, paying people to cast votes they would not otherwise cast, or coordinating to defeat the one-identity-one-vote rule.</li>
<li>Impersonating Africa GATES, Afrovanguard, a judge, a member of staff or a volunteer.</li>
<li>Creating or advertising unofficial payment channels. Payments are only ever taken through the official checkout on this platform.</li>
<li>Attempting to influence a judge, or offering anything of value in connection with a scoring decision.</li>
<li>Falsifying nomination evidence, or submitting another person&rsquo;s work as a nominee&rsquo;s own.</li>
<li>Harassment, abuse, hate speech or threats, including in community discussions.</li>
<li>Interfering with the platform&rsquo;s security, or attempting to access data that is not yours.</li>
</ul>
<p>Report any of this to <a href="mailto:integrity@afrovanguard.org.ng">integrity@afrovanguard.org.ng</a>. Reports are treated confidentially and you do not need proof to raise a concern.</p>

<h2>This is not a pyramid or investment scheme</h2>
<p>Africa GATES is <strong>not</strong> a Ponzi scheme, pyramid scheme, multi-level marketing programme, investment scheme, get-rich-quick scheme or fraudulent fundraising operation.</p>
<ul>
<li>No participant is promised a financial return for recruiting other participants.</li>
<li>No participant earns anything by introducing another person into the programme.</li>
<li>There is no chain, no downline, no tier to climb and no referral commission.</li>
<li>Any community-return arrangement is a programme-based allocation subject to stated eligibility requirements. It is <strong>not</strong> an investment return, interest, profit share, or a promise of financial appreciation, and it is not payable simply because money was spent.</li>
</ul>
<p>If anyone tells you otherwise &mdash; particularly if they ask you to pay them directly, or promise you earnings for signing others up &mdash; they are not acting for Africa GATES. Report it.</p>

<h2>Content you submit</h2>
<p>You keep ownership of what you submit. By submitting it you grant Africa GATES a non-exclusive, royalty-free licence to publish, display and reproduce it in connection with the programme and its promotion, including in announcements, the public register and the legacy archive.</p>
<p>You confirm that you have the right to submit it, that it does not infringe anyone else&rsquo;s rights, and that any photograph you upload is one you are entitled to use. We remove material on a substantiated rights complaint.</p>

<h2>Disputes and appeals</h2>
<p>If you believe a result was manipulated, a fraud pattern was missed, or a decision about you was wrong, say so. Every recomputation is logged, so a count can be replayed from the underlying votes and the working shown to you.</p>
<p>Write to <a href="mailto:integrity@afrovanguard.org.ng">integrity@afrovanguard.org.ng</a> or use <a href="/support">Support &amp; appeals</a>. Appeals are reviewed by someone independent of the original decision. We will acknowledge your complaint, export the relevant audit trail, engage an independent reviewer where a complaint is substantiated, and publish a summary of findings where a dispute affected a final result.</p>

<h2>Availability and liability</h2>
<p>We work to keep the platform available and accurate, but we do not guarantee uninterrupted service, and cycle dates may change where circumstances require it. Where a change affects voting that has already happened, we will say so publicly rather than adjust quietly.</p>
<p>To the extent permitted by law, our liability in connection with the programme is limited to the amount you contributed in the cycle to which the claim relates. Nothing in these terms limits liability that cannot be limited under Nigerian law, including for fraud.</p>

<h2>Changes to these terms</h2>
<p>We may revise these terms. The effective date is shown at the top of this page, and a plain-text and Markdown copy of the version you are reading can be downloaded from the toolbar &mdash; so you can keep the version that applied to you. Material changes affecting an open cycle will be announced rather than made silently.</p>

<h2>Governing law</h2>
<p>These terms are governed by the laws of the <strong>Federal Republic of Nigeria</strong>, and the courts of Nigeria have jurisdiction. Africa GATES is an initiative of Afrovanguard, Lagos, Nigeria.</p>
<p>Questions about these terms: <a href="mailto:legal@afrovanguard.org.ng">legal@afrovanguard.org.ng</a>.</p>
HTML;
    }

    /**
     * The privacy policy.
     *
     * NOTE: it deliberately contains NO "Automated processing (AI)" section.
     * {@see \AfricaGates\Services\LegalDocument::bodyHtml()} appends that section,
     * generated from the AI capability registry, to whatever is stored here — so
     * writing one by hand would produce two, and the handwritten one would be the
     * one that went stale.
     */
    private static function privacy(): string
    {
        return <<<'HTML'
<h2>Who we are and how to reach us</h2>
<p>Africa GATES is operated by <strong>Afrovanguard</strong>, Lagos, Nigeria, which is the data controller for the personal data described here.</p>
<p>For anything about your data, including a request to see it or erase it, write to <a href="mailto:privacy@afrovanguard.org.ng">privacy@afrovanguard.org.ng</a>. We would rather answer a question than have you guess.</p>

<h2>What we collect when you vote</h2>
<p>Voting is deliberately designed so that we can tell two votes came from the same person <strong>without holding the person</strong>.</p>
<ul>
<li>A <strong>SHA-256 hash</strong> of your email address. Not the address itself.</li>
<li>A SHA-256 hash of your IP address, and a device fingerprint, both used to detect vote manipulation.</li>
<li>The nominee and category you voted in, and the timestamp.</li>
<li>A risk score for the attempt, and whether it was approved, held for review or refused.</li>
</ul>
<p>Your one-time code is sent to your email address in order to deliver it, and the address is not retained against the vote in a readable form. <strong>We never store your plain-text email address against a vote.</strong></p>

<h2>What we collect when you nominate</h2>
<ul>
<li>The nominee&rsquo;s name, country, region, category, the supporting reason you write, any evidence links, and an optional photograph.</li>
<li>The nominee&rsquo;s contact detail &mdash; an email address or a phone number &mdash; so that we can tell them they have been nominated. Phone numbers are normalised to international format.</li>
<li>Your own name, email address, region and locality, so that a nomination is accountable to a real person and so we can tell you when it is reviewed.</li>
</ul>
<p>A nomination is intended for publication if it is approved. Do not put anything in the supporting reason that the nominee would not want published.</p>

<h2>What we collect when you contribute or buy</h2>
<ul>
<li>The order reference, amount, currency, status and timestamps.</li>
<li>The name and email address you give at checkout, and whether you chose to be named publicly on a nominee&rsquo;s supporters list.</li>
<li>A reference from the payment gateway, so a payment can be re-verified later.</li>
</ul>
<p><strong>We never see or store your card number, CVV or bank credentials.</strong> Card details are entered on the payment provider&rsquo;s own hosted page and never reach our servers.</p>

<h2>What we do not do</h2>
<ul>
<li>We do not sell, rent or trade personal data. Not to anyone, at any price.</li>
<li>We do not use your data for advertising profiling.</li>
<li>We do not show a nominee who voted for them. Nominees see counts, never voter identities.</li>
<li>We do not publish a supporter&rsquo;s name unless the supporter chose to be named. Free votes are never added to a public supporters list, because the name on a free vote is collected for accountability rather than for publication.</li>
</ul>

<h2>Why we are allowed to hold it</h2>
<p>Under the Nigeria Data Protection Act we rely on:</p>
<ul>
<li><strong>Your consent</strong>, for optional things &mdash; being named on a supporters list, marketing email, an uploaded photograph. You can withdraw it.</li>
<li><strong>Performance of a contract</strong>, for the things without which we cannot run what you asked for: recording a vote, processing a payment, delivering a nomination.</li>
<li><strong>Legitimate interests</strong>, for keeping the count honest &mdash; fraud detection, risk scoring, abuse prevention and audit. A recognition programme whose results cannot be trusted has no purpose, and this is the narrowest processing that makes them trustworthy.</li>
<li><strong>Legal obligation</strong>, for financial records we are required to keep.</li>
</ul>

<h2>Who we share it with</h2>
<p>Only the parties needed to run the programme, and only what each of them needs:</p>
<ul>
<li><strong>Payment providers</strong>, to take and verify payments.</li>
<li><strong>Our email and SMS providers</strong>, to deliver one-time codes, receipts and notifications.</li>
<li><strong>Cloudflare Turnstile</strong>, to tell a person from a script.</li>
<li><strong>Our hosting and storage providers</strong>, who hold the data at rest.</li>
<li><strong>Language-model providers</strong>, for the specific advisory features listed in the automated-processing section below &mdash; which is generated from the platform&rsquo;s own configuration rather than written by hand, so it always describes what the code actually does.</li>
<li><strong>Judges</strong>, who see a nominee&rsquo;s dossier. They do not see voter data or vote tallies.</li>
</ul>
<p>We may disclose data where we are legally required to, or where it is necessary to investigate manipulation or protect someone from harm.</p>

<h2>How long we keep it</h2>
<ul>
<li><strong>Vote records</strong> &mdash; hashes, risk scores and timestamps &mdash; are kept for the life of the archive. They are what makes a historic result auditable, and they contain no readable identity.</li>
<li><strong>Nomination and contact details</strong> are kept for the cycle and a reasonable period afterwards for audit and appeals.</li>
<li><strong>Payment records</strong> are kept as long as financial and tax rules require.</li>
<li><strong>One-time codes</strong> expire in minutes and are discarded.</li>
</ul>

<h2>Your rights</h2>
<p>You may ask us to:</p>
<ol>
<li>Tell you what we hold about you, and give you a copy.</li>
<li>Correct anything inaccurate.</li>
<li>Erase your personal data, where we are not required to keep it.</li>
<li>Stop using it for a particular purpose, or withdraw a consent you gave.</li>
<li>Remove your name from a public supporters list.</li>
</ol>
<p>Write to <a href="mailto:privacy@afrovanguard.org.ng">privacy@afrovanguard.org.ng</a>. One honest limitation: because a vote is stored against a hash rather than an address, we may need you to confirm the address you voted with in order to find the record at all. If you are not satisfied with how we handle a request, you can complain to the Nigeria Data Protection Commission.</p>

<h2>Cookies and local storage</h2>
<p>We use a session cookie to keep you signed in and to carry the security token that protects forms from cross-site submission. It is marked HttpOnly, and Secure over HTTPS.</p>
<p>Local storage is used for small conveniences: remembering which ballots you have already voted in on this device, and whether you dismissed a banner. We do not use third-party advertising or cross-site tracking cookies.</p>

<h2>Children</h2>
<p>The platform is not directed at children under 13, and we do not knowingly collect their data. A nominee may be a minor &mdash; a young person can be recognised for their work &mdash; in which case a parent or guardian should be the one to provide contact details and consent to publication. Write to us and we will remove data collected from a child in error.</p>

<h2>Where your data is held</h2>
<p>Our providers may process data outside Nigeria. Where that happens we rely on the transfer mechanisms available under the Nigeria Data Protection Act and on the providers&rsquo; own contractual protections.</p>
<p>What we cannot yet tell you, and would rather state than paper over: exactly how long each language-model provider retains what it receives, and whether they use it to train their models. That is governed by each provider&rsquo;s own terms rather than ours. If it matters to your decision to take part, ask us and we will tell you what we know.</p>

<h2>Changes to this policy</h2>
<p>The effective date is shown at the top of this page, and you can download a copy of the version you are reading from the toolbar. Where a change materially affects how we handle data you have already given us, we will say so rather than update quietly.</p>
HTML;
    }
}
