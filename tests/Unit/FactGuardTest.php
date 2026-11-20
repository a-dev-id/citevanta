<?php

namespace Tests\Unit;

use App\Services\Content\FactGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FactGuardTest extends TestCase
{
    private const FACTS = ['Check-in from 14:00', 'USD 120-180 per night', '45 minutes to the airport'];

    public static function texts(): array
    {
        return [
            'numbers from the facts stay' => ['Check-in is from 14:00 and rooms cost USD 120-180.', 'Check-in is from 14:00 and rooms cost USD 120-180.'],
            'an invented distance is flagged' => ['The beach is 10 minutes away.', 'The beach is [check: 10 minutes] away.'],
            'an invented price is flagged' => ['Rooms from USD 95.', 'Rooms from USD [check: 95].'],
            'list numbering stays' => ["1. Pool\n2) Spa", "1. Pool\n2) Spa"],
            'placeholders stay' => ['Distance: [add: 12 km to beach]', 'Distance: [add: 12 km to beach]'],
            'text without numbers stays' => ['A calm garden pool.', 'A calm garden pool.'],
        ];
    }

    #[DataProvider('texts')]
    public function test_numbers_not_in_the_facts_are_flagged(string $text, string $expected): void
    {
        $this->assertSame($expected, FactGuard::check($text, self::FACTS));
    }
}
