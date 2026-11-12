<?php

namespace Tests\Feature;

use App\Models\Holiday;
use App\Support\TextRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Stricter keyboard-mashing check (owner, 2026-11-12): "Chinese New
// Yearsadaswasdasdasass" got through when only words over 25 characters were
// refused. Real names must still pass.
class NameMashingTest extends TestCase
{
    use RefreshDatabase;

    private const MASHED = 'Check the spelling of the name. A word in it is too long or repeats the same letters.';

    private function short(string $v): ?string
    {
        return TextRules::message('Name', $v, TextRules::NAME_CHARS, TextRules::HOLIDAY, TextRules::SHORT_WORD);
    }

    public function test_mashing_is_refused(): void
    {
        foreach (['Chinese New Yearsadaswasdasdasass', 'Asdasdasd', 'Dasdas', 'Qwer Store', 'Asdf', 'Zxcv', 'Sdfg', 'Fdsa', 'Qweqwe', 'Hahahaha', 'Poiuy'] as $v) {
            $this->assertSame(self::MASHED, $this->short($v), $v);
        }
        // holiday, route, product, supplier, position: no word over 15 characters
        $this->assertSame(self::MASHED, $this->short('Extraordinaryday Holiday'));
        // other names keep 25
        $this->assertNull(TextRules::message('Name', 'Extraordinaryday Store', TextRules::STORE_CHARS, TextRules::STORE, TextRules::LONG_WORD));
    }

    public function test_real_filipino_and_english_names_pass(): void
    {
        $people = ['Juan', 'Maria Clara', 'Dela Cruz', 'De los Santos', 'Macapagal-Arroyo', "O'Brien", 'Ma. Teresa', 'Ñoño', 'Kristine Joy', 'Bongbong', 'Lualhati',
            'Dimaculangan', 'Pangilinan', 'Robert', 'Albert', 'Gilbert', 'Christopher', 'Alexander', 'Katherine', 'Jojo', 'Lolo', 'Nonoy', 'Bebeng', 'Dodong', 'Tyrell'];
        foreach ($people as $v) {
            $this->assertNull(TextRules::message('Name', $v, TextRules::PERSON_CHARS, TextRules::PERSON, TextRules::LONG_WORD), $v);
        }
        $stores = ['Tindahan ni Aling Rosa', "Lola Nena's Halo-Halo", 'Halohalo Haus', 'Bonbon Bakeshop', 'Bulaklak Flower Shop', 'Kyu Kyu Ramen',
            'Gang Gang Chicken', 'Puregold Jr. Muzon', "McDonald's Quirino Highway", 'Sari-Sari Store 24/7', 'Pharmaceuticals Inc.'];
        foreach ($stores as $v) {
            $this->assertNull(TextRules::message('Name', $v, TextRules::STORE_CHARS, TextRules::STORE, TextRules::SHORT_WORD), $v);
        }
    }

    public function test_every_holiday_in_the_database_passes(): void
    {
        $names = Holiday::pluck('name'); // the seeded official list
        $this->assertNotEmpty($names);
        foreach ($names as $name) {
            $this->assertNull($this->short($name), $name);
        }
    }

    public function test_messages_name_the_field_and_say_how_to_fix_it(): void
    {
        $this->assertSame('Holiday name must be 50 characters or fewer.', TextRules::message('Holiday name', str_repeat('Long name ', 6), TextRules::NAME_CHARS, 50, 15));
        $this->assertSame('First name can only include letters, spaces, periods, hyphens and apostrophes.', TextRules::message('First name', 'Juan2', TextRules::PERSON_CHARS, 40, 25));
        $this->assertSame('Route name must include letters.', TextRules::message('Route name', '123', TextRules::NAME_CHARS, 40, 15));
    }
}
