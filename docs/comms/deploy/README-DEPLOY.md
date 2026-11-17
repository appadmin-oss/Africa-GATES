# Africa GATES: update of 17 Nov 2026

Unzip at the site root (where `src/`, `templates/` and `public/` are) and overwrite.
Built on the branch `claude/ai-assistance-judges-features-1ka4oz` (commit d07cd725).
If the live site is on an older version, these files may need code they don't include.
Check before overwriting.

## After uploading
1. Run the database migration (needed for the voting extension). Either:
   - `php bin/console db:migrate`, or
   - with no shell: set `SETUP_TOKEN` in `.env`, open `/__setup/migrate?token=…` once,
     then delete the token.
2. Admin → Settings → Public site: set the announcement and tick
   "Also show it as a pop-up card". The copy is in `docs/comms/2026-11-voting-extension.md`.
3. Admin → Settings → "Publishing results": stays on **Manual** unless you choose
   Automatic.
4. Admin → Programmes → the award's cycle → **Extend voting**: record the extension you
   already made. Leave "new close" as the current close and set "previous close" to the
   old date, then write the public reason. Untick "Stop paid votes at the previous close"
   unless you want paid voting to stop.

## What's in it
- Website announcement pop-up card (image or GIF, title, text, button).
- Results publish manually by default, with an automatic "result is late" notice.
- Voting extension: admin action, public notice, optional paid-vote cutoff, sealed
  standings snapshot, written policy (`docs/VOTING-EXTENSION-POLICY.md`).
- Bulk mail (Admin → Campaigns): new professional design, general-purpose starter with
  no countdown, kicker line, typed https:// button links, and the `{full_name}`,
  `{award_name}` and `{edition_name}` placeholders.

## Test status (please read)
The automated test suite could NOT be run in the build environment, because its
dependencies were missing. Every PHP file passes a syntax check, the templates were
parsed, and the email and popup were rendered and screenshotted. The voting-extension
feature changes paid-vote checkout and has had no test run at all. Try it on a staging
copy first if you can, or upload everything except the extension files listed below.

To deploy WITHOUT the voting-extension feature, leave out:
database/migrations/2027_06_03_voting_extensions.php, src/Services/VotingExtension.php,
templates/partials/voting-extension.twig, src/Admin/Controllers/ProgrammesController.php,
templates/admin/programmes/cycle.twig, src/Controllers/PaidVoteController.php,
src/Controllers/ResultsController.php, src/Controllers/VoteController.php,
src/Services/AwardsFront.php, src/Services/PaidVoteService.php,
src/Services/SnapshotService.php, src/Services/VoteFront.php, src/Services/WalletVotes.php,
src/routes.php, and the changed pages: templates/pages/vote.twig, vote-nominee.twig,
vote-program.twig, awards/programme.twig, results/open.twig
