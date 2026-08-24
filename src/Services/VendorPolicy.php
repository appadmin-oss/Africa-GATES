<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * What a vendor must supply, and what they may sell — decided by the organiser, not the code.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THESE TWO THINGS HAD TO STOP BEING CONSTANTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see PartnerOrg::vendorDocuments()} hard-coded the certificate list and
 * {@see StandType::CATEGORIES} hard-coded the trade list. Both are POLICY, both differ by
 * event and by year, and both were changeable only by editing PHP — on a host with no SSH,
 * which means not changeable at all.
 *
 * The consequences were specific rather than theoretical:
 *
 *  · CAC was demanded of every registered business at every event. A craft market of twenty
 *    market traders does not need company registration certificates, and asking for one
 *    turns away exactly the vendors these events exist to include. There was no way to stop
 *    asking.
 *
 *  · SCUML was required of partner organisations and of nobody else, with no way to require
 *    it of a vendor handling large cash sums, which is the case it exists for.
 *
 *  · The trade list was seven fixed slugs. An organiser running a book fair could not add
 *    "publishing", and a food festival could not remove "beauty" — so a quota, which is the
 *    entire fairness mechanism for stands, could only ever be set against categories
 *    somebody else chose.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE CODE'S LIST IS STILL THE DEFAULT, AND IS NEVER COPIED INTO A ROW
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Same discipline as {@see AiPrompt}: nothing is seeded. An empty setting means "use what
 * the code says", so a deployment that never opens this screen behaves exactly as before
 * and a later release can improve the defaults for everybody who has not overridden them.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND ONE THING THAT IS NOT NEGOTIABLE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A vendor must always supply SOMETHING that identifies them — {@see documentsFor()} keeps
 * an identity document whatever the toggles say. An event that collects money from the
 * public on behalf of traders it cannot name is not a lighter-touch event, it is an
 * unattributable one, and the first time it matters is a food-safety complaint.
 */
final class VendorPolicy
{
    /** Settings keys. Prefixed so they group on the settings screen. */
    private const K_CAC        = 'vendor_require_cac';
    private const K_SCUML      = 'vendor_require_scuml';
    private const K_INSURANCE  = 'vendor_require_insurance';
    private const K_CATEGORIES = 'vendor_categories';

    /**
     * Every certificate the platform knows how to ask for.
     *
     * A closed list: each of these has an upload slot, a label on the application form and a
     * place in the completeness check, so an organiser inventing a new slug here would
     * create a requirement nothing can satisfy.
     *
     * @var array<string,string>
     */
    public const DOCUMENTS = [
        'id'        => 'Government photo ID',
        'cac'       => 'CAC registration certificate',
        'scuml'     => 'SCUML certificate',
        'insurance' => 'Public liability insurance',
    ];

    /**
     * The trades on offer when nobody has said otherwise.
     *
     * @var array<string,string>
     */
    public const DEFAULT_CATEGORIES = [
        'food'     => 'Food and drink',
        'craft'    => 'Craft and handmade',
        'fashion'  => 'Fashion and textiles',
        'beauty'   => 'Beauty and cosmetics',
        'books'    => 'Books and print',
        'services' => 'Services',
        'general'  => 'General',
    ];

    // ═══════════════════════════════════════════════════════════════════════
    // THE TOGGLES
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Is CAC registration required of a registered business?
     *
     * Never of an individual, whatever this says. An individual has no CAC number by
     * definition, and asking for one is how somebody ends up borrowing a number that is not
     * theirs — which puts the wrong name on the paperwork and on the money.
     *
     * Defaults ON, because that is the behaviour every existing deployment already has and
     * a migration that quietly relaxed a compliance requirement would be indefensible.
     */
    public static function requireCac(): bool
    {
        return self::flag(self::K_CAC, true);
    }

    /**
     * Is a SCUML certificate required of a vendor?
     *
     * Defaults OFF. It was never asked of vendors, and turning it on for everybody in an
     * upgrade would make every existing vendor application incomplete overnight — including
     * ones already accepted, whose stands are booked.
     */
    public static function requireScuml(): bool
    {
        return self::flag(self::K_SCUML, false);
    }

    /** Public liability insurance. Defaults ON — it is the existing behaviour. */
    public static function requireInsurance(): bool
    {
        return self::flag(self::K_INSURANCE, true);
    }

    /**
     * What a vendor of this entity type must upload.
     *
     * @return array<string,string> slug => label
     */
    public static function documentsFor(string $entity): array
    {
        $individual = $entity === PartnerOrg::ENTITY_INDIVIDUAL;

        $out = [];

        // ── ALWAYS SOMETHING THAT IDENTIFIES THEM ───────────────────────────
        //
        // An individual gives photo ID. A business gives its registration when CAC is
        // required — and when it is NOT, it falls back to photo ID rather than to nothing,
        // so relaxing a compliance requirement never becomes anonymity.
        if ($individual) {
            $out['id'] = self::DOCUMENTS['id'];
        } elseif (self::requireCac()) {
            $out['cac'] = self::DOCUMENTS['cac'];
        } else {
            $out['id'] = self::DOCUMENTS['id'];
        }

        if (self::requireScuml())     $out['scuml']     = self::DOCUMENTS['scuml'];
        if (self::requireInsurance()) $out['insurance'] = self::DOCUMENTS['insurance'];

        return $out;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // THE TRADES
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * The trade list, as an organiser has set it — or the code's, if they have not.
     *
     * @return array<string,string> slug => label
     */
    public static function categories(): array
    {
        $raw = self::setting(self::K_CATEGORIES);
        if ($raw === '') return self::DEFAULT_CATEGORIES;

        $parsed = json_decode($raw, true);
        if (!is_array($parsed) || $parsed === []) return self::DEFAULT_CATEGORIES;

        $out = [];
        foreach ($parsed as $slug => $label) {
            $s = self::slug((string) $slug);
            $l = trim((string) $label);
            if ($s === '' || $l === '') continue;
            $out[$s] = mb_substr($l, 0, 60);
        }

        // An override that parses to nothing usable is treated as absent rather than as an
        // empty list. A stand form with no categories at all cannot be filled in, and
        // falling back is recoverable where an empty screen is not.
        return $out === [] ? self::DEFAULT_CATEGORIES : $out;
    }

    /**
     * Replace the trade list.
     *
     * @param array<int|string,string> $in slug => label, or a list of labels
     * @return array{ok:bool, message:string, count:int}
     */
    public static function saveCategories(array $in, int $adminId = 0): array
    {
        $out = [];
        foreach ($in as $slug => $label) {
            $l = trim((string) $label);
            if ($l === '') continue;

            // A caller may pass labels only. Deriving the slug from the label is right for a
            // new row and wrong for an existing one — a renamed category would get a new
            // slug and orphan every stand type already filed under the old one — so an
            // explicit key always wins.
            $s = is_string($slug) && self::slug($slug) !== '' ? self::slug($slug) : self::slug($l);
            if ($s === '') continue;

            $out[$s] = mb_substr($l, 0, 60);
            if (count($out) >= 40) break;
        }

        if ($out === []) {
            return ['ok' => false, 'count' => 0,
                    'message' => 'Keep at least one category. A stand form with none cannot '
                               . 'be filled in.'];
        }

        self::put(self::K_CATEGORIES, (string) json_encode($out), $adminId);

        return ['ok' => true, 'count' => count($out),
                'message' => count($out) . ' categor' . (count($out) === 1 ? 'y' : 'ies') . ' saved.'];
    }

    /**
     * Turn the certificate toggles on or off.
     *
     * @param array<string,mixed> $in
     */
    public static function saveRequirements(array $in, int $adminId = 0): array
    {
        foreach ([self::K_CAC => 'cac', self::K_SCUML => 'scuml',
                  self::K_INSURANCE => 'insurance'] as $key => $field) {
            self::put($key, !empty($in[$field]) ? '1' : '0', $adminId);
        }

        return ['ok' => true, 'message' => 'Saved. This applies to applications from now on — '
                                         . 'vendors already accepted are not re-checked.'];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // STORAGE
    // ═══════════════════════════════════════════════════════════════════════

    private static function flag(string $key, bool $default): bool
    {
        $v = self::setting($key);

        // '' means nothing stored, which is the default rather than false. Reading an unset
        // setting as "off" would silently drop a compliance requirement on every deployment
        // that has never opened the screen.
        return $v === '' ? $default : $v === '1';
    }

    private static function setting(string $key): string
    {
        try {
            $v = DB::table('gates_settings')->where('key_name', $key)->value('value');
            return is_string($v) ? trim($v) : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private static function put(string $key, string $value, int $adminId): void
    {
        try {
            DB::table('gates_settings')->updateOrInsert(
                ['key_name' => $key],
                ['value' => $value, 'updated_at' => date('Y-m-d H:i:s'),
                 'updated_by' => $adminId ?: null]
            );
        } catch (\Throwable $e) {
            error_log('[vendor-policy] could not save ' . $key . ': ' . $e->getMessage());
        }
    }

    /**
     * Slug::make and not a local regex.
     *
     * The obvious `preg_replace('/[^a-z0-9]+/', '-', …)` DELETES accented letters rather
     * than folding them, so "Café" becomes "caf" and "Ilé-Ifè" loses two characters. On a
     * pan-African platform that is not an edge case, and SlugTest fails the build over it —
     * which is how this one was caught.
     */
    private static function slug(string $s): string
    {
        return \AfricaGates\Support\Slug::make($s, 40);
    }
}
