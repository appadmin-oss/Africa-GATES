<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Support;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * May this admin move shop money — refund, cancel a paid order, use the till?
 *
 * The one resolver. Opening the shop is still the `content` section (Permissions); moving its
 * money additionally needs `gates_admins.shop_money`, granted per person by a superadmin on the
 * admin's own account. A superadmin always holds it, so the console can never be left with
 * nobody able to refund a buyer. A viewer never does: viewers cannot write anywhere.
 *
 * Before the column exists (the migration not yet applied) the old rule stands — any writer
 * who can open the shop — so deploying the code ahead of the migration takes nothing from
 * anybody. See 2027_06_08_shop_money_archive.php for who was granted it and why.
 */
final class ShopMoney
{
    public static function can(int $adminId, string $role): bool
    {
        if ($role === 'superadmin') return true;
        if (!Permissions::canWrite($role) || !Permissions::canOpen($role, '/admin/shop/orders')) return false;
        if (!SchemaHas::column('gates_admins', 'shop_money')) return true;
        if ($adminId < 1) return false;
        return (int) (DB::table('gates_admins')->where('id', $adminId)->value('shop_money') ?? 0) === 1;
    }

    /** The signed-in admin. */
    public static function current(): bool
    {
        return self::can((int) ($_SESSION['admin_id'] ?? 0), (string) ($_SESSION['admin_role'] ?? ''));
    }

    public const REFUSAL = 'Moving shop money — refunds, cancelling a paid order, the till — needs the shop-money permission on your account. A superadmin can grant it under Admins.';
}
