<?php

namespace Oriclab\EInvoice;

use Jiannius\Myinvois\Helpers\Code;

/** LHDN code tables for host-app forms, so hosts never touch the SDK. */
final class Codes
{
    /** @return array<string, string> code => description */
    public static function classifications(): array
    {
        return self::table(Code::classifications()->all());
    }

    /** @return array<string, string> code => description */
    public static function taxTypes(): array
    {
        return self::table(Code::taxes()->all());
    }

    /** @return array<string, string> code => state */
    public static function states(): array
    {
        return self::table(Code::states()->all(), 'State');
    }

    /** LHDN state code for a code or a loosely written name ("selangor", "W.P. Kuala Lumpur", "KL"). */
    public static function state(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $states = self::states();
        if (isset($states[$value])) {
            return $value;
        }

        $normal = fn (string $s) => preg_replace('/[^a-z]/', '', str_ireplace(['wilayah persekutuan', 'w.p.', 'wp '], '', strtolower($s)));
        $needle = ['kl' => 'kualalumpur', 'penang' => 'pulaupinang', 'malacca' => 'melaka'][$normal($value)] ?? $normal($value);

        return array_search($needle, array_map($normal, $states), true) ?: null;
    }

    /**
     * @param  iterable<array<string, string>>  $rows
     * @return array<string, string>
     */
    private static function table(iterable $rows, string $label = 'Description'): array
    {
        return collect($rows)->mapWithKeys(fn ($row) => [$row['Code'] => trim($row[$label])])->all();
    }
}
