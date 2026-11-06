<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Qr;
use AfricaGates\Support\Translator;

/**
 * A nominee's share kit: the ready-made messages and the QR code that take a vote link OFF this
 * site — to WhatsApp, a status, an SMS, a printed poster.
 *
 * The owner's position (27 Oct 2026): the site cannot carry every nominee, and no nominee may
 * buy a place on it while votes are being cast (D15). So promotion happens where each nominee's
 * own people already are, and the platform's part is to equip every nominee identically (D32):
 * the same messages, the same formats, the same QR code, built from the same facts. Nothing here
 * varies by standing, money or who is asking — NomineeShareKitTest holds that the kit for one
 * nominee differs from another's only by their own name, category, award and link.
 *
 * Every message is a URL a phone already knows how to open (wa.me, sms:, the share intents), so
 * the kit works on the cheapest handset with nothing installed, and nothing is sent by us.
 */
final class ShareKit
{
    /**
     * @param array{name:string,category:string,programme:string,url:string} $f the flier's facts
     * @param string $link the link to share — the nominee's own, or a supporter's `?via=` link
     * @return array{text:string, links:list<array{key:string,label:string,href:string}>, qr:?string, link:string}
     */
    public static function for(array $f, string $link = ''): array
    {
        $link = $link !== '' ? $link : (string) $f['url'];
        $text = Translator::t('Please vote for %n% in %c%, %a%. It takes a minute: %u%', [
            '%n%' => (string) $f['name'], '%c%' => (string) $f['category'], '%a%' => (string) $f['programme'], '%u%' => $link,
        ]);
        $short = Translator::t('Vote for %n% in %c%', ['%n%' => (string) $f['name'], '%c%' => (string) $f['category']]);
        $enc = rawurlencode($text);
        return [
            'text'  => $text,
            'link'  => $link,
            'links' => [
                ['key' => 'whatsapp', 'label' => 'WhatsApp', 'href' => 'https://wa.me/?text=' . $enc],
                // `?&body=` is the form both iOS and Android accept.
                ['key' => 'sms',      'label' => 'SMS',      'href' => 'sms:?&body=' . $enc],
                ['key' => 'telegram', 'label' => 'Telegram', 'href' => 'https://t.me/share/url?url=' . rawurlencode($link) . '&text=' . rawurlencode($short)],
                ['key' => 'x',        'label' => 'X',        'href' => 'https://x.com/intent/post?text=' . $enc],
                ['key' => 'facebook', 'label' => 'Facebook', 'href' => 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($link)],
            ],
            'qr'    => self::qr($link),
        ];
    }

    /**
     * The QR code for a printed poster, as SVG markup — or null when the link is too long to
     * encode (Qr::encodeBytes carries 74 bytes). Byte mode, case kept: an uppercased URL path
     * scans perfectly and goes nowhere (CLAUDE.md, Support\Qr).
     */
    public static function qr(string $link): ?string
    {
        if ($link === '' || strlen($link) > 74) return null;
        try {
            return Qr::svgBytes($link, 6);
        } catch (\Throwable) {
            return null;
        }
    }
}
