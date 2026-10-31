<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Services\AiService;
use AfricaGates\Support\AwardWording;
use Illuminate\Database\Capsule\Manager as DB;
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

        $r = (new \AfricaGates\Services\AiGateway())->run('admin.content_assist', [
            'system'      => $system,
            'user'        => $user,
            'temperature' => 0.4,
            'schema'      => static function (string $raw): ?string {
                // Strip any stray code fences the model may wrap around HTML.
                $html = trim((string) preg_replace('/^```[a-z]*\s*|\s*```$/i', '', trim($raw)));
                return $html === '' ? null : $html;
            },
        ]);
        if (!$r->ok) {
            return $this->json($res, ['ok' => false, 'error' => self::reasonFor($r)], $r->code === 'NO_PROVIDER' ? 503 : 502);
        }
        // NOTE: this HTML is stored and later rendered through |sanitize_html,
        // which is the allow-list that makes model-authored markup safe.
        return $this->json($res, ['ok' => true, 'html' => $r->value]);
    }

    /**
     * Draft the seven phrases one award's nomination form uses about its own people.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY A MODEL IS WORTH ASKING HERE, AND WHAT IT MAY NOT DECIDE
     * ══════════════════════════════════════════════════════════════════════════
     *
     * The question this answers is "what noun does this award use?" — principal,
     * public servant, choir — and the award already states the answer in its own
     * title, subtitle and description. That is a rewriting job, not a judgement, and
     * it is the whole reason an operator would otherwise leave the house wording in
     * place: writing seven phrases from scratch for each of a dozen awards is the
     * kind of work that simply does not get done, and the cost of not doing it is a
     * choirmaster being asked for "the nominee's full name".
     *
     * It decides nothing. The reply is a DRAFT in the form's boxes; the operator
     * reads it, edits it, and presses Save, and {@see AwardWording::save()} applies
     * the same caps it applies to anything typed. Nothing is stored by this route.
     *
     * ── THE SCHEMA IS THE FIELD TABLE, NOT A SECOND LIST ────────────────────
     *
     * `AwardWording::FIELDS` drives the allowed keys and every cap, so a field added
     * there is drafted here with no edit — and a model inventing an eighth phrase has
     * it dropped rather than shown. A separate list of keys in this file would be the
     * second copy of a rule this codebase keeps paying for.
     *
     * A reply that carries not one usable phrase is discarded whole: a form that
     * fills two boxes and leaves five looks like the operator half-finished it.
     *
     * JSON in:  {programme_id}
     * Out:      {ok, wording:{…}} — only keys in FIELDS, each already within its cap.
     */
    public function awardWording(Request $req, Response $res): Response
    {
        if (!$this->budgetOk()) {
            return $this->json($res, ['ok' => false, 'error' => 'Hourly AI budget reached for your role — try again shortly.'], 429);
        }

        $id  = (int) (((array) $req->getParsedBody())['programme_id'] ?? 0);
        $row = $id > 0 ? DB::table('gates_award_programmes')->where('id', $id)->first() : null;
        if (!$row) {
            return $this->json($res, ['ok' => false, 'error' => 'That award could not be found.'], 404);
        }

        // What the model is given is the award describing itself — nothing else. The
        // fence is the gateway's; this is only the content inside it.
        $about = "Title: " . (string) ($row->title ?? '')
               . "\nSubtitle: " . (string) ($row->subtitle ?? '')
               . "\nDescription: " . mb_substr(trim((string) ($row->description ?? '')), 0, 1200);

        $rules = [];
        foreach (AwardWording::FIELDS as $key => $f) {
            $rules[] = '"' . $key . '" — ' . $f['label'] . '. ' . $f['help']
                     . ' At most ' . $f['max'] . ' characters.';
        }

        $system = 'You choose the words one awards programme uses on its own nomination form, for '
            . 'Africa GATES, a continental awards platform. Reply with ONLY a JSON object whose keys are '
            . 'exactly these and whose values are plain strings:' . "\n" . implode("\n", $rules) . "\n"
            . 'Write in the register the award itself uses. Use British English. Keep "reason_question" to one '
            . 'question containing the literal placeholders {name} and {category}. Invent no eligibility rule, '
            . 'no deadline and no figure that the award has not stated. No markdown, no prose, JSON only.';

        $r = (new \AfricaGates\Services\AiGateway())->run('admin.award_wording', [
            'system'      => $system,
            'user'        => $about,
            'json'        => true,
            'temperature' => 0.3,
            'schema'      => static function (string $raw): ?array {
                $data = json_decode($raw, true);
                if (!is_array($data)) return null;

                $out = [];
                foreach (AwardWording::FIELDS as $key => $f) {
                    $v = $data[$key] ?? null;
                    if (!is_scalar($v)) continue;
                    $v = trim((string) $v);
                    if ($v === '') continue;
                    // Capped HERE rather than trusted, so an over-long phrase arrives
                    // as a usable draft instead of a refusal the operator cannot act
                    // on. `save()` applies the same ceiling to whatever they keep.
                    $out[$key] = mb_substr($v, 0, $f['max']);
                }

                // The reason question is the one field with a mechanism in it: without
                // both placeholders the sentence renders as itself on every category,
                // which reads as the form being broken rather than as a wording choice.
                if (isset($out['reason_question'])
                    && !(str_contains($out['reason_question'], '{name}')
                      && str_contains($out['reason_question'], '{category}'))) {
                    unset($out['reason_question']);
                }

                return $out === [] ? null : $out;
            },
        ]);

        if (!$r->ok) {
            return $this->json($res, ['ok' => false, 'error' => $r->code === 'SCHEMA_REJECTED'
                ? 'Nothing usable came back — try again, or write the words yourself.'
                : self::reasonFor($r)], $r->code === 'NO_PROVIDER' ? 503 : 502);
        }

        return $this->json($res, ['ok' => true, 'wording' => $r->value]);
    }

    /**
     * Generate a form field schema from a plain-English description.
     * JSON in: {prompt}
     * Out: {ok, fields:[{type,label,required,placeholder,help,options[]}]}
     */
    /**
     * Turn a gateway refusal into something an operator can act on. Previously
     * every non-answer collapsed into one generic 502, so "no key", "switched
     * off" and "over budget" were indistinguishable.
     */
    private static function reasonFor(\AfricaGates\Services\AiResult $r): string
    {
        return match ($r->code) {
            'NO_PROVIDER'         => 'No AI provider is configured. A superadmin can add a key (Groq is free) under Settings → AI providers.',
            'DISABLED_GLOBAL'     => 'AI is switched off for this platform (Settings → ai_enabled).',
            'DISABLED_CAPABILITY' => 'This AI feature is switched off in Settings.',
            'BUDGET_CALLS',
            'BUDGET_TOKENS'       => 'This feature has reached today\'s AI budget. It resets at midnight.',
            default               => 'The AI provider did not return anything — try again.',
        };
    }

    public function formFields(Request $req, Response $res): Response
    {
        if (!$this->budgetOk()) {
            return $this->json($res, ['ok' => false, 'error' => 'Hourly AI budget reached for your role — try again shortly.'], 429);
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
        // The whole field-shaping pass IS the schema: types come from a fixed
        // allow-list, labels are required, options only on the types that take
        // them, and the count is capped. An unusable reply is discarded.
        $r = (new \AfricaGates\Services\AiGateway())->run('admin.form_design', [
            'system'      => $system,
            'user'        => $prompt,
            'json'        => true,
            'temperature' => 0.3,
            'schema'      => static function (string $raw): ?array {
                $data = json_decode($raw, true);
                if (!is_array($data) || !isset($data['fields']) || !is_array($data['fields'])) return null;
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
                return $fields === [] ? null : $fields;
            },
        ]);
        if (!$r->ok) {
            return $this->json($res, ['ok' => false, 'error' => $r->code === 'SCHEMA_REJECTED'
                ? 'Could not generate usable fields — try rephrasing.'
                : self::reasonFor($r)], $r->code === 'NO_PROVIDER' ? 503 : 502);
        }
        $fields = $r->value;
        if ($fields === []) {
            return $this->json($res, ['ok' => false, 'error' => 'No usable fields came back — try rephrasing.'], 502);
        }
        return $this->json($res, ['ok' => true, 'fields' => $fields]);
    }
}
