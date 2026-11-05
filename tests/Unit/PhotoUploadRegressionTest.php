<?php
declare(strict_types=1);
namespace Tests\Unit;

use AfricaGates\Admin\Services\UploadService;
use AfricaGates\Controllers\AccountPhotoController;
use AfricaGates\Services\{AccountPhotoService, NomineeDetails, NomineePortrait, UserAccountService};
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\{UploadedFile, Response};
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

final class PhotoUploadRegressionTest extends TestCase
{
    private array $files = [];
    protected function tearDown(): void
    {
        foreach ($this->files as $file) @unlink($file);
        unset($_SESSION['user_id'], $_SESSION['flash_ok'], $_SESSION['flash_error']);
        parent::tearDown();
    }
    private function upload(string $type = 'png', int $side = 320): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'photo-test-');
        $this->files[] = $tmp;
        $im = imagecreatetruecolor($side, $side);
        if ($type === 'webp') imagewebp($im, $tmp); else imagepng($im, $tmp);
        imagedestroy($im);
        return new UploadedFile($tmp, 'portrait.' . $type, 'image/' . $type, filesize($tmp), UPLOAD_ERR_OK, false);
    }
    private function member(): int
    {
        return (int) DB::table('gates_users')->insertGetId(['name' => 'Photo Member', 'email' => uniqid() . '@example.test', 'status' => 'active']);
    }
    private function nominee(): object
    {
        $nid = DB::table('gates_nominees')->insertGetId(['category_id' => 1, 'name' => 'Photo Nominee', 'status' => 'approved']);
        $id = DB::table('gates_nominee_details')->insertGetId(['nominee_id' => $nid, 'phones' => '[]', 'emails' => '[]', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        return DB::table('gates_nominee_details')->where('id', $id)->first();
    }
    private function track(string $path): void { $this->files[] = dirname(__DIR__, 2) . '/public' . $path; }

    public function test_member_upload_is_persisted_and_scoped_to_signed_in_owner(): void
    {
        $owner = $this->member(); $other = $this->member();
        $_SESSION['user_id'] = $owner;
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/account/photo')
            ->withParsedBody(['user_id' => $other])->withUploadedFiles(['photo' => $this->upload()]);
        $controller = new AccountPhotoController(new UserAccountService(), new AccountPhotoService());
        $response = $controller->upload($request, new Response());
        $path = AccountPhotoService::currentPath(); $this->track($path);
        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/account?tab=profile#account-photo-h', $response->getHeaderLine('Location'));
        $this->assertStringStartsWith('/uploads/member-photos/', $path);
        $this->assertFileExists(dirname(__DIR__, 2) . '/public' . $path);
        $this->assertNull(DB::table('gates_users')->where('id', $other)->value('avatar_path'));
        $controller->remove($request->withParsedBody(['user_id' => $other]), new Response());
        $this->assertSame('', AccountPhotoService::currentPath());
        $this->assertNull(DB::table('gates_users')->where('id', $owner)->value('avatar_path'));
    }
    public function test_webp_bytes_match_extension_and_recorded_mime(): void
    {
        $result = (new UploadService())->uploadImage($this->upload('webp'), 'member-photos', 1200, 82, null, 'user', $this->member(), 200);
        $this->track($result['path']);
        $abs = dirname(__DIR__, 2) . '/public' . $result['path'];
        $this->assertSame('image/webp', (new \finfo(FILEINFO_MIME_TYPE))->file($abs));
        $this->assertSame(IMAGETYPE_WEBP, getimagesize($abs)[2]);
        $this->assertSame('image/webp', DB::table('gates_uploads')->where('path', $result['path'])->value('mime'));
    }
    public function test_nominee_upload_becomes_public_and_removal_promotes_remaining_photo(): void
    {
        $d = $this->nominee();
        $this->assertTrue(NomineeDetails::addPhoto($d, $this->upload())['ok']);
        $first = NomineeDetails::files((int) $d->nominee_id, 'photo')[0]; $this->track($first->path);
        $this->assertSame($first->path, DB::table('gates_nominees')->where('id', $d->nominee_id)->value('photo_path'));
        $this->assertTrue(NomineeDetails::addPhoto($d, $this->upload())['ok']);
        $second = NomineeDetails::files((int) $d->nominee_id, 'photo')[1]; $this->track($second->path);
        $this->assertTrue(NomineeDetails::remove($d, (int) $first->id));
        $this->assertSame($second->path, DB::table('gates_nominees')->where('id', $d->nominee_id)->value('photo_path'));
        $this->assertFalse(NomineePortrait::uploads((int) $d->nominee_id)->where('path', $first->path)->exists());
        NomineeDetails::remove($d, (int) $second->id);
        $this->assertNull(DB::table('gates_nominees')->where('id', $d->nominee_id)->value('photo_path'));
    }
    public function test_nominee_choice_supersedes_existing_staff_portrait(): void
    {
        $d = $this->nominee();
        DB::table('gates_nominees')->where('id', $d->nominee_id)->update(['photo_path' => '/approved.webp']);
        $this->assertTrue(NomineeDetails::addPhoto($d, $this->upload())['ok']);
        $this->track(NomineeDetails::files((int) $d->nominee_id, 'photo')[0]->path);
        $this->assertSame(NomineeDetails::files((int) $d->nominee_id, 'photo')[0]->path, DB::table('gates_nominees')->where('id', $d->nominee_id)->value('photo_path'));
    }
    public function test_repair_migration_is_repeatable_and_ignores_removed_files(): void
    {
        $d = $this->nominee();
        DB::table('gates_nominee_files')->insert([
            ['nominee_id' => $d->nominee_id, 'kind' => 'photo', 'path' => '/removed.png', 'created_at' => date('Y-m-d H:i:s'), 'removed_at' => '2026-01-01 00:00:00'],
            ['nominee_id' => $d->nominee_id, 'kind' => 'photo', 'path' => '/existing.png', 'created_at' => date('Y-m-d H:i:s'), 'removed_at' => null],
        ]);
        require dirname(__DIR__, 2) . '/database/migrations/2027_04_30_account_and_nominee_photos.php';
        $this->assertSame('/existing.png', DB::table('gates_nominees')->where('id', $d->nominee_id)->value('photo_path'));
        DB::table('gates_nominees')->where('id', $d->nominee_id)->update(['photo_path' => '/chosen.png']);
        require dirname(__DIR__, 2) . '/database/migrations/2027_04_30_account_and_nominee_photos.php';
        $this->assertSame('/chosen.png', DB::table('gates_nominees')->where('id', $d->nominee_id)->value('photo_path'));
    }
    public function test_staff_removal_does_not_leave_a_photo_in_the_member_gallery(): void
    {
        $d = $this->nominee();
        $this->assertTrue(NomineeDetails::addPhoto($d, $this->upload())['ok']);
        $photo = NomineeDetails::files((int) $d->nominee_id, 'photo')[0]; $this->track($photo->path);
        NomineePortrait::remove((int) $d->nominee_id, $photo->path);
        $this->assertSame([], NomineeDetails::files((int) $d->nominee_id, 'photo'));
        $this->assertNull(DB::table('gates_nominees')->where('id', $d->nominee_id)->value('photo_path'));
        $this->assertFalse(NomineePortrait::uploads((int) $d->nominee_id)->exists());
    }
    public function test_staff_gallery_removal_clears_a_portrait_without_member_history(): void
    {
        $d = $this->nominee();
        $image = (new UploadService())->uploadImage($this->upload(), 'nominees', 1200, 82, null, 'nominee', (int) $d->nominee_id, 200);
        $this->track($image['path']);
        DB::table('gates_nominees')->where('id', $d->nominee_id)->update(['photo_path' => $image['path']]);
        NomineePortrait::remove((int) $d->nominee_id, $image['path']);
        $this->assertNull(DB::table('gates_nominees')->where('id', $d->nominee_id)->value('photo_path'));
        $this->assertFalse(NomineePortrait::uploads((int) $d->nominee_id)->exists());
    }

    public function test_changed_photo_templates_compile_with_the_real_twig_configuration(): void
    {
        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        $env = $builder->build()->get(\Slim\Views\Twig::class)->getEnvironment();
        foreach (['partials/avatar.twig', 'partials/site-header.twig', 'partials/app-bar.twig', 'partials/menu-sheet.twig', 'partials/photo-upload.twig', 'pages/account/sections/profile.twig', 'nominee/profile.twig', 'layout/shell.twig', 'admin/layout.twig'] as $template) {
            $env->parse($env->tokenize($env->getLoader()->getSourceContext($template)));
            $this->addToAssertionCount(1);
        }
    }
    public function test_missing_and_small_files_preserve_current_member_photo(): void
    {
        $id = $this->member(); $_SESSION['user_id'] = $id;
        DB::table('gates_users')->where('id', $id)->update(['avatar_path' => '/existing.png']);
        $c = new AccountPhotoController(new UserAccountService(), new AccountPhotoService());
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/account/photo');
        $c->upload($req, new Response());
        $this->assertNotEmpty($_SESSION['flash_error']);
        $c->upload($req->withUploadedFiles(['photo' => $this->upload('png', 80)]), new Response());
        $this->assertSame('/existing.png', AccountPhotoService::currentPath());
        unset($_SESSION['user_id']);
        $this->assertSame('/account/login', $c->upload($req, new Response())->getHeaderLine('Location'));
    }
}
