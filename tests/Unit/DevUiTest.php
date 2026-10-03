<?php
declare(strict_types=1);

namespace Tests\Unit;

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * `components.css` is the base and nothing else, and /_dev/ui shows every piece of
 * it in every state — with the four tokens that had no reader read by real components.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THREE FAULTS, EACH ALREADY SHIPPED ONCE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *  1. **The base layer became everybody's sheet.** Phase 1 wrote 436 lines of base
 *     components; seven later commits appended the site header, the Menu, Quick
 *     settings, the records library and a nomination notice to the same file, until
 *     it was 1,376 lines every page downloads and a rebuild of the base could not be
 *     done without destroying other phases' work. The rebuild moved them out
 *     (`components/chrome.css`, `components/library.css`); this holds the door.
 *
 *  2. **A specimen page manufactured readers.** The page this replaces drew the
 *     spacing, radius and layer ladders as `style="width:var(--ag-sp-36)"` swatches,
 *     which kept DeadTokenTest quiet about rungs no component used — §17's
 *     "declared, no reader", with a reader built to order (GAPS C7). Here an inline
 *     style may only DECLARE a data-driven property, never read a token, and the four
 *     tokens that were unread are required to be read by components.css rules whose
 *     classes the page actually renders.
 *
 *  3. **A dev route that reaches production.** 404 there, so it is not even
 *     discoverable.
 */
final class DevUiTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    /** The base components Phase 1 owns (PHASE-1 build item 3, §9.2, §9.4, §6.1). */
    private const BASE = [
        'btn', 'chip', 'chip-row', 'sticky', 'cs', 'field', 'label', 'hint', 'err', 'card',
        'list', 'icon-tile', 'scrim', 'sheet', 'switch', 'switch-target', 'seg', 'skel',
        'empty', 'stock', 'badge',
        // Context, not a component: a document page's ink switch.
        'doc',
    ];

    /** The four tokens DeadTokenTest reported unread before the rebuild. */
    private const ONCE_UNREAD = ['--ag-dur-3', '--ag-ease-pop', '--ag-stock-low', '--ag-stock-gone'];

    private function css(): string
    {
        return (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents(self::ROOT . 'public/assets/css/components.css'));
    }

    /** @return list<array{0:string,1:string}> innermost rules as [selector, body] */
    private function rules(string $css): array
    {
        $out = [];
        $stack = [];
        $buf = '';
        foreach (str_split($css) as $ch) {
            if ($ch === '{') { $stack[] = trim($buf); $buf = ''; continue; }
            if ($ch === '}') {
                $sel = array_pop($stack);
                if ($sel !== null && !str_starts_with($sel, '@') && trim($buf) !== '') $out[] = [$sel, $buf];
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        return $out;
    }

    private function render(string $env = 'development'): \Psr\Http\Message\ResponseInterface
    {
        $prev = $_ENV['APP_ENV'] ?? null;
        $_ENV['APP_ENV'] = $env;
        try {
            $builder = new ContainerBuilder();
            $builder->addDefinitions(self::ROOT . 'config/container.php');
            AppFactory::setContainer($builder->build());
            $app = AppFactory::create();
            (require self::ROOT . 'src/routes.php')($app);
            $app->addRoutingMiddleware();
            $app->addErrorMiddleware(false, false, false);
            return $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/_dev/ui'));
        } finally {
            if ($prev === null) unset($_ENV['APP_ENV']); else $_ENV['APP_ENV'] = $prev;
        }
    }

    // ── 1 · the base is the base ─────────────────────────────────────────────

    public function test_components_css_holds_the_base_components_and_nothing_else(): void
    {
        $foreign = [];
        foreach ($this->rules($this->css()) as [$sel]) {
            preg_match_all('~\.ag-([a-z0-9]+(?:-[a-z0-9]+)*)~', $sel, $m);
            foreach ($m[1] as $cls) {
                // BEM: the block is the name before an element (__) or a modifier (--).
                $block = (string) preg_split('~__|--~', $cls)[0];
                if (!in_array($block, self::BASE, true)) $foreign[$block] = $sel;
            }
        }
        $this->assertSame([], $foreign,
            "components.css is the Phase 1 base. A screen's or a phase's component belongs in its own sheet:\n"
            . implode("\n", array_map(static fn ($b, $s) => ".ag-{$b}  ({$s})", array_keys($foreign), $foreign)));
    }

    public function test_the_carved_out_rules_are_loaded_where_they_used_to_be(): void
    {
        foreach (['public/assets/css/components/chrome.css', 'public/assets/css/components/library.css'] as $f) {
            $this->assertFileExists(self::ROOT . $f);
        }
        // The two sheets Phase 2 and the records pages still read. If either is missing
        // the header, the Menu and the account dashboard lose their styling at once.
        $chrome = (string) file_get_contents(self::ROOT . 'public/assets/css/components/chrome.css');
        foreach (['.ag-appbar', '.ag-tabbar', '.ag-menu', '.ag-head', '.ag-mega'] as $c) {
            $this->assertStringContainsString($c, $chrome);
        }
        $this->assertStringContainsString('.ag-pill', (string) file_get_contents(self::ROOT . 'public/assets/css/components/library.css'));
    }

    // ── 2 · real readers, no manufactured ones ───────────────────────────────

    public function test_the_once_unread_tokens_are_read_by_components_the_page_renders(): void
    {
        $body = (string) $this->render()->getBody();
        preg_match_all('~class="([^"]*)"~', $body, $m);
        $rendered = array_flip(preg_split('~\s+~', implode(' ', $m[1])) ?: []);

        foreach (self::ONCE_UNREAD as $tok) {
            $readers = [];
            foreach ($this->rules($this->css()) as [$sel, $decls]) {
                if (!preg_match('~var\(\s*' . preg_quote($tok, '~') . '\b~', $decls)) continue;
                preg_match_all('~\.(ag-[a-z0-9_-]+)~', $sel, $c);
                foreach ($c[1] as $cls) $readers[$cls] = true;
            }
            $this->assertNotSame([], $readers, "{$tok} has no reader in components.css");
            $shown = array_intersect_key($readers, $rendered);
            $this->assertNotSame([], $shown,
                "{$tok} is read by " . implode(', ', array_keys($readers)) . ' and /_dev/ui renders none of them');
        }
    }

    public function test_the_page_declares_data_driven_properties_and_reads_no_token_inline(): void
    {
        // Twig comments reach nobody — and this page's own docblock quotes the swatch
        // it retired, which is how a comment ought to describe a removal.
        $tpl = (string) preg_replace('/\{#.*?#\}/s', '',
            (string) file_get_contents(self::ROOT . 'templates/pages/dev-ui.twig'));
        preg_match_all('~\bstyle="([^"]*)"~', $tpl, $m);
        $bad = [];
        foreach ($m[1] as $attr) {
            foreach (array_filter(array_map('trim', explode(';', $attr))) as $decl) {
                // `--ag-dot:var(--ag-live)` declares a property the component reads. Anything
                // else — `width:var(--ag-sp-36)` — is the page drawing a swatch to read a token.
                if (!preg_match('~^--[a-z0-9-]+\s*:~', $decl)) $bad[] = $decl;
            }
        }
        $this->assertSame([], $bad, 'an inline style on /_dev/ui that is not a custom-property declaration: '
            . implode('; ', $bad));
        foreach (self::ONCE_UNREAD as $tok) {
            $this->assertStringNotContainsString($tok, $tpl, "/_dev/ui names {$tok} itself — a manufactured reader");
        }
        $this->assertDoesNotMatchRegularExpression('~<style\b~', $tpl,
            'the page furniture is public/assets/css/dev-ui.css, not a <style> block');
    }

    // ── every component, every state ─────────────────────────────────────────

    public function test_every_component_is_shown_in_every_state(): void
    {
        $body = (string) $this->render()->getBody();

        $need = [
            // components
            'ag-btn--primary', 'ag-btn--secondary', 'ag-btn--text', 'ag-btn--ink', 'ag-btn--danger',
            'ag-btn--icon', 'ag-chip', 'ag-chip-row', 'ag-sticky', 'ag-cs__row', 'ag-cs__btn',
            'ag-field', 'ag-field--search', 'ag-label', 'ag-hint', 'ag-err', 'ag-card', 'ag-list',
            'ag-list__row', 'ag-icon-tile', 'ag-scrim', 'ag-sheet', 'ag-sheet__grabber', 'ag-sheet__head',
            'ag-switch', 'ag-seg', 'ag-skel', 'ag-empty', 'ag-stock--low', 'ag-stock--gone', 'ag-badge',
            // states
            'is-hover', 'is-focus', 'is-active', ' disabled', 'aria-pressed="true"', 'aria-checked="true"',
            'aria-checked="false"', 'data-invalid', 'aria-invalid="true"', 'aria-busy="true"',
            'aria-disabled="true"', 'data-cs',
        ];
        $missing = array_values(array_filter($need, static fn (string $n): bool => !str_contains($body, $n)));
        $this->assertSame([], $missing, '/_dev/ui is missing: ' . implode(', ', $missing));

        // And each component in EACH of its states, read per element: a state shown on
        // one component does not count for another — a page with a hovered chip and no
        // hovered button has not shown a hovered button.
        $states = [
            'ag-btn--primary'   => ['is-hover', 'is-focus', 'is-active', 'disabled', 'aria-busy="true"'],
            'ag-btn--secondary' => ['is-hover', 'is-focus', 'is-active', 'disabled', 'aria-busy="true"'],
            'ag-btn--ink'       => ['is-hover', 'is-focus', 'is-active', 'disabled', 'aria-busy="true"'],
            'ag-btn--text'      => ['is-hover', 'is-focus', 'is-active', 'disabled', 'aria-busy="true"'],
            'ag-btn--danger'    => ['is-hover', 'is-focus', 'is-active', 'disabled', 'aria-busy="true"'],
            'ag-btn--icon'      => ['is-focus', 'is-active', 'disabled'],
            'ag-chip'           => ['is-hover', 'is-focus', 'is-active', 'aria-pressed="true"', 'aria-pressed="false"', 'disabled'],
            'ag-field'          => ['is-focus', 'data-invalid'],
            'ag-card'           => ['is-hover', 'is-focus'],
            'ag-list__row'      => ['is-hover', 'is-focus'],
            'ag-switch'         => ['aria-checked="true"', 'aria-checked="false"', 'is-focus', 'disabled'],
            'ag-seg__opt'       => ['aria-checked="true"', 'aria-checked="false"', 'is-focus', 'disabled'],
        ];
        $gaps = [];
        foreach ($states as $cls => $want) {
            preg_match_all('~<[a-z]+\b[^>]*\bclass="[^"]*(?<![\w-])' . preg_quote($cls, '~') . '(?![\w-])[^"]*"[^>]*>~', $body, $tags);
            foreach ($want as $state) {
                $hit = false;
                foreach ($tags[0] as $tag) {
                    if (preg_match('~(?<![\w-])' . preg_quote($state, '~') . '(?![\w-])~', $tag)) { $hit = true; break; }
                }
                if (!$hit) $gaps[] = "{$cls} · {$state}";
            }
        }
        $this->assertMatchesRegularExpression('~<input\b[^>]*\bdisabled\b~', $body, 'a disabled field');
        $this->assertSame([], $gaps, '/_dev/ui does not show: ' . implode(', ', $gaps));

        // §9.4: errors are announced, and the empty state is one sentence and one action.
        $this->assertMatchesRegularExpression('~class="ag-err"[^>]*aria-live="polite"~', $body);
        $this->assertStringContainsString('We couldn’t reach Africa GATES. Your details are saved. Try again.', $body);
        $this->assertMatchesRegularExpression('~class="ag-empty"[^>]*role="status"~', $body);
    }

    public function test_the_route_is_not_there_in_production(): void
    {
        $this->assertSame(200, $this->render('development')->getStatusCode());
        $this->assertSame(404, $this->render('production')->getStatusCode());
    }
}
