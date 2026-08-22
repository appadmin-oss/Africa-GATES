<?php
declare(strict_types=1);

namespace Tests\Unit;

/**
 * The same twelve inbox properties, applied to the editable campaign's skeleton.
 *
 * `templates/emails/campaign.twig` was built from `final-hours.twig` precisely so that
 * everything {@see EmailInboxCompatTest} proves about the original keeps holding — the
 * fluid-hybrid wrapper, the MSO conditionals, the VML button, the styled alt text, the
 * presentation roles, the hidden preheader, no data: URIs, no CSP nonce.
 *
 * Inheriting the assertions rather than copying them is the point. `HANDOFF.md` §6 warns
 * that an editor is the fastest way to break these; two copies of the rules would be the
 * second fastest.
 *
 * The content half — what an EDIT can break, which no template test can see — is held by
 * {@see \AfricaGates\Services\EmailInboxGuard} at save time and by
 * {@see EmailCampaignTest}.
 */
final class CampaignInboxCompatTest extends EmailInboxCompatTest
{
    protected static function tpl(): string
    {
        return __DIR__ . '/../../templates/emails/campaign.twig';
    }
}
