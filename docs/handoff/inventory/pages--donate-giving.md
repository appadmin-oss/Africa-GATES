# `templates/pages/donate-giving.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /giving/manage/{token:[a-f0-9]{32}} → DonationController::giving`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
 The unhappy path, said plainly. A 404 that reads as a fault sends somebody who is
       trying to stop paying us to their bank instead.
```

**Headings:** This link is not valid · {% if stopped %}This gift is stopped{% else %}Your monthly gift{% endif %}

**Data read (top-level variables/functions):** `sub`, `stopped`, `flash_ok`, `flash_err`, `token`

**Forms:**
- `POST /giving/manage/{{ token }}/stop` [data-confirm] fields: _token[hidden]; buttons: Stop this monthly gift

**States / branches (9 distinct conditions):** `not sub` · `stopped` · `flash_ok` · `flash_err` · `sub.status == 'failed'` · `sub.status == 'pending'` · `sub.charges > 0` · `sub.next_charge_at and not stopped` · `not stopped`

**Links out:** `/support`

**JS behaviours:** data hooks: `data-confirm`

**Accessibility affordances:** roles: status, alert

**Styling carried:** 1 <style> block(s), 0 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- none found by path or route
