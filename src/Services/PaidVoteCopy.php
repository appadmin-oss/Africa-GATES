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
            return Translator::t('Under this award’s rules the tally is the whole community half, so a contributed vote adds to it at full weight. The split between free and contributed votes is published with the result.');
        }

        $people = (int) round(CpiService::REACH_PEOPLE_SHARE * 100);
        return Translator::t('A contributed vote adds to the tally, which is %tally%% of the community half. It does not add to the number of people backing a nominee, which is the other %people%%: that counts each person once, however much they give.', [
            '%tally%'  => (string) (100 - $people),
            '%people%' => (string) $people,
        ]);
    }
}
