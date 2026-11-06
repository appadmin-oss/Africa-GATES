<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\FormService;
use Illuminate\Database\Capsule\Manager as DB;

/** Form builder engine: schema normalization, conditional visibility, server validation, storage. */
class FormServiceTest extends TestCase
{
    private function form(array $fields): array
    {
        return ['id' => 1, 'form_key' => 'k', 'title' => 'T', 'description' => '', 'submit_message' => '', 'status' => 'published', 'fields' => FormService::normalizeFields($fields)];
    }

    public function test_normalize_dedupes_names_and_drops_unlabeled(): void
    {
        $f = FormService::normalizeFields([
            ['type' => 'text', 'label' => 'Name'],
            ['type' => 'bogus', 'label' => 'Name'],  // duplicate name; invalid type → text
            ['type' => 'text', 'label' => ''],        // no label → dropped
        ]);
        $this->assertCount(2, $f);
        $this->assertSame('name', $f[0]['name']);
        $this->assertSame('name_2', $f[1]['name']);   // de-duplicated
        $this->assertSame('text', $f[1]['type']);     // invalid type coerced
    }

    public function test_required_and_email_validation(): void
    {
        $form = $this->form([['type' => 'text', 'label' => 'Full name', 'required' => true], ['type' => 'email', 'label' => 'Email', 'required' => true]]);
        $this->assertNotEmpty(FormService::validate($form, ['full_name' => '', 'email' => 'bad']));
        $this->assertSame([], FormService::validate($form, ['full_name' => 'Ada', 'email' => 'ada@x.io']));
    }

    public function test_conditional_visibility_skips_hidden_required(): void
    {
        $form = $this->form([
            ['type' => 'select', 'label' => 'Attending', 'required' => true, 'options' => ['Yes', 'No']],
            ['type' => 'text', 'label' => 'Guest name', 'required' => true, 'showIfField' => 'attending', 'showIfValue' => 'Yes'],
        ]);
        // attending=No → guest_name hidden → not required → valid
        $this->assertSame([], FormService::validate($form, ['attending' => 'No']));
        // attending=Yes → guest_name visible + required + empty → error
        $this->assertNotEmpty(FormService::validate($form, ['attending' => 'Yes']));
    }

    public function test_invalid_select_choice_rejected(): void
    {
        $form = $this->form([['type' => 'select', 'label' => 'Pick', 'options' => ['A', 'B']]]);
        $this->assertNotEmpty(FormService::validate($form, ['pick' => 'Z']));   // not an option
        $this->assertSame([], FormService::validate($form, ['pick' => 'A']));
    }

    public function test_store_submission_keeps_only_declared_fields(): void
    {
        $form = $this->form([['type' => 'text', 'label' => 'Name']]);
        $id = FormService::storeSubmission($form, ['name' => 'Ada', 'evil_extra' => 'x'], '1.2.3.4');
        $this->assertGreaterThan(0, $id);
        $data = json_decode((string) DB::table('gates_form_submissions')->where('id', $id)->value('data_json'), true);
        $this->assertSame(['name' => 'Ada'], $data);   // unknown field stripped
    }

    public function test_checkbox_option_allowlist_enforced(): void
    {
        $form = $this->form([['type' => 'checkbox', 'label' => 'Topics', 'options' => ['A', 'B']]]);
        $name = $form['fields'][0]['name'];
        $this->assertSame([], FormService::validate($form, [$name => ['A']]));         // valid choice
        $this->assertNotEmpty(FormService::validate($form, [$name => ['A', 'EVIL']])); // out-of-allowlist value rejected
    }

    public function test_invalid_regex_pattern_dropped_at_normalize(): void
    {
        $bad = FormService::normalizeFields([['type' => 'text', 'label' => 'Code', 'pattern' => '[unclosed(']]);
        $this->assertSame('', $bad[0]['pattern']);                  // broken regex stripped → can't silently disable validation
        $ok = FormService::normalizeFields([['type' => 'text', 'label' => 'Code', 'pattern' => '^[A-Z]{3}$']]);
        $this->assertSame('^[A-Z]{3}$', $ok[0]['pattern']);          // valid regex preserved
    }
}
