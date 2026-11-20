<?php
declare(strict_types=1);

/*
 * Igbo — read by AfricaGates\Support\Translator, keyed by the ENGLISH source string.
 *
 * A key here must be a sentence some template passes to `|trans` or some PHP passes to
 * Translator, word for word; TranslatorTest fails on one that nothing reads. A missing
 * entry renders the English, so leaving a sentence out is always safe and inventing a
 * key never is. Each entry wants a speaker's eye before it is relied on.
 */
return [
    // The first-visit prompt (Support\Languages::prompts()) — asked in this language
    // whatever the page is rendering in, so it is offered only where both are written.
    'View Africa GATES in your language?' => 'Lee Africa GATES n\'Igbo?',
    'Yes'                                 => 'Ee',
];
