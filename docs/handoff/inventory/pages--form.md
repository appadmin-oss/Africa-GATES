# `templates/pages/form.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /f/{key} → FormController::show`; `POST /f/{key} → FormController::submit`
**Extends:** layout/gates.twig · **Includes/imports:** —

**Headings:** {{ form.title }}

**Data read (top-level variables/functions):** `req`, `form`, `error`, `you`, `your`, `response`, `has`, `been`, `recorded`, `values`, `done`, `vis`

**Forms:**
- `POST /f/{{ form.form_key }}` [enctype,x-data] fields: _token[hidden], {{ f.name }}[{{ f.type }} required,maxlength,pattern], {{ f.name }}[textarea required,maxlength], {{ f.name }}[select required], {{ f.name }}[radio required], {{ f.name }}[][checkbox], {{ f.name }}[checkbox required], {{ f.name }}[file required]; buttons: Submit

**States / branches (18 distinct conditions):** `done` · `form.description` · `error` · `f.showIfField` · `f.type != 'checkbox' or f.options` · `f.type in ['text','email','tel','number','date','textarea','select','file']` · `req` · `f.type in ['text','email','tel','number','date']` · `f.placeholder` · `f.maxlength` · `f.pattern` · `f.type == 'textarea'` · `f.type == 'select'` · `f.type == 'radio'` · `f.type == 'checkbox'` · `f.options` · `f.type == 'file'` · `f.help`

**Links out:** `/`

**JS behaviours:** 1 inline <script> block(s); Alpine x-data: `formApp({{ values|default({})|json_encode|e('html_attr') }})`

**Accessibility affordances:** aria-hidden×1; roles: alert; <label for>×1

**Styling carried:** 1 <style> block(s), 1 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `ColourBudgetTest::test_no_page_spends_more_colour_than_its_tier_allows` — (no docblock)
