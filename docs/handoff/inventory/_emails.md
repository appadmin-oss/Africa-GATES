# The email templates — feature inventory before the rebuild (3 Oct 2026)

Taken from the working tree immediately before `templates/emails/*.twig` were deleted and written again to
the handoff's type ladder with colours from `Support\Accent` (owner decision, 3 Oct 2026 — `docs/handoff/PHASE-2.md`
§10). Every row below is a behaviour the rebuilt file still has; the "kept by" column names the test that holds it.

**What changed in every file, and only this:** each `font-size` under 11.5px (10px and 11px labels) is 11.5px, the
§6.2 floor; every colour is `{{ c.<token> }}` / `{{ c['<token>'] }}` — `Accent::mail()`, the palette's hex values
under the handoff's own names, passed in by the sender — where a literal hex was typed (38 distinct hexes, 34 of
them in no palette); and the four document emails' dark-mode block is the newsletter's (ink ground, ground
words), because the handoff has no dark palette and the hexes it carried were in no palette at all. px stays: mail
has no Display & reading setting and many clients ignore rem. The mono and capitals rules are the handoff's for
SCREENS and do not apply here (`MonoAndCaseTest` scopes mail out), so the tracked uppercase micro-labels and the
Consolas reference keep their voice.

**Shared by all seven:** rendered by a bare `Twig\Environment` with `autoescape: html`, wrapped in
`Translator::register()` (so `|trans` would compile; no email string passes through it today — none did before, and
adding catalogue-less entries is not this change); autoescaping applies to every name that reaches them. No email
carries a CSP nonce (`CspTest`). None reads a Twig global.

| Template | Renderer · transport | Shape | Kept by |
|---|---|---|---|
| `invitation.twig` | `InviteMailer::html()` / `preview()` → `OtpService::sendBranded()` → `brandWrap()` | FRAGMENT inside the house shell | `InviteMailerTest`, `InviteInboxCompatTest` |
| `invite-reminder.twig` | `InviteReminders::html()` / `preview()` → `sendBranded()` | FRAGMENT inside the house shell | `InviteRemindersTest` |
| `final-hours.twig` | `NomineeBroadcast::html()` → `sendRawHtml()` | whole document (agw skeleton) | `EmailInboxCompatTest`, `EmailCampaignTest` (visible-text identity with the starter campaign) |
| `campaign.twig` | `EmailCampaign::render()` (+ `sampleVars()` for previews) → `sendRawHtml()` | whole document (agw skeleton) | `CampaignInboxCompatTest`, `EmailCampaignTest` |
| `questionnaire.twig` | `QuestionnaireInvites::html()` → `sendRawHtml()` | whole document (agw skeleton) | `QuestionnaireInvitesTest` |
| `stand-decision.twig` | `StandNotice::html()` → `sendRawHtml()` | whole document (agw skeleton) | `StandNoticeTest` |
| `newsletter.twig` | `Newsletter::html()` → `sendRawHtml()` | whole document (agw skeleton) | `NewsletterInboxCompatTest`, `NewsletterTest` |

## Per template

### `invitation.twig` (fragment)
- **Variables:** `salutation`, `name`, `witness`, `event_title`, `when_day`, `when_date`, `when_time`, `where`, `id_url`,
  `reference`, `discount`, `quota`, `tier_line`, `events_url`. Branches: `where`, `tier_line`.
- **Links:** exactly two — the pass (`id_url`, `/honour/…`) and the guests' tickets (`events_url`). The shell adds Help,
  Privacy and, for an announcement, Unsubscribe.
- **Structure:** Playfair/Georgia salutation; green-ruled "You are invited to" panel with the date as the loudest fact;
  VML + `[if !mso]` green pill "Open your pass"; gold-ruled "Bring your people" panel with the reference in
  `Consolas,'Courier New',monospace` (asserted); sign-off "With respect, Africa GATES".
- **Preheader, unsubscribe, footer:** the shell's (`brandWrap`): hidden preheader with `mso-hide:all`, List-Unsubscribe
  header from the sender, footer © year · postal address · Help · Privacy · Unsubscribe.

### `invite-reminder.twig` (fragment)
- **Variables:** `salutation`, `name`, `headline`, `countdown_up`, `when_day`, `when_date`, `when_time`, `where`,
  `paragraphs` (loop, `|nl2br`), `id_url`, `reference`, `discount`, `quota`, `tier_line`, `events_url`, `sign_off`,
  `team`. Branches: `where`, `tier_line`, `loop.last` margin.
- **Links:** the pass and the tickets, as the invitation.
- **Structure:** gold countdown panel first (`countdown_up` in Playfair 26), the operator's paragraphs as written,
  the green pill, a hairline "Bring your people" panel with the Consolas reference, the view's own sign-off.
- **Preheader, unsubscribe, footer:** the shell's.

### `final-hours.twig`
- **Variables:** `countdown_url`, `countdown_alt`, `closes_human`, `first_name`, `category_name`, `vote_url`,
  `events_url`, `site_url`, `postal_address`, `unsubscribe_url`. Branches: `first_name`, `category_name`.
- **Links:** the nominee's own vote page (twice — the ask and the CTA), `events_url` (twice), `site_url`, `unsubscribe_url`.
- **Structure:** MSO OfficeDocumentSettings; `<style>` resets, `[data-ogsc]` locks, `mso-line-height-rule`, a dark-mode
  block, a ≤600px block; hidden preheader (`display:none; max-height:0; … mso-hide:all; font-size:1px`); fluid-hybrid
  560 wrapper with an `[if mso]` fixed table; dark header bar (hosted 40px mark, "Africa / GATES", "Final Hours");
  countdown GIF hero with styled alt, explicit width/height and the deadline repeated as text ("Voting closes
  {{ closes_human }}"); headline "Finish strong"; quote; two numbered asks; dark callout; VML + fluid CTA "Vote & Share
  Your Link"; secondary tickets link; closing; footer "Africa G.A.T.E.S." · postal address · site · Unsubscribe.
- **Legal footer lines:** the postal address (`MailConfig::postal()`) and a one-click Unsubscribe on every send.

### `campaign.twig`
- **Variables:** `subject`, `preheader`, `countdown_url`, `countdown_alt`, `closes_human`, `blocks` (typed: hero, quote,
  paragraph, heading, ask, callout, button, signoff, divider — each arm the markup final-hours used for that shape),
  `site_url`, `postal_address`, `unsubscribe_url`. `first` pads the top block.
- **Links:** only resolved keys (`b.href`, `b.secondary_link_href`), `site_url`, `unsubscribe_url` — no typed URL can
  arrive (`EmailCampaign::resolve()`).
- **Structure, preheader, footer:** final-hours' skeleton, line for line; the starter campaign's visible text must equal
  final-hours' (`EmailCampaignTest`).

### `questionnaire.twig`
- **Variables:** `first_name`, `programme`, `category_name`, `link`, `deadline`, `site_url`, `postal_address`,
  `unsubscribe_url`. Branches: each of those that is optional; `deadline` renders the deadline panel only when set
  and never says the form closes.
- **Links:** the questionnaire link (button + the URL as text, `word-break:break-all`), `site_url`, `unsubscribe_url`
  (only when given).
- **Structure:** agw skeleton, header label "Your nomination", headline "Tell the judges in your own words", the two
  plain statements ("Nothing here costs money", "Answering honestly…") as real content, footer.

### `stand-decision.twig`
- **Variables:** `kind` (offered · rejected · expired), `subject`, `preheader`, `eyebrow`, `first_name`, `event_title`,
  `event_date`, `stand_name`, `stand_size`, `price`, `deposit`, `offer_hours`, `expires_at`, `reason`, `link`,
  `terms_url`, `site_url`, `postal_address`, `unsubscribe_url`. Branches per `kind`, plus each optional fact.
- **Links:** `link` (accept, or the call), `terms_url`, `site_url`, `unsubscribe_url` (only when given).
- **Structure:** agw skeleton, per-kind headline and body, a facts panel, primary (offered) or outlined (otherwise) button,
  footer.

### `newsletter.twig`
- **Variables:** `subject`, `preheader`, `headline`, `dateline`, `greeting`, `sections` → `items` (`title`, `line`,
  `href`, `cta`, `new`), `since`, `site_url`, `unsubscribe_url`, `postal_address`, `c` (colours).
- **Links:** every item's own page, `site_url`, the per-recipient token unsubscribe (`EmailOptOut::url`).
- **Footer:** "you confirmed a subscription on {{ since }}" (who asked), postal address, Unsubscribe.
- **Colours:** already `c.*` from `Accent`, but through `Newsletter::palette()`, a second name for each token (`card`,
  `ink2`, `surface2`, `action`, `bar`, `dark`, `bar_accent`, `bar_soft`). Those aliases were deleted with the rebuild;
  the template reads the handoff's names from `Accent::mail()` like the other six.

## The house shell (`OtpService::brandWrap()`, PHP)

The invitation and the reminder arrive inside it, so it is part of what those two messages are. Before the rebuild it
set the masthead labels at 10px and 10.5px and typed its own colours (`#dfe1dc`, `#f0f2f2`, `#0c2225`, `#4a5256`,
`#006634`, `#7FC87C`, `rgba(255,255,255,…)`, `rgba(16,41,44,…)`). Kept: the doctype and metas, the iOS data-detector
neutraliser, the ≤620px gutter, the colour-LOCK dark block (a decision — the fragments' ink is inline), the hidden
preheader, the fluid 600 card with its `[if mso]` table, the alpha mark from `Brand::logoUrl(onTint: true)` at 72×83
with a styled alt, the descriptor and category labels, the hero row with width and height, the `ag-pad ag-body` cell
(`InviteInboxCompatTest` cuts the letter out on it), and the footer's © · postal address · Help · Privacy ·
Unsubscribe.
