<?php

declare(strict_types=1);

namespace AndyDefer\Task\Helpers;

use AndyDefer\DomainStructures\Utils\MapCollection;

/**
 * Array helper utilities.
 */
final class ArrayHelper
{
    /**
     * Recursively convert all array keys to PascalCase.
     *
     * @param  array<string, mixed>  $array  The associative array to convert
     * @return array<string, mixed> The array with keys converted to PascalCase
     */
    public static function keysToPascalCase(array $array): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $newKey = self::toPascalCase((string) $key);

            if (is_array($value)) {
                $result[$newKey] = self::keysToPascalCase($value);
            } elseif (is_object($value) && method_exists($value, 'toArray')) {
                $result[$newKey] = self::keysToPascalCase($value->toArray());
            } else {
                $result[$newKey] = $value;
            }
        }

        return $result;
    }

    /**
     * Convert an iterable or an object with toArray() to a MapCollection with PascalCase keys.
     *
     * @param  iterable<array-key, mixed>|object  $data  The data to convert
     * @return MapCollection<string, mixed> A MapCollection with PascalCase keys
     */
    public static function toPascalMap(iterable|object $data): MapCollection
    {
        $array = [];

        if ($data instanceof MapCollection) {
            $array = $data->toArray();
        } elseif (is_array($data)) {
            $array = $data;
        } elseif ($data instanceof \Traversable) {
            $array = iterator_to_array($data);
        } elseif (is_object($data) && method_exists($data, 'toArray')) {
            $array = $data->toArray();
        } elseif (is_object($data)) {
            $array = (array) $data;
        }

        $converted = self::keysToPascalCase($array);

        return MapCollection::from($converted);
    }

    /**
     * Convert a string to PascalCase.
     *
     * @param  string  $string  The string to convert
     * @return string The PascalCase string
     */
    private static function toPascalCase(string $string): string
    {
        $cleaned = preg_replace('/[^a-zA-Z0-9]/', ' ', $string);
        $words = preg_split('/\s+/', $cleaned ?? '');
        $words = array_filter($words);

        $pascalCase = array_map('ucfirst', $words);

        return implode('', $pascalCase);
    }
}
