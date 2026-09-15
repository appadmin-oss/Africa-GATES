<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * What a vendor sells, as rows a machine and a visitor can both read.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS WHEN `what_they_sell` ALREADY DID
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A stand application carries one free-text field — up to two thousand characters of "tell
 * us about your trade". That is the right field for the question it asks and the wrong one
 * for the three things that happen afterwards:
 *
 *   · An ORGANISER allocating a hall against published CATEGORY QUOTAS has to read forty
 *     paragraphs and decide by eye which of them is "food". §5 of the vendor specification
 *     makes the allocation defensible by fixing the rule in advance — and a rule applied to
 *     data nobody can sort is still a judgement call wearing a rule's clothes.
 *   · A VISITOR planning their Saturday cannot see what will be on sale at all.
 *   · The VENDOR cannot correct a price or add a line, because the application is frozen
 *     after submission on purpose.
 *
 * The application keeps its paragraph — it is the vendor's own account of their trade at
 * the moment they applied, and part of the record the allocation was made against. This is
 * what they sell NOW, and it is bound to the ORGANISATION rather than to one application:
 * a trader who applies to three market days sells the same jollof at all three.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IT REFUSES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A price of zero, unless the vendor says so in words. A row with `price_naira = 0` prints
 * "Free" beside a bag of rice, and a column that defaults to zero makes that the default
 * for everybody who has not decided yet. So the price is NULLABLE and an empty box means
 * "not saying", which is a real answer at a market and is printed as one.
 *
 * A category outside the admin's own list. {@see VendorPolicy::categories()} is what the
 * quota rule counts against, so an item filed under a category the organiser does not
 * recognise is an item the quota cannot see — and a vendor typing "streetfood" would
 * quietly fall out of the food quota they were allocated against.
 */
final class VendorCatalogue
{
    /** Enough for a full stall, small enough that nobody is publishing a wholesale list. */
    public const MAX_ITEMS = 60;

    public const MAX_NAME  = 160;
    public const MAX_DESC  = 600;
    public const MAX_NOTE  = 80;

    /** ₦50m for one market-stall line item is a typo, not a price. */
    public const MAX_PRICE = 50_000_000;

    // ═══════════════════════════════════════════════════════════════════════
    // READING
    // ═══════════════════════════════════════════════════════════════════════

    /** @return array<int,object> Everything this vendor has, available or not. */
    public static function forOrg(int $orgId): array
    {
        if ($orgId < 1) return [];
        try {
            return DB::table('gates_vendor_items')
                ->where('org_id', $orgId)
                ->orderBy('sort_order')->orderBy('id')
                ->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * How many items this vendor has in each category.
     *
     * The number an organiser needs and the one the paragraph could never give: it is what
     * makes "this applicant is a food trader" a fact rather than a reading.
     *
     * @return array<string,int> category slug → count
     */
    public static function categoryMix(int $orgId): array
    {
        $out = [];
        foreach (self::forOrg($orgId) as $i) {
            $c = trim((string) ($i->category ?? ''));
            if ($c === '') continue;
            $out[$c] = ($out[$c] ?? 0) + 1;
        }
        arsort($out);
        return $out;
    }

    /**
     * The single category this vendor mostly trades in, or ''.
     *
     * ADVISORY, and named that way on every screen that shows it. It is a count of what
     * they typed, not a verdict — an organiser allocating against a quota still decides,
     * and a vendor whose catalogue is half food and half craft is a real thing that a
     * single label would flatten.
     */
    public static function leadingCategory(int $orgId): string
    {
        $mix = self::categoryMix($orgId);
        return $mix === [] ? '' : (string) array_key_first($mix);
    }

    public static function find(int $id): ?object
    {
        if ($id < 1) return null;
        try {
            return DB::table('gates_vendor_items')->where('id', $id)->first();
        } catch (\Throwable) {
            return null;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // WRITING
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Add or edit one line.
     *
     * @return array{ok:bool, message:string, id?:int, field?:string}
     */
    public static function save(int $orgId, int $id, array $in): array
    {
        if ($orgId < 1) return ['ok' => false, 'message' => 'That account does not exist.'];

        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'field' => 'name',
                    'message' => 'Give the item a name — what a customer would call it.'];
        }
        if (mb_strlen($name) > self::MAX_NAME) {
            return ['ok' => false, 'field' => 'name',
                    'message' => 'That name is too long. Keep it to what fits on a sign — '
                               . self::MAX_NAME . ' characters.'];
        }

        // ── the category, against the admin's own list ───────────────────────
        $category = trim((string) ($in['category'] ?? ''));
        if ($category !== '' && !array_key_exists($category, VendorPolicy::categories())) {
            return ['ok' => false, 'field' => 'category',
                    'message' => 'Choose one of the categories on the list. Stands are '
                               . 'allocated against those categories, so an item filed under '
                               . 'anything else would not count towards your quota.'];
        }

        // ── the price, which is allowed to be absent ─────────────────────────
        $rawPrice = trim((string) ($in['price_naira'] ?? ''));
        $price    = null;
        if ($rawPrice !== '') {
            $digits = preg_replace('~[^0-9]~', '', $rawPrice) ?? '';
            if ($digits === '') {
                return ['ok' => false, 'field' => 'price_naira',
                        'message' => 'Write the price as a number, or leave it empty and put '
                                   . 'what you want to say in the note beside it.'];
            }
            $price = (int) $digits;
            if ($price > self::MAX_PRICE) {
                return ['ok' => false, 'field' => 'price_naira',
                        'message' => 'That is more than ₦' . number_format(self::MAX_PRICE)
                                   . ' for one item, which is usually a typo. If it is not, '
                                   . 'leave the price empty and describe it instead.'];
            }
        }

        $row = [
            'org_id'       => $orgId,
            'name'         => mb_substr($name, 0, self::MAX_NAME),
            'category'     => $category !== '' ? $category : null,
            'price_naira'  => $price,
            'price_note'   => mb_substr(trim((string) ($in['price_note'] ?? '')), 0, self::MAX_NOTE) ?: null,
            'description'  => mb_substr(trim((string) ($in['description'] ?? '')), 0, self::MAX_DESC) ?: null,
            'is_available' => empty($in['unavailable']) ? 1 : 0,
            'sort_order'   => max(0, min(999, (int) ($in['sort_order'] ?? 0))),
            'updated_at'   => date('Y-m-d H:i:s'),
        ];

        try {
            if ($id > 0) {
                $existing = self::find($id);
                // Scoped to the OWNER on the update, not only on the read. A dashboard that
                // checks ownership when it renders the edit form and not when it saves is a
                // dashboard where the id in a hidden field is the whole authorisation.
                if (!$existing || (int) $existing->org_id !== $orgId) {
                    return ['ok' => false, 'message' => 'That item is not yours.'];
                }
                DB::table('gates_vendor_items')->where('id', $id)->where('org_id', $orgId)
                  ->update($row);
                return ['ok' => true, 'id' => $id, 'message' => 'Saved.'];
            }

            if (count(self::forOrg($orgId)) >= self::MAX_ITEMS) {
                return ['ok' => false,
                        'message' => 'You have ' . self::MAX_ITEMS . ' items, which is the most '
                                   . 'this list holds. Remove one, or group a few together.'];
            }

            $row['created_at'] = date('Y-m-d H:i:s');
            $newId = (int) DB::table('gates_vendor_items')->insertGetId($row);
            return ['ok' => true, 'id' => $newId, 'message' => 'Added.'];
        } catch (\Throwable $e) {
            error_log('[catalogue] save failed: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'That could not be saved just now.'];
        }
    }

    /** Attach a photograph already stored on disk. Path guarded the same way a logo is. */
    public static function attachPhoto(int $orgId, int $id, string $path): array
    {
        $path = ltrim(trim($path), '/');
        if (!OrgBrand::safePath($path)) {
            return ['ok' => false, 'message' => 'That photograph could not be attached.'];
        }

        $item = self::find($id);
        if (!$item || (int) $item->org_id !== $orgId) {
            return ['ok' => false, 'message' => 'That item is not yours.'];
        }

        try {
            DB::table('gates_vendor_items')->where('id', $id)->where('org_id', $orgId)
              ->update(['photo_path' => $path, 'updated_at' => date('Y-m-d H:i:s')]);
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'That could not be saved just now.'];
        }

        return ['ok' => true, 'message' => 'Photograph added.'];
    }

    public static function delete(int $orgId, int $id): array
    {
        $item = self::find($id);
        if (!$item || (int) $item->org_id !== $orgId) {
            return ['ok' => false, 'message' => 'That item is not yours.'];
        }

        try {
            DB::table('gates_vendor_items')->where('id', $id)->where('org_id', $orgId)->delete();
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'That could not be removed just now.'];
        }

        return ['ok' => true, 'message' => 'Removed.'];
    }

    /** Toggle one line in or out of the public catalogue. */
    public static function setAvailable(int $orgId, int $id, bool $available): array
    {
        $item = self::find($id);
        if (!$item || (int) $item->org_id !== $orgId) {
            return ['ok' => false, 'message' => 'That item is not yours.'];
        }

        try {
            DB::table('gates_vendor_items')->where('id', $id)->where('org_id', $orgId)
              ->update(['is_available' => $available ? 1 : 0,
                        'updated_at'   => date('Y-m-d H:i:s')]);
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'That could not be changed just now.'];
        }

        return ['ok' => true, 'message' => $available ? 'Back on the list.' : 'Marked unavailable.'];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PRESENTATION
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * The price as a visitor should read it.
     *
     * No price and no note is "Ask at the stand" and not "₦0" — the difference between a
     * trader who has not decided and a trader giving it away.
     */
    public static function priceLabel(?object $item): string
    {
        $note  = trim((string) ($item->price_note ?? ''));
        $naira = $item->price_naira ?? null;

        if ($naira === null || $naira === '') {
            return $note !== '' ? $note : 'Ask at the stand';
        }

        $money = '₦' . number_format((int) $naira);
        return $note !== '' ? $money . ' · ' . $note : $money;
    }
}
