# `templates/pages/stands/apply.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /events/{slug}/stands/apply → StandApplyController::form`; `POST /events/{slug}/stands/apply → StandApplyController::submit`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
 ─────────────────────────────────────────────────────────────────────────────
   THE STAND APPLICATION.

   Two shapes, one page. A signed-in vendor sees only the questions that are about this
   event; a new applicant is asked who they are as well, and the account is created in the
   same request as the application — splitting them produces accounts belonging to people
   who never finished applying.

   ── WHAT THE REBUILD FIXED, AND WHY EACH ONE WAS COSTING APPLICATIONS ───────

   IT LOOKED LIKE A CHECKOUT. Three prices and a submit button, and nothing anywhere
   saying that applying is free. Somebody reading "₦95,000" beside "Create account and
   apply" has every reason to think they are about to be charged, and the ones who are not
   sure close the tab. It now says so, twice, in the places a person is deciding.
…
```

**Headings:** Apply to trade at {{ event.title }} · Which stand · Applying as {{ org.name }} · Who is applying · What you will upload · {{ entities[entity]|default(entity) }} · What you will sell · What happens after you press it

**Data read (top-level variables/functions):** `old`, `bad_field`, `event`, `org`, `entity`, `label`, `slug`, `call`, `error`, `categories`, `photo_max`, `photo_min`, `photo_limit_h`, `photo_budget_h`, `photo_required`, `list`, `entities`, `sells_max`, `photo_accept`, `offer_hours`, `application`, `account`, `apply`, `photo_max_bytes`, `photo_budget`, `capacity`, `docs`

**Forms:**
- `POST /events/{{ event.slug }}/stands/apply` [enctype] fields: _token[hidden], stand_type_id[radio required], entity_type[radio], name[text required,maxlength], legal_name[text required,maxlength], cac_number[text maxlength,pattern], contact_email[email required,maxlength,autocomplete], password[password required,minlength,autocomplete], contact_name[text maxlength,autocomplete], contact_phone[tel maxlength,autocomplete], what_they_sell[textarea required,maxlength], category[select required], photos[][file], needs_power[checkbox], needs_step_free[checkbox]; buttons: Suggest from what I wrote | Add photo | {{ org ? 'Submit application' : 'Create account an

**States / branches (25 distinct conditions):** `error` · `bad_field` · `c.left == 0` · `loop.first` · `loop.first and bad_field == 'stand_type_id'` · `old.stand_type_id is defined and old.stand_type_id == c.type.id` · `c.left > 0` · `c.type.includes_power` · `c.type.step_free` · `c.type.deposit_naira > 0` · `c.type.description` · `org` · `old.entity_type is not defined or old.entity_type == 'individual'` · `old.entity_type is defined and old.entity_type == 'business'` · `bad_field == 'name'` · `bad_field == 'legal_name'` · `bad_field == 'cac_number'` · `bad_field == 'contact_email'` · `bad_field == 'password'` · `bad_field == 'what_they_sell'` · `bad_field == 'category'` · `old.category|default('') == slug` · `photo_required` · `old.needs_power is defined` · `old.needs_step_free is defined`

**Links out:** `/events/{{ event.slug }}/stands` · `#{{ {'name':'apName','legal_name':'apLegal','cac_number':'apCac',
              ` · `/org`

**JS behaviours:** 1 inline <script> block(s); data hooks: `data-page`, `data-ap-step`; fetches: `/events/{{ event.slug }}/stands/suggest-category`

**Accessibility affordances:** aria-hidden×18, aria-invalid×8, aria-describedby×8, aria-live×2, aria-label×1, aria-labelledby×1; roles: alert, status; visually-hidden text×2; <label for>×9; tabindex×1; autocomplete×4

**Legal / consent lines:**
- The call and its terms

**Styling carried:** 1 <style> block(s), 6 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `StandPhotosTest::test_the_form_posts_the_photographs_with_everything_else` — The photographs ride along in the single POST that was already there.

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `StandPhotosTest::test_the_form_posts_the_photographs_with_everything_else` — The photographs ride along in the single POST that was already there. — *the form carries files and did not say so*
- `StandSurfacesTest::test_a_field_the_refusal_is_not_about_is_left_unmarked` — A field the refusal is not about is left unmarked.
- `StandSurfacesTest::test_a_post_php_discarded_is_explained_as_the_upload_it_was` — The body PHP threw away. — *still blaming the stand type for a body the language discarded*
- `StandSurfacesTest::test_a_refusal_names_the_field_it_is_about` — a refusal re-renders, it does not redirect
- `StandSurfacesTest::test_a_rejected_form_comes_back_filled_in` — A bad detail must not cost them the other eight fields. — *The typed answers must survive the error.*
- `StandSurfacesTest::test_an_application_without_photographs_is_refused_before_anything_is_written` — Photographs are a submit gate, not a dashboard nag. — *it went through without photographs*
- `StandSurfacesTest::test_the_form_offers_the_individual_route_first` — The CAC field must be visibly optional, or a sole trader invents a number for it.
- `StandSurfacesTest::test_the_textarea_cap_and_the_server_cap_are_the_same_number` — The textarea cap and the server cap are the same number.
- `StandSurfacesTest::test_too_few_photographs_is_refused_and_the_count_is_named` — And two is not three. — *a vendor short of the minimum must be told how far short*
