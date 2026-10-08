<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * The published rubric — four equal tests, and the doctrine that goes with them.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THE PLATFORM SHIPS ONE AT ALL
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_judge_criteria` had no default rows anywhere: not in either schema file, not in
 * `seed.sql`, not in a migration. A fresh installation therefore had an EMPTY global rubric,
 * and {@see \AfricaGates\Judge\Services\JudgeService} locks scoring when a programme has no
 * criteria — so the judging panel of a brand-new deployment could not score anybody until
 * somebody wrote four rows by hand in the database.
 *
 * These four are the rubric this platform actually publishes, so they are the ones it should
 * install.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THEY ARE EQUAL, AND WHY THAT IS THE POINT RATHER THAN A DEFAULT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Each carries 25. Not because equal weights are a neutral starting position — they are a
 * DECISION. Four equal quarters mean no single dimension can carry a result on its own:
 * not wealth, not a following, not a public tally, not a reputation that arrived before the
 * evidence did. A rubric weighted 40/25/20/15 lets the heaviest criterion decide, and the
 * heaviest criterion is always the one easiest to perform.
 *
 * The weights are RELATIVE in the scorer (see {@see JudgeRubric}), so four 25s and four 1s
 * would produce identical results. 25 is used because the rubric is published as
 * percentages and an operator opening this screen should find the number they were shown.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND WHY INSTALLING IS CONDITIONAL
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see install()} writes nothing when a global rubric already exists. An operator who has
 * retired a criterion, reweighted one, or added a fifth has made a decision about criteria
 * that ballots may already point at — and a seeder that reasserts the shipped four on the
 * next deploy would silently undo it, mid-cycle, with no record. Same reasoning as
 * {@see LegalService::seedShipped()}.
 */
final class JudgeRubricSeeder
{
    /**
     * The four, in ballot order.
     *
     * @return list<array{slug:string,label:string,weight:int,question:string,description:string,test:string}>
     */
    public static function criteria(): array
    {
        return [
            [
                'slug'     => 'impact',
                'label'    => 'Impact',
                'weight'   => 25,
                'question' => 'What measurable difference has this person made?',
                'description' =>
                    'The tangible change created through the nominee\'s work. The question is '
                  . 'not "what has this person done?" but "what is different because they did '
                  . 'it?" Evidence may include lives improved, systems changed, communities '
                  . 'strengthened, industries advanced, opportunities created, problems '
                  . 'solved, or measurable contributions to Africa\'s development.',
                'test'     => 'Can the difference be demonstrated?',
            ],
            [
                'slug'     => 'originality',
                'label'    => 'Originality',
                'weight'   => 25,
                'question' => 'What genuinely new value has this person introduced?',
                'description' =>
                    'The ability to think beyond convention and create, develop or introduce '
                  . 'something meaningfully different — a new idea, model, solution or '
                  . 'approach; an existing idea transformed exceptionally; a problem solved '
                  . 'from a fresh perspective; or something others can learn from, replicate '
                  . 'or build upon. Doing something well is not necessarily originality.',
                'test'     => 'What is distinctly different about this contribution?',
            ],
            [
                'slug'     => 'reach',
                'label'    => 'Reach',
                'weight'   => 25,
                'question' => 'How far has the influence of the work travelled?',
                'description' =>
                    'The breadth and depth of influence — community, local, regional, '
                  . 'national, continental or global. Who has been affected, adopted the '
                  . 'idea, benefited from the work, or been influenced by it. Reach is NOT '
                  . 'follower count: a person with 500 followers who has transformed a '
                  . 'community may show greater meaningful reach than one with 500,000 whose '
                  . 'influence produced little measurable change.',
                'test'     => 'How far has the work meaningfully travelled, and who has it reached?',
            ],
            [
                'slug'     => 'integrity',
                'label'    => 'Integrity',
                'weight'   => 25,
                'question' => 'Who is this person when doing the right thing comes at a cost?',
                'description' =>
                    'The consistency between a nominee\'s values, words, decisions and '
                  . 'actions, particularly under pressure: accountability, transparency, '
                  . 'responsibility, ethical decision-making, willingness to accept '
                  . 'consequences, and commitment to principle when compromise would be '
                  . 'easier. The strongest evidence appears when doing the right thing costs '
                  . 'something — money, influence, convenience, opportunity or recognition.',
                'test'     => 'Can this person\'s character be trusted when nobody is rewarding them for it?',
            ],
        ];
    }

    /**
     * The question the whole process exists to answer.
     *
     * Kept beside the criteria rather than in a template, because it is what the four are
     * FOR — and a screen that lists four criteria without it reads as a scoring form rather
     * than as a standard.
     */
    public const PURPOSE =
        'Beyond visibility, popularity or public sentiment, what has this nominee actually '
      . 'contributed — and what evidence proves it?';

    public const STANDARD =
        'Do not score the person because they are known. Score the evidence because it is '
      . 'convincing.';

    /**
     * The independence principle, which is a description of the CODE and not an aspiration.
     *
     * Every clause below is enforced, and it is worth knowing where, because a principle
     * that is only printed is a principle that quietly stops being true:
     *
     *  · The public tally never reaches the judging screen. `vote_count` and
     *    `organic_vote_count` are UNSET at the boundary in
     *    {@see \AfricaGates\Judge\Services\JudgeService::ballot()} rather than merely
     *    left unrendered — the template not printing them today is a property of today's
     *    template, and one `{{ n.vote_count }}` added in good faith while building a nicer
     *    card would put the community signal back inside the expert one. It cannot be
     *    printed if it is not there.
     *
     *  · The ORDER carries no tally either. The ballot used to be sorted most-voted first,
     *    which meant it printed "judge on documented impact, not popularity" and then walked
     *    the panel through the nominees in exactly popularity order. The number was never
     *    rendered, so it looked clean; position is one of the better-evidenced anchors there
     *    is, every judge saw the same order, and the bias pointed the same way for the whole
     *    panel. It is now shuffled per judge, deterministically — stable for one judge
     *    across page loads, different between judges, so position bias cancels across a
     *    panel instead of accumulating.
     *
     * @return list<array{title:string, body:string}>
     */
    public static function doctrine(): array
    {
        return [
            [
                'title' => 'Judges judge the evidence, not the popularity',
                'body'  => 'Judges are shown the nominee\'s dossier and supporting evidence, '
                         . 'never their public vote count. The tally is removed from the data '
                         . 'before the judging screen is built, not merely left off the page.',
            ],
            [
                'title' => 'And the running order carries no signal either',
                'body'  => 'The ballot is shuffled per judge rather than ranked, because a '
                         . 'list ordered by popularity carries the tally even when the number '
                         . 'is hidden. Each judge sees a stable order of their own, so '
                         . 'position bias cancels across the panel instead of pointing the '
                         . 'same way for everybody.',
            ],
            [
                'title' => 'Why: anchoring',
                'body'  => 'An existing number, ranking or popular opinion unconsciously '
                         . 'influences an independent assessment. A judge should meet a '
                         . 'nominee first as a body of evidence, form a view, and then score. '
                         . 'Public support can show community engagement and mobilisation, but '
                         . 'it is not a substitute for merit.',
            ],
        ];
    }

    /** Whether a global rubric already exists — the thing install() refuses to overwrite. */
    public static function installed(): bool
    {
        try {
            return DB::table('gates_judge_criteria')->whereNull('programme_id')->exists();
        } catch (\Throwable) {
            return true;   // unknown is treated as installed: never write blind
        }
    }

    /**
     * Install the shipped rubric as the GLOBAL default, if and only if there is not one.
     *
     * Global (`programme_id IS NULL`) rather than one copy per programme, because that is
     * how {@see JudgeRubric::effective()} and the scorer already resolve: the global rubric
     * applies to every programme, and a programme that needs its own version overrides a
     * criterion by reusing its slug. Copying four rows into every programme would produce
     * the same ballot today and four separate things to edit tomorrow.
     *
     * @return array{ok:bool, installed:int, message:string}
     */
    public static function install(): array
    {
        if (self::installed()) {
            return ['ok' => true, 'installed' => 0,
                    'message' => 'A rubric is already in place; nothing was changed.'];
        }

        $rows = [];
        foreach (self::criteria() as $i => $c) {
            $rows[] = [
                'programme_id' => null,
                'slug'         => $c['slug'],
                'label'        => $c['label'],
                // The question and the test are what a judge actually needs at the moment of
                // scoring — the description explains the criterion, these two apply it. They
                // live in one column because the schema has one, and the screen splits them
                // back apart on the blank line.
                'description'  => mb_substr(
                    $c['question'] . "\n\n" . $c['description'] . "\n\nThe test: " . $c['test'],
                    0, JudgeRubric::MAX_DESC),
                'weight'       => $c['weight'],
                'sort_order'   => $i + 1,
                'is_active'    => 1,
            ];
        }

        try {
            DB::table('gates_judge_criteria')->insert($rows);
        } catch (\Throwable $e) {
            error_log('[rubric] could not install the shipped rubric: ' . $e->getMessage());
            return ['ok' => false, 'installed' => 0,
                    'message' => 'The rubric could not be installed just now.'];
        }

        return ['ok' => true, 'installed' => count($rows),
                'message' => 'Installed the four equal criteria — Impact, Originality, Reach '
                           . 'and Integrity, 25 each.'];
    }
}
