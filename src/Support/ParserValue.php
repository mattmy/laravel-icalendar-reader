<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Support;

use LogicException;
use Sabre\VObject\Property as SabreProperty;
use Stringable;

/** Narrow decoded parser values before applying their documented text conversion. */
final class ParserValue
{
    /**
     * Normalize validated numeric tokens for Sabre's strict recurrence comparisons.
     *
     * @return array<array-key, mixed>
     */
    public static function recurrenceParts(SabreProperty $rule): array
    {
        $parts = $rule->getParts();

        foreach (['BYHOUR', 'BYMONTH'] as $name) {
            if (isset($parts[$name])) {
                $values = \is_array($parts[$name]) ? $parts[$name] : [$parts[$name]];
                $parts[$name] = \array_map(static fn (mixed $value): string => (string) (int) self::text($value), $values);
            }
        }

        return $parts;
    }

    /** Return scalar parser text, rejecting unexpected structured or object values. */
    public static function text(mixed $value): string
    {
        if ($value === null || \is_scalar($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        throw new LogicException('Sabre returned a value that cannot be represented as text.');
    }
}
