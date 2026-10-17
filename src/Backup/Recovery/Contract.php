<?php

declare(strict_types=1);

namespace SecureS3StorageForWordpress\Backup\Recovery;

use DateTimeImmutable;
use RuntimeException;
use stdClass;

/** Strict, bounded wire validation. Errors deliberately do not echo input. */
final class Contract
{
    public static function require(bool $condition): void
    {
        if (! $condition) {
            throw new RuntimeException('Invalid recovery contract.');
        }
    }

    public static function fields(array $value, array $names): void
    {
        $keys = array_keys($value);
        sort($keys);
        sort($names);
        self::require($keys === $names);
    }

    public static function id(mixed $value): void
    {
        self::require(is_string($value) && preg_match('/^[a-f0-9]{32}$/D', $value) === 1);
    }

    public static function hash(mixed $value): void
    {
        self::require(is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1);
    }

    public static function count(mixed $value, int $minimum = 0): void
    {
        self::require(is_int($value) && $value >= $minimum);
    }

    public static function time(mixed $value): void
    {
        self::require(is_string($value));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value);
        self::require($date !== false && $date->format('Y-m-d\TH:i:s\Z') === $value);
    }

    public static function interval(array $value, string $earliest, string $latest): void
    {
        self::fields($value, ['start', 'end']);
        self::time($value['start']);
        self::time($value['end']);
        self::require($earliest <= $value['start'] && $value['start'] <= $value['end'] && $value['end'] <= $latest);
    }

    public static function path(string $value, bool $allowEmpty = false): void
    {
        self::require(($allowEmpty && $value === '') || ($value !== '' && strlen($value) <= 800
            && preg_match('/^[A-Za-z0-9_.\/-]+$/D', $value) === 1
            && ! array_intersect(explode('/', $value), ['', '.', '..'])));
    }

    public static function decode(string $json): array
    {
        self::require(strlen($json) <= 65536);
        $decoded = json_decode($json, false, 16, JSON_THROW_ON_ERROR);
        self::require($decoded instanceof stdClass);
        // json_decode silently accepts duplicate names. Walk its already-validated
        // token stream, decoding escaped names before checking each object scope.
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\]:,]|[^\s{}\[\]:,]+/s', $json, $matches);
        $stack = [];
        foreach ($matches[0] as $index => $token) {
            if ($token === '{' || $token === '[') {
                $stack[] = ['object' => $token === '{', 'keys' => []];
            } elseif ($token === '}' || $token === ']') {
                array_pop($stack);
            } elseif (str_starts_with($token, '"') && ($matches[0][$index + 1] ?? '') === ':') {
                $key = json_decode($token, true, 2, JSON_THROW_ON_ERROR);
                $last = count($stack) - 1;
                self::require($last >= 0 && $stack[$last]['object'] && ! isset($stack[$last]['keys'][$key]));
                $stack[$last]['keys'][$key] = true;
            }
        }
        // Keep object/array distinctions: objects become maps, arrays remain lists.
        return self::object($decoded);
    }

    private static function object(stdClass $object): array
    {
        $result = [];
        foreach (get_object_vars($object) as $key => $value) {
            self::require(is_string($key) && ! ctype_digit($key));
            $result[$key] = $value instanceof stdClass ? self::object($value) : $value;
        }
        return $result;
    }
}
