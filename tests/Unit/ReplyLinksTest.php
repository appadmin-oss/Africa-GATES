<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\ReplyLinks;
use PHPUnit\Framework\TestCase;

/**
 * A live Gee answer linked every result to `https://africagates.com/…` (owner, 13 Oct 2026:
 * "It's not my domain. The response is faulty"). The model invented the host from the brand;
 * the widget then drew the links as raw brackets because it renders site paths only. Held
 * here on the owner's own pasted reply: our host and any guess at it become paths, and no
 * other host is ever left as a link.
 */
final class ReplyLinksTest extends TestCase
{
    private const OWNERS_REPLY = "- Academic Excellence (Alimosho Incredible Principal Awards): Ajayi Temitope Oluwarotimi. "
        . "[View Results](https://africagates.com/results/1-academic-excellence)\n"
        . "- Teachers' Choice (Alimosho Incorruptible Child Award): Awe-Olola Champion Victoria. "
        . "[View Results](https://africagates.com/results/6-teachers-choice)";

    public function test_the_owners_reply_links_to_paths_on_this_site(): void
    {
        $out = ReplyLinks::clean(self::OWNERS_REPLY, 'afg.afrovanguard.org.ng');
        $this->assertStringContainsString('[View Results](/results/1-academic-excellence)', $out);
        $this->assertStringContainsString('[View Results](/results/6-teachers-choice)', $out);
        $this->assertStringNotContainsString('africagates.com', $out);
        // And the widget's own link pattern (gee.js MD_LINK) draws them.
        $this->assertSame(2, preg_match_all('~\[([^\]\n]{1,80})\]\((/[a-z0-9/-]{1,80})\)~', $out));
    }

    public function test_every_guess_at_our_name_is_ours_and_our_real_host_is_kept_as_a_path(): void
    {
        foreach (['https://www.africa-gates.org/vote', 'http://africangates.ng/vote', 'https://afg.afrovanguard.org.ng/vote',
                  'www.africagates.com/vote'] as $u) {
            $this->assertSame('Go to /vote now.', ReplyLinks::clean("Go to $u now.", 'afg.afrovanguard.org.ng'), $u);
        }
    }

    public function test_no_other_website_is_ever_left_as_a_link(): void
    {
        $out = ReplyLinks::clean('See [the guide](https://evil.example/phish) for more.', 'afg.afrovanguard.org.ng');
        $this->assertSame('See the guide (evil.example) for more.', $out);
        $this->assertSame('Plain text stays exactly as it was.', ReplyLinks::clean('Plain text stays exactly as it was.', 'x.test'));
        $this->assertSame('[Vote](/vote)', ReplyLinks::clean('[Vote](/vote)', 'x.test'), 'a path is already right');
    }

    public function test_both_front_doors_clean_every_reply_and_both_prompts_forbid_a_domain(): void
    {
        $root = dirname(__DIR__, 2);
        $guide = (string) file_get_contents("$root/src/Controllers/GuideController.php");
        $this->assertSame(0, preg_match("~'reply'\\s*=>\\s*\\\$(?:out|r)\\['reply'\\]~", $guide), 'every Gee reply is cleaned');
        $this->assertStringContainsString('ReplyLinks::clean((string) $r[\'reply\'])',
            (string) file_get_contents("$root/src/Controllers/SupportController.php"));
        $this->assertStringContainsString('never write http://, https://, www. or any domain name',
            (string) file_get_contents("$root/src/Services/GuideService.php"));
        $this->assertStringContainsString('Never add a domain, http:// or www.',
            (string) file_get_contents("$root/src/Services/SupportAgentService.php"));
    }
}
