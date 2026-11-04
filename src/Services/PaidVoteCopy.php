<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Translator;

/**
 * WHAT A CONTRIBUTED VOTE DOES — one sentence, read from the rule that decides it.
 *
 * GAPS Q10 is open: the handoff's ballot says "each contributed vote counts the same as a
 * free one toward {first}'s Cultural Power Index", which is half true and reads as the
 * whole truth. Until the owner answers, the real rule is what every Phase 5 surface says
 * (the ballot, the vote page's About tab, the award's details): under the `ideal` and
 * `reach` bases a contributed vote adds to the TALLY — the smaller share of the community
 * half — and never to the count of PEOPLE backing a nominee, the larger share
 * (CLAUDE.md, "Money buys the tally, never the reach"). The shares are read from
 * `CpiService::REACH_PEOPLE_SHARE`, never typed.
 *
 * Under the legacy `relative`/`absolute` bases there is no people term at all, so a
 * contributed vote adds to the whole community half, and the sentence says that instead — a
 * line about a 70% that the programme's own rules do not compute would be the
 * TallyOnlyCommunityHalfTest fault on a ballot.
 *
 * One place, so the ballot and the award page cannot come to disagree about money, which
 * is the one disagreement this platform has already been publicly wrong about
 * (MoneyClaimSweepTest).
 */
final class PaidVoteCopy
{
    public static function sentence(?int $programmeId = null, ?int $cycleId = null): string
    {
        $basis = CpiService::basis((new RuleEngine())->effective($programmeId, $cycleId)['community_basis'] ?? null);

        if (!in_array($basis, [CpiService::BASIS_IDEAL, CpiService::BASIS_REACH], true)) {
            return Translator::t('Under this award’s rules a contributed vote counts in full toward the community half.');
        }

        $people = (int) round(CpiService::REACH_PEOPLE_SHARE * 100);
        // Short on purpose (owner, 27 Oct 2026: "remove all unnecessary descriptions"): the two
        // facts a buyer needs, the shares read from the rule, and the long form one link away.
        return Translator::t('Adds to the tally (%tally%% of the community half), not to the supporter count (%people%%), which counts each person once.', [
            '%tally%'  => (string) (100 - $people),
            '%people%' => (string) $people,
        ]);
    }
}
