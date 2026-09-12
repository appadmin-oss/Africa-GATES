<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\AiCapability;
use AfricaGates\Services\AiPrompt;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Changing what the platform tells its models to do.
 *
 * ── THE SCREEN'S FIRST JOB IS TO BE HONEST ABOUT ITSELF ──────────────────────
 *
 * The request behind it was "let me train the AI". This is not training, and the page says
 * so above the fold rather than in a footnote — because somebody who believes they are
 * training a model will make a real decision on that belief, and find out they were wrong
 * at the worst possible moment.
 *
 * What it is instead is the useful ninety per cent: change the INSTRUCTION, per capability,
 * without a deploy. On cPanel with no SSH, that is the difference between a prompt somebody
 * can fix and a prompt frozen at the developer's first guess.
 *
 * ── SUPERADMIN ONLY ──────────────────────────────────────────────────────────
 *
 * Not because an edit is dangerous in the way a payout is — the injection fence and the
 * advisory rule are both enforced outside anything typed here, so the worst case is a
 * capability that answers badly and is visibly rolled back. It is superadmin because the
 * blast radius is EVERY future call to that feature and the effect is invisible at the
 * point of use: a moderator would not see the wording that scored the nomination in front
 * of them. A change nobody can see should be made by somebody accountable for it.
 */
final class AiPromptsController
{
    public function __construct(
        private readonly Twig $view,
        private readonly ?AuditService $audit = null,
    ) {}

    private function adminId(): int { return (int) ($_SESSION['admin_id'] ?? 0); }

    private function back(Response $res, string $to): Response
    {
        return $res->withHeader('Location', $to)->withStatus(302);
    }

    /** The list of what can be changed, and what has been. */
    public function index(Request $req, Response $res): Response
    {
        return $this->view->render($res, 'admin/ai-prompts/index.twig', [
            'page_title' => 'AI instructions',
            'admin_page' => 'ai',
            'rows'       => AiPrompt::overview(),
        ]);
    }

    /** One capability: the current wording, the history, and a box to change it. */
    public function edit(Request $req, Response $res, array $args = []): Response
    {
        $name = (string) ($args['capability'] ?? '');
        $cap  = AiCapability::find($name);

        if ($cap === null) {
            $_SESSION['flash_error'] = 'There is no AI capability by that name.';
            return $this->back($res, '/admin/ai-prompts');
        }

        $active  = AiPrompt::active($name);
        $shipped = AiPrompt::shipped($name);

        // What the box opens with: the wording in force. Not blank — an editor that starts
        // empty invites somebody to write a replacement from scratch for a prompt that was
        // ninety per cent right, and to lose the parts that made the parser work.
        $current = $active['body'] ?? $shipped ?? '';

        return $this->view->render($res, 'admin/ai-prompts/edit.twig', [
            'page_title' => 'AI instructions — ' . $name,
            'admin_page' => 'ai',
            'cap'        => $cap,
            'active'     => $active,
            'shipped'    => $shipped,
            'current'    => $current,
            'history'    => AiPrompt::history($name),
            // Only meaningful once there is both a baseline and an override; the template
            // decides whether to show it.
            'diff'       => ($active && $shipped) ? AiPrompt::diff($shipped, (string) $active['body']) : [],
            'max_body'   => AiPrompt::MAX_BODY,
            'min_body'   => AiPrompt::MIN_BODY,
            'kept'       => $this->kept(),
        ]);
    }

    public function save(Request $req, Response $res, array $args = []): Response
    {
        $name = (string) ($args['capability'] ?? '');
        $b    = (array) $req->getParsedBody();

        $r = AiPrompt::save($name, (string) ($b['body'] ?? ''), (string) ($b['note'] ?? ''),
                            $this->adminId());

        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['message'];

        if ($r['ok']) {
            $this->audit?->record($this->adminId(), 'ai_prompt.save', 'capability', null,
                ['capability' => $name, 'version' => $r['version'],
                 'note' => mb_substr((string) ($b['note'] ?? ''), 0, 200)]);
        } else {
            // Keep what they wrote. A refusal here is usually the missing note or a body a
            // few hundred characters over — and returning an empty box to somebody who has
            // just rewritten a prompt is how the edit never gets made at all.
            $_SESSION['ai_prompt_kept'] = [
                'capability' => $name,
                'body'       => (string) ($b['body'] ?? ''),
                'note'       => (string) ($b['note'] ?? ''),
            ];
        }

        return $this->back($res, '/admin/ai-prompts/' . rawurlencode($name));
    }

    /** Put an earlier version back. */
    public function activate(Request $req, Response $res, array $args = []): Response
    {
        $name = (string) ($args['capability'] ?? '');
        $id   = (int) (((array) $req->getParsedBody())['version_id'] ?? 0);

        $row = AiPrompt::find($id);
        if (!$row || $row['capability'] !== $name) {
            $_SESSION['flash_error'] = 'That version does not belong to this feature.';
            return $this->back($res, '/admin/ai-prompts/' . rawurlencode($name));
        }

        $r = AiPrompt::activate($id, $this->adminId());
        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['message'];
        if ($r['ok']) {
            $this->audit?->record($this->adminId(), 'ai_prompt.activate', 'capability', null,
                ['capability' => $name, 'version' => $row['version']]);
        }

        return $this->back($res, '/admin/ai-prompts/' . rawurlencode($name));
    }

    /** Stop overriding; go back to the shipped wording. */
    public function revert(Request $req, Response $res, array $args = []): Response
    {
        $name = (string) ($args['capability'] ?? '');

        $r = AiPrompt::revert($name, $this->adminId());
        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['message'];
        if ($r['ok']) {
            $this->audit?->record($this->adminId(), 'ai_prompt.revert', 'capability', null,
                ['capability' => $name]);
        }

        return $this->back($res, '/admin/ai-prompts/' . rawurlencode($name));
    }

    /** @return array<string,string> the refused draft, once */
    private function kept(): array
    {
        $k = $_SESSION['ai_prompt_kept'] ?? null;
        unset($_SESSION['ai_prompt_kept']);

        return is_array($k) ? array_map('strval', $k) : [];
    }
}
