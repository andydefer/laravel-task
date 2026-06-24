<?php

declare(strict_types=1);

namespace AndyDefer\Task\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use InvalidArgumentException;

/**
 * Value Object representing a JSON string.
 *
 * Provides methods for JSON manipulation, encoding, decoding,
 * and validation.
 *
 * @author Andy Defer
 */
final class JsonVO extends AbstractValueObject
{
    public readonly string $value;

    /**
     * Create a new JsonVO instance.
     *
     * @param  string  $json  The JSON string (must be valid JSON)
     *
     * @throws InvalidArgumentException If the JSON is invalid
     */
    public function __construct(string $json)
    {
        if (! $this->isValid($json)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid JSON: %s',
                $json
            ));
        }

        $this->value = $json;
    }

    /**
     * Create a JsonVO from an array.
     *
     * @param  array<string, mixed>  $data  The data to encode as JSON
     * @return self New JsonVO instance
     */
    public static function fromArray(array $data): self
    {
        return new self(json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * Create a JsonVO from a StrictDataObject.
     *
     * @param  StrictDataObject  $data  The data to encode as JSON
     * @return self New JsonVO instance
     */
    public static function fromStrictDataObject(StrictDataObject $data): self
    {
        return new self(json_encode($data->toArray(), JSON_THROW_ON_ERROR));
    }

    /**
     * Get the JSON string value.
     *
     * @return string The JSON string
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Decode the JSON to an array.
     *
     * @return array<string, mixed> The decoded array
     */
    public function toArray(): array
    {
        return json_decode($this->value, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Decode the JSON to a StrictDataObject.
     *
     * @return StrictDataObject The decoded data
     */
    public function toStrictDataObject(): StrictDataObject
    {
        return new StrictDataObject($this->toArray());
    }

    /**
     * Decode the JSON to an object.
     *
     * @return object The decoded object
     */
    public function toObject(): object
    {
        return json_decode($this->value, false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Check if the JSON is valid.
     *
     * @param  string  $json  The JSON string to validate
     * @return bool True if the JSON is valid, false otherwise
     */
    public function isValid(string $json): bool
    {
        if ($json === '') {
            return false;
        }

        if (! is_string($json)) {
            return false;
        }

        if (trim($json) === '') {
            return false;
        }

        json_decode($json);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Check if the JSON is empty (empty object or array).
     *
     * @return bool True if the JSON is empty, false otherwise
     */
    public function isEmpty(): bool
    {
        $data = $this->toArray();

        return empty($data);
    }

    /**
     * Check if a key exists in the JSON.
     *
     * @param  string  $key  The key to check
     * @return bool True if the key exists, false otherwise
     */
    public function has(string $key): bool
    {
        $data = $this->toArray();

        return isset($data[$key]);
    }

    /**
     * Get a value by key from the JSON.
     *
     * @param  string  $key  The key to retrieve
     * @param  mixed  $default  Default value if key not found
     * @return mixed The value or default
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $data = $this->toArray();

        return $data[$key] ?? $default;
    }

    /**
     * Merge another JSON into this one.
     *
     * @param  JsonVO  $other  The JSON to merge
     * @return self New JsonVO with merged data
     */
    public function merge(JsonVO $other): self
    {
        $current = $this->toArray();
        $merged = array_merge($current, $other->toArray());

        return self::fromArray($merged);
    }

    /**
     * Format the JSON with pretty print.
     *
     * @return string The pretty printed JSON
     */
    public function pretty(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Check if the JSON is an array.
     *
     * @return bool True if the JSON is an array, false otherwise
     */
    public function isArray(): bool
    {
        $data = $this->toArray();

        return is_array($data);
    }

    /**
     * Check if the JSON is an object.
     *
     * @return bool True if the JSON is an object, false otherwise
     */
    public function isObject(): bool
    {
        return ! $this->isArray() || isset($this->toArray()[0]);
    }

    /**
     * Convert to string.
     *
     * @return string The JSON string
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
