<?php

declare(strict_types=1);

namespace App\Search;

/**
 * Case- and accent-insensitive substring matching on service names.
 * "massage" matches "Djupvävnadsmassage"; "djupvavnad" matches it too.
 * Deliberately no synonyms or fuzzy matching ("massör" does not match "massage").
 */
final class ServiceMatcher
{
    private const FALLBACK_MAP = [
        'å' => 'a', 'ä' => 'a', 'à' => 'a', 'á' => 'a', 'â' => 'a',
        'ö' => 'o', 'ø' => 'o', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ü' => 'u', 'ú' => 'u', 'ù' => 'u', 'í' => 'i', 'ï' => 'i', 'æ' => 'ae', 'ç' => 'c', 'ñ' => 'n',
    ];

    public static function matches(string $query, string $serviceName): bool
    {
        $needle = self::normalize($query);

        return $needle !== '' && str_contains(self::normalize($serviceName), $needle);
    }

    public static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');

        if (class_exists(\Transliterator::class)) {
            $t = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
            $s = $t?->transliterate($s) ?: $s;
        }
        $s = strtr($s, self::FALLBACK_MAP);

        // Collapse whitespace so "klassisk   massage" still matches.
        return preg_replace('/\s+/u', ' ', $s) ?? $s;
    }
}
