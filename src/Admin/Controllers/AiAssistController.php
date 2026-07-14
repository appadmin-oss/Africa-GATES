<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Services\AiService;
use AfricaGates\Services\RateLimitService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Shared admin AI helpers used across the console — terms/legal drafting, prose
 * polishing, and form-schema generation. Content roles only (the auth
 * middleware already blocks read-only roles from these POSTs); rate-limited per
 * admin; LOUD when no provider is configured (operators must know AI is off,
 * never get a silent no-op).
 */
final class AiAssistController
{
    private const BUDGET_PER_HOUR = 40;

    public function __construct(private readonly ?RateLimitService $rateLimit = null) {}

    private function json(Response $res, array $data, int $status = 200): Response
    {
        $res->getBody()->write((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $res->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function budgetOk(): bool
    {
        if (($_SESSION['admin_role'] ?? '') === 'superadmin' || !$this->rateLimit) return true;
        return $this->rateLimit->check('admin:' . (int) ($_SESSION['admin_id'] ?? 0), 'admin_ai_assist', self::BUDGET_PER_HOUR, 3600);
    }

    /**
     * Draft or improve rich-text content (terms, policies, descriptions).
     * JSON in: {mode:'draft'|'improve', prompt?, current?, kind?}
     * Out: {ok, html} — sanitized-friendly HTML (h2/h3/p/ul/li/strong/em/a only).
     */
    public function assist(Request $req, Response $res): Response
    {
        if (!$this->budgetOk()) {
            return $this->json($res, ['ok' => false, 'error' => 'Hourly AI budget reached for your role — try again shortly.'], 429);
        }
        $ai = AiService::boot();
        if (!$ai->configured()) {
            return $this->json($res, ['ok' => false, 'error' => 'No AI provider is configured. A superadmin can add a key (Groq is free) under Settings → AI providers.'], 503);
        }

        $b       = (array) $req->getParsedBody();
        $mode    = ($b['mode'] ?? 'draft') === 'improve' ? 'improve' : 'draft';
        $kind    = mb_substr(trim((string) ($b['kind'] ?? 'document')), 0, 60);
        $prompt  = mb_substr(trim((string) ($b['prompt'] ?? '')), 0, 2000);
        $current = mb_substr(trim((string) ($b['current'] ?? '')), 0, 8000);

        if ($mode === 'improve' && $current === '') {
            return $this->json($res, ['ok' => false, 'error' => 'Nothing to improve yet — write a draft first.'], 422);
        }
        if ($mode === 'draft' && $prompt === '') {
            return $this->json($res, ['ok' => false, 'error' => 'Describe what you want drafted.'], 422);
        }

        $system = 'You write clear, plain-language ' . $kind . ' content for Africa GATES, a continental cultural-awards platform. '
            . 'Output ONLY clean semantic HTML using these tags: <h2>, <h3>, <p>, <ul>, <li>, <strong>, <em>, <a href>. '
            . 'No <html>/<body>, no markdown, no inline styles, no scripts. Use <h2> for section headings. Keep it accurate and neutral; '
            . 'do not invent legal guarantees, dates, or figures. This is a draft an editor will review — not legal advice.';
        $user = $mode === 'improve'
            ? "Improve the clarity, structure and tone of this " . $kind . " while preserving its meaning. Return the full improved HTML:\n\n" . $current
            : "Draft a " . $kind . " based on this brief:\n\n" . $prompt . ($current !== '' ? "\n\nExisting content to build on:\n" . $current : '');

        $html = $ai->complete($system, $user, 1500, false, 0.4);
        if ($html === null || trim($html) === '') {
            return $this->json($res, ['ok' => false, 'error' => 'The AI provider did not return anything — try again.'], 502);
        }
        // Strip any stray code fences the model may wrap around HTML.
        $html = trim(preg_replace('/^```[a-z]*\s*|\s*```$/i', '', trim($html)) ?? $html);
        return $this->json($res, ['ok' => true, 'html' => $html]);
    }

    /**
     * Generate a form field schema from a plain-English description.
     * JSON in: {prompt}
     * Out: {ok, fields:[{type,label,required,placeholder,help,options[]}]}
     */
    public function formFields(Request $req, Response $res): Response
    {
        if (!$this->budgetOk()) {
            return $this->json($res, ['ok' => false, 'error' => 'Hourly AI budget reached for your role — try again shortly.'], 429);
        }
        $ai = AiService::boot();
        if (!$ai->configured()) {
            return $this->json($res, ['ok' => false, 'error' => 'No AI provider is configured. Add a key under Settings → AI providers.'], 503);
        }
        $prompt = mb_substr(trim((string) (((array) $req->getParsedBody())['prompt'] ?? '')), 0, 1500);
        if ($prompt === '') {
            return $this->json($res, ['ok' => false, 'error' => 'Describe the form you want.'], 422);
        }

        $allowed = 'text, email, tel, number, textarea, select, radio, checkbox, date, file';
        $system = 'You design web form schemas for Africa GATES. Reply with ONLY a JSON object of the exact shape '
            . '{"fields":[{"type":"...","label":"...","required":true|false,"placeholder":"...","help":"...","options":["..."]}]}. '
            . 'Allowed types: ' . $allowed . '. Use "options" ONLY for select/radio/checkbox (omit or [] otherwise). '
            . 'Keep labels short, 3–12 fields, sensible required flags. No prose, no markdown, JSON only.';
        $raw = $ai->complete($system, $prompt, 900, true, 0.3);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || !isset($data['fields']) || !is_array($data['fields'])) {
            return $this->json($res, ['ok' => false, 'error' => 'Could not generate fields — try rephrasing.'], 502);
        }

        $allowedTypes = ['text','email','tel','number','textarea','select','radio','checkbox','date','file'];
        $fields = [];
        foreach ($data['fields'] as $f) {
            if (!is_array($f)) continue;
            $type = in_array($f['type'] ?? '', $allowedTypes, true) ? $f['type'] : 'text';
            $label = mb_substr(trim((string) ($f['label'] ?? '')), 0, 120);
            if ($label === '') continue;
            $opts = [];
            if (in_array($type, ['select','radio','checkbox'], true) && !empty($f['options']) && is_array($f['options'])) {
                foreach ($f['options'] as $o) { $o = mb_substr(trim((string) $o), 0, 100); if ($o !== '') $opts[] = $o; }
            }
            $fields[] = [
                'type' => $type, 'label' => $label, 'required' => !empty($f['required']),
                'placeholder' => mb_substr(trim((string) ($f['placeholder'] ?? '')), 0, 120),
                'help' => mb_substr(trim((string) ($f['help'] ?? '')), 0, 160),
                'options' => $opts,
            ];
            if (count($fields) >= 20) break;
        }
        if ($fields === []) {
            return $this->json($res, ['ok' => false, 'error' => 'No usable fields came back — try rephrasing.'], 502);
        }
        return $this->json($res, ['ok' => true, 'fields' => $fields]);
    }
}
