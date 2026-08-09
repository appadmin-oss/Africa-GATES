# Deploying this change set

Branch: `claude/codebase-audit-structure-cja4h8` · base: `d96228f`

Two archives are attached. Use **one** of them.

| Archive | Use it when |
|---|---|
| `africa-gates-changed-files.zip` (231 KB, 29 files) | Your live tree already matches `d96228f`. Extract over the app root; it only contains files that changed. Fastest and least disruptive. |
| `africa-gates-app.zip` (27 MB) | You are not sure what state the live tree is in, or you would rather replace the application wholesale. Extract over the app root. |

Neither archive contains `.env`, `vendor/`, the local SQLite database, logs, or git
history — so your production secrets and dependencies are untouched.

If you deploy from git instead, `git pull` the branch and skip to step 2.

---

## 1 · Extract

Extract **at the application root** — the directory that contains `public/`,
`src/`, `composer.json`. Not inside `public/`.

> If you use cPanel File Manager, check afterwards that
> `public/uploads/.htaccess` still exists. File Manager hides dotfiles by
> default, and that file is what stops anything under `/uploads/` executing.

## 2 · Nothing to install, nothing to migrate

Deliberately: this change set adds **no dependencies** and **no migrations**.

- `composer.json` / `composer.lock` — unchanged. Do **not** re-run `composer install`.
- `database/migrations/` — no new files. `db:migrate` has nothing to do.

## 3 · Install the terms and privacy policy

This is the one required step. The documents are new content for
`gates_legal_docs`, which is empty or holding older copy on your side.

```bash
php bin/console legal:seed
```

It **skips** any document that already exists, and prints what it skipped. To
replace what is there:

```bash
php bin/console legal:seed --force
```

After this, edit both documents in **Admin → Legal**. The command will not touch
them again without `--force`, so your edits are safe.

**No shell?** The documents can be pasted into Admin → Legal by hand instead —
ask and I will send the HTML for each.

> ⚠️ **This wording has not been through counsel.** It is an accurate,
> plain-language description of what the platform actually does, written from the
> code. The NDPA specifics, the consumer-protection wording and the
> limitation-of-liability clause in particular want a lawyer's eye before you
> rely on it.

## 4 · Set the community return to 50%, if it is not already

An override row in `gates_rule_sets` **beats** the code default.

- If nobody has ever saved the Community return card in admin, the new default
  (5000 bps = 50%) applies and there is nothing to do.
- If somebody has, that row still says whatever it said. Go to
  **Admin → Settings → Community return** and set *Share of each contribution*
  to `50`, then save.

**How to check without guessing:** load `/integrity`. Section 06 publishes the
figure that is actually in force. If it reads 50%, you are done.

## 5 · Optional housekeeping

```bash
php bin/console assets:build      # not required — see below
```

The new stylesheet (`public/assets/css/components/article.css`) is loaded per-page
by the four document pages and is deliberately **outside** the global CSS bundle,
so the bundle does not need rebuilding. Running `assets:build` anyway is harmless.

If you have `TWIG_CACHE=true`, compiled templates recompile on their own
(`auto_reload` is on). If anything looks stale, clear it:

```bash
rm -rf var/cache/twig/*
```

---

## What to check once it is live

| URL | Expect |
|---|---|
| `/philosophy` | The full essay, 16 sections, Copy · Download · Cite in the header |
| `/philosophy.txt` and `/philosophy.md` | Download as files, not render in the browser |
| `/integrity` | Opens with "Why this exists", then 10 method sections. **Cite only** — no Copy/Download, on purpose |
| `/integrity.txt` | Redirects (301) to `/philosophy.txt` |
| `/terms` and `/privacy` | The new content in the article design, with a contents rail |
| `/privacy` | Contains one "Automated processing (AI)" section, generated from your live AI configuration |
| `/privacy.txt` | Contains that same AI section |
| `/nominate` | A link to the philosophy under the three orientation steps |
| `/vote` | Quotes the live community share, and links the philosophy |

## Rollback

Nothing here is destructive except `legal:seed --force`, which overwrites the two
legal documents. Everything else is templates, CSS, new PHP classes and additive
route registrations — reverting the files restores previous behaviour with no
database change needed.

`gates_rule_sets` is the only table this touches, and only if you change the
community-return setting in admin.
