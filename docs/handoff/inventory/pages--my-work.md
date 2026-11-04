# `templates/pages/my-work.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /my-work/{token:[a-f0-9]{32}} → MyWorkController::page`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
 ── THE NOMINEE'S QUESTIONNAIRE ──────────────────────────────────────────────

   The first version of this page was a form nearly five thousand pixels tall: eleven
   questions, each with help text, then a works list, then a submit panel. A reasonable form
   and an intimidating one. The person reading it is a teacher with an hour after school, on a
   phone, who has never used this site.

   It is now one question at a time, ASKED rather than listed: a mark, a bubble, the help and
   what a judge is looking for underneath, and a composer below with a microphone beside it.
   The shape of the interview, over the fields of the form — so the page that works with no
   AI key and no JavaScript still feels like the version people finish.

   There used to be a third way to answer, between this and the live interview: a guided chat
   over the same draft. It is gone. Three doors onto one submission is not three times the
…
```

**Headings:** This link is not working · {{ form.nominee }} — tell the judges about your work · This has gone to the judging panel · Before you start — what this is, and what happens to it · Introduce yourself out loud — optional · Your works and evidence · Send it to the judges · Attach a file

**Data read (top-level variables/functions):** `form`, `token`, `support_email`, `voice`, `intro`, `val`, `still`, `readiness`, `notice`, `error`, `can`, `send`, `you`, `intro_max`, `missing`, `it`, `whenever`, `like`, `needed`, `before`, `looking_for`, `question`, `skip`, `this`, `one`, `replace`, `the`, `current`, `file`, `intro_ready`, `brief`, `can_resume_interview`

**Forms:**
- `POST /my-work/{{ token }}/ready` fields: _token[hidden]; buttons: I have read this — let's start
- `POST /my-work/{{ token }}` fields: _token[hidden], a[{{ q.slug }}][textarea maxlength], a[{{ q.slug }}][select], a[{{ q.slug }}][{{ q.kind == 'url' ? 'url' : (q.kind == 'number' ? 'number' : (q.kind == 'date' ? 'date' : 'text')) }} maxlength], declared_name[text maxlength,autocomplete]; buttons: {{ loop.index }}{% if q.is_required %}{% endif %} | Say it instead of typing | 0" @click="go(step - 1)">← Back | {{ q.is_required ? 'Next question' : 'Next — or sk | Try a different file | Remove this item | + Add your first item | Send to the judges | Save and finish later
- `POST /my-work/{{ token }}/interview/resume` fields: _token[hidden]; buttons: Would you rather answer these out loud, in a conve
- `POST /my-work/{{ token }}/upload` [enctype] fields: _token[hidden], uid[select], file[file]; buttons: Upload

**States / branches (20 distinct conditions):** `form is null` · `form.is_test|default(false)` · `form.category` · `form.deadline` · `notice` · `error` · `missing|length` · `form.submitted` · `form.declared_name` · `not intro_ready` · `voice` · `q.is_required` · `q.help` · `q.kind == 'textarea'` · `q.kind == 'select'` · `q.criterion` · `voice and q.kind == 'textarea'` · `val` · `still|length` · `can_resume_interview`

**Links out:** `mailto:{{ support_email }}`

**JS behaviours:** fetches: `/my-work/{{ token }}`, `/my-work/{{ token }}/summary`, `/my-work/{{ token }}/speak`, `/my-work/{{ token }}/listen`, `/my-work/{{ token }}/upload`, `/my-work/{{ token }}/intro`, `/my-work/{{ token }}/coach`

**Accessibility affordances:** aria-hidden×6, aria-label×4, aria-pressed×3, aria-live×1, aria-current×1; roles: status, group; visually-hidden text×1; <label for>×11; autocomplete×1; prefers-reduced-motion×2; noscript×2

**Legal / consent lines:**
- .mw__consent{ margin-top:14px; background:#fffdf5; border:1px solid #ecd9a0;
- .mw__consent p{ margin:0; font-size:13px; line-height:1.7; color:#6b5312 }
- // agrees independently — the summary is keyed on a hash of the answers — so a
- consented: {{ (intro.consented ?? false) ? "true" : "false" }},
- /* Re-recording withdraws the old agreement: a panel agreed to hear one
- this.consented = false;
- else if(action === "delete"){ this.has = false; this.text = ""; this.consented = false;
- else { this.consented = true; this.note = d.message; }
- @click="act('consent')">Yes — the panel may hear this

**Styling carried:** 1 <style> block(s), 31 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `GuidedFormTest` (whole file, via a helper/constant/data provider: 20 tests) — test_the_conversation_is_no_longer_read_through_a_letterbox, test_the_wizard_has_a_map, test_the_map_has_a_reactive_dependency_or_it_would_never_move, test_attaching_a_file_no_longer_asks_anybody_to_save_first, test_an_oversize_file_is_offered_the_route_that_works, test_there_are_no_emoji_left_on_the_page, test_the_drawn_glyphs_replaced_them, test_the_page_offers_no_chat_mode, test_the_page_no_longer_posts_to_the_chat_endpoint, test_the_way_back_to_the_live_interview_is_still_there, test_a_question_is_a_wizard_step_and_not_a_chat_turn, test_forward_is_the_primary_control …

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `GuidedFormTest::test_a_question_is_a_wizard_step_and_not_a_chat_turn` — A WIZARD, not a chat.
- `GuidedFormTest::test_an_oversize_file_is_offered_the_route_that_works` — An oversize file is offered the route that works.
- `GuidedFormTest::test_attaching_a_file_no_longer_asks_anybody_to_save_first` — Attaching a file no longer asks anybody to save first.
- `GuidedFormTest::test_dictation_targets_the_question_it_was_started_from` — A dictated answer goes into the field it was dictated for, not into a global box.
- `GuidedFormTest::test_every_field_stays_in_the_dom` — The fields must stay in the DOM at every step. — *x-if removes the field from the DOM, so the answer would never be posted*
- `GuidedFormTest::test_forward_is_the_primary_control` — Next is the primary control and Back is not, because forward is the common press.
- `GuidedFormTest::test_the_alpine_scope_carries_no_apostrophe` — the x-data attribute is truncated — something inside it closed the quote
- `GuidedFormTest::test_the_drawn_glyphs_replaced_them` — no style for {$cls}
- `GuidedFormTest::test_the_map_has_a_reactive_dependency_or_it_would_never_move` — The map has a reactive dependency or it would never move.
- `GuidedFormTest::test_the_page_renders_through_the_real_controller` — Everything above reads the template as text. — *the wizard step did not render*
- `GuidedFormTest::test_the_progress_bar_reads_the_live_answer_count` — The progress bar counts from the fields, and it counts in the OUTER scope. — *the live count is not in the scope the progress bar can see*
- `GuidedFormTest::test_the_speaker_button_is_not_collapsed_by_a_second_rule` — The speaker button was 0x0 pixels. — *two .mw__spk rules again — the later one decides the size of every speaker button*
- `GuidedFormTest::test_the_way_back_to_the_live_interview_is_still_there` — The interview is NOT the chat, and must survive its removal. — */interview/resume*
- `GuidedFormTest::test_the_wizard_has_a_map` — The wizard has a map.
- `GuidedFormTest::test_voice_moved_onto_the_form_rather_than_leaving_with_the_chat` — Voice had to survive the chat, because the person it exists for is now on the form. — *the microphone left with the chat*
- `QuestionnaireTest::test_an_unknown_token_says_nothing_about_whether_it_exists` — An unknown token says nothing about whether it exists.
- `QuestionnaireTest::test_the_page_is_not_indexable` — The page is not indexable.
- `QuestionnaireTest::test_the_page_opens_on_the_brief_and_not_on_a_question` — And before any of that, the page says what is expected of them. — *the questions were shown before anybody had been told what this is*
- `QuestionnaireTest::test_the_page_shows_the_questions_and_says_nothing_costs_money` — The brief now stands in front of the questions, so this walks past it first. — *the impact question*
- `GuidedFormTest::test_the_conversation_is_no_longer_read_through_a_letterbox` — The conversation is no longer read through a letterbox.
- `GuidedFormTest::test_there_are_no_emoji_left_on_the_page` — an emoji survived: {$glyph}
- `GuidedFormTest::test_the_page_offers_no_chat_mode` — The page offers no chat mode.
- `GuidedFormTest::test_the_page_no_longer_posts_to_the_chat_endpoint` — The page no longer posts to the chat endpoint.
- `GuidedFormTest::test_no_binding_references_state_that_left_with_the_chat` — No Alpine binding may reference state that left with the chat.
- `SecurityHeadersTest::test_no_capability_the_site_actually_uses_is_denied_by_the_header` **(guard kept, edited)** — The microphone positive control came from my-work dictation; now proven on planted constraints (audio:true -> microphone; audio:false -> nothing). The rebuilt my-work page must restore assertArrayHasKey('microphone', $found).
