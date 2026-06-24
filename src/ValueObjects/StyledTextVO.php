<?php

declare(strict_types=1);

namespace AndyDefer\Task\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;

/**
 * Value Object for styled text with color and formatting.
 *
 * Provides methods for building styled console output with chaining.
 *
 * @author Andy Defer
 */
final class StyledTextVO extends AbstractValueObject
{
    public readonly string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * Create an empty styled text.
     *
     * @return self Empty styled text
     */
    public static function empty(): self
    {
        return new self('');
    }

    /**
     * Append text to the current value.
     *
     * @param  string  $text  The text to append
     * @return self New instance with appended text
     */
    public function append(string $text): self
    {
        return new self($this->value.$text);
    }

    /**
     * Append a styled text to the current value.
     *
     * @param  StyledTextVO  $styledText  The styled text to append
     * @return self New instance with appended styled text
     */
    public function appendStyled(self $styledText): self
    {
        return new self($this->value.$styledText->value);
    }

    /**
     * Add a new line.
     *
     * @param  int  $count  Number of new lines
     * @return self New instance with new lines
     */
    public function newLine(int $count = 1): self
    {
        return new self($this->value.str_repeat("\n", $count));
    }

    /**
     * Add a space.
     *
     * @param  int  $count  Number of spaces
     * @return self New instance with spaces
     */
    public function space(int $count = 1): self
    {
        return new self($this->value.str_repeat(' ', $count));
    }

    /**
     * Apply cyan color.
     *
     * @return self New instance with cyan color
     */
    public function cyan(): self
    {
        return new self($this->value.'<fg=cyan>');
    }

    /**
     * Apply red color.
     *
     * @return self New instance with red color
     */
    public function red(): self
    {
        return new self($this->value.'<fg=red>');
    }

    /**
     * Apply green color.
     *
     * @return self New instance with green color
     */
    public function green(): self
    {
        return new self($this->value.'<fg=green>');
    }

    /**
     * Apply yellow color.
     *
     * @return self New instance with yellow color
     */
    public function yellow(): self
    {
        return new self($this->value.'<fg=yellow>');
    }

    /**
     * Apply magenta color.
     *
     * @return self New instance with magenta color
     */
    public function magenta(): self
    {
        return new self($this->value.'<fg=magenta>');
    }

    /**
     * Apply blue color.
     *
     * @return self New instance with blue color
     */
    public function blue(): self
    {
        return new self($this->value.'<fg=blue>');
    }

    /**
     * Apply white color.
     *
     * @return self New instance with white color
     */
    public function white(): self
    {
        return new self($this->value.'<fg=white>');
    }

    /**
     * Reset color.
     *
     * @return self New instance with color reset
     */
    public function reset(): self
    {
        return new self($this->value.'</>');
    }

    /**
     * Apply bold formatting.
     *
     * @return self New instance with bold formatting
     */
    public function bold(): self
    {
        return new self($this->value.'<options=bold>');
    }

    /**
     * Apply underline formatting.
     *
     * @return self New instance with underline formatting
     */
    public function underline(): self
    {
        return new self($this->value.'<options=underline>');
    }

    /**
     * Format with sprintf-like placeholders.
     *
     * @param  array<string, int|string|float>  $replacements  Key-value pairs for replacement
     * @return self New instance with replacements applied
     */
    public function format(array $replacements): self
    {
        $result = $this->value;
        foreach ($replacements as $key => $value) {
            $result = str_replace('{'.$key.'}', (string) $value, $result);
        }

        return new self($result);
    }

    /**
     * Align text to the right.
     *
     * @param  int  $width  Total width
     * @return self New instance with right-aligned text
     */
    public function alignRight(int $width): self
    {
        $padding = max(0, $width - strlen(strip_tags($this->value)));

        return new self(str_repeat(' ', $padding).$this->value);
    }

    /**
     * Align text to the left.
     *
     * @param  int  $width  Total width
     * @return self New instance with left-aligned text
     */
    public function alignLeft(int $width): self
    {
        $padding = max(0, $width - strlen(strip_tags($this->value)));

        return new self($this->value.str_repeat(' ', $padding));
    }

    /**
     * Center text.
     *
     * @param  int  $width  Total width
     * @return self New instance with centered text
     */
    public function center(int $width): self
    {
        $textLength = strlen(strip_tags($this->value));
        $padding = max(0, $width - $textLength);
        $left = (int) floor($padding / 2);
        $right = (int) $padding - $left;

        return new self(str_repeat(' ', $left).$this->value.str_repeat(' ', $right));
    }

    /**
     * Get the raw value (without any formatting tags).
     *
     * @return string The raw text
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Get the plain text (strip all formatting tags).
     *
     * @return string The plain text without tags
     */
    public function getPlainText(): string
    {
        return strip_tags($this->value);
    }

    /**
     * Convert to string.
     *
     * @return string The formatted text
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
