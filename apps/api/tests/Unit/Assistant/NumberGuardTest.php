<?php

namespace Tests\Unit\Assistant;

use App\Support\Assistant\NumberGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** P-04: every number of a reply must be a number a tool (or the user) gave. Pure class: no framework, no database. */
class NumberGuardTest extends TestCase
{
    private NumberGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new NumberGuard(['ES', 'ISO', 'CAS', 'IFRA', 'Category', 'الفئة']);
    }

    /**
     * @param  array<mixed>  $toolResult
     * @return list<string>
     */
    private function violations(string $reply, array $toolResult = [], string $userText = ''): array
    {
        return $this->guard->violations($reply, $this->guard->collect([$toolResult, $userText]));
    }

    public function test_an_invented_percentage_is_rejected(): void
    {
        $this->assertSame(['12'], $this->violations('حوالي 12% من الخامة دي', ['percentWW' => '35']));
    }

    public function test_a_number_the_user_wrote_may_be_repeated(): void
    {
        $this->assertSame([], $this->violations('طلبت 35 جنيه للعبوة', [], 'عايز شامبو بـ 35 جنيه'));
    }

    public function test_arabic_indic_digits_match_the_latin_number_of_the_tool(): void
    {
        $this->assertSame([], $this->violations('النسبة ١٢٫٥٪', ['percentWW' => '12.5']));
    }

    public function test_arabic_indic_digits_of_the_tool_match_a_latin_reply(): void
    {
        $this->assertSame([], $this->violations('The share is 12.5%', ['percentWW' => '١٢٫٥']));
    }

    public function test_eastern_arabic_indic_digits_are_normalized(): void
    {
        $this->assertSame([], $this->violations('نسبة ۱۲٫۵', ['percentWW' => '12.5']));
    }

    #[DataProvider('equivalentForms')]
    public function test_the_same_number_in_another_written_form_is_accepted(string $reply, string $tool): void
    {
        $this->assertSame([], $this->violations($reply, ['value' => $tool]));
    }

    /** @return array<string, array{string, string}> */
    public static function equivalentForms(): array
    {
        return [
            'trailing zeros of the reply' => ['القيمة 12.500', '12.5'],
            'trailing zeros of the tool' => ['القيمة 12.5', '12.500000'],
            'whole number with .0' => ['16.0 جنيه', '16'],
            'leading zeros' => ['012.5', '12.5'],
            'leading dot' => ['.5 كجم', '0.5'],
            'thousands comma' => ['التكلفة 1,234.5', '1234.5'],
            'thousands comma on the tool' => ['التكلفة 1234.5', '1,234.5'],
            'arabic thousands and decimal marks' => ['١٬٢٣٤٫٥', '1234.5'],
            'decimal comma' => ['12,5', '12.5'],
            'ambiguous comma read as thousands' => ['1,500', '1500'],
            'ambiguous comma read as decimal' => ['1,500', '1.5'],
            'a negative number' => ['الفرق -3.25', '-3.25'],
        ];
    }

    #[DataProvider('differentNumbers')]
    public function test_a_rounded_or_shifted_number_is_a_different_number(string $reply, string $tool): void
    {
        $this->assertNotSame([], $this->violations($reply, ['value' => $tool]));
    }

    /** @return array<string, array{string, string}> */
    public static function differentNumbers(): array
    {
        return [
            'rounded up' => ['13', '12.5'],
            'rounded down' => ['12', '12.5'],
            'truncated decimals' => ['12.4', '12.4999'],
            'a ratio instead of a percent' => ['0.125', '12.5'],
            'the sign is part of the number' => ['3.25', '-3.25'],
            'a digit more' => ['125', '12.5'],
        ];
    }

    public function test_numbers_inside_units_are_still_numbers(): void
    {
        $this->assertSame(['5', '250'], $this->violations('ضيف 5kg و250مل', ['percentWW' => '35']));
        $this->assertSame([], $this->violations('ضيف 5kg و250مل', ['a' => '5', 'b' => '250']));
    }

    public function test_a_percent_sign_or_unit_word_does_not_change_the_number(): void
    {
        $this->assertSame([], $this->violations('12.5 % و 12.5٪ و 12.5 في المية', ['percentWW' => '12.5']));
    }

    public function test_an_arabic_prefix_glued_to_a_number_does_not_hide_it(): void
    {
        $this->assertSame(['40'], $this->violations('والنسبة و40 جنيه', ['percentWW' => '35']));
        $this->assertSame(['35'], $this->violations('بـ35 جنيه', ['percentWW' => '12']));
    }

    public function test_a_range_is_two_numbers(): void
    {
        $this->assertSame(['14'], $this->violations('من 12 إلى 14', ['a' => '12']));
        $this->assertSame(['14'], $this->violations('12-14', ['a' => '12']));
    }

    public function test_list_markers_are_not_numbers(): void
    {
        $this->assertSame([], $this->violations("1. أول خطوة\n2) تاني خطوة\n- 3. تالت خطوة\n**4.** رابع"));
    }

    public function test_a_number_inside_a_sentence_is_not_a_list_marker(): void
    {
        $this->assertSame(['2'], $this->violations('ضيف 2. ثم قلّب'));
    }

    public function test_dates_are_not_numbers(): void
    {
        $this->assertSame([], $this->violations('السعر بتاريخ 2026-11-23 وفي 23/11/2026'));
    }

    public function test_chemical_names_and_codes_glued_to_latin_letters_are_not_numbers(): void
    {
        $this->assertSame([], $this->violations('SLES-70 وPEG-40 وC12-14 وH2O وL3 وE330'));
    }

    public function test_a_ph_value_is_a_number_even_without_a_space(): void
    {
        $this->assertSame(['5.5'], $this->violations('pH5.5'));
        $this->assertSame([], $this->violations('pH5.5', ['ph' => '5.5']));
    }

    public function test_standard_and_category_numbers_after_their_word_are_not_numbers(): void
    {
        $this->assertSame([], $this->violations('حسب ES 1562 وISO 22716 وCAS 7732-18-5 وIFRA Category 4 والفئة ٥'));
    }

    public function test_a_standard_word_does_not_excuse_the_next_quantity(): void
    {
        $this->assertSame(['3'], $this->violations('حسب ES 1562 ثم 3 كجم'));
    }

    public function test_a_reply_with_no_numbers_passes(): void
    {
        $this->assertSame([], $this->violations('مفيش بيانات كفاية، محتاج سعر الخامة.'));
    }

    public function test_each_unsupported_number_is_listed_once(): void
    {
        $this->assertSame(['12', '7'], $this->violations('12 ثم 7 ثم 12 تاني'));
    }

    public function test_numbers_of_a_tool_are_collected_from_nested_data_and_numbers(): void
    {
        $allowed = $this->guard->collect([['lines' => [['percentWW' => '60.000', 'cost' => '6']], 'count' => 3, 'ratio' => 0.5]]);

        $this->assertSame(['60', '6', '3', '0.5'], array_map('strval', array_keys($allowed)));
    }

    public function test_identifiers_and_hashes_of_a_tool_do_not_supply_numbers(): void
    {
        $allowed = $this->guard->collect([['materialId' => '3f2a9b-7710', 'inputHash' => 'ab12cd34', 'total' => '16']], ['materialId', 'inputHash']);

        $this->assertSame(['16'], array_map('strval', array_keys($allowed)));
        $this->assertSame(['7710'], $this->guard->violations('الكود 7710', $allowed));
    }

    public function test_the_tool_numbers_must_be_from_this_turn_only(): void
    {
        $thisTurn = $this->guard->collect([['total' => '16']]);

        $this->assertSame([], $this->guard->violations('التكلفة 16', $thisTurn));
        $this->assertSame(['25'], $this->guard->violations('التكلفة 25', $thisTurn));
    }

    public function test_the_guard_is_deterministic(): void
    {
        $reply = 'الخامة ١٢٫٥٪ وحوالي 7 و ES 1562';
        $allowed = $this->guard->collect([['a' => '12.5']]);

        $this->assertSame($this->guard->violations($reply, $allowed), $this->guard->violations($reply, $allowed));
        $this->assertSame($this->guard->extract($reply), $this->guard->extract($reply));
    }
}
