<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\NationsLive;
use AfricaGates\Support\SchemaHas;
use AfricaGates\Support\Translator;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * `/registry/{slug}` — A PUBLIC PROFILE, owner and visitor (Phase 6 · ProfilePage.dc.html · §8.8).
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IS ON THE PAGE, AND WHAT IS NOT BECAUSE NOTHING RECORDS IT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *  · RECOGNITION — {@see Recognitions::forProfile()}, issued by verified issuers only. A
 *    withdrawn one stays on the page under "withdrawn", with its reason, linked to the
 *    public log: a record that silently disappears is history being edited. Beside them,
 *    the profile's nominations in awards still running ("Nominated", "Shortlisted") — facts,
 *    and plainly not recognitions.
 *  · EVIDENCE — the reviewed dossier of every nominee row this profile stood as. LOCKED:
 *    there is no edit or delete path for it on this page or behind it (PHASE-6 "Done when").
 *    A visitor sees what kinds were reviewed and how many, never what they said — the
 *    dossier is the panel's, and no column says a row may be published (the nominee page's
 *    rule, PHASE-5 B-3). The OWNER sees their own items by title, each "Reviewed" or
 *    "Awaiting review".
 *  · TRUST — only facts this platform holds: that the profile passed review, its
 *    verification tier, how often it was recognised, where it was nominated, how much of
 *    its evidence a person reviewed. The DC's "checked against a government ID" and "vouched
 *    for by 9 people" have no record behind them here (Discover's own finding, PHASE-4), so
 *    neither is drawn.
 *  · THE INDEX — the score with the basis it was computed on, the inventory's MUST RESTORE
 *    rule (ProfileCpiClaimTest): a judged profile is described as judged, a pending one as
 *    waiting on a panel, an unnominated one as profile strength and never credited to a jury.
 */
final class ProfilePage
{
    /** Evidence kind → the word a reader sees. The nominee page's words, so the two agree. */
    public const EVIDENCE_WORDS = [
        'nomination' => 'nomination', 'interview' => 'interview', 'document' => 'document',
        'link' => 'link', 'media' => 'recording', 'award' => 'award', 'press' => 'press article', 'note' => 'note',
    ];

    /** Who holds this profile: the one answer for the page, the edit form and its POST. */
    public static function owns(int $userId, int $profileId): bool
    {
        if ($userId < 1 || $profileId < 1) return false;
        try {
            // A verified member account on the profile's own address.
            $u = DB::table('gates_users')->where('id', $userId)->first(['email', 'email_verified', 'status']);
            if (!$u || (string) ($u->status ?? 'active') !== 'active') return false;
            $p = DB::table('gates_profiles')->where('id', $profileId)->first(['email']);
            if ($p && (int) $u->email_verified === 1
                && strtolower(trim((string) $u->email)) !== ''
                && strtolower(trim((string) $u->email)) === strtolower(trim((string) $p->email))) {
                return true;
            }
            // Or an ACTIVE claim on a nominee row that stands as this profile — the claim
            // service's own word for "they hold the page".
            if (SchemaHas::table('gates_nominee_claims')) {
                return DB::table('gates_nominee_claims as c')
                    ->join('gates_nominees as n', 'n.id', '=', 'c.nominee_id')
                    ->where('c.user_id', $userId)
                    ->where('c.status', NomineeClaimService::ST_ACTIVE)
                    ->where('n.profile_id', $profileId)
                    ->exists();
            }
        } catch (\Throwable) {
        }
        return false;
    }

    /** @param array<string,mixed> $p a profile from ProfileService::getBySlug() */
    public static function build(array $p, int $viewerId = 0): array
    {
        $pid   = (int) $p['id'];
        $owner = self::owns($viewerId, $pid);
        $noms  = self::nominees($pid);
        $rec   = Recognitions::forProfile($pid);

        $nominated = [];
        $shortlisted = self::shortlistedIds(array_keys($noms));
        foreach ($noms as $id => $n) {
            if (in_array((string) $n->cycle_status, PublicResults::RELEASED, true)) continue;
            if (!in_array((string) $n->status, ['approved', 'winner', 'runner_up'], true)) continue;
            $nominated[] = [
                'year'   => (int) $n->year,
                'title'  => (string) $n->category,
                'award'  => (string) $n->programme,
                'status' => isset($shortlisted[$id]) ? 'Shortlisted' : 'Nominated',
                'url'    => \AfricaGates\Support\NomineeUrl::path((int) $id),
            ];
        }

        $evidence = self::evidence(array_keys($noms), $owner);
        $wins = $runner = $final = 0; $editions = [];
        foreach ($rec['active'] as $r) {
            if ($r['standing'] === Recognitions::WINNER) $wins++;
            elseif ($r['standing'] === Recognitions::RUNNER_UP) $runner++;
            else $final++;
            $editions[$r['award']['programme'] . $r['award']['edition']] = true;
        }
        $categories = count($noms);
        $awards = count(array_unique(array_map(static fn ($n) => (int) $n->programme_id, $noms)));

        $trust = [['title' => Translator::t('Profile reviewed'), 'detail' => Translator::t('Approved by the Africa GATES team before it could be listed or ranked')]];
        if (in_array((string) ($p['verification_tier'] ?? 'none'), ['verified', 'premium'], true)) {
            $trust[] = ['title' => Translator::t('Verified profile'), 'detail' => Translator::t('Verification tier: %t%', ['%t%' => Translator::t((string) $p['verification_tier'])])];
        }
        if ($rec['active'] !== []) {
            $parts = array_filter([
                $wins ? Translator::t($wins === 1 ? '1 win' : '%n% wins', ['%n%' => (string) $wins]) : '',
                $runner ? Translator::t($runner === 1 ? '1 runner-up' : '%n% runner-up places', ['%n%' => (string) $runner]) : '',
                $final ? Translator::t($final === 1 ? '1 finalist place' : '%n% finalist places', ['%n%' => (string) $final]) : '',
            ]);
            $trust[] = [
                'title'  => count($rec['active']) === 1 ? Translator::t('Recognised once') : Translator::t('Recognised %n% times', ['%n%' => (string) count($rec['active'])]),
                'detail' => implode(', ', $parts) . ', ' . (count($editions) === 1 ? Translator::t('in 1 edition') : Translator::t('across %n% editions', ['%n%' => (string) count($editions)])),
            ];
        }
        if ($categories > 0) {
            $trust[] = [
                'title'  => $categories === 1 ? Translator::t('Nominated in 1 category') : Translator::t('Nominated in %n% categories', ['%n%' => (string) $categories]),
                'detail' => $awards === 1 ? Translator::t('In 1 award') : Translator::t('Across %n% awards', ['%n%' => (string) $awards]),
            ];
        }
        if ($evidence['reviewed'] > 0) {
            $trust[] = [
                'title'  => $evidence['reviewed'] === 1 ? Translator::t('1 piece of reviewed evidence') : Translator::t('%n% pieces of reviewed evidence', ['%n%' => (string) $evidence['reviewed']]),
                'detail' => Translator::t('Kept exactly as reviewed, never edited'),
            ];
        }

        $headline = null;
        foreach ($rec['active'] as $r) {
            if ($r['standing'] === Recognitions::WINNER) {
                $headline = Translator::t('Winner · %c% %y%', ['%c%' => $r['award']['category'], '%y%' => (string) $r['award']['year']]);
                break;
            }
        }

        $cc = strtoupper((string) ($p['country_code'] ?? ''));
        $place = implode(', ', array_filter([(string) ($p['location_city'] ?? ''), $cc !== '' ? NationsLive::name($cc) : '']));

        $weights = (new RuleEngine())->weights();

        return [
            'owner'       => $owner,
            'role'        => implode(' · ', array_filter([(string) ($p['category'] ?? ''), $place])),
            'country'     => $cc !== '' ? NationsLive::name($cc) : '',
            'headline'    => $headline,
            'recognition' => $rec,
            'nominated'   => $nominated,
            'evidence'    => $evidence,
            'trust'       => $trust,
            'counts'      => ['nominations' => $categories, 'recognitions' => count($rec['active'])],
            'cpi'         => [
                'score'  => (int) ($p['cpi_score'] ?? 0),
                'tier'   => (string) ($p['cpi_tier'] ?? 'unranked'),
                'tier_name' => ucfirst((string) ($p['cpi_tier'] ?? 'unranked')),
                'basis'  => (string) ($p['cpi_basis'] ?? 'baseline'),
                'weights'=> ['community' => (int) round($weights['community'] * 100), 'judge' => (int) round($weights['judge'] * 100)],
            ],
            'following'   => $viewerId > 0 && SchemaHas::table('gates_follows')
                && DB::table('gates_follows')->where('user_id', $viewerId)->where('target_type', 'profile')->where('target_id', $pid)->exists(),
        ];
    }

    /** @return array<int,object> nominee rows standing as this profile, live programmes only */
    private static function nominees(int $profileId): array
    {
        try {
            $q = DB::table('gates_nominees as n')
                ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->join('gates_award_cycles as y', 'y.id', '=', 'c.cycle_id')
                ->join('gates_award_programmes as p', 'p.id', '=', 'y.programme_id')
                ->where('n.profile_id', $profileId)->where('p.is_active', 1)->whereNull('n.merged_into')
                ->orderByDesc('y.year')->orderBy('n.id');
            return $q->get(['n.id', 'n.status', 'c.title as category', 'y.year', 'y.status as cycle_status',
                            'p.id as programme_id', 'p.title as programme'])->keyBy('id')->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param list<int> $ids @return array<int,true> */
    private static function shortlistedIds(array $ids): array
    {
        if ($ids === [] || !SchemaHas::table('gates_shortlist_entries')) return [];
        try {
            return array_fill_keys(array_map('intval', DB::table('gates_shortlist_entries as e')
                ->join('gates_shortlists as s', 's.id', '=', 'e.shortlist_id')
                ->where('s.status', 'published')->whereIn('e.nominee_id', $ids)
                ->pluck('e.nominee_id')->all()), true);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The locked record. Visitors: reviewed counts by kind. Owner: their items by title.
     *
     * @param list<int> $nomineeIds
     */
    private static function evidence(array $nomineeIds, bool $owner): array
    {
        $out = ['reviewed' => 0, 'kinds' => [], 'items' => []];
        if ($nomineeIds === [] || !SchemaHas::table('gates_nominee_evidence')) return $out;
        try {
            foreach (DB::table('gates_nominee_evidence')->whereIn('nominee_id', $nomineeIds)->where('verified', 1)
                         ->selectRaw('kind, COUNT(*) AS n')->groupBy('kind')->get() as $e) {
                $out['kinds'][(string) $e->kind] = (int) $e->n;
                $out['reviewed'] += (int) $e->n;
            }
            if ($owner) {
                foreach (DB::table('gates_nominee_evidence')->whereIn('nominee_id', $nomineeIds)
                             ->orderByDesc('verified')->orderByDesc('created_at')->orderByDesc('id')->limit(50)
                             ->get(['kind', 'title', 'provenance', 'verified', 'verified_at', 'created_at']) as $e) {
                    $out['items'][] = [
                        'kind'     => (string) $e->kind,
                        'word'     => ucfirst(self::EVIDENCE_WORDS[(string) $e->kind] ?? 'item'),
                        'title'    => (string) $e->title,
                        'by'       => (string) $e->provenance,
                        'added'    => $e->created_at ? (string) $e->created_at : null,
                        'reviewed' => (int) $e->verified === 1,
                        'reviewed_at' => $e->verified_at ? (string) $e->verified_at : null,
                    ];
                }
            }
        } catch (\Throwable) {
        }
        return $out;
    }
}
