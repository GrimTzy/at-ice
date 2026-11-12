<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Product;
use App\Models\Supplier;
use App\Support\TextRules;
use Illuminate\Console\Command;

// Lists saved rows whose text breaks the text rules (owner, 2026-11-12,
// App\Support\TextRules): names too long, with refused characters or keyboard
// mashing, and address lines or notes over their limit. Such a row cannot be
// saved again until the field is fixed. It changes nothing.
//   php artisan text:check
class CheckText extends Command
{
    protected $signature = 'text:check';

    protected $description = 'List customers, employees, holidays, suppliers and products whose text breaks the text rules (read only)';

    public function handle(): int
    {
        // model => [field => [kind, max]]; kind: store, name, person, full, length
        $checks = [
            'Customer' => [Customer::class, [
                'name' => ['store', TextRules::STORE],
                'contact_person' => ['full', TextRules::FULL],
                'street' => ['length', TextRules::ADDRESS],
                'barangay' => ['length', TextRules::ADDRESS],
                'city' => ['length', TextRules::ADDRESS],
                'province' => ['length', TextRules::ADDRESS],
                'block_lot' => ['length', TextRules::ADDRESS],
                'subdivision' => ['length', TextRules::ADDRESS],
                'notes' => ['length', TextRules::NOTE],
            ]],
            'Employee' => [Employee::class, [
                'first_name' => ['person', TextRules::PERSON],
                'last_name' => ['person', TextRules::PERSON],
                'street' => ['length', TextRules::ADDRESS],
                'barangay' => ['length', TextRules::ADDRESS],
                'city' => ['length', TextRules::ADDRESS],
                'province' => ['length', TextRules::ADDRESS],
                'block_lot' => ['length', TextRules::ADDRESS],
                'subdivision' => ['length', TextRules::ADDRESS],
                'emergency_name' => ['full', TextRules::FULL],
            ]],
            'Holiday' => [Holiday::class, [
                'name' => ['name', TextRules::HOLIDAY],
            ]],
            'Supplier' => [Supplier::class, [
                'name' => ['store', TextRules::NAME],
                'contact_person' => ['full', TextRules::FULL],
                'address' => ['length', TextRules::ADDRESS],
                'barangay' => ['length', TextRules::ADDRESS],
                'city' => ['length', TextRules::ADDRESS],
                'province' => ['length', TextRules::ADDRESS],
                'notes' => ['length', TextRules::NOTE],
            ]],
            'Product' => [Product::class, [
                'name' => ['name', TextRules::NAME],
                'category' => ['length', TextRules::NAME], // picked from a list
                'description' => ['length', TextRules::NOTE],
            ]],
        ];

        $rows = [];
        $checked = 0;
        foreach ($checks as $label => [$model, $fields]) {
            $table = (new $model)->getTable();
            $columns = array_values(array_filter(array_keys($fields), fn ($f) => \Illuminate\Support\Facades\Schema::hasColumn($table, $f)));
            foreach ($model::query()->get(array_merge(['id'], $columns)) as $row) {
                $checked++;
                foreach ($columns as $field) {
                    $value = $row->getRawOriginal($field);
                    if ($value === null || trim((string) $value) === '') {
                        continue;
                    }
                    if ($why = self::problem((string) $value, ...$fields[$field])) {
                        $rows[] = [$label, $row->id, $field, mb_strimwidth(preg_replace('/\s+/', ' ', (string) $value), 0, 50, '…'), $why];
                    }
                }
            }
        }

        $this->info("{$checked} rows checked. Nothing was changed.");
        if (! $rows) {
            $this->info('Every saved name, address line and note follows the text rules.');

            return self::SUCCESS;
        }
        $this->warn(count($rows).' field(s) break the text rules. Fix them on their page before the row is saved again:');
        $this->table(['Table', 'Id', 'Field', 'Value', 'Problem'], $rows);

        return self::SUCCESS;
    }

    public static function problem(string $value, string $kind, int $max): ?string
    {
        return match ($kind) {
            'length' => mb_strlen(trim($value)) > $max ? "Longer than {$max} characters (".mb_strlen(trim($value)).').' : null,
            // a supplier name (40) has the short-word limit, a store name (60) the long one
            'store' => TextRules::problem($value, TextRules::STORE_CHARS, $max, $max <= TextRules::NAME ? TextRules::SHORT_WORD : TextRules::LONG_WORD),
            'person' => TextRules::problem($value, TextRules::PERSON_CHARS, $max),
            'full' => TextRules::problem($value, TextRules::FULL_NAME_CHARS, $max),
            default => TextRules::problem($value, TextRules::NAME_CHARS, $max, TextRules::SHORT_WORD), // holiday, product
        };
    }
}
