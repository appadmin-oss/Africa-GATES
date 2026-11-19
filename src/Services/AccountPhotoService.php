<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use AfricaGates\Admin\Services\UploadService;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\UploadedFileInterface;

/** Account portraits belong to members, independently of public registry profiles. */
final class AccountPhotoService
{
    public static function currentPath(): string
    {
        $id = (int) ($_SESSION['user_id'] ?? 0);
        if ($id < 1) return '';
        try {
            $user = DB::table('gates_users')->where('id', $id)->first();
            return (string) (($user->photo_path ?? '') ?: ($user->avatar_path ?? ''));
        }
        catch (\Throwable) { return ''; } // Setup and pre-migration pages must still render.
    }

    public function save(int $userId, UploadedFileInterface $file): string
    {
        if ($userId < 1 || !DB::table('gates_users')->where('id', $userId)->exists()) throw new \RuntimeException('Sign in to update your photo.');
        if (!DB::schema()->hasColumn('gates_users', 'avatar_path')) throw new \RuntimeException('Profile photos are not available yet. Please contact support.');
        $image = (new UploadService())->uploadImage($file, 'member-photos', 1200, 82, null, 'user', $userId, 200);
        self::store($userId, (string) $image['path']);
        return (string) $image['path'];
    }

    private static function store(int $userId, ?string $path): void
    {
        $changes = ['avatar_path' => $path];
        if (DB::schema()->hasColumn('gates_users', 'photo_path')) $changes['photo_path'] = $path;
        DB::table('gates_users')->where('id', $userId)->update($changes);
        if ((int) ($_SESSION['user_id'] ?? 0) === $userId) $_SESSION['user_photo'] = $path ?? '';
    }

    public function remove(int $userId): void
    {
        // Keep upload history; clearing one member's photo must not delete a shared file.
        self::store($userId, null);
    }
}
