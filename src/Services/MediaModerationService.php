<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

/**
 * Looking at a picture before the world does.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * WHAT THIS DOES AND — MORE IMPORTANTLY — WHAT IT DOES NOT
 * ══════════════════════════════════════════════════════════════════════════
 *
 * IMAGES are sent to a vision model, which returns a verdict. That verdict
 * decides whether the post is published or held. It is a genuine check and it
 * catches the obvious cases, which on a platform whose audience includes
 * children is most of what actually gets uploaded in bad faith.
 *
 * VIDEO IS NOT INSPECTED AT ALL. It cannot be: reading a frame out of an MP4
 * needs ffmpeg, and the target shared host has none. Every honest option was
 * bad, so the choice is the one that fails safe — video from a member is HELD
 * for a human by default. Saying "video is moderated" while sampling nothing
 * would be the actual failure here, so the code does not say it and neither
 * should the interface.
 *
 * WHEN THE MODEL IS UNREACHABLE, CONTENT IS HELD, NOT PUBLISHED. An outage, an
 * expired key, a rate limit — none of them are evidence that a picture is fine.
 * `open` on failure is the setting that turns one bad afternoon into a photo on
 * the front of the site, so the default here is the other one. An operator who
 * would rather trade that risk for throughput can set MEDIA_MODERATION=off, and
 * that is a decision with a name and a place to look.
 */
final class MediaModerationService
{
    /** Above this the image is refused outright; between REVIEW and this it is held. */
    private const REJECT_AT = 0.80;
    private const REVIEW_AT = 0.35;

    private const TIMEOUT = 20;

    /** Bytes we will send to a vision model. Beyond this the image is downscaled. */
    private const MAX_INLINE = 4 * 1024 * 1024;

    public function __construct(private readonly ?ClientInterface $http = null) {}

    /**
     * Is media moderation switched on?
     *
     * Defaults to ON. An operator has to write MEDIA_MODERATION=off to turn it
     * off, which is a deliberate act rather than an omission — the opposite
     * default means a fresh install quietly publishes anything.
     */
    public static function enabled(): bool
    {
        return strtolower(trim((string) Env::get('MEDIA_MODERATION', 'on'))) !== 'off';
    }

    private static function key(): string
    {
        // Same resolution order AiService uses, so one configured key powers both.
        try {
            $s = (new \AfricaGates\Admin\Services\SettingsService())->all();
            $k = trim((string) ($s['ai_gemini_key'] ?? ''));
            if ($k !== '') return $k;
        } catch (\Throwable) { /* settings table absent on a fresh install */ }
        return trim((string) Env::get('GEMINI_API_KEY', ''));
    }

    private static function model(): string
    {
        $m = trim((string) Env::get('GEMINI_VISION_MODEL', ''));
        return $m !== '' ? $m : 'gemini-3.6-flash';
    }

    /**
     * Judge one file.
     *
     * @param string $type 'image' or 'video'
     * @return array{verdict:string, score:float, reason:string, checked:bool}
     *         verdict ∈ {approved, review, rejected}
     */
    public function check(string $absPath, string $type, string $mime): array
    {
        if (!self::enabled()) {
            return ['verdict' => 'approved', 'score' => 0.0,
                    'reason' => 'Media moderation is switched off.', 'checked' => false];
        }

        // Video: held, and honest about why. See the class note.
        if ($type !== 'image') {
            return ['verdict' => 'review', 'score' => 0.5, 'checked' => false,
                    'reason' => 'Video cannot be inspected automatically on this host — held for a moderator.'];
        }

        $key = self::key();
        if ($key === '') {
            return ['verdict' => 'review', 'score' => 0.5, 'checked' => false,
                    'reason' => 'No vision model is configured — held for a moderator.'];
        }

        $inline = $this->inlineBytes($absPath, $mime);
        if ($inline === null) {
            return ['verdict' => 'review', 'score' => 0.5, 'checked' => false,
                    'reason' => 'The image could not be read for checking — held for a moderator.'];
        }

        $prompt = <<<'P'
        You are moderating a photo uploaded to a community feed on an African
        cultural-awards platform. Children and schools are part of the audience
        and appear in legitimate photos: a classroom, a choir, a prize-giving, a
        child holding a project are all NORMAL and must score low.

        Score 0.0 to 1.0 for how UNSAFE this image is to publish:
          0.0-0.2  ordinary: people, events, places, screenshots, documents, art
          0.3-0.6  questionable: unclear context, possible personal data on screen,
                   graphic injury, alcohol or gambling promotion
          0.7-1.0  must not publish: sexual content, nudity, any sexualisation of a
                   minor, gore, violence against a person, hate symbols, weapons
                   aimed at people, identity documents, or credentials/card numbers

        Reply with ONLY: {"score": 0.NN, "reason": "under 12 words", "labels": ["..."]}
        P;

        try {
            $client = $this->http ?? new Client(['timeout' => self::TIMEOUT]);
            $res = $client->post(
                'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode(self::model())
                . ':generateContent?key=' . urlencode($key),
                [
                    'json' => [
                        'contents' => [[
                            'parts' => [
                                ['text' => $prompt],
                                ['inline_data' => ['mime_type' => $inline['mime'], 'data' => $inline['b64']]],
                            ],
                        ]],
                        'generationConfig' => [
                            'temperature' => 0.0,
                            'maxOutputTokens' => 200,
                            'responseMimeType' => 'application/json',
                        ],
                        // Ask the provider not to refuse outright — a refusal
                        // gives us no score, and "the model would not answer" is
                        // not the same signal as "the picture is fine".
                        'safetySettings' => array_map(
                            static fn($c) => ['category' => $c, 'threshold' => 'BLOCK_NONE'],
                            ['HARM_CATEGORY_HARASSMENT', 'HARM_CATEGORY_HATE_SPEECH',
                             'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'HARM_CATEGORY_DANGEROUS_CONTENT']
                        ),
                    ],
                    'timeout' => self::TIMEOUT, 'http_errors' => false,
                ]
            );

            if ($res->getStatusCode() >= 300) {
                return $this->held('The checking service returned HTTP ' . $res->getStatusCode() . '.');
            }

            $j = json_decode((string) $res->getBody(), true);
            $text = $j['candidates'][0]['content']['parts'][0]['text'] ?? '';

            // A provider-side block is itself a signal, and a strong one.
            $blocked = ($j['candidates'][0]['finishReason'] ?? '') === 'SAFETY'
                    || isset($j['promptFeedback']['blockReason']);
            if ($blocked) {
                return ['verdict' => 'rejected', 'score' => 1.0, 'checked' => true,
                        'reason' => 'The checking service refused to process this image.'];
            }

            $v = json_decode(is_string($text) ? $text : '', true);
            if (!is_array($v) || !isset($v['score'])) {
                return $this->held('The checking service gave no usable verdict.');
            }

            $score  = max(0.0, min(1.0, (float) $v['score']));
            $reason = mb_substr(trim((string) ($v['reason'] ?? '')), 0, 160);
            $labels = is_array($v['labels'] ?? null) ? array_slice($v['labels'], 0, 4) : [];
            if ($labels) $reason = trim($reason . ' [' . implode(', ', array_map('strval', $labels)) . ']');

            $verdict = $score >= self::REJECT_AT ? 'rejected'
                     : ($score >= self::REVIEW_AT ? 'review' : 'approved');

            return ['verdict' => $verdict, 'score' => $score,
                    'reason' => $reason !== '' ? $reason : 'Checked.', 'checked' => true];
        } catch (\Throwable $e) {
            error_log('[media-moderation] check failed: ' . $e->getMessage());
            return $this->held('The checking service was unreachable.');
        }
    }

    /** Unreachable, unreadable, unparseable — all mean HELD, never published. */
    private function held(string $why): array
    {
        return ['verdict' => 'review', 'score' => 0.5, 'checked' => false,
                'reason' => $why . ' Held for a moderator.'];
    }

    /**
     * The bytes to send, downscaled if the original is large.
     *
     * A 12MP phone photo is several megabytes of mostly redundant detail; a
     * 1024px version carries every signal a safety classifier needs and costs a
     * fraction of the request. If GD is unavailable the original is sent when it
     * fits, and the file is held when it does not — sending nothing and calling
     * it approved is the one outcome to avoid.
     *
     * @return array{mime:string, b64:string}|null
     */
    private function inlineBytes(string $absPath, string $mime): ?array
    {
        $size = @filesize($absPath) ?: 0;
        if ($size > 0 && $size <= self::MAX_INLINE) {
            $raw = @file_get_contents($absPath);
            if ($raw !== false) return ['mime' => $mime, 'b64' => base64_encode($raw)];
        }

        if (!function_exists('imagecreatefromstring')) return null;
        $raw = @file_get_contents($absPath);
        if ($raw === false) return null;

        $im = @imagecreatefromstring($raw);
        if ($im === false) return null;

        $w = imagesx($im); $h = imagesy($im);
        $scale = 1024 / max($w, $h, 1);
        if ($scale < 1) {
            $small = imagescale($im, (int) round($w * $scale), (int) round($h * $scale));
            if ($small !== false) { imagedestroy($im); $im = $small; }
        }

        ob_start();
        imagejpeg($im, null, 82);
        $out = (string) ob_get_clean();
        imagedestroy($im);

        return $out !== '' ? ['mime' => 'image/jpeg', 'b64' => base64_encode($out)] : null;
    }
}
