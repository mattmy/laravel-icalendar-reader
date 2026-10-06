<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Support;

use LogicException;
use Sabre\VObject\Property as SabreProperty;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;
use Stringable;

/** Narrow decoded parser values before applying their documented text conversion. */
final class ParserValue
{
    /** Decide whether Sabre applies every effective rule part with the required frequency semantics. */
    public static function supportsRecurrence(SabreProperty $rule, ?SabreProperty $start): bool
    {
        $parts = self::recurrenceParts($rule);
        $isDate = $start instanceof DateTimeProperty && $start->getValueType() === 'DATE';

        if ($isDate) {
            unset($parts['BYHOUR']);
        }

        $frequency = self::text($parts['FREQ'] ?? '');
        $allowed = match ($frequency) {
            'HOURLY' => [],
            'DAILY' => ['BYDAY', 'BYHOUR', 'BYMONTH'],
            'WEEKLY' => ['BYDAY', 'BYHOUR'],
            'MONTHLY' => ['BYDAY', 'BYMONTHDAY', 'BYSETPOS'],
            'YEARLY' => ['BYMONTH', 'BYDAY', 'BYMONTHDAY', 'BYSETPOS'],
            default => null,
        };

        if ($allowed === null || ($isDate && $frequency === 'HOURLY')
            || \array_diff(\array_keys($parts), ['FREQ', 'COUNT', 'INTERVAL', 'UNTIL', 'WKST', ...$allowed]) !== []) {
            return false;
        }

        if ($frequency === 'DAILY' && isset($parts['BYMONTH']) && ! isset($parts['BYDAY']) && ! isset($parts['BYHOUR'])) {
            return false;
        }

        if ($frequency === 'WEEKLY' && isset($parts['BYHOUR']) && ! isset($parts['BYDAY'])) {
            return false;
        }

        foreach (isset($parts['BYDAY']) ? (array) $parts['BYDAY'] : [] as $day) {
            if (! \preg_match('/^(?:[+-]?[1-5])?(?:MO|TU|WE|TH|FR|SA|SU)$/D', self::text($day))) {
                return false;
            }
        }

        if ($frequency === 'MONTHLY' && isset($parts['UNTIL']) && (isset($parts['BYDAY']) || isset($parts['BYMONTHDAY']))) {
            return false;
        }

        if (isset($parts['BYSETPOS']) && ! isset($parts['BYDAY']) && ! isset($parts['BYMONTHDAY'])) {
            return false;
        }

        if (isset($parts['BYDAY'], $parts['BYMONTHDAY'])) {
            foreach ((array) $parts['BYMONTHDAY'] as $day) {
                if (self::text($day) !== (string) (int) self::text($day)) {
                    return false;
                }
            }
        }

        if ($frequency !== 'YEARLY') {
            return true;
        }

        if (! isset($parts['BYMONTH'])) {
            return ! isset($parts['BYDAY']) && ! isset($parts['BYMONTHDAY']) && ! isset($parts['BYSETPOS']);
        }

        $months = \array_map(self::text(...), \is_array($parts['BYMONTH']) ? $parts['BYMONTH'] : [$parts['BYMONTH']]);

        if (isset($parts['BYSETPOS']) && \count(\array_unique($months)) !== 1) {
            return false;
        }

        // Without a day selector Sabre rolls missing dates into the next month.
        return isset($parts['BYDAY']) || isset($parts['BYMONTHDAY'])
            || ($start !== null && (int) \substr((string) $start, 6, 2) <= 28);
    }

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
