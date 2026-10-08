<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\ShopController;
use AfricaGates\Admin\Services\AuditService;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * The shop-orders search, with an underscore in it.
 *
 * Order references and addresses are full of `_`, and the escape was a backslash with no
 * ESCAPE clause — MySQL's default and nothing at all to SQLite — so on SQLite every such
 * search came back empty and the screen read as "no such order". Through Support\Like
 * the `!` escape means the same thing on both drivers.
 */
final class ShopOrderSearchTest extends TestCase
{
    /** @return list<string> the references the screen would draw */
    private function search(string $q): array
    {
        $seen = [];
        $twig = $this->createMock(\Slim\Views\Twig::class);
        $twig->method('render')->willReturnCallback(function ($res, $tpl, $ctx) use (&$seen) {
            $seen = array_column($ctx['rows'] ?? [], 'reference');
            return $res;
        });
        (new ShopController($twig, new AuditService()))->orders(
            (new ServerRequestFactory())->createServerRequest('GET', '/admin/shop/orders')->withQueryParams(['q' => $q]),
            new Response());
        sort($seen);
        return $seen;
    }

    public function test_an_underscore_is_a_letter_and_it_matches(): void
    {
        foreach (['AG_SHOP_1' => 'a_b@x.test', 'AGXSHOPX2' => 'axb@x.test'] as $ref => $email) {
            DB::table('gates_orders')->insert([
                'reference' => $ref, 'email' => $email, 'name' => 'Buyer', 'items_json' => '[]',
                'status' => 'paid', 'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $this->assertSame(['AG_SHOP_1'], $this->search('ag_shop'), 'an underscore search found nothing, or matched any character');
        $this->assertSame(['AG_SHOP_1'], $this->search('a_b@'));
        $this->assertSame([], $this->search('%'), 'a typed % matched every order');
    }
}
