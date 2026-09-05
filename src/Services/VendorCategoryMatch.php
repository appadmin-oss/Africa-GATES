<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * Reading what a vendor sells and suggesting which trade it is.
 *
 * ── WHAT PROBLEM THIS ACTUALLY SOLVES ────────────────────────────────────────
 *
 * A vendor arrives at the stand form knowing exactly what they sell and nothing about
 * how this event has decided to file it. They are shown a list of trades an organiser
 * wrote — which may be seven, may be twenty, and on a book fair contains "publishing"
 * and on a food festival does not contain "beauty" at all — and asked to pick the one
 * their business is. The person who writes "small chops and zobo for parties" should not
 * have to work out that this event calls that `food`.
 *
 * So the description they were already writing does the work: they write it, and the
 * form offers the trade it reads like.
 *
 * ── IT SUGGESTS. IT NEVER FILES ──────────────────────────────────────────────
 *
 * The trade is what an organiser's screens group by, and it sits next to the quota,
 * which is the entire fairness mechanism for stands — §10.1 exists so a market does not
 * end up with twelve jewellery stalls and no food. A model that quietly filed an
 * applicant under the wrong trade would move them between groups without either party
 * knowing, and the first anybody would learn of it is a market with the wrong balance on
 * the day.
 *
 * So this returns a suggestion for a control the vendor can already operate by hand, and
 * a reason in the vendor's own terms. They accept it or ignore it. With no model
 * configured, no key, or the budget spent, the form is exactly the form it was — which
 * is why {@see suggest()} returns null rather than throwing, and why nothing downstream
 * may treat a null as an error.
 */
final class VendorCategoryMatch
{
    public const CAPABILITY = 'vendor.category_match';

    /** Below this the match is not worth showing — see {@see suggest()}. */
    private const FLOOR = 0.45;

    /**
     * Enough words to be a description rather than a product name.
     *
     * "Bread" is a true answer to "what do you sell" and tells a matcher nothing that
     * three categories do not equally fit. Asking a model to choose between them anyway
     * produces a confident wrong answer, which is worse than no suggestion at all.
     */
    private const MIN_CHARS = 12;

    /**
     * Suggest the trade that best fits a description.
     *
     * @param array<string,string> $categories slug => label, the organiser's own list
     * @return array{slug:string, label:string, confidence:float, why:string}|null
     */
    public static function suggest(string $description, array $categories): ?array
    {
        $description = trim($description);
        if (mb_strlen($description) < self::MIN_CHARS || $categories === []) return null;
        if (!AiGateway::available(self::CAPABILITY)) return null;

        // The list goes into the prompt as `slug — label` pairs, because the model has to
        // answer with a slug this platform recognises and the label is what carries the
        // meaning. Sending labels alone would need a reverse lookup on free text, which is
        // the fuzzy match this exists to avoid.
        $offered = [];
        foreach ($categories as $slug => $label) {
            $offered[] = $slug . ' — ' . $label;
        }

        $r = (new AiGateway())->run(self::CAPABILITY, [
            'system' => 'You file a market vendor under one trade category. '
                . 'Reply ONLY with JSON: {"slug":"...","confidence":0.0,"why":"..."}. '
                . 'slug MUST be one of these exactly, or null if none of them fit: '
                . implode('; ', $offered) . '. '
                . 'confidence is 0 to 1: how sure you are, given only what they wrote. '
                . 'why is one short clause naming what in their description decided it, '
                . 'addressed to the vendor and written in plain words. '
                . 'Never invent a category that is not on the list. '
                . 'If the description could equally be two of them, say so in why and '
                . 'give a confidence below 0.5.',
            'trusted' => 'File the vendor whose description follows.',
            'user'    => $description,
            'json'    => true,
            'schema'  => static function (string $raw) use ($categories): ?array {
                $j = json_decode($raw, true);
                if (!is_array($j)) return null;

                // ── ON THE LIST, OR NOTHING ─────────────────────────────────
                //
                // Not corrected, not fuzzy-matched, not mapped to the nearest label. A
                // slug the organiser does not publish is a group that does not exist, and
                // a form offering it would let a vendor accept a suggestion the server
                // then refuses — with the description they wrote already gone from the
                // page. Dropped is the only safe reading of a wrong answer.
                $slug = strtolower(trim((string) ($j['slug'] ?? '')));
                if ($slug === '' || !isset($categories[$slug])) return null;

                $conf = isset($j['confidence']) && is_numeric($j['confidence'])
                    ? max(0.0, min(1.0, (float) $j['confidence']))
                    : 0.0;

                // Trimmed hard. This is rendered next to a control, and a model that
                // answers with a paragraph would push the control off the screen.
                // strip_tags because this is rendered beside a control, and the text
                // came back from a model that was handed a vendor's own free text.
                $why = trim(strip_tags((string) ($j['why'] ?? '')));
                $why = mb_substr(preg_replace('/\s+/', ' ', $why) ?? '', 0, 140);

                return ['slug' => $slug, 'label' => $categories[$slug],
                        'confidence' => $conf, 'why' => $why];
            },
        ]);

        if (!$r->ok || !is_array($r->value)) return null;
        $out = $r->value;

        // A low-confidence suggestion is worse than none: it is a wrong answer with a
        // button next to it, and the vendor has no way to check it. Withheld rather than
        // shown with a hedge — a hedge next to a filled-in field still reads as an answer.
        if ($out['confidence'] < self::FLOOR) return null;

        return $out;
    }
}
