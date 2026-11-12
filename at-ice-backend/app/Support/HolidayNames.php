<?php

namespace App\Support;

// When two holiday names are the same holiday (owner, 2026-11-12), using the
// usual duplicate-rule pattern: block sure duplicates, warn on possible ones.
// The web app has the same rules (src/lib/holidayNames.js).
//
//   key()        the name compared: lower case, without . ' and with - , as
//                spaces, single spaces, and a trailing "s" dropped from each
//                word. "Chinese New Years" and "chinese new year" have the
//                same key: saving is blocked.
//   isClose()    keys that differ by only 1 or 2 letters, for names of 8 or
//                more letters ("Chinese New Yaer"): a soft "Did you mean"
//                note; saving is still allowed.
// Christmas Eve / Christmas Day (3 letters apart) and the other real
// holidays are neither.
class HolidayNames
{
    public const MIN_LETTERS = 8;

    public const MAX_APART = 2;

    public static function key(?string $name): string
    {
        $v = mb_strtolower(trim((string) $name));
        $v = preg_replace("/[.'’]/u", '', $v);
        $v = preg_replace('/[\-,]/u', ' ', $v);
        $words = preg_split('/\s+/u', trim($v), -1, PREG_SPLIT_NO_EMPTY);

        return implode(' ', array_map(fn ($w) => mb_strlen($w) > 1 ? preg_replace('/s$/u', '', $w) : $w, $words));
    }

    public static function same(?string $a, ?string $b): bool
    {
        return self::key($a) === self::key($b);
    }

    // 1 or 2 letters apart (not the same), for names of 8 or more letters.
    public static function isClose(?string $a, ?string $b): bool
    {
        $ka = self::key($a);
        $kb = self::key($b);
        if ($ka === $kb || self::letters($ka) < self::MIN_LETTERS || self::letters($kb) < self::MIN_LETTERS) {
            return false;
        }

        return self::distance($ka, $kb) <= self::MAX_APART;
    }

    private static function letters(string $key): int
    {
        return preg_match_all('/\p{L}/u', $key);
    }

    // Edit distance in letters (multibyte safe), stopping early past MAX_APART.
    public static function distance(string $a, string $b): int
    {
        $a = mb_str_split($a);
        $b = mb_str_split($b);
        if (abs(count($a) - count($b)) > self::MAX_APART) {
            return self::MAX_APART + 1;
        }
        $prev = range(0, count($b));
        foreach ($a as $i => $ca) {
            $cur = [$i + 1];
            foreach ($b as $j => $cb) {
                $cur[] = min($prev[$j + 1] + 1, $cur[$j] + 1, $prev[$j] + ($ca === $cb ? 0 : 1));
            }
            $prev = $cur;
        }

        return end($prev);
    }
}
