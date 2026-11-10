<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\NomineePortrait;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * "Nominees have more than one photo, but only one is ever visible" (owner, 10 Nov 2026).
 *
 * A nominee may hold six photographs of their own and staff may attach more, and the public
 * page drew `photo_path` alone — NomineePortrait's docblock spoke of "the public portrait and
 * gallery" while nothing public read past the first. The list is now one function, read by the
 * nominee's page, under the rules the portrait already obeys.
 */
final class NomineePublicGalleryTest extends TestCase
{
    private function nominee(string $portrait): int
    {
        return (int) DB::table('gates_nominees')->insertGetId(['category_id' => 1, 'name' => 'Ada Obi', 'status' => 'approved',
            'vote_count' => 0, 'photo_path' => $portrait]);
    }

    private function own(int $id, string $path, bool $removed = false): void
    {
        DB::table('gates_nominee_files')->insert(['nominee_id' => $id, 'kind' => 'photo', 'path' => $path,
            'created_at' => date('Y-m-d H:i:s'), 'removed_at' => $removed ? date('Y-m-d H:i:s') : null]);
    }

    public function test_every_live_photograph_the_portrait_first_and_nothing_removed(): void
    {
        $id = $this->nominee('/uploads/p/portrait.jpg');
        $this->own($id, '/uploads/p/portrait.jpg');      // the portrait is also one of theirs: listed once
        $this->own($id, '/uploads/p/at-work.jpg');
        $this->own($id, '/uploads/p/taken-down.jpg', removed: true);
        DB::table('gates_uploads')->insert(['attached_to_type' => 'nominee', 'attached_to_id' => $id,
            'path' => '/uploads/p/staff.jpg', 'created_at' => date('Y-m-d H:i:s')]);
        // A staff copy of a photograph the nominee took down stays down.
        DB::table('gates_uploads')->insert(['attached_to_type' => 'nominee', 'attached_to_id' => $id,
            'path' => '/uploads/p/taken-down.jpg', 'created_at' => date('Y-m-d H:i:s')]);

        $this->assertSame(['/uploads/p/portrait.jpg', '/uploads/p/at-work.jpg', '/uploads/p/staff.jpg'],
            NomineePortrait::gallery($id, '/uploads/p/portrait.jpg'));
        $this->assertSame([], NomineePortrait::gallery($this->nominee(''), ''), 'nobody with no photograph gets an empty slide');
    }

    public function test_the_page_draws_every_photograph_it_is_given(): void
    {
        $tpl = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/vote-nominee.twig');
        $this->assertStringContainsString('{% for ph in _photos %}', $tpl, 'the page loops the list rather than drawing one photo');
        $this->assertStringContainsString("'photos'           => \\AfricaGates\\Services\\NomineePortrait::gallery(",
            (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/VoteController.php'), 'and the controller hands it over');
    }
}
