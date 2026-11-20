# Image hosting: Cloudinary, and the bulk migration

Everything here is **optional**. With no credentials set, uploads land under
`public/uploads/` exactly as they always have and nothing degrades. This document
covers what you gain by turning it on, how to move the images already on disk, and the
handful of decisions that are worth knowing about before you do.

---

## 1. Why

Three things, in order of how much they matter to this platform.

**The flier frames the nominee's face.** The shareable flier's photo panel is 1080×820 —
a landscape box — and a submitted portrait is almost always taller than it is wide with
the face in the upper third. Filling that box means cropping, and both renderers cropped
from the centre, which on a waist-up photo lands on the subject's chest. The face is the
entire persuasive content of a share graphic: a flier without one still renders, still
downloads, still previews in WhatsApp, and is worthless. Nobody reports it, because it
does not look broken.

GD cannot fix this — it has no face detection, and this deploys to shared hosting where
adding OpenCV is not on the table. Cloudinary's `g_faces:auto` gravity does exactly this
job: crop anchored on the detected face, falling back to saliency detection when it finds
none. That is the single reason this integration exists.

**Page weight.** `f_auto` serves AVIF or WebP to browsers that advertise support, `q_auto`
picks a quality per image, `dpr_auto` sends a 2× asset only to screens that can use one.
On a mobile-first Nigerian audience paying per megabyte, that is the largest single
performance win available in this codebase.

**Disk.** Uploads stop consuming your hosting quota.

## 2. Turning it on

Add to `.env` (or supply as real environment variables — `Support\Env` reads both):

```
CLOUDINARY_URL=cloudinary://<api_key>:<api_secret>@<cloud_name>
CLOUDINARY_FOLDER=africa-gates
```

The URL is what the Cloudinary dashboard hands you under **Programmable Media → API
keys**. If your API secret contains a character that breaks the URL form, use the three
discrete names instead — `CLOUDINARY_CLOUD_NAME`, `CLOUDINARY_API_KEY`,
`CLOUDINARY_API_SECRET`.

Verify with `bin/console app:doctor` (the `media` section) or by opening **Admin →
Media**, which shows `Connected` and the cloud/folder in use. `app:doctor` is worth
running: absent or misspelled credentials break nothing at all — uploads keep landing on
local disk — so there is otherwise no signal that you did not turn it on.

From that moment, **new** uploads go to Cloudinary. Existing images do not move until you
run the sweep.

## 3. Moving the images already on disk

### With shell access

```bash
bin/console media:cloudinary --dry-run    # prints every file it would upload; changes nothing
bin/console media:cloudinary              # does it
bin/console media:cloudinary --status     # what is left
```

Useful flags: `--limit=N` to stop after N rows, `--table=gates_nominees` to sweep one
table (nominee photos first is a reasonable order — they are the images that matter).

### Without shell access

**Admin → Media** has a Cloudinary panel with the same two actions behind buttons. It
works in bounded batches and the page continues itself until nothing is pending, which is
the same pattern `/__setup/migrate` uses and for the same reason: a request that loops for
minutes gets killed by `max_execution_time` with the work half-finished and no report.

### What the sweep touches

Fourteen columns across fourteen table/column pairs, declared in
`MediaMigrationService::TARGETS` — nominee photos, nomination photos, profile
avatars/covers/galleries, judge and admin avatars, programme covers, legacy event
covers/galleries, shop covers, event covers, post covers, and the media library's own
index. It uploads the file, then rewrites the referencing column to the delivery URL, so
the site starts serving from the CDN with no template change and no redeploy.

`gates_posts.audio_path` is deliberately **not** in that list. It holds an MP3, and
Cloudinary's `image/upload` endpoint would reject it — a `%_path` heuristic would have
swept it in by accident.

## 4. The four properties you can rely on

**It never deletes a local file.** After the sweep the database points at Cloudinary and
every original is still exactly where it was. That is the only version of this that an
operator can undo. `gates_uploads.local_path` retains the original path for the same
reason.

**It is safe to re-run.** `gates_media_migrations.source_path` is UNIQUE and public ids
are derived from the source path (`Support\MediaPublicId`), so a file already swept is
reused rather than re-uploaded, and even a genuine re-upload overwrites one asset instead
of creating a second. "Run it again, I'm not sure it finished" is correct advice, not a
way to triple your Cloudinary bill. One file referenced by several rows is uploaded once
and all those rows point at it.

**It is resumable.** Work is bounded per batch and committed per row, so a request killed
mid-sweep loses at most the row in flight.

**It is honest about failure.** A file the database references but which is not on disk
(a restored database, a pruned uploads directory) is recorded as `missing` and **left
alone** — that image was already broken before any migration, and rewriting it to a CDN
URL that also 404s would turn a broken image into an undiagnosable one. A rejected upload
is recorded as `failed` with Cloudinary's reason and the row is not rewritten. Both show
up in `app:doctor` and in the admin panel. Query the detail:

```sql
SELECT source_path, status, error FROM gates_media_migrations WHERE status <> 'migrated';
```

A `failed` row can be retried simply by running the sweep again — the ledger is upserted,
so a failure never becomes a permanent verdict.

## 5. How delivery URLs are built

One place: `Support\Media::url($path, $preset)`, exposed to templates as the `media_url`
Twig filter.

| preset | transformation | used for |
| --- | --- | --- |
| `avatar` | `c_fill,g_faces:auto,w_128,h_128` | small round/square avatars |
| `thumb` | `c_fill,g_faces:auto,w_320,h_320` | card thumbnails, leaderboard rows |
| `portrait` | `c_fill,g_faces:auto,w_800,h_1000` | the big portrait on a nominee/profile page |
| `cover` | `c_fill,g_auto,w_1200,h_675` | shop items, blog heroes, event banners |
| `wide` | `c_limit,w_1600` | anything whose aspect must survive |
| `flier` | `c_fill,g_faces:auto,w_1080,h_820,f_jpg` | the flier's photo panel |
| `og` | `c_fill,g_faces:auto,w_1200,h_630,f_jpg` | an `og:image` built from a raw photo |

A preset name rather than a transformation string at each of forty call sites, because
otherwise the rule that frames a nominee's face is decided by whoever last edited a
partial.

Two details that are load-bearing:

- **`media_url` is safe on a local path**, which it returns untouched. That is what lets
  templates call it unconditionally while a migration is only partly through — and a
  database will hold Cloudinary URLs and `/uploads/...` paths side by side for as long as
  that takes, plus some rows (a hand-typed cover image URL on someone else's host) that
  will never be Cloudinary at all.
- **`flier` pins `f_jpg`, not `f_auto`.** That derivative is fetched server-side by GD and
  by social crawlers, neither of which sends an `Accept` header for `f_auto` to negotiate
  against. Its geometry is pinned to `FlierService::W` / `FlierService::PHOTO_H` by a
  test, because a preset that disagreed with the panel would hand GD a differently-shaped
  image and silently reintroduce the centre crop.

## 6. What happens to local-only photos

They still work, and they are cropped better than before: `FlierService`'s local path now
anchors the crop 22% down instead of at the centre (`PHOTO_ANCHOR_Y`), because
photographic composition puts the eyes near the upper-third line. That is a heuristic and
it is not face detection — which is the strongest practical argument for running the
sweep.

## 7. Security notes

- Uploads are sanitised **before** they leave this server. `UploadService` writes locally
  first — finfo MIME sniff from the bytes, re-encode, dimension checks — and only then
  hands the cleaned file to Cloudinary. The local write is the first step of both paths,
  not a fallback.
- An upload whose Cloudinary leg fails still returns a usable local path, so a nomination
  form never rejects a supporter's photo because of a CDN outage or an expired key. The
  row is marked `provider = 'local'` and the sweep picks it up later.
- **PDFs are never sent to Cloudinary.** A PDF here is nomination evidence or a judge
  dossier — private moderation material streamed through the admin-gated
  `/admin/media/{id}/view` route. A public CDN URL would make an unlisted guess the only
  thing between it and the internet, for no benefit.
- The sweep refuses any stored path that is not inside `uploads/`, contains `..`, or is
  already absolute. `gates_nominations.nominee_photo_path` is written by the **public**
  nomination form, and this code turns a stored string into a filesystem read followed by
  a permanent public URL — a crafted `../../.env` would otherwise be published. Pinned by
  test.
- Deleting an item in Admin → Media removes **both** copies — the Cloudinary asset (by
  `public_id`) and the local original. Removing only the one that `path` happens to name
  would leave an asset publicly reachable and billed after an admin was told it was gone.
- `Csp` needs no change: `img-src` already allows `https:`, deliberately, because nominee
  photos and partner logos legitimately come from arbitrary hosts.

## 8. Schema

`database/migrations/2026_07_30_cloudinary_media.php`, and the same objects in
`admin-schema.sql` / `sqlite-admin-schema.sql` for fresh installs.

- `gates_uploads.provider` — `local` | `cloudinary`. Not redundant with `path`: it is how
  the sweep finds rows whose Cloudinary leg failed at upload time, and how the delete
  knows what to call.
- `gates_uploads.public_id` — the handle needed to delete a Cloudinary asset.
- `gates_uploads.local_path` — the original on-disk path, retained after migration.
- `gates_media_migrations` — the sweep ledger. UNIQUE on `source_path`.

## 9. The link-preview card

`FlierService::ogCard()` renders a **1200×630** graphic at
`/vote/{programme}/{id}-{name}/card.png`, and that — not the flier — is the `og:image` on
both the ballot and the flier page.

The flier is 4:5. Facebook and LinkedIn crop an `og:image` to 1.91:1 and WhatsApp to
roughly square, so the flier's bottom third was cut off in every preview — and the bottom
third is the vote URL, the rally copy and the jury footnote. The card is a horizontal
split instead: the face in a 480px column (Cloudinary's `og_photo` preset, face-anchored),
everything else in the 720px beside it. Nothing is cropped away, because the aspect ratio
is already the one the platforms want.

It is designed for **~380px**, which is roughly how wide a preview renders in a WhatsApp
thread. Only three elements are sized to survive that: the name, the gold rank chip and
the standing line. Category, URL and the footnote are deliberately secondary, and momentum
is omitted — a fourth number at 8 effective pixels costs the other three their clarity.

The flier is unchanged and remains what a nominee downloads and posts.

Three geometry bugs were found by rendering it and looking, not by reading the code, and
each is now pinned by a test in `OgCardTest`:

- the name collided with the kicker, because the size ladder was copied from the flier's
  952px column into a 608px one — sizes are now measured (`fitLines`);
- a surname wider than the column ran off the card, because `wrapMeasured` places an
  over-wide word rather than break it, and its output was trusted without checking widths;
- a name with stacked Yoruba diacritics grew upward toward the kicker, because the first
  baseline came from the point size rather than the glyphs' measured ink extent
  (`ascent()`). Measured: 84px vs 79px for `Ọlásùnkànmí` against `Olasunkanmi` at 76pt.

The last two also existed latently in the flier's own renderer and are fixed there too.

## 10. Rolling back

There is no automated rollback, because there has not needed to be one: the local files
are all still present, and `gates_media_migrations` records `source_path` → `remote_url`
for every rewrite. Reversing a column is a join against that table. If you want the
platform back on local storage, unset the credentials (new uploads go local again) and
restore the paths from the ledger.
