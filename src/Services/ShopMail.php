<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * The buyer's emails after the receipt: cancelled, refunded, delivered, ready to collect.
 *
 * Before this the buyer heard about two moments — paid and shipped. An order cancelled from
 * the console, one delivered, and money sent back were all silent, so the first the buyer knew
 * of a cancellation was the absence of a parcel, and a refund was a number appearing in a bank
 * statement days later with nothing to say what it was for.
 *
 * Two doors, one message. {@see send()} runs inline when a person pressed something in the
 * console and the mailer is to hand. {@see queue()} is for the webhook, which has a ~30-second
 * budget for the whole delivery and must not spend twelve of it on SMTP (the same reasoning as
 * {@see ShopOrderService::JOB_RECEIPT}); the job is deduplicated per order, kind and key, so a
 * gateway re-delivering `refund.processed` sends one email.
 *
 * Every send is best-effort and never throws: the state is in the database whether or not the
 * message got through, and the buyer's own order page says the same thing.
 */
final class ShopMail
{
    public const JOB = 'shop.mail';

    public const KINDS = ['cancelled', 'refunded', 'refund_failed', 'delivered', 'ready', 'collected'];

    /** @param array<string,scalar|null> $data */
    public static function queue(int $orderId, string $kind, array $data = [], string $key = ''): void
    {
        if ($orderId < 1 || !in_array($kind, self::KINDS, true)) return;
        try {
            (new QueueService())->push(self::JOB, ['order_id' => $orderId, 'kind' => $kind, 'data' => $data],
                0, 'shop-mail-' . $orderId . '-' . $kind . ($key !== '' ? '-' . $key : ''));
        } catch (\Throwable $e) {
            error_log('[shop] could not queue a buyer email: ' . $e->getMessage());
        }
    }

    /** The queue's handler. */
    public static function handle(array $payload, ?OtpService $mailer): void
    {
        self::send($mailer, (int) ($payload['order_id'] ?? 0), (string) ($payload['kind'] ?? ''),
                   is_array($payload['data'] ?? null) ? $payload['data'] : []);
    }

    /**
     * @param array<string,scalar|null> $data
     * @return bool whether a message was handed to the transport
     */
    public static function send(?OtpService $mailer, int $orderId, string $kind, array $data = []): bool
    {
        if ($mailer === null || !in_array($kind, self::KINDS, true)) return false;
        $order = DB::table('gates_orders')->where('id', $orderId)->first();
        if (!$order || !filter_var((string) $order->email, FILTER_VALIDATE_EMAIL)) return false;

        [$subject, $lines] = self::words($order, $kind, $data);
        $e    = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $link = rtrim(\AfricaGates\Support\SiteUrl::base(), '/') . '/shop/order/' . rawurlencode((string) $order->reference);

        $html = '<p>Hello <strong>' . $e((string) $order->name) . '</strong>,</p>';
        foreach ($lines as $l) $html .= '<p>' . $e($l) . '</p>';
        $html .= '<p><a href="' . $e($link) . '">See this order</a></p>';
        $plain = 'Hello ' . (string) $order->name . ",\n\n" . implode("\n\n", $lines)
               . "\n\n" . $link . "\n\n— Africa GATES";

        try {
            $mailer->sendBranded((string) $order->email, $subject, $html, $plain, 'Shop');
            ShopOrderLog::add($orderId, 'mail', 'Buyer emailed: ' . $subject);
            return true;
        } catch (\Throwable $ex) {
            error_log('[shop] buyer email "' . $kind . '" failed for #' . $orderId . ': ' . $ex->getMessage());
            return false;
        }
    }

    /**
     * @param array<string,scalar|null> $data
     * @return array{0:string,1:list<string>}
     */
    private static function words(object $order, string $kind, array $data): array
    {
        $ref    = (string) $order->reference;
        $amount = isset($data['amount']) ? '₦' . number_format((int) $data['amount']) : '';
        $reason = trim((string) ($data['reason'] ?? ''));

        return match ($kind) {
            'cancelled' => ['Your order ' . $ref . ' has been cancelled', array_values(array_filter([
                'Your order ' . $ref . ' has been cancelled.',
                $reason !== '' ? 'Reason: ' . $reason : '',
                ($data['refund'] ?? '') === 'gateway'
                    ? 'The money you paid is being returned to the card or account you paid with. We will email again when the payment provider confirms it; it can then take up to 10 working days to reach you.'
                    : (($data['refund'] ?? '') === 'manual'
                        ? 'The money you paid has been returned to you directly.'
                        : 'No payment had been taken for it.'),
            ]))],
            'refunded' => ['Your refund for ' . $ref . ' has been processed', [
                'A refund of ' . ($amount ?: 'your payment') . ' for order ' . $ref . ' has been processed.',
                ($data['method'] ?? '') === 'manual'
                    ? 'It was paid back to you directly.'
                    : 'Your bank or card issuer can take up to 10 working days to show it.',
            ]],
            'refund_failed' => ['A refund for ' . $ref . ' could not be completed', [
                'We tried to refund ' . ($amount ?: 'your payment') . ' for order ' . $ref . ', and the payment provider could not complete it.',
                'Nothing has been lost: our team has been told and will contact you to return the money another way.',
            ]],
            'delivered' => ['Your order ' . $ref . ' has been delivered', [
                'Your order ' . $ref . ' has been marked as delivered. Thank you for supporting Africa GATES.',
                'If anything is missing or not right, reply to this email.',
            ]],
            'ready' => ['Your order ' . $ref . ' is ready to collect', array_values(array_filter([
                'Your order ' . $ref . ' is ready to collect' . (($data['point'] ?? '') !== '' ? ' at ' . $data['point'] : '') . '.',
                ($data['address'] ?? '') !== '' ? 'Where: ' . $data['address'] : '',
                ($data['hours'] ?? '') !== '' ? 'When: ' . $data['hours'] : '',
                ($data['code'] ?? '') !== '' ? 'Your collection code is ' . $data['code'] . '. Show it when you collect.' : '',
            ]))],
            'collected' => ['Your order ' . $ref . ' has been collected', [
                'Your order ' . $ref . ' was collected. Thank you for supporting Africa GATES.',
            ]],
        };
    }
}
