<?php
declare(strict_types=1);

namespace AfricaGates\Services\Ops;

use AfricaGates\Services\AiCapability;
use AfricaGates\Services\AiGateway;
use AfricaGates\Services\AiService;
use AfricaGates\Services\SupportAgentService;
use AfricaGates\Services\SupportContext;
use AfricaGates\Support\AiReply;

/**
 * The admin assistant as an agent that RUNS the platform's own checks before it answers.
 *
 * ── WHAT IT REPLACED, AND WHY THAT COST SO MUCH ──────────────────────────────
 *
 * The assistant used to send the whole operational snapshot — pretty-printed JSON, every
 * queue, every cycle, every warning — ahead of EVERY message, whether the question was
 * about payments or about how to word an award, and then answer from that one picture. It
 * could not look anything up it had not been handed, so "did every paid vote land last
 * week?" got a paraphrase of a pending-orders count.
 *
 * Now the prompt is short and fixed (the provider caches it), and the evidence is fetched
 * on demand by running a check from {@see OpsScripts}: the payment triage, the vote-delivery
 * proof, the standings chain, the support queue. The work is done by code that already
 * exists and is already trusted; the model only chooses which to run and reads the result.
 *
 * It can never change anything. Repairs are offered as a button to the scripts page, where a
 * person with the right role runs them with a reason.
 */
final class OpsAgent
{
    private const MAX_ROUNDS = 4;
    public const CAPABILITY = 'admin.agent';

    private const RULES = <<<'TXT'
    You are the Africa GATES admin assistant: an operations copilot inside the admin console of a
    continental awards platform (public voting, judging, nominations, payments, shop, events).

    HOW YOU WORK
    - You have CHECKS: read-only scripts that report on the platform. Run the ones that answer the
      question before you answer it. Never state a count, a status or a failure you did not get
      from a check in this conversation.
    - "What needs attention?" — run ops_snapshot first, then whatever it points at.
    - Payments or votes — payments_triage, votes_proof, payments_reconcile_preview.
    - Something broken — recent_errors, then the check for that area.
    - Two or three checks is usually enough. Do not run one twice.
    - REPAIRS change data and you cannot run them. When one would fix what you found, say which
      and why, and call offer_action with url /admin/assistant/scripts#<key> so the button is in
      front of the operator. If their role cannot run it, say who can.
    - For anything else in the console, offer_action to the page (a path starting /admin).

    HOW YOU ANSWER
    - Lead with what matters, with the numbers the checks returned. Then where to act.
    - Plain sentences, under about 180 words unless asked for depth. No tables.
    - A `critical` schema warning or a failed proof is more urgent than any queue length: say so.
    TXT;

    public function __construct(private readonly AiService $ai) {}

    /**
     * @param list<array{role:string,text?:string,content?:string}> $history
     * @return array{reply:string, ran:list<string>, actions:list<array{label:string,url:string}>}|null
     *         null when no provider could answer — the caller falls back
     */
    public function answer(string $message, array $history, string $role, ?int $adminId): ?array
    {
        if (!$this->ai->configured() || !AiGateway::available(self::CAPABILITY)) return null;
        $cap = AiCapability::find(self::CAPABILITY);
        if ($cap === null) return null;

        $checks  = OpsScripts::forRole($role, OpsScripts::CHECK);
        $repairs = OpsScripts::forRole($role, OpsScripts::REPAIR);
        $tools = [];
        foreach ($checks as $key => $s) {
            $tools[] = ['name' => $key, 'description' => $s['title'] . '. ' . $s['what'],
                        'parameters' => ['type' => 'object', 'properties' => []]];
        }
        $tools[] = ['name' => 'offer_action',
                    'description' => 'Put a button under your answer that opens a console page — a repair on '
                                   . '/admin/assistant/scripts#<key>, or any /admin page. At most two.',
                    'parameters' => ['type' => 'object', 'properties' => [
                        'label' => ['type' => 'string', 'description' => 'the button text, a verb first'],
                        'url'   => ['type' => 'string', 'description' => 'a path starting /admin']]]];

        $repairList = [];
        foreach ($repairs as $key => $s) $repairList[] = '- ' . $key . ': ' . $s['title'] . '. ' . $s['what'];
        $volatile = "The operator's console role is: {$role}.\n"
                  . ($repairList !== [] ? "REPAIRS this role may run from the scripts page:\n" . implode("\n", $repairList)
                                        : 'This role cannot run repairs; an admin or superadmin can.');

        $messages = [['role' => 'system', 'content' => self::RULES], ['role' => 'system', 'content' => $volatile]];
        foreach (array_slice($history, -8) as $h) {
            if (!is_array($h)) continue;
            $t = mb_substr(trim((string) ($h['content'] ?? $h['text'] ?? '')), 0, 1200);
            if ($t === '') continue;
            $r = ($h['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            if ($r === 'assistant' && count($messages) === 2) continue;
            $messages[] = ['role' => $r, 'content' => $t];
        }
        $messages[] = ['role' => 'user', 'content' => mb_substr($message, 0, 2000)];

        $ran = []; $actions = []; $reply = null; $done = [];
        for ($round = 1; $round <= self::MAX_ROUNDS; $round++) {
            $reply = $this->call($messages, $tools, $cap);
            if ($reply === null || !$reply->hasTools()) break;
            $messages[] = $reply->asMessage();
            foreach ($reply->toolCalls as $c) {
                $name = (string) $c['name'];
                if ($name === 'offer_action') {
                    try {
                        $a = SupportContext::offerAction((string) ($c['arguments']['label'] ?? ''), (string) ($c['arguments']['url'] ?? ''), true);
                        if (str_starts_with($a['url'], '/admin') && count($actions) < 2) $actions[$a['url']] = $a;
                        $result = ['ok' => true];
                    } catch (\Throwable) {
                        $result = ['ok' => false, 'error' => 'That is not a console page.'];
                    }
                } elseif (!isset($checks[$name])) {
                    $result = ['ok' => false, 'error' => 'Not a check this role may run.'];
                } elseif (isset($done[$name])) {
                    $result = $done[$name];
                } else {
                    $r = OpsScripts::run($name, $role, $adminId, 'ai');
                    $result = ['ok' => $r['ok'], 'output' => $r['output']] + (isset($r['error']) ? ['error' => $r['error']] : []);
                    $done[$name] = $result;
                    $ran[] = $r['title'];
                }
                $messages[] = ['role' => 'tool', 'tool_call_id' => (string) $c['id'], 'name' => $name,
                               'content' => SupportAgentService::compact($result)];
            }
            $reply = null;
        }

        $text = $reply !== null ? trim($reply->text) : '';
        if ($text === '') return $ran === [] ? null : ['reply' => 'I ran ' . implode(', ', $ran)
            . ' but could not write the answer just now. The results are on the scripts page.',
            'ran' => $ran, 'actions' => [['label' => 'Open the scripts', 'url' => '/admin/assistant/scripts']]];
        return ['reply' => $text, 'ran' => $ran, 'actions' => array_values($actions)];
    }

    private function call(array $messages, array $tools, AiCapability $cap): ?AiReply
    {
        $t0 = microtime(true);
        $r = $this->ai->withTimeout($cap->timeout)->chat($messages, [
            'tools' => $tools, 'route' => $cap->route(), 'max_attempts' => $cap->maxAttempts,
            'max_tokens' => $cap->maxTokens, 'temperature' => 0.2, 'effort' => 'low',
        ]);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        AiGateway::record(self::CAPABILITY, $r === null ? 'PROVIDER_ERROR' : 'OK', $r === null
            ? ['latency_ms' => $ms, 'error' => AiService::describeHops($this->ai->hopErrors())]
            : ['provider' => $r->provider, 'model' => $r->model, 'tokens_in' => (int) ($r->usage['in'] ?? 0),
               'tokens_out' => (int) ($r->usage['out'] ?? 0), 'latency_ms' => $ms,
               'output_summary' => $r->hasTools() ? 'ran: ' . implode(', ', array_column($r->toolCalls, 'name')) : $r->text]);
        return $r;
    }
}
