<?php
declare(strict_types=1);
namespace AfricaGates\Controllers;

use AfricaGates\Services\{AccountPhotoService, UserAccountService};
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AccountPhotoController
{
    public function __construct(private UserAccountService $accounts, private AccountPhotoService $photos) {}

    public function upload(Request $request, Response $response): Response
    {
        $user = $this->accounts->current();
        if (!$user) return $response->withHeader('Location', '/account/login')->withStatus(302);
        $file = $request->getUploadedFiles()['photo'] ?? null;
        try {
            if (!$file instanceof \Psr\Http\Message\UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) throw new \RuntimeException('Choose a photo first.');
            $this->photos->save((int) $user->id, $file);
            $_SESSION['flash_ok'] = 'Your profile photo has been updated.';
        } catch (\Illuminate\Database\QueryException $e) {
            $_SESSION['flash_error'] = 'Your photo could not be saved. Please try again or contact support.';
        } catch (\RuntimeException $e) {
            $_SESSION['flash_error'] = 'Could not save your photo. ' . $e->getMessage();
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'This file could not be used as a photo. Please choose another image.';
        }
        return $response->withHeader('Location', '/account?tab=profile#account-photo-h')->withStatus(303);
    }

    public function remove(Request $request, Response $response): Response
    {
        $user = $this->accounts->current();
        if (!$user) return $response->withHeader('Location', '/account/login')->withStatus(302);
        try {
            $this->photos->remove((int) $user->id);
            $_SESSION['flash_ok'] = 'Your profile photo has been removed.';
        } catch (\Throwable) { $_SESSION['flash_error'] = 'Your photo could not be removed. Please try again.'; }
        return $response->withHeader('Location', '/account?tab=profile#account-photo-h')->withStatus(303);
    }
}
