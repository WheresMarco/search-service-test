<?php

declare(strict_types=1);

namespace App\Tests;

use App\Search\ServiceMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServiceMatcherTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'exact' => ['Massage', 'Massage', true],
            'case-insensitive' => ['MASSAGE', 'Klassisk massage', true],
            'substring inside compound word' => ['massage', 'Djupvävnadsmassage', true],
            'sportmassage' => ['massage', 'Sportmassage', true],
            'accent folding in name' => ['djupvavnad', 'Djupvävnadsmassage', true],
            'accent in query' => ['herrklippning', 'Herrklippning', true],
            'whitespace trimmed' => ['  klassisk   massage ', 'Klassisk massage', true],
            'no synonyms' => ['massör', 'Klassisk massage', false],
            'massage is not massör' => ['massage', 'Massör - 50 min', false],
            'unrelated' => ['klippning', 'Sportmassage', false],
            'empty query matches nothing' => ['   ', 'Massage', false],
        ];
    }

    #[DataProvider('cases')]
    public function testMatches(string $query, string $name, bool $expected): void
    {
        self::assertSame($expected, ServiceMatcher::matches($query, $name));
    }
}
