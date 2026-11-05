<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Csp;

/**
 * EVERY OUTSIDE SERVICE THIS PLATFORM CAN HAND SOMEBODY'S DATA TO — DECLARED ONCE, HELD TO THE CODE.
 *
 * Owner, 5 Nov 2026: "for some tools or information that are being used that requires us to
 * declare them please do". The privacy policy already generated two of these declarations —
 * the AI models (AiPrivacy) and speech for a nominee's spoken answers (voiceHtml) — and the
 * cookie list is generated from CookieRegistry. Nothing declared the rest: the payment
 * gateways a donor types a card into, the SMS and email services that carry a vote code, the
 * image host every profile photo is served from, Google's calendar for an interview, the
 * bot check on a ballot, the speech service that says a guest's NAME aloud at an event door.
 * Each of those receives personal data, and the NDPA 2023 and the GDPR both expect a reader
 * to be told who the recipients are.
 *
 * ── WHY IT IS A REGISTRY AND NOT A PARAGRAPH ─────────────────────────────────
 *
 * This codebase has paid four times for prose that outlived the code (CLAUDE.md, the cookie
 * policy's "we set one cookie"). So this list is rendered from data on every view of
 * /privacy, and `ThirdPartiesTest` holds it to the code in the two directions that matter:
 *
 *   · every provider the platform can call ({@see ProviderProbe::catalogue()}, the list the
 *     admin's "ask every provider" page runs) is declared here — a new integration cannot
 *     ship without a sentence about what it receives;
 *   · every outside origin the browser is ALLOWED to reach ({@see Csp}) is declared here or
 *     listed in {@see ALLOWED_UNUSED} with a reason — and an unused one is checked to still
 *     be unused, so that list cannot become a quiet exemption.
 *
 * What it does not claim: where each provider stores data, or for how long. That is set by
 * each provider's own terms, the policy says so, and nothing here guesses.
 */
final class ThirdParties
{
    /**
     * @var list<array{id:string, name:string, for:string, gets:string, when:string,
     *                 probes:list<string>, hosts:list<string>, see?:string}>
     */
    private const SERVICES = [
        [
            'id' => 'payments', 'name' => 'Paystack and Flutterwave',
            'for' => 'Taking a payment: a donation, a ticket, a paid vote, a shop order, a stand or an advert.',
            'gets' => 'Your email address, the amount and what it is for, and the card or bank details you type into their own payment page. We never see or store a card number.',
            'when' => 'Only when you choose to pay.',
            'probes' => ['paystack', 'flutterwave'],
            'hosts' => ['https://*.paystack.com', 'https://*.paystack.co', 'https://*.flutterwave.com', 'https://flutterwave.com'],
        ],
        [
            'id' => 'sms', 'name' => "Africa's Talking, Termii and Twilio",
            'for' => 'Sending a text message: a sign-in or profile-claim code, an invitation to an interview or questionnaire, or a reminder.',
            'gets' => 'Your phone number and the text of the message.',
            'when' => 'Only when a message is sent to a phone number you gave us. Whichever of the three is set up carries it.',
            'probes' => ['africastalking', 'termii', 'twilio'],
            'hosts' => [],
        ],
        [
            'id' => 'email', 'name' => 'Our email delivery service',
            'for' => 'Sending email: sign-in codes, receipts, tickets, confirmations and, if you subscribed, the newsletter.',
            'gets' => 'Your email address and the message.',
            'when' => 'Whenever we email you.',
            'probes' => ['smtp'],
            'hosts' => [],
        ],
        [
            'id' => 'images', 'name' => 'Cloudinary',
            'for' => 'Storing and resizing images: profile photos, nominee pictures, event covers and organisation logos.',
            'gets' => 'The images uploaded to the platform. Your browser loads them from Cloudinary, which sees your IP address as any image host does.',
            'when' => 'On any page that shows an uploaded image.',
            'probes' => ['cloudinary'],
            'hosts' => ['https://' . CloudinaryService::DELIVERY_HOST, 'https://*.' . CloudinaryService::DELIVERY_HOST],
        ],
        [
            'id' => 'calendar', 'name' => 'Google Calendar and Google Meet',
            'for' => 'Scheduling a judges’ interview and creating its video call.',
            'gets' => 'The names and email addresses of the people invited, and the time of the sitting.',
            'when' => 'Only for interviews an award runs, and only for the people in them.',
            'probes' => ['gas'],
            'hosts' => [],
        ],
        [
            'id' => 'door-voice', 'name' => 'OpenAI, ElevenLabs and Azure Speech (speech)',
            'for' => 'Greeting a guest aloud by name at an event door, and reading questions aloud to a nominee.',
            'gets' => 'At a door, the guest’s name as it will be spoken, so the service can say it. The operator’s chosen service is asked first; if it does not answer, the next one set up is tried.',
            'when' => 'Only at events that greet guests aloud at the door, and only for guests checked in there. Each name is spoken once and the recording kept, so it is not sent again.',
            'probes' => ['openai_voice', 'elevenlabs', 'azure_speech'],
            'hosts' => [],
            'see' => 'voice',
        ],
        [
            'id' => 'ai', 'name' => 'AI model providers (OpenAI, Groq, Google Gemini, Anthropic)',
            'for' => 'Moderation, triage and optional writing suggestions.',
            'gets' => 'Described in full, feature by feature, in the automated-processing section above.',
            'when' => 'Only while an administrator has these features switched on.',
            'probes' => ['openai', 'groq', 'gemini', 'anthropic'],
            'hosts' => [],
            'see' => 'automated-processing',
        ],
        [
            'id' => 'cloudflare', 'name' => 'Cloudflare',
            'for' => 'Checking that a vote or a form is sent by a person and not a script (Turnstile), and running our scheduled tasks.',
            'gets' => 'Your IP address and technical details of your browser, during the check.',
            'when' => 'On the pages that run the check, such as a ballot. The scheduled tasks carry no personal data.',
            'probes' => ['cron'],
            'hosts' => ['https://challenges.cloudflare.com'],
        ],
        [
            'id' => 'analytics', 'name' => 'Google Analytics',
            'for' => 'Counting visits, if the operator has switched it on.',
            'gets' => 'The pages you visit, your device and approximate location, and an identifier in a cookie.',
            'when' => 'Only after you say yes to Analytics in the cookie choices. Without that yes, nothing is loaded from Google.',
            'probes' => [],
            'hosts' => ['https://www.googletagmanager.com', 'https://*.google-analytics.com', 'https://*.analytics.google.com', 'https://*.googletagmanager.com'],
            'see' => 'cookies',
        ],
        [
            'id' => 'video', 'name' => 'YouTube and Vimeo',
            'for' => 'Playing a video an organisation has put on its giving page.',
            'gets' => 'Your IP address, and whatever the video site records about a viewer.',
            'when' => 'Only after you press play. Until then nothing is loaded from either.',
            'probes' => [],
            'hosts' => ['https://www.youtube.com', 'https://www.youtube-nocookie.com', 'https://player.vimeo.com'],
        ],
        [
            'id' => 'maps', 'name' => 'Google Maps',
            'for' => 'Showing where an event is.',
            'gets' => 'Your IP address, when you open the map.',
            'when' => 'Only when you follow an event’s map link; the page itself loads nothing from Google.',
            'probes' => [],
            'hosts' => ['https://www.google.com'],
        ],
        [
            'id' => 'fonts', 'name' => 'Google Fonts',
            'for' => 'Supplying the type on the judges’ console and the event-door scanner.',
            'gets' => 'The IP address and browser details of a judge or a door steward, as any font host receives.',
            'when' => 'Only on those two staff screens. Every public page, the easy-read font included, uses fonts served from our own files.',
            'probes' => [],
            'hosts' => ['https://fonts.googleapis.com', 'https://fonts.gstatic.com'],
        ],
    ];

    /**
     * Origins the browser policy ALLOWS that nothing on the site uses YET. The other unused
     * ones (jQuery's CDN, unpkg, Spline, Vidzflow, Plyr's CDN) were removed from the policy
     * on 5 Nov 2026; these stay because the owner is placing AdSense. `ThirdPartiesTest`
     * fails the day any of them is referenced — which is the day the ad tag ships — so the
     * declaration of Google AdSense has to be written then, not remembered.
     *
     * @var array<string,string> origin => why it is in the policy
     */
    public const ALLOWED_UNUSED = [
        'https://pagead2.googlesyndication.com'  => 'Google AdSense, kept for the ad placements being built (owner, 5 Nov 2026)',
        'https://googleads.g.doubleclick.net'    => 'as above',
        'https://tpc.googlesyndication.com'      => 'as above',
    ];

    /** @return list<array<string,mixed>> */
    public static function services(): array
    {
        return self::SERVICES;
    }

    /** Every origin a declared service accounts for. @return list<string> */
    public static function declaredHosts(): array
    {
        $out = [];
        foreach (self::SERVICES as $s) foreach ($s['hosts'] as $h) $out[] = $h;
        return array_values(array_unique($out));
    }

    /** Every provider id a declared service accounts for. @return list<string> */
    public static function declaredProbes(): array
    {
        $out = [];
        foreach (self::SERVICES as $s) foreach ($s['probes'] as $p) $out[] = $p;
        return array_values(array_unique($out));
    }

    /**
     * Every outside origin the public Content Security Policy allows, read from the policy's
     * own constants so nothing is typed twice. @return list<string>
     */
    public static function policyHosts(): array
    {
        $all = implode(' ', [Csp::SCRIPT_HOSTS, Csp::STYLE_HOSTS, Csp::FONT_HOSTS, Csp::CONNECT_HOSTS,
                             Csp::MEDIA_HOSTS, Csp::CDN_HOSTS, Csp::FRAME_HOSTS, Csp::GA_SCRIPT_HOSTS, Csp::GA_CONNECT_HOSTS]);
        return array_values(array_unique(array_filter(preg_split('/\s+/', $all) ?: [], static fn (string $h): bool => str_starts_with($h, 'https://'))));
    }
}
