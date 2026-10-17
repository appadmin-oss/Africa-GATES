<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\NominationFeedbackService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * ONE RESOLVER PER SETTING, AND THE SWEEP THAT SAYS SO.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE RULE THIS REPOSITORY KEEPS PAYING FOR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_settings` is read by hand in fifty-two places across thirty-five files, and that
 * is fine: the house pattern is one static per service — settings first, `.env` behind it
 * — because there is no shell on production and a credential read only from a file is a
 * credential nobody can set. What is NOT fine is two of them reading the SAME key, which
 * is how the halves of a feature come to disagree about what is configured.
 *
 * `review_sla_hours` had three readers and no owner:
 *
 *   · `config/container.php` cast it into a Twig global with no floor — and that global is
 *     printed as a promise on `/nominate-success` ("usually within N hours") and on
 *     `/integrity` ("Acknowledge the complaint within N hours");
 *   · `GuideService` cast it with no floor into the site-state block the assistant is
 *     explicitly allowed to quote to the public;
 *   · `Maintenance::sendPendingAcknowledgements()` floored it at one.
 *
 * The only reader that ACTED on the number was the only one that guarded it. The settings
 * form offered `min="0"`, so nought was a value an operator could save — it reads like
 * turning the promise off. It turned nothing off. It made two public pages and the
 * assistant promise a nominator their entry is reviewed within *zero hours*, and a
 * complainant that we acknowledge within *zero hours*, on the page whose whole subject is
 * whether this platform can be believed, while the mailer carried on at one.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THE SWEEP READS THE SOURCE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The divergence is invisible at runtime: every surface renders, nothing throws, and the
 * two answers are only different for one value of one setting. A behavioural test would
 * have to know which key and which value in advance — which is exactly what nobody knew.
 * So the structural claim is the one worth holding: no settings key is spelled in two
 * classes. It is the shape of the fault rather than the instance of it.
 */
final class OneResolverPerSettingTest extends TestCase
{
    /** @return array<string,string> path => PHP source with comments blanked */
    private static function sources(): array
    {
        $root = dirname(__DIR__, 2);
        $out  = [];

        foreach ([$root . '/src', $root . '/config'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($it as $f) {
                if (!$f->isFile() || $f->getExtension() !== 'php') continue;
                $src = (string) file_get_contents($f->getPathname());
                if (!str_contains($src, 'gates_settings')) continue;
                // Comments blanked. Every fix in this repository documents the fault in the
                // words of the fault, so a scan that reads comments finds the thing it just
                // removed — which is how a sweep comes to be deleted rather than kept.
                $out[str_replace($root . '/', '', $f->getPathname())] =
                    (string) preg_replace(['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~'], ' ', $src);
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Every `const NAME = 'literal'`, PER FILE.
     *
     * ── WITHOUT THE MAP THE SCAN IS BLIND TO ITS OWN FIX ────────────────────
     *
     * Giving a key an owner means the owner stops spelling it: this service reads
     * `self::SLA_KEY`, so the literal `review_sla_hours` left the source the moment the
     * fault was repaired. A scan reading only literals would then report the sweep's own
     * subject as a key nothing resolves — quiet when the code improves, and therefore not
     * to be trusted when it regresses.
     *
     * ── AND WITHOUT THE *PER FILE* PART IT INVENTS FAULTS ───────────────────
     *
     * A first cut keyed the map by bare constant name across the whole tree, so the last
     * `LAST_ERROR` parsed won and `AzureVoice` — which declares its own,
     * `azure_speech_last_error` — was reported as a second reader of ElevenLabs' key.
     * `SETTING` did the same to `DoorVoice` and `DisplayTime`. Two findings, both invented,
     * both plausible enough to send somebody refactoring code that was already correct:
     * the same shape of tooling error as a sweep that assumed `$a` meant the admin route
     * group. A constant is scoped to its class; resolve it there.
     *
     * `Class::NAME` is resolved through the file whose basename is that class, which is
     * this repository's layout everywhere. A reference that cannot be resolved is skipped
     * rather than guessed — an unresolvable name is not evidence of a second resolver.
     *
     * @return array<string, array<string,string>> file => (constant name => literal)
     */
    private static function constants(): array
    {
        $out = [];
        foreach (self::sources() as $rel => $src) {
            $out[$rel] = [];
            preg_match_all("~const\s+([A-Z][A-Z0-9_]*)\s*=\s*'([^']*)'~", $src, $m, PREG_SET_ORDER);
            foreach ($m as $c) $out[$rel][$c[1]] = $c[2];
        }
        return $out;
    }

    /** The literal behind `self::NAME` or `Other::NAME` as written in $rel, or null. */
    private static function resolveConst(array $consts, string $rel, string $cls, string $name): ?string
    {
        if ($cls === 'self' || $cls === 'static') return $consts[$rel][$name] ?? null;

        foreach ($consts as $file => $map) {
            if (basename($file, '.php') === $cls) return $map[$name] ?? null;
        }
        return null;
    }

    /**
     * @return array<string, list<string>> settings key => the files that resolve it
     */
    private static function readers(): array
    {
        $consts = self::constants();
        $by     = [];

        foreach (self::sources() as $rel => $src) {
            $keys = [];

            // A literal key, which is how forty-odd of these reads are written.
            preg_match_all("~where\(\s*'key_name'\s*,\s*'([A-Za-z0-9_.]+)'~", $src, $m);
            foreach ($m[1] as $k) $keys[$k] = true;

            // A named constant, resolved in the scope it was written in.
            preg_match_all("~where\(\s*'key_name'\s*,\s*(self|static|[A-Za-z_][A-Za-z0-9_]*)::([A-Z][A-Z0-9_]*)~",
                           $src, $m, PREG_SET_ORDER);
            foreach ($m as $ref) {
                $lit = self::resolveConst($consts, $rel, $ref[1], $ref[2]);
                if ($lit !== null) $keys[$lit] = true;
            }

            // A batch read, which the settings screen and the status page both use.
            preg_match_all("~whereIn\(\s*'key_name'\s*,\s*\[([^\]]*)\]~", $src, $m);
            foreach ($m[1] as $list) {
                preg_match_all("~'([A-Za-z0-9_.]+)'~", $list, $mm);
                foreach ($mm[1] as $k) $keys[$k] = true;
            }

            foreach (array_keys($keys) as $k) $by[$k][] = $rel;
        }
        return $by;
    }

    /**
     * The scan has to be looking at something. Without this it passes by matching nothing
     * the day the query idiom changes — and the fault it guards is silent by construction.
     */
    public function test_the_scan_actually_finds_the_settings_reads(): void
    {
        $by = self::readers();

        $this->assertGreaterThan(20, count($by),
            'the settings-read scan is matching almost nothing, so it is not a guard');
        // The key this sweep was written for, reached through a CONSTANT rather than a
        // literal — see `constants()`. If this assertion fails the scan has gone blind to
        // every resolver that gave its key a name, which is every resolver worth having.
        $this->assertArrayHasKey(NominationFeedbackService::SLA_KEY, $by,
            'the scan cannot see a key read through a class constant');
        $this->assertSame(['src/Services/NominationFeedbackService.php'],
            $by[NominationFeedbackService::SLA_KEY],
            'the review SLA has picked up a second resolver again');
    }

    public function test_no_settings_key_is_resolved_in_two_places(): void
    {
        $shared = [];
        foreach (self::readers() as $key => $files) {
            // The writer and the reader of a key are not two resolvers, and the settings
            // screen legitimately touches everything: it is the form. Excluded by ROLE
            // rather than by name, so a new key cannot be quietly added to an allowance.
            $files = array_values(array_filter($files, static fn (string $f): bool =>
                !str_ends_with($f, 'Admin/Controllers/SettingsController.php')
                && !str_ends_with($f, 'Admin/Services/SettingsService.php')));

            if (count($files) > 1) $shared[$key] = $files;
        }

        $lines = [];
        foreach ($shared as $k => $files) $lines[] = $k . ' — ' . implode(', ', $files);

        $this->assertSame([], $lines,
            "Two classes resolving one settings key is how the halves of a feature come to\n"
            . "disagree about whether it is configured, and how one surface floors a value\n"
            . "while another publishes it raw. Give the key an owner and let the other side\n"
            . "call it — see NominationFeedbackService::slaHours().\n\n  "
            . implode("\n  ", $lines));
    }

    // ══ and the value itself, at the edge that broke ═════════════════════════

    /**
     * NOUGHT IS NOT A PROMISE OF INSTANT REVIEW.
     *
     * The floor is in the resolver, so it applies to the mailer, to the Twig global the
     * public pages print, and to the assistant's site state — which is the whole reason
     * there is one resolver.
     */
    public function test_the_sla_never_resolves_below_an_hour(): void
    {
        $this->assertSame(1, NominationFeedbackService::slaHours('0'),
            'a saved nought used to reach two public pages verbatim');
        $this->assertSame(1, NominationFeedbackService::slaHours('-9'));
        $this->assertSame(72, NominationFeedbackService::slaHours('72'));
    }

    /**
     * AND A CLEARED FIELD IS AN UNSET OVERRIDE, NOT A ZERO.
     *
     * An operator emptying the box is removing their override. Reading that as nought and
     * then flooring it to one hour would replace a considered default of two working days
     * with the most aggressive promise the platform can make.
     */
    public function test_a_blank_or_missing_value_falls_back_to_the_default(): void
    {
        $this->assertSame(NominationFeedbackService::SLA_DEFAULT,
            NominationFeedbackService::slaHours(''));
        $this->assertSame(NominationFeedbackService::SLA_DEFAULT,
            NominationFeedbackService::slaHours('   '));

        DB::table('gates_settings')->where('key_name', NominationFeedbackService::SLA_KEY)->delete();
        $this->assertSame(NominationFeedbackService::SLA_DEFAULT,
            NominationFeedbackService::slaHours());
    }

    /** Reading the stored row, which is the path every caller with no value in hand takes. */
    public function test_it_reads_the_stored_setting(): void
    {
        DB::table('gates_settings')->updateOrInsert(
            ['key_name' => NominationFeedbackService::SLA_KEY], ['value' => '96']);

        $this->assertSame(96, NominationFeedbackService::slaHours());
    }

    /**
     * THE FORM MUST NOT OFFER THE VALUE THAT BROKE IT.
     *
     * The floor makes nought harmless; the input still invited it, and an operator who
     * types nought, saves, and reads nought back has been told something the platform is
     * not doing. Pinned on the markup because that is where the invitation lives.
     */
    public function test_the_settings_form_does_not_offer_a_zero_hour_sla(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/admin/settings.twig');
        $src = (string) preg_replace('~\{#.*?#\}~s', ' ', $src);

        $this->assertMatchesRegularExpression(
            '~name="review_sla_hours"[^>]*min="1"~', $src,
            'the SLA input offers min="0", and nought is printed verbatim as a promise on '
            . '/nominate-success and /integrity');
    }
}
