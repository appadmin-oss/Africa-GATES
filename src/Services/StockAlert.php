<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * "Tell me when it's back", and the one moment it is worth telling somebody.
 *
 * ── THE SAME GAP THE EVENT WAITLIST CLOSED, ON THE OTHER SIDE ────────────────
 *
 * A sold-out ticket said "fully booked" and stopped. A sold-out product did the same, and
 * worse — stock comes back far more often than a seat. Somebody who wanted a Large enough to
 * arrive on the page the week it ran out is the easiest sale this shop will ever make, and the
 * page's entire answer to them was a greyed-out button.
 *
 * ── WHY THE NOTIFICATION FIRES FROM A SAVE, NOT FROM A CRON ──────────────────
 *
 * There is exactly one moment stock rises: somebody types a bigger number into the product
 * editor. A cron polling for changes would have to remember every previous stock level to
 * spot it, and would either miss a restock that sold out again before the next tick or fire on
 * a number that was only briefly positive. {@see release()} is called from the editor, at the
 * moment the fact becomes true.
 *
 * ── AND A BATCH LIMIT, SAID OUT LOUD ─────────────────────────────────────────
 *
 * Sending four hundred emails inside a form submission would time the request out and lose the
 * save. {@see BATCH} go out per call and the rest stay pending — the editor reports how many
 * are still waiting rather than pretending the job is done, and the next save (or the button on
 * the product form) picks them up.
 *
 * ── NOTHING HERE CONFIRMS AN ADDRESS EXISTS ──────────────────────────────────
 *
 * {@see want()} answers the same way whether or not the address was already on the list. A
 * form that said "you are already signed up" would be a way to test which addresses are, which
 * matters more here than it looks: the list is a record of what somebody wanted to buy.
 */
final class StockAlert
{
    /** How many people are emailed per call. See the docblock. */
    public const BATCH = 100;

    // ══ 1. asking ════════════════════════════════════════════════════════════

    /**
     * Ask to be told. Idempotent per (product, variant, email).
     *
     * @return array{ok:bool, message:string}
     */
    public static function want(int $productId, int $variantId, string $email,
                               string $name = '', string $ipHash = ''): array
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Please enter a valid email address.'];
        }

        $product = DB::table('gates_products')->where('id', $productId)->where('is_active', 1)->first();
        if (!$product) return ['ok' => false, 'message' => 'That product is not available.'];

        // The variant is checked against the product, exactly as ShopCatalogue::pick() does —
        // the pairing arrives from a form, so it is verified rather than believed.
        if ($variantId > 0) {
            $v = DB::table('gates_product_variants')->where('id', $variantId)->first();
            if (!$v || (int) $v->product_id !== $productId) {
                return ['ok' => false, 'message' => 'That option is not available.'];
            }
        }

        // Only worth asking about something that has actually gone. Otherwise the answer is
        // "you can buy it right now", which is more useful than a promise to email later.
        //
        // NULL is UNLIMITED, so it belongs on this side of the test with "there are some left".
        // Reading it as "no stock" would take an email address for something that has never
        // been out of stock and can never trigger a restock — a promise nothing could keep.
        $left = ShopCatalogue::available($product, $variantId);
        if ($left === null || $left > 0) {
            return ['ok' => false, 'message' => 'Good news — this is in stock. You can order it now.'];
        }

        $now  = Carbon::now()->toDateTimeString();
        $said = 'We will email you the moment this is back. One message, and nothing else.';

        try {
            $existing = DB::table('gates_stock_alerts')
                ->where('product_id', $productId)->where('variant_id', $variantId)
                ->whereRaw('LOWER(email) = ?', [$email])->first();

            if ($existing) {
                // Already asked, or asked before and been told. A previous notification is
                // reopened rather than duplicated: they want to know about THIS restock, and a
                // second row would email them twice.
                DB::table('gates_stock_alerts')->where('id', (int) $existing->id)->update([
                    'notified_at' => null, 'cancelled_at' => null,
                    'name' => $name !== '' ? mb_substr($name, 0, 160) : ($existing->name ?? null),
                ]);
                // Answered identically to a fresh sign-up. See the docblock: a different
                // sentence here would be a way to test which addresses are on the list.
                return ['ok' => true, 'message' => $said];
            }

            DB::table('gates_stock_alerts')->insert([
                'product_id' => $productId,
                'variant_id' => $variantId,
                'email'      => mb_substr($email, 0, 190),
                'name'       => $name !== '' ? mb_substr($name, 0, 160) : null,
                'token'      => bin2hex(random_bytes(16)),
                'ip_hash'    => $ipHash !== '' ? $ipHash : null,
                'created_at' => $now,
            ]);
        } catch (\Throwable $e) {
            error_log('[stock-alert] could not record a request for product ' . $productId
                . ': ' . $e->getMessage());
            return ['ok' => false, 'message' => 'We could not save that just now. Please try again.'];
        }

        return ['ok' => true, 'message' => $said];
    }

    /** How many people are waiting. `$variantId = -1` counts every option of a product. */
    public static function waiting(int $productId, int $variantId = -1): int
    {
        try {
            $q = DB::table('gates_stock_alerts')->where('product_id', $productId)
                ->whereNull('notified_at')->whereNull('cancelled_at');
            if ($variantId >= 0) $q->where('variant_id', $variantId);
            return (int) $q->count();
        } catch (\Throwable) { return 0; }
    }

    /**
     * @return array<int,int> variant_id => how many are waiting, for one product
     */
    public static function waitingByVariant(int $productId): array
    {
        try {
            $rows = DB::table('gates_stock_alerts')->where('product_id', $productId)
                ->whereNull('notified_at')->whereNull('cancelled_at')
                ->select('variant_id', DB::raw('COUNT(*) as n'))
                ->groupBy('variant_id')->get();
        } catch (\Throwable) { return []; }

        $out = [];
        foreach ($rows as $r) $out[(int) $r->variant_id] = (int) $r->n;
        return $out;
    }

    // ══ 2. telling ═══════════════════════════════════════════════════════════

    /**
     * Tell the people waiting for this exact thing that it is back.
     *
     * Called from the product editor at the moment stock rises — see the docblock for why that
     * is the only honest trigger. Refuses to send if the thing is NOT actually available, so a
     * mis-called release cannot send four hundred people to a sold-out page.
     *
     * @return array{sent:int, left:int, message:string}
     */
    public static function release(int $productId, int $variantId, ?OtpService $mailer = null,
                                  string $baseUrl = ''): array
    {
        $product = DB::table('gates_products')->where('id', $productId)->first();
        if (!$product) return ['sent' => 0, 'left' => 0, 'message' => 'No such product.'];

        $left = ShopCatalogue::available($product, $variantId);
        if ($left !== null && $left < 1) {
            // Guarded rather than trusted. Sending somebody to a page that says "sold out" is
            // worse than never writing to them, because it spends the one message they agreed
            // to receive.
            return ['sent' => 0, 'left' => self::waiting($productId, $variantId),
                    'message' => 'That is still sold out, so nobody was emailed.'];
        }

        try {
            $rows = DB::table('gates_stock_alerts')
                ->where('product_id', $productId)->where('variant_id', $variantId)
                ->whereNull('notified_at')->whereNull('cancelled_at')
                ->orderBy('id')->limit(self::BATCH)->get();
        } catch (\Throwable) {
            return ['sent' => 0, 'left' => 0, 'message' => 'The waiting list could not be read.'];
        }

        $label = $variantId > 0
            ? (string) (DB::table('gates_product_variants')->where('id', $variantId)->value('label') ?? '')
            : '';

        $sent = 0;
        foreach ($rows as $r) {
            // Stamped FIRST. A mail failure must not leave the row pending, or the next save
            // writes to the same person again — and the row is the record of whether we have
            // used up the one message they agreed to.
            $done = DB::table('gates_stock_alerts')->where('id', (int) $r->id)
                ->whereNull('notified_at')
                ->update(['notified_at' => Carbon::now()->toDateTimeString()]);
            if ($done === 0) continue;                 // somebody else got there first

            $sent++;
            self::tell($product, $label, $r, $mailer, $baseUrl);
        }

        $stillWaiting = self::waiting($productId, $variantId);

        return ['sent' => $sent, 'left' => $stillWaiting,
                'message' => $sent === 0
                    ? 'Nobody was waiting for that.'
                    : $sent . ' ' . ($sent === 1 ? 'person' : 'people') . ' emailed'
                      . ($label !== '' ? ' about ' . $label : '') . '.'
                      // Said out loud rather than hidden: a batch limit that looked like
                      // completion is how half a list quietly never hears anything.
                      . ($stillWaiting > 0
                          ? ' ' . $stillWaiting . ' still waiting — save again to send the next batch.'
                          : '')];
    }

    /** Stop. Token-as-whole-credential, and it never says whether the token was real. */
    public static function stop(string $token): bool
    {
        $token = strtolower(trim($token));
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) return false;
        try {
            return DB::table('gates_stock_alerts')->where('token', $token)
                ->whereNull('cancelled_at')
                ->update(['cancelled_at' => Carbon::now()->toDateTimeString()]) > 0;
        } catch (\Throwable) { return false; }
    }

    /**
     * What one email says.
     *
     * Best-effort: the row is already stamped, so a bounce costs somebody a message and never
     * corrupts the record. The link goes to the PRODUCT rather than straight into a cart,
     * because somebody who signed up three weeks ago may well have changed their mind, and a
     * page is an invitation where a pre-filled basket is a presumption.
     */
    private static function tell(object $product, string $label, object $alert,
                                 ?OtpService $mailer, string $baseUrl): void
    {
        if ($mailer === null) return;

        $base = rtrim($baseUrl !== '' ? $baseUrl
            : (string) \AfricaGates\Support\Env::get('APP_URL', ''), '/');
        $e    = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $link = $base . '/shop/' . rawurlencode((string) $product->slug);
        $stop = $base . '/shop/back-in-stock/stop/' . rawurlencode((string) $alert->token);
        $what = (string) $product->name . ($label !== '' ? ' (' . $label . ')' : '');
        $hi   = trim((string) ($alert->name ?? '')) !== ''
            ? 'Hello ' . $e(trim((string) $alert->name)) . ','
            : 'Hello,';

        $html = '<p>' . $hi . '</p>'
              . '<p><strong>' . $e($what) . '</strong> is back in stock.</p>'
              . '<p>You asked us to tell you. This is that message — and it is the only one, '
              . 'you are not on a mailing list.</p>'
              . '<p style="text-align:center;margin:22px 0"><a href="' . $link . '"'
              . ' style="display:inline-block;padding:12px 28px;background:#10292C;color:#fff;'
              . 'border-radius:999px;font-weight:600;text-decoration:none">See it in the shop →</a></p>'
              . '<p style="font-size:12px;color:#7a8a8c">Changed your mind? '
              . '<a href="' . $stop . '" style="color:#7a8a8c">Take me off this</a>.</p>';

        $plain = ($hi === 'Hello,' ? 'Hello,' : 'Hello ' . trim((string) $alert->name) . ',') . "\n\n"
               . $what . " is back in stock.\n\n"
               . "You asked us to tell you. This is that message — and it is the only one.\n\n"
               . $link . "\n\nTake me off this: " . $stop . "\n\n— Africa GATES";

        try {
            $mailer->sendBranded((string) $alert->email,
                $what . ' is back in stock', $html, $plain, 'Shop');
        } catch (\Throwable) {}
    }

    // ══ 3. for the operator ══════════════════════════════════════════════════

    /**
     * Demand, by product — what people are asking for and cannot buy.
     *
     * The most actionable list in the shop and one nothing has ever been able to show: it is a
     * restock order written by the people who wanted to pay.
     *
     * @return list<array<string,mixed>>
     */
    public static function demand(int $limit = 60): array
    {
        try {
            $rows = DB::table('gates_stock_alerts as a')
                ->join('gates_products as p', 'p.id', '=', 'a.product_id')
                ->whereNull('a.notified_at')->whereNull('a.cancelled_at')
                ->select('a.product_id', 'a.variant_id', 'p.name', 'p.slug',
                         DB::raw('COUNT(*) as n'), DB::raw('MAX(a.created_at) as latest'))
                ->groupBy('a.product_id', 'a.variant_id', 'p.name', 'p.slug')
                ->orderByDesc(DB::raw('COUNT(*)'))
                ->limit($limit)->get();
        } catch (\Throwable) { return []; }

        return $rows->map(static function ($r): array {
            $label = (int) $r->variant_id > 0
                ? (string) (DB::table('gates_product_variants')->where('id', (int) $r->variant_id)
                    ->value('label') ?? '(a removed option)')
                : '';
            return [
                'product_id' => (int) $r->product_id,
                'variant_id' => (int) $r->variant_id,
                'name'       => (string) $r->name,
                'slug'       => (string) $r->slug,
                'variant'    => $label,
                'waiting'    => (int) $r->n,
                'latest'     => (string) $r->latest,
            ];
        })->all();
    }
}
