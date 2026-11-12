<?php

namespace App\Support;

use Closure;

// Length and character rules for text people type (owner, 2026-11-12). The
// same rules and messages are in the web app (src/lib/textRules.js), which
// stops typing at the limit, shows a live "38 / 50" counter and the same
// message under the field.
//
//   NAME     40   route, product/item, supplier, chain, position
//   HOLIDAY  50   holiday (official names such as "Feast of the Immaculate
//                 Conception of Mary")
//   PERSON   40   first, middle and last name (each)
//   FULL     80   a whole person's name in one field (contact person,
//                 applicant, worker)
//   STORE    60   store or business name (customer, contract, mall stop,
//                 project, school, company)
//   ADDRESS 120   one address line (street, barangay, city, location...)
//   NOTE    500   notes, reasons, remarks
//
// Characters: names allow letters, numbers, spaces and . , ' - & ( ); store
// and business names also / and +; person names letters, spaces and . - '
// (a whole name in one field also a comma: "Dela Cruz, Juan").
//
// Keyboard mashing is refused in every name (owner, 2026-11-12):
//   - a word longer than 15 characters in a holiday, route, product/item,
//     supplier or position name (SHORT_WORD), 25 in the others (LONG_WORD)
//   - the same 2 to 4 letters 3 or more times in a row ("asdasdasd")
//   - a word that is the same 3 or 4 letters twice with two keys side by side
//     on the keyboard ("dasdas", "qweqwe"; "Bonbon", "Halohalo" pass)
//   - 4 or more keys side by side on a keyboard row ("qwer", "asdf", "zxcv",
//     "sdfg", either way)
//
// Messages follow the GOV.UK pattern: they name the field, say what is wrong
// and how to fix it, in plain words.
class TextRules
{
    public const NAME = 40;

    public const PERSON = 40;

    public const HOLIDAY = 50;

    public const FULL = 80;

    public const STORE = 60;

    public const ADDRESS = 120;

    public const NOTE = 500;

    public const SHORT_WORD = 15;

    public const LONG_WORD = 25;

    public const NAME_CHARS = "/^[\\p{L}\\p{N} .,'\\-&()]+$/u";

    public const STORE_CHARS = "/^[\\p{L}\\p{N} .,'\\-&()\\/+]+$/u";

    public const PERSON_CHARS = "/^[\\p{L} .'\\-]+$/u";

    // a whole name in one field may be written "Dela Cruz, Juan"
    public const FULL_NAME_CHARS = "/^[\\p{L} .,'\\-]+$/u";

    private const ALLOWED = [
        self::NAME_CHARS => 'letters, numbers, spaces, periods, commas, apostrophes, hyphens, ampersands (&) and brackets',
        self::STORE_CHARS => 'letters, numbers, spaces, periods, commas, apostrophes, hyphens, ampersands (&), brackets, slashes (/) and plus signs (+)',
        self::PERSON_CHARS => 'letters, spaces, periods, hyphens and apostrophes',
        self::FULL_NAME_CHARS => 'letters, spaces, commas, periods, hyphens and apostrophes',
    ];

    // Field names in messages, for attributes whose own name says too little.
    private const LABELS = [
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'contact_person' => 'Contact person',
        'emergency_name' => 'Emergency contact name',
        'emergency_relationship' => 'Relationship',
        'worker_name' => 'Worker name',
        'position_applied' => 'Position',
        'company_name' => 'Company name',
        'stop_group' => 'Stop name',
        'school' => 'School',
        'course' => 'Course or strand',
        'company' => 'Company',
        'position' => 'Position',
        'owner' => 'Owner',
        'chain' => 'Chain',
        'source' => 'Source',
        'label' => 'Label',
    ];

    // Rows of the keyboard, for "side by side" keys
    private const KEY_ROWS = ['qwertyuiop', 'asdfghjkl', 'zxcvbnm'];

    // A name (holiday, route, product, position, project...)
    public static function name(int $max = self::NAME, ?string $label = null, int $longestWord = self::SHORT_WORD): array
    {
        return ['string', self::check(self::NAME_CHARS, $max, $label, $longestWord)];
    }

    // A store or business name (customer, contract, supplier, mall stop,
    // pickup company): a name that may also have / and + ("Sari-Sari Store
    // 24/7", "Ice + Water Station").
    public static function store(int $max = self::STORE, ?string $label = null, int $longestWord = self::LONG_WORD): array
    {
        return ['string', self::check(self::STORE_CHARS, $max, $label, $longestWord)];
    }

    // A person's name (first, middle, last, or the whole name in one field)
    public static function person(int $max = self::PERSON, ?string $label = null): array
    {
        return ['string', self::check($max > self::PERSON ? self::FULL_NAME_CHARS : self::PERSON_CHARS, $max, $label, self::LONG_WORD)];
    }

    public static function address(): array
    {
        return ['string', 'max:'.self::ADDRESS];
    }

    public static function note(int $max = self::NOTE): array
    {
        return ['string', "max:{$max}"];
    }

    // What is wrong with a saved value, or null (holidays:check, text:check).
    public static function problem(?string $value, string $chars = self::NAME_CHARS, int $max = self::NAME, int $longestWord = self::LONG_WORD): ?string
    {
        $v = trim((string) $value);
        if ($v === '') {
            return 'Empty name.';
        }

        return self::message('Name', $v, $chars, $max, $longestWord);
    }

    // The GOV.UK style message for $value in the field $field, or null.
    public static function message(string $field, string $value, string $chars, int $max, int $longestWord): ?string
    {
        $v = trim($value);
        $lower = mb_strtolower($field);
        if (mb_strlen($v) > $max) {
            return "{$field} must be {$max} characters or fewer.";
        }
        if (! preg_match($chars, $v)) {
            return "{$field} can only include ".self::ALLOWED[$chars].'.';
        }
        if (! preg_match('/\p{L}/u', $v)) {
            return "{$field} must include letters.";
        }
        if (self::looksMashed($v, $longestWord)) {
            return "Check the spelling of the {$lower}. A word in it is too long or repeats the same letters.";
        }

        return null;
    }

    // Keyboard mashing: see the rules at the top.
    public static function looksMashed(string $value, int $longestWord = self::LONG_WORD): bool
    {
        foreach (preg_split('/[\s\-\/+,.()&]+/u', mb_strtolower(trim($value)), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            if (mb_strlen($word) > $longestWord) {
                return true;
            }
            // the same 2 to 4 letters 3 or more times in a row
            if (preg_match('/(\p{L}{2,4})\1{2,}/u', $word)) {
                return true;
            }
            // the same 3 or 4 letters twice, with two keys side by side
            if (preg_match('/^(\p{L}{3,4})\1$/u', $word, $m) && self::keyRun($m[1]) >= 2) {
                return true;
            }
            if (self::keyRun($word) >= 4) {
                return true;
            }
        }

        return false;
    }

    // The longest run of keys side by side on one keyboard row in $word
    // (left to right or right to left).
    private static function keyRun(string $word): int
    {
        $best = 1;
        $run = 1;
        $dir = 0;
        $chars = mb_str_split($word);
        for ($i = 1; $i < count($chars); $i++) {
            $step = self::keyStep($chars[$i - 1], $chars[$i]);
            if ($step !== 0 && $step === $dir) {
                $run++;
            } elseif ($step !== 0) {
                [$run, $dir] = [2, $step];
            } else {
                [$run, $dir] = [1, 0];
            }
            $best = max($best, $run);
        }

        return $best;
    }

    // +1 or -1 when $b is the next key to the right or left of $a, else 0
    private static function keyStep(string $a, string $b): int
    {
        foreach (self::KEY_ROWS as $row) {
            $i = strpos($row, $a);
            $j = strpos($row, $b);
            if ($i !== false && $j !== false && abs($i - $j) === 1) {
                return $j - $i;
            }
        }

        return 0;
    }

    public static function labelFor(string $attribute): string
    {
        $key = preg_replace('/^.*\./', '', $attribute);

        return self::LABELS[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

    private static function check(string $chars, int $max, ?string $label, int $longestWord): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($chars, $max, $label, $longestWord) {
            if (! is_string($value) || trim($value) === '') {
                return;
            }
            if ($why = self::message($label ?? self::labelFor($attribute), $value, $chars, $max, $longestWord)) {
                $fail($why);
            }
        };
    }
}
