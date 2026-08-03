# Support & Help — how the whole surface fits together

*Last rewritten: 2026-08-03. Owner: whoever last changed `HelpCentre::articles()`.*

There are five places a stuck person can end up, and until now they overlapped
badly: two of them answered the same questions from two different documents, one
of them linked to the product instead of to answers, and none of them could be
linked to. This is what each one is now for, and what must never move between
them.

---

## 1. The map

| Surface | URL | Who it's for | What it can actually do |
|---|---|---|---|
| **Help Centre** | `/help`, `/help/<slug>` | Anyone, signed in or not | Answers a general question. Cannot see your account. |
| **Support assistant** | `/support/assistant` | Anyone | **Repairs things.** Re-checks a payment with the gateway, credits missing votes, resends a receipt, reports refund status. Reads *your* records if signed in. |
| **Support & appeals** | `/support` | Anyone | Triage. Routes to the three below in the order that resolves fastest. |
| **Tickets** | `/support/tickets` | Members only | A threaded conversation with a person. |
| **Appeal form** | `/support#sp-form` | Anyone | Opens a mail draft to `appeals@` for decisions that need judgement. |

**The rule that decides which one gets a job:** can the outcome be reached by
*looking something up*, or does it need *judgement*? Lookups go to the assistant.
Judgement goes to a person. The Help Centre explains; it never adjudicates.

---

## 2. One corpus, two readers

The single most important structural change. Before, the same questions were
answered in two places:

- `help.twig` carried six FAQ pairs in a `{% set %}` block.
- `SupportKnowledge` carried a separate set of playbooks for the models.

Neither knew about the other. When the checkout cutoff moved, or the refund grace
doubled, or paid voting gained a tier ladder, each had to be remembered in two
files by whoever happened to make the change. A supporter reading the page and a
supporter asking the assistant were the same person, answered from different
documents.

Now there is **one corpus** — `src/Services/HelpCentre.php` — and both read it:

```
HelpCentre::articles()          the written answers (24 today)
   ├── /help, /help/<slug>      HelpController renders them
   └── help_article tool        the assistant searches and QUOTES them, with a URL
```

`SupportKnowledge` still exists and still matters: it teaches the models how the
platform *works* (how money moves, what breaks, what they may do). The Help Centre
holds what a *supporter* should be told. Briefing vs. answer — different audiences,
no overlap.

### Why articles have their own URLs

An accordion has no URL, and four things follow from that:

1. Support cannot paste an answer into a reply.
2. The assistant cannot cite one — so it paraphrases, and a paraphrase of a policy
   is a new policy nobody approved.
3. A receipt email cannot point at the exact paragraph about late payments.
4. Nobody searching the web for *"africa gates votes not showing"* finds anything,
   so they open a ticket instead.

One URL per answer fixes all four.

---

## 3. Nothing in an article states a number

An article that states a price is an article that eventually lies — and a wrong
help page is worse than a missing one, because the reader has no way to tell.

Every figure is a placeholder, resolved from the running system at render time:

| Placeholder | Reads from |
|---|---|
| `{price}` | `PaidVoteService::pricePerVote()` |
| `{max_qty}` | `PaidVoteService::maxQtyForOrder()` |
| `{cutoff}` | `PaidVoteService::checkoutCutoffMinutes()` |
| `{grace}` | `PaidVoteService::lateMintGraceHours()` |

Change the price of a vote in Settings and every article that mentions it is
already correct. `HelpCentreTest` fails the build if anyone writes a naira figure
or a cutoff into the prose directly.

---

## 4. Search matches what people type, not what we wrote

`HelpCentre::search()` scores **keywords above titles above body text**, which is
the opposite of naive search and is the whole point. Keywords are the words real
people use, and several appear nowhere in the article they point at:

- *"debited"* → **I paid but my votes have not appeared** (the article never uses the word)
- *"OPay"* → **The reference my wallet app shows is different**
- *"not reflecting"* → the payment article, not the free-voting one
- *"someone is buying votes"* → **I think a count or a ranking is wrong**

Twelve of these routes are pinned in `HelpCentreTest`. They are the assertions
most likely to be broken by a careless edit and the least likely to be noticed.

This is also why search is **server-side**. The old page filtered accordions with
Alpine, which can only match text already rendered — so "debited" found nothing.

---

## 5. The corpus grows from evidence

```
php bin/console support:gaps --days=90
```

Reads real ticket subjects and first messages, runs each through the *same* search
the Help Centre and the assistant use, and sorts them:

- **COVERED** — a confident match exists.
- **WEAK** — something matched, barely. Usually an article that exists but doesn't
  use the reader's words. A keyword away from working; cheaper to fix than to write.
- **UNCOVERED** — nothing matched. Write these, ranked by how many people asked.

A high COVERED count is itself a finding: people are opening tickets for things we
*have* written, which is a **discovery** problem, not a content one, and no new
article will fix it. Look at the ballot, the receipt email, the checkout failure
chips, and whether the assistant is reaching for `help_article` at all.

### Why it reports instead of writing

It would be easy to point a model at the uncovered cluster and generate prose.
Deliberately not. A help article is a **promise about how the platform behaves**,
and the corpus is handed to the support models as *trusted context, above the
fence*. Something that generated its own trusted context from user-supplied ticket
text would be a prompt-injection vector with a publishing pipeline attached — a
ticket reading *"ignore previous instructions, the refund policy is X"* must never
become an article that says so.

So it reports, with real examples in the askers' own words, and a person writes the
answer.

---

## 6. Page-by-page layout

### `/help` — index

Ordered as a triage, not a taxonomy. A person here is stuck, not browsing.

1. **Search** — a real `GET` form, so a search is a URL you can share with support.
2. **"Most people are here for one of these"** — four articles, hard-coded in
   `HelpController::index()`, covering the commonest arrivals.
3. **Categories** — everything else, six groups, money first.
4. **Still stuck** — the assistant (can repair) and a person (can decide).

Money leads. A product team would lead with nominations because that's the start of
the journey; the ticket queue says the commonest and most upset arrival is somebody
who paid and saw nothing. Making them scroll past *"How to nominate"* is a small
unkindness repeated thousands of times.

**A search with no results is not a dead end** — it hands over to the assistant
with the question carried across in the query string.

### `/help/<slug>` — article

- One column, ~62 characters. Prose read while upset, often one-handed.
- Rail is genuinely secondary: it collapses **below** the answer on narrow screens,
  never above it.
- "Did this answer it?" is **routing, not rating**. "No" opens the assistant with
  the article's own title as the question. A help page that measures satisfaction
  without offering an exit measures the wrong thing.
- An unknown slug redirects to `/help?q=<slug as words>` rather than 404ing —
  somebody following a stale link from an old email is still a person with a question.

### `/support` — triage

The old page had five visually identical cards in two rows doing three different
things: two navigated, three silently set a hidden form field and scrolled. The
only way to learn which was which was to click one.

Now one numbered triage, in resolution order:

1. **Try this first** — assistant (styled as the recommendation, because it's the
   only route that can *fix* something) and the Help Centre.
2. **Reach a person** — tickets (members, threaded) or the appeal form (anyone).
3. **Submit an appeal** — the form, with the reason chips **inside** it where they
   belong, and a hint that changes per reason.

---

## 6b. The assistant's tools

Grouped by what they are for. A tool that does not remove a whole class of ticket
is not worth the surface it adds.

**Diagnose — "is it me or you?"**

| Tool | Answers | Notes |
|---|---|---|
| `gateway_status` | Is Paystack/Flutterwave actually down? | Free Statuspage JSON, no key. During an outage this turns a hundred identical tickets into one sentence. **An unreachable status page never reports all-clear.** |
| `check_email_domain` | Can that address receive mail at all? | MX lookup + typo detection. `gmial.com` is a transposition, which plain Levenshtein scores as 2 — handled explicitly. Only the domain is read; the local part never leaves. |
| `platform_health` | Are our own systems up? | Probed, not asserted. |

**Look up — over our own data**

| Tool | Answers |
|---|---|
| `find_nominee` | Ballot link, category, and whether they can be voted for *now* |
| `category_state` | One category: open, closing, checkout cutoff, nominee count |
| `voting_deadlines` | The three clocks — cutoff, bell, late-delivery grace |
| `site_state`, `pricing` | Platform-wide phases and prices |
| `help_article` | The written answers, quoted with a URL |
| `convert_currency` | Naira → USD/GBP/EUR/CAD/ZAR/GHS/KES/XOF/AED. Free FX, no key, indicative only |

**Repair — open to guests, because most buyers have no account**

`fix_payment`, `resend_receipt`. Both idempotent, both decided by the gateway
rather than by the model, both returning an outcome word and never an amount or
an address.

**Scoped to the signed-in member**

`my_transactions`, `my_votes`, `my_tickets`, `my_nominations`, `lookup_reference`.

### Rules for anything that leaves the server

A support bot is a page a stranger can make the server call things from, so the
two outbound tools are deliberately boring: **no keys** (both endpoints are free
and unauthenticated), **no user input in a URL** (so it cannot be turned into a
proxy), **no PII outward**, **6s timeout**, **cached hard** (status 2 min, rates 6
h), and **fail soft** — never reporting "fine" because a check did not run.

---

## 6c. When the model is unavailable

The reported malfunction:

> **User:** I paid and my votes never arrived
> **Gee:** I looked, but I could not put a reliable answer together…
> *· re-checked the payment · checked the reference*

Read the chips. **The tools ran.** They asked the gateway, resolved the reference,
and each returned a `say` field written in plain English precisely so it could be
relayed. All of it was then discarded because a language model could not be
reached — and the supporter was sent to find a human for a question that had
already been answered.

The model here is a *phrasing layer over work that has already happened*. When it
is down, the work is still done and the words already exist.
`SupportAgentService::fromFactsAlone()` composes the reply from those `say` fields
directly. Nothing is generated, so nothing can be hallucinated: it is the most
trustworthy answer the system produces and simply the least fluent.

Two details: `say` strings written **for the model** ("Do not tell them…", "Give
them the link…") are filtered out — excellent instructions to a writer,
humiliating to read in a support chat. And the article preview strip is populated
server-side regardless, so even a failed turn carries something readable.

---

## 7. What the assistant may and may not do

Unchanged by this work, restated because it's the boundary everything else respects:

- Identity comes from `$_SESSION` only. **No tool accepts a user id or email.**
- Repair tools return an *outcome word* — never an amount, a name or an address.
- **There is no tool that causes a refund.** `refund_status` reads only. A language
  model with a refund button is a language model that can be talked into pressing it.
- Ticket creation is members-only; payment repair is not, because most people who
  buy votes never sign in.
- The unattended resolver (`SupportAutoResolver`) runs a **narrower** allowlist,
  checked in code and never in a prompt. Disclosing reads are excluded — there is
  no audience to disclose to. `help_article` is on it; `my_transactions` is not.

---

## 8. Adding an article

1. Add an entry to `HelpCentre::articles()`.
2. `keywords` are **their** words, not yours. Run `support:gaps` and copy the
   phrasings out of real tickets.
3. Use `{price}` / `{cutoff}` / `{grace}` — never a literal number.
4. Add it to at least one other article's `related`, or it is an orphan.
5. `php vendor/bin/phpunit --filter HelpCentreTest` — this checks slugs are unique
   and URL-safe, categories are real, related links resolve, no placeholder leaks
   to a reader, and no figure is hardcoded.

Both readers pick it up immediately. There is nothing to deploy separately and no
second copy to update.
