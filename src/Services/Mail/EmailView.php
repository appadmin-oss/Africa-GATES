<?php
declare(strict_types=1);

namespace AfricaGates\Services\Mail;

use AfricaGates\Support\Accent;
use AfricaGates\Support\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The one Twig environment the email templates render in.
 *
 * The shell (`emails/_shell.twig`), the block macros (`emails/_blocks.twig`) and every body
 * built on them read their colours as `c.*`. A Twig MACRO cannot see the template that
 * called it, so a palette passed as a render variable never reaches a block — it has to be
 * a GLOBAL of the environment, which is what this is for. Without it every macro would take
 * the palette as an argument, and the first one somebody forgot would render
 * `color:;` — an empty declaration, which MailPaletteTest fails on and an inbox paints black.
 *
 * Bare, and wrapped in Translator::register(): an unknown filter is a compile error, so the
 * first `|trans` in a shared partial would otherwise break every mailer that renders it,
 * from a cron tick whose catch swallows it (CLAUDE.md, "|trans is the same trap, louder").
 * No app globals — mail has no request, no session and no CSRF token.
 */
final class EmailView
{
    private static ?Environment $env = null;

    public static function env(): Environment
    {
        if (self::$env === null) {
            self::$env = Translator::register(new Environment(new FilesystemLoader(\dirname(__DIR__, 3) . '/templates'), [
                'autoescape' => 'html', 'strict_variables' => false, 'cache' => false,
            ]));
            self::$env->addGlobal('c', Accent::mail());
        }
        return self::$env;
    }

    /** @param array<string,mixed> $vars */
    public static function render(string $template, array $vars = []): string
    {
        return self::env()->render($template, $vars);
    }
}
