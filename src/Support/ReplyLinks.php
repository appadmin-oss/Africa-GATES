<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * THE LINKS IN AN ASSISTANT'S REPLY, MADE TRUE BEFORE ANYBODY SEES THEM.
 *
 * Owner, 13 Oct 2026, pasting a live answer from Gee: every result link read
 * `[View Results](https://africagates.com/results/1-academic-excellence)` — "it's not my
 * domain, the response is faulty". The tools hand the model PATHS (`/results/1-…`); the
 * model, knowing the brand and not the host, made them absolute with a domain it invented.
 * Two faults in one: a link to somebody else's website, sent to the platform's own
 * supporters in its own voice, and a link the widget could not draw (it renders site paths
 * only), so it showed as raw brackets.
 *
 * The prompt now says so. This is the half that does not depend on a model obeying it:
 *   · a link to THIS site, or to any host that is a guess at it (the brand name in any
 *     spelling), becomes the bare path — which the widget draws as a link;
 *   · a link to anywhere else becomes its label and the host in plain words. A model must
 *     never be able to put a clickable off-site link in front of somebody: an invented
 *     host is at best a dead end and at worst somebody else's page.
 * Runs on every reply from both front doors (GuideController, SupportController).
 */
final class ReplyLinks
{
    /** A host that is a guess at ours: the brand in any of its spellings. */
    private const GUESSED = '~(^|\.)(africa-?gates|africangates|africag8|african-?g8)\.~i';

    public static function clean(string $reply, ?string $ownHost = null): string
    {
        if ($reply === '' || stripos($reply, 'http') === false && stripos($reply, 'www.') === false) return $reply;
        $own = strtolower((string) ($ownHost ?? parse_url(SiteUrl::base(), PHP_URL_HOST) ?? ''));

        $local = static function (string $url) use ($own): ?string {
            $u = preg_match('~^https?://~i', $url) ? $url : 'https://' . $url;
            $p = parse_url($u);
            if (!is_array($p) || empty($p['host'])) return null;
            $host = strtolower((string) $p['host']);
            if (($own !== '' && ($host === $own || $host === 'www.' . $own)) || preg_match(self::GUESSED, $host)) {
                $path = (string) ($p['path'] ?? '/');
                return ($path === '' ? '/' : $path) . (isset($p['query']) ? '?' . $p['query'] : '');
            }
            return null;
        };

        // [label](https://host/path) → [label](/path), or "label (host)"
        $reply = (string) preg_replace_callback('~\[([^\]\n]{1,120})\]\(\s*((?:https?://|www\.)[^)\s]+)\s*\)~i',
            static function (array $m) use ($local): string {
                $path = $local($m[2]);
                if ($path !== null) return '[' . $m[1] . '](' . $path . ')';
                $host = (string) parse_url(preg_match('~^https?://~i', $m[2]) ? $m[2] : 'https://' . $m[2], PHP_URL_HOST);
                return $m[1] . ($host !== '' ? ' (' . $host . ')' : '');
            }, $reply);

        // A bare URL: ours becomes the path; anybody else's is left as words, unlinked.
        return (string) preg_replace_callback('~(?<![\w(/])((?:https?://|www\.)[^\s)<>\]]+)~i',
            static function (array $m) use ($local): string {
                $trail = '';
                $url = $m[1];
                if (preg_match('~[.,;:!?]+$~', $url, $t)) { $trail = $t[0]; $url = substr($url, 0, -strlen($trail)); }
                $path = $local($url);
                return ($path ?? $url) . $trail;
            }, $reply);
    }
}
