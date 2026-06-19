<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use AfricaGates\Support\Session;

class SessionTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_rotate_changes_session_id_and_preserves_data(): void
    {
        if (PHP_SAPI === 'cli') {
            ini_set('session.save_handler', 'files');
            ini_set('session.save_path', sys_get_temp_dir());
        }
        @session_start();
        $before = session_id();
        $_SESSION['admin_id'] = 7;

        Session::rotate();

        $this->assertNotSame($before, session_id(), 'session id should change');
        $this->assertSame(7, $_SESSION['admin_id'], 'session data should survive rotation');
    }

    public function test_rotate_is_safe_with_no_active_session(): void
    {
        // No session started here — must not throw.
        Session::rotate();
        $this->assertTrue(true);
    }
}
