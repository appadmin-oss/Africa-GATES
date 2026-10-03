<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Middleware\LanguageMiddleware;
use AfricaGates\Support\Languages;
use AfricaGates\Support\Translator;
use DI\ContainerBuilder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use Tests\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * The translation layer: `|trans`, the catalogues, and the one resolver behind both.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT EACH PART IS GUARDING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * · THE FILTER EXISTS ON THE ENVIRONMENTS THAT RENDER. Every snippet in the design
 *   handoff uses `{{ '…'|trans }}`, and an unknown filter is a COMPILE error — not a
 *   blank, a 500. So the app's own environment is asked, through the real container,
 *   and `src/` is swept for a bare `Twig\Environment` that does not register it: those
 *   render mail from a cron tick, where the throw is caught and nobody sees it.
 *
 * · A MISSING ENTRY IS THE ENGLISH, NEVER EMPTY. Gettext-style keys mean the template
 *   already holds the right fallback; an empty translation is a button with no label.
 *
 * · PLAIN TEXT OUT. A translation containing `<b>` renders as `&lt;b&gt;`; the filter
 *   must never be marked safe, or a catalogue becomes a way to put markup on a page.
 *
 * · A CATALOGUE ENTRY NEEDS A READER (CLAUDE.md §17). An entry no template passes to
 *   `|trans` and no PHP passes to Translator is dead weight that reads as coverage —
 *   and is usually the old wording of a sentence somebody has since edited, which is
 *   exactly the entry a translator would otherwise go on maintaining.
 *
 * · THE PROMPT'S WORDS MOVED, AND NOTHING WAS LOST. They were the only translated
 *   strings in `Support\Languages`; they are catalogue entries now, and the prompt
 *   must still be offered in every language it was offered in before.
 */
final class TranslatorTest extends TestCase
{
    private ?string $tmp = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Both memos are per PROCESS and the suite is one process.
        Languages::forget();
        Translator::forget();
    }

    protected function tearDown(): void
    {
        Languages::forget();
        Translator::forget();
        if ($this->tmp !== null) {
            array_map('unlink', glob($this->tmp . '/*.php') ?: []);
            @rmdir($this->tmp);
        }
        parent::tearDown();
    }

    private static function root(): string { return dirname(__DIR__, 2); }

    private function speak(string $code): void
    {
        $r = (new ServerRequestFactory())->createServerRequest('GET', '/?lang=' . $code)
            ->withQueryParams(['lang' => $code]);
        Languages::observe($r);
    }

    /** @param array<string,array<string,string>> $catalogues code → entries */
    private function catalogues(array $catalogues): void
    {
        $this->tmp = sys_get_temp_dir() . '/ag-lang-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
        foreach ($catalogues as $code => $entries) {
            file_put_contents($this->tmp . "/$code.php", '<?php return ' . var_export($entries, true) . ';');
        }
        Translator::forget($this->tmp);
    }

    private function render(string $tpl, array $ctx = []): string
    {
        $env = Translator::register(new Environment(new ArrayLoader(['t' => $tpl]), ['autoescape' => 'html']));
        return $env->render('t', $ctx);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The resolver
    // ══════════════════════════════════════════════════════════════════════════

    public function test_english_is_the_source_string_itself(): void
    {
        $this->assertSame('Yes', $this->render("{{ 'Yes'|trans }}"));
        $this->speak('en');
        $this->assertSame('Back to results', $this->render("{{ 'Back to results'|trans }}"));
    }

    public function test_a_non_english_request_gets_the_catalogue_string(): void
    {
        $this->speak('fr');
        $this->assertSame('Oui', $this->render("{{ 'Yes'|trans }}"));
        $this->speak('sw');
        $this->assertSame('Ndiyo', Translator::t('Yes'));
    }

    public function test_a_missing_or_empty_entry_falls_back_to_the_source_never_to_nothing(): void
    {
        $this->catalogues(['fr' => ['Close' => '', 'Open' => 'Ouvrir']]);
        $this->speak('fr');

        $this->assertSame('Nowhere in any catalogue', $this->render("{{ 'Nowhere in any catalogue'|trans }}"));
        $this->assertSame('Close', Translator::t('Close'), 'an empty translation is a button with no label');
        $this->assertSame('Ouvrir', Translator::t('Open'));
        $this->assertNull(Translator::translated('fr', 'Close'));
    }

    public function test_an_unsupported_code_never_reaches_the_filesystem(): void
    {
        // A file one level up that WOULD answer, so a missing allowlist reads it.
        $this->catalogues(['fr' => ['Yes' => 'Oui']]);
        mkdir($this->tmp . '/lang');
        file_put_contents($this->tmp . '/evil.php', "<?php return ['Yes' => 'PWNED'];");
        Translator::forget($this->tmp . '/lang');
        $this->assertNull(Translator::translated('../evil', 'Yes'));
        $this->assertNull(Translator::translated('xx', 'Yes'));
        rmdir($this->tmp . '/lang');

        Translator::forget();
        $this->assertSame('Oui', Translator::translated(' FR ', 'Yes'));
    }

    public function test_placeholders_are_filled_in_english_and_in_translation(): void
    {
        $this->assertSame('Hello Ada', $this->render("{{ 'Hello %name%'|trans({'%name%': n}) }}", ['n' => 'Ada']));

        $this->catalogues(['fr' => ['Hello %name%' => 'Bonjour %name%']]);
        $this->speak('fr');
        $this->assertSame('Bonjour Ada', $this->render("{{ 'Hello %name%'|trans({'%name%': n}) }}", ['n' => 'Ada']));
    }

    public function test_the_output_is_plain_text_and_stays_autoescaped(): void
    {
        $this->catalogues(['fr' => ['Bold' => '<b>gras</b>', 'Hello %name%' => 'Bonjour %name%']]);
        $this->speak('fr');

        $this->assertSame('&lt;b&gt;gras&lt;/b&gt;', $this->render("{{ 'Bold'|trans }}"));
        // A value dropped into a sentence is escaped with the rest of it — a nominee's
        // name is typed by somebody we do not control.
        $this->assertSame(
            'Bonjour &lt;i&gt;x&lt;/i&gt;',
            $this->render("{{ 'Hello %name%'|trans({'%name%': n}) }}", ['n' => '<i>x</i>'])
        );
    }

    public function test_a_catalogue_is_read_once_per_process(): void
    {
        // A memo has no other observable behaviour: change the file under it and the
        // answer must not move, while the FIRST read must have reached the file.
        $this->catalogues(['fr' => ['Open' => 'Ouvrir']]);
        $this->assertSame('Ouvrir', Translator::translated('fr', 'Open'));

        file_put_contents($this->tmp . '/fr.php', "<?php return ['Open' => 'CHANGED'];");
        $this->assertSame('Ouvrir', Translator::translated('fr', 'Open'));
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Where it is registered
    // ══════════════════════════════════════════════════════════════════════════

    public function test_the_apps_own_environment_has_trans_and_rtl_still_sets_dir(): void
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(require self::root() . '/config/container.php');
        $env = $b->build()->get(Twig::class)->getEnvironment();
        $env->setLoader(new ArrayLoader([
            'p' => '<html lang="{{ lang() }}" dir="{{ lang_dir() }}">{{ \'Yes\'|trans }}</html>',
        ]));

        // Through the real middleware, so the locale is the one it settled and not one
        // this test set by hand.
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/?lang=ar')
            ->withQueryParams(['lang' => 'ar']);
        $out = null;
        (new LanguageMiddleware())->process($req, new class ($env, $out) implements RequestHandlerInterface {
            public function __construct(private Environment $env, private ?string &$out) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->out = $this->env->render('p');
                return new Response(200);
            }
        });

        $this->assertSame('<html lang="ar" dir="rtl">نعم</html>', $out);
    }

    public function test_every_bare_environment_in_src_registers_trans(): void
    {
        $missing = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/src'));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') continue;
            $src   = (string) file_get_contents($f->getPathname());
            $built = preg_match_all('/new\s+\\\\?(?:Twig\\\\)?Environment\s*\(|Twig::create\s*\(/', $src);
            $reg   = preg_match_all('/Translator::register\(\s*new\s+\\\\?(?:Twig\\\\)?Environment\s*\(/', $src);
            if ($built > $reg) $missing[] = substr($f->getPathname(), strlen(self::root()) + 1);
        }

        $this->assertSame([], $missing,
            "A Twig environment built without `trans` cannot compile a template that uses it:\n  "
            . implode("\n  ", $missing));
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The catalogues
    // ══════════════════════════════════════════════════════════════════════════

    /** @return array<string,array<string,mixed>> code → raw catalogue */
    private static function shipped(): array
    {
        $out = [];
        foreach (glob(self::root() . '/resources/lang/*.php') ?: [] as $f) {
            $out[basename($f, '.php')] = require $f;
        }
        return $out;
    }

    public function test_catalogues_are_for_offered_languages_and_english_has_none(): void
    {
        $shipped = self::shipped();
        $this->assertNotEmpty($shipped, 'the sweep found no catalogues at all');

        // English is identity: an en.php is a second copy of every source string, and
        // the first template edit makes the English page show the old one.
        $this->assertArrayNotHasKey(Languages::DEFAULT, $shipped);

        foreach ($shipped as $code => $c) {
            $this->assertArrayHasKey($code, Languages::ALL, "$code.php is a catalogue for a language nobody can choose");
            $this->assertIsArray($c, "$code.php does not return an array");
            foreach ($c as $k => $v) {
                $this->assertIsString($k, "$code.php has a non-string key");
                $this->assertIsString($v, "$code.php: '$k' is not a string");
                $this->assertNotSame('', trim($v), "$code.php: '$k' is empty, which renders as nothing");
            }
        }
    }

    public function test_a_translation_keeps_every_placeholder_its_source_has(): void
    {
        // A dropped `%name%` loses the person the sentence was about, in one language
        // only, which nobody reading the English page will ever see.
        $bad = [];
        foreach (self::shipped() as $code => $c) {
            foreach ($c as $k => $v) {
                preg_match_all('/%[a-z_]+%/i', (string) $k, $m);
                foreach ($m[0] as $ph) {
                    if (!str_contains((string) $v, $ph)) $bad[] = "$code: '$k' drops $ph";
                }
            }
        }
        $this->assertSame([], $bad);
    }

    public function test_every_catalogue_entry_is_read_somewhere(): void
    {
        $read = self::readers();
        $this->assertContains('Yes', $read, 'the reader sweep found nothing it should have');

        $dead = [];
        foreach (self::shipped() as $code => $c) {
            foreach (array_keys($c) as $k) {
                if (!in_array($k, $read, true)) $dead[] = "$code: '$k'";
            }
        }

        $this->assertSame([], $dead,
            "Catalogue entries nothing passes to |trans or Translator — read nowhere (§17):\n  "
            . implode("\n  ", $dead));
    }

    public function test_the_prompt_is_still_offered_in_every_language_it_was(): void
    {
        // Before the move each of the seven carried its own question and "yes"; a
        // catalogue losing either now drops that language from the prompt silently.
        $codes = array_column(Languages::prompts(), 'code');
        sort($codes);
        $this->assertSame(['ar', 'fr', 'ha', 'ig', 'pt', 'sw', 'yo'], $codes);

        $ar = array_values(array_filter(Languages::prompts(), static fn ($l) => $l['code'] === 'ar'))[0];
        $this->assertSame('نعم', $ar['yes']);
        $this->assertSame('rtl', $ar['dir']);
    }

    public function test_the_prompt_asks_in_the_visitors_language_not_the_pages(): void
    {
        // The page is English for somebody who has not chosen; the prompt row for French
        // must still be French — it is the one sentence that cannot fall back to English.
        $this->speak('en');
        $fr = array_values(array_filter(Languages::prompts(), static fn ($l) => $l['code'] === 'fr'))[0];
        $this->assertSame('Voir Africa GATES en français ?', $fr['ask']);
    }

    /**
     * Every source string some code hands the translator: `'…'|trans` in a template
     * (comments stripped — a Twig comment reaches nobody), and a literal passed to
     * `Translator::t()` or `Translator::translated()` in `src/`.
     *
     * @return list<string>
     */
    private static function readers(): array
    {
        $out = [];

        $tpl = self::root() . '/templates';
        $it  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tpl));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'twig') continue;
            $body = (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($f->getPathname()));
            preg_match_all('/(\'|")((?:(?!\1)[^\\\\]|\\\\.)*)\1\s*\|\s*trans\b/', $body, $m);
            foreach ($m[2] as $i => $s) $out[] = stripcslashes($s);
        }

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/src'));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') continue;
            $body = (string) file_get_contents($f->getPathname());
            $lit  = "'((?:[^'\\\\]|\\\\.)*)'";
            preg_match_all('/Translator::t\(\s*' . $lit . '/', $body, $a);
            preg_match_all('/Translator::translated\(\s*[^,()]+,\s*' . $lit . '/', $body, $b);
            foreach (array_merge($a[1], $b[1]) as $s) $out[] = str_replace(["\\'", '\\\\'], ["'", '\\'], $s);
        }

        return array_values(array_unique($out));
    }
}
