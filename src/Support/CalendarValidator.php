<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Support;

use Exception;
use LogicException;
use Mattmy\ICalendar\CalendarIssue;
use Mattmy\ICalendar\Exceptions\InvalidCalendar;
use Sabre\VObject\Component as SabreComponent;
use Sabre\VObject\Component\VAlarm;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VTodo;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\InvalidDataException;
use Sabre\VObject\Node;
use Sabre\VObject\Parameter;
use Sabre\VObject\ParseException;
use Sabre\VObject\Property as SabreProperty;
use Sabre\VObject\Property\FloatValue;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;
use Sabre\VObject\Property\ICalendar\Duration as DurationProperty;
use Sabre\VObject\Property\ICalendar\Period as PeriodProperty;
use Sabre\VObject\Reader as SabreReader;

/** Parse and fully validate one iCalendar document without repairing input. */
final class CalendarValidator
{
    /** Use the same calendar-defined date-time semantics as hydration. */
    public function __construct(
        private readonly DateTimeMapper $dateTimeMapper = new DateTimeMapper(),
    ) {}

    /**
     * Return a validated calendar and its non-fatal validation warnings.
     *
     * @return array{calendar: VCalendar, warnings: list<CalendarIssue>}
     *
     * @throws InvalidCalendar
     */
    public function validate(string $contents, string $floatingTimezone = 'UTC'): array
    {
        try {
            $document = SabreReader::read($contents, 0);
        } catch (ParseException|InvalidDataException $exception) {
            throw new InvalidCalendar(
                message: 'The contents are not a valid iCalendar document.',
                issues: [new CalendarIssue(CalendarIssue::LEVEL_ERROR, 'parser_error', 'The contents could not be parsed as an iCalendar document.', 'parser')],
                previous: $exception,
            );
        }

        if (! $document instanceof VCalendar) {
            throw new InvalidCalendar(
                message: 'The contents are not a valid iCalendar document.',
                issues: [new CalendarIssue(CalendarIssue::LEVEL_ERROR, 'invalid_root_component', 'The root component must be VCALENDAR.', 'parser', component: $document?->name)],
            );
        }

        $unfolded = \preg_replace('/\r?\n[ \t]/', '', $contents) ?? $contents;

        if (\preg_match_all('/^BEGIN:VCALENDAR\r?$/mi', $unfolded) !== 1
            || \preg_match_all('/^END:VCALENDAR\r?$/mi', $unfolded) !== 1
            || ! \preg_match('/\A(?:\r?\n)*BEGIN:VCALENDAR\r?\n.*\r?\nEND:VCALENDAR(?:\r?\n)*\z/is', $unfolded)) {
            throw new InvalidCalendar(
                message: 'The reader accepts exactly one VCALENDAR object.',
                issues: [$this->issue(CalendarIssue::LEVEL_ERROR, 'The input must contain exactly one complete VCALENDAR object and no trailing data.', $document)],
            );
        }

        $errors = $this->originalValueTreeIssues($document);

        if ($errors !== []) {
            throw new InvalidCalendar('The iCalendar document failed validation.', $errors);
        }

        $warnings = [];

        foreach ($document->validate() as $validationIssue) {
            if (! \is_array($validationIssue)
                || ! \is_int($validationIssue['level'] ?? null)
                || ! \is_string($validationIssue['message'] ?? null)
                || ! ($validationIssue['node'] ?? null) instanceof Node) {
                throw new LogicException('Sabre returned an invalid validation diagnostic.');
            }

            if ($this->isValidEmailAttachmentIssue($validationIssue)
                || $this->isValidTodoDueIssue($validationIssue, $floatingTimezone)) {
                continue;
            }

            $level = $validationIssue['level'];
            $issue = $this->issue($level, $validationIssue['message'], $validationIssue['node']);

            if ($level >= CalendarIssue::LEVEL_ERROR) {
                $errors[] = $issue;
            } elseif ($level === CalendarIssue::LEVEL_WARNING) {
                $warnings[] = $issue;
            }
        }

        $errors = [...$errors, ...$this->componentIssues($document, $floatingTimezone)];

        if ($errors !== []) {
            throw new InvalidCalendar('The iCalendar document failed validation.', $errors);
        }

        return ['calendar' => $document, 'warnings' => $warnings];
    }

    /** Create one stable issue from a structured Sabre validation result. */
    private function issue(int $level, string $message, Node $node): CalendarIssue
    {
        $normalizedLevel = $level >= CalendarIssue::LEVEL_ERROR
            ? CalendarIssue::LEVEL_ERROR
            : CalendarIssue::LEVEL_WARNING;
        $component = $node instanceof SabreComponent
            ? $node->name
            : ($node->parent instanceof SabreComponent ? $node->parent->name : null);

        return new CalendarIssue(
            level: $normalizedLevel,
            code: $normalizedLevel === CalendarIssue::LEVEL_ERROR ? 'validation_error' : 'validation_warning',
            message: $message,
            source: 'validator',
            line: $node instanceof SabreProperty && isset($node->lineIndex) ? $node->lineIndex + 1 : null,
            component: $component,
            property: $node instanceof SabreProperty ? $node->name : null,
        );
    }

    /**
     * Collect semantic issues from one component and its complete descendant tree.
     *
     * @return list<CalendarIssue>
     */
    private function componentIssues(SabreComponent $component, string $floatingTimezone): array
    {
        $issues = [
            ...$this->temporalIssues($component, $floatingTimezone),
            ...($component instanceof VAlarm ? $this->alarmIssues($component) : []),
        ];

        foreach ($component->children() as $child) {
            if ($child instanceof SabreProperty) {
                $issues = [...$issues, ...$this->temporalPropertyIssues($child)];
            }

            if ($child instanceof DateTimeProperty) {
                $issues = [...$issues, ...$this->dateTimeIssues($child)];
            } elseif ($child instanceof DurationProperty) {
                $issues = [...$issues, ...$this->durationIssues($child)];
            } elseif ($child instanceof PeriodProperty) {
                $issues = [...$issues, ...$this->periodIssues($child, $floatingTimezone)];
            } elseif ($child instanceof SabreProperty && \strtoupper($child->getValueType()) === 'INTEGER') {
                $issues = [...$issues, ...$this->integerIssues($child)];
            } elseif ($child instanceof SabreComponent) {
                $issues = [...$issues, ...$this->componentIssues($child, $floatingTimezone)];
            }
        }

        return $issues;
    }

    /**
     * Validate temporal relationships and recurrence constraints across properties.
     *
     * @return list<CalendarIssue>
     */
    private function temporalIssues(SabreComponent $component, string $floatingTimezone): array
    {
        $name = \strtoupper($component->name);
        $start = $this->property($component, PropertyName::DTSTART);
        $endName = $name === 'VEVENT' ? PropertyName::DTEND : ($name === 'VTODO' ? PropertyName::DUE : null);
        $end = $endName === null ? null : $this->property($component, $endName);
        $duration = $this->property($component, PropertyName::DURATION);
        $issues = [];

        if ($end !== null && $duration !== null) {
            $issues[] = $this->issue(CalendarIssue::LEVEL_ERROR, "{$endName} and DURATION are mutually exclusive in {$name}.", $duration);
        }

        if ($duration !== null) {
            $rawDuration = $duration->getRawMimeDirValue();

            if ($start === null && $name === 'VTODO') {
                $issues[] = $this->issue(CalendarIssue::LEVEL_ERROR, 'VTODO DURATION requires DTSTART.', $duration);
            }

            if (\str_starts_with($rawDuration, '-') || ! \preg_match('/[1-9]/', $rawDuration)) {
                $issues[] = $this->issue(CalendarIssue::LEVEL_ERROR, 'DURATION must be positive.', $duration);
            }

            if ($start instanceof DateTimeProperty && $start->getValueType() === 'DATE' && \str_contains($rawDuration, 'T')) {
                $issues[] = $this->issue(CalendarIssue::LEVEL_ERROR, 'A DATE DTSTART requires a day-or-week DURATION.', $duration);
            }
        }

        if ($start instanceof DateTimeProperty && $end instanceof DateTimeProperty) {
            if ($start->getValueType() !== $end->getValueType()) {
                $issues[] = $this->issue(CalendarIssue::LEVEL_ERROR, "DTSTART and {$endName} must use the same value type.", $end);
            } elseif (! $this->isLater($end, $start, $floatingTimezone)) {
                $issues[] = $this->issue(CalendarIssue::LEVEL_ERROR, "{$endName} must be later than DTSTART.", $end);
            }
        }

        if (\in_array($name, ['VTODO', 'VJOURNAL'], true)
            && $this->property($component, PropertyName::RRULE) !== null
            && $start === null) {
            $issues[] = $this->issue(CalendarIssue::LEVEL_ERROR, "{$name} RRULE requires DTSTART.", $component);
        }

        $recurrenceId = $this->property($component, PropertyName::RECURRENCE_ID);

        if ($start instanceof DateTimeProperty && $recurrenceId instanceof DateTimeProperty
            && ($start->getValueType() !== $recurrenceId->getValueType()
                || $this->dateTimeForm($start) !== $this->dateTimeForm($recurrenceId))) {
            $issues[] = $this->issue(CalendarIssue::LEVEL_ERROR, 'RECURRENCE-ID must match the DTSTART value and local-time form.', $recurrenceId);
        }

        $rule = $this->property($component, PropertyName::RRULE);
        $until = $rule?->getParts()['UNTIL'] ?? null;

        if ($start instanceof DateTimeProperty && $rule instanceof SabreProperty
            && \is_string($until) && ! (\in_array($name, ['STANDARD', 'DAYLIGHT'], true)
                ? $this->isValidDateValue($until) && \str_ends_with($until, 'Z')
                : $this->untilMatchesStart($until, $start))) {
            $issues[] = $this->issue(CalendarIssue::LEVEL_ERROR, 'RRULE UNTIL must match the DTSTART value and local-time form.', $rule);
        }

        return $issues;
    }

    /**
     * Enforce the value types and singleton values of RFC temporal properties.
     *
     * @return list<CalendarIssue>
     */
    private function temporalPropertyIssues(SabreProperty $property): array
    {
        $types = match ($property->name) {
            PropertyName::DTSTART, PropertyName::DTEND, PropertyName::DUE, PropertyName::RECURRENCE_ID,
            PropertyName::EXDATE => ['DATE', 'DATE-TIME'],
            PropertyName::DTSTAMP, PropertyName::CREATED, PropertyName::LAST_MODIFIED,
            PropertyName::COMPLETED => ['DATE-TIME'],
            PropertyName::RDATE => ['DATE', 'DATE-TIME', 'PERIOD'],
            PropertyName::DURATION => ['DURATION'],
            PropertyName::TRIGGER => ['DURATION', 'DATE-TIME'],
            default => null,
        };

        if ($types === null) {
            return [];
        }

        if (! \in_array($property->getValueType(), $types, true)) {
            return [$this->issue(CalendarIssue::LEVEL_ERROR, 'The temporal property has an invalid value type.', $property)];
        }

        if (! \in_array($property->name, [PropertyName::RDATE, PropertyName::EXDATE], true)
            && \count($property->getParts()) !== 1) {
            return [$this->issue(CalendarIssue::LEVEL_ERROR, 'The temporal property requires exactly one value.', $property)];
        }

        return [];
    }

    /**
     * Enforce action-specific alarm properties and paired repeat settings.
     *
     * @return list<CalendarIssue>
     */
    private function alarmIssues(VAlarm $alarm): array
    {
        $action = \strtoupper((string) $this->property($alarm, PropertyName::ACTION));
        $issues = [];

        if ($action === 'DISPLAY' && \count($alarm->select(PropertyName::DESCRIPTION)) !== 1) {
            $issues[] = $this->issue(CalendarIssue::LEVEL_ERROR, 'A DISPLAY alarm requires exactly one DESCRIPTION.', $alarm);
        }

        if ($action === 'EMAIL') {
            foreach ([PropertyName::DESCRIPTION, PropertyName::SUMMARY] as $required) {
                if (\count($alarm->select($required)) !== 1) {
                    $issues[] = $this->issue(CalendarIssue::LEVEL_ERROR, "An EMAIL alarm requires exactly one {$required}.", $alarm);
                }
            }

            if ($alarm->select(PropertyName::ATTENDEE) === []) {
                $issues[] = $this->issue(CalendarIssue::LEVEL_ERROR, 'An EMAIL alarm requires at least one ATTENDEE.', $alarm);
            }
        }

        if ($action === 'AUDIO' && \count($alarm->select(PropertyName::ATTACH)) > 1) {
            $issues[] = $this->issue(CalendarIssue::LEVEL_ERROR, 'An AUDIO alarm permits at most one ATTACH.', $alarm);
        }

        $forbidden = match ($action) {
            'AUDIO' => [PropertyName::DESCRIPTION, PropertyName::SUMMARY, PropertyName::ATTENDEE],
            'DISPLAY' => [PropertyName::ATTACH, PropertyName::SUMMARY, PropertyName::ATTENDEE],
            default => [],
        };

        foreach ($forbidden as $name) {
            if ($this->property($alarm, $name) !== null) {
                $issues[] = $this->issue(CalendarIssue::LEVEL_ERROR, "{$name} is not valid for a {$action} alarm.", $alarm);
            }
        }

        $repeat = $this->property($alarm, PropertyName::REPEAT);
        $duration = $this->property($alarm, PropertyName::DURATION);

        if (($repeat === null) !== ($duration === null)) {
            $issues[] = $this->issue(CalendarIssue::LEVEL_ERROR, 'VALARM REPEAT and DURATION must occur together.', $repeat ?? $duration ?? $alarm);
        }

        return $issues;
    }

    /**
     * Validate date-time lexical forms and restrictions on timezone parameters.
     *
     * @return list<CalendarIssue>
     */
    private function dateTimeIssues(DateTimeProperty $property): array
    {
        $name = \strtoupper((string) $property->name);
        $parts = \array_values(\array_map(static fn (mixed $part): string => ParserValue::text($part), $property->getParts()));
        $hasTzid = $property[ParameterName::TZID] instanceof Parameter;
        $issues = $this->timezoneParameterIssues($property);

        if (\in_array($name, [PropertyName::DTSTAMP, PropertyName::CREATED, PropertyName::LAST_MODIFIED, PropertyName::COMPLETED], true)
            && ($property->getValueType() !== 'DATE-TIME' || $hasTzid || ! $this->allUtc($parts))) {
            $issues[] = $this->issue(CalendarIssue::LEVEL_ERROR, "{$name} must be a UTC DATE-TIME.", $property);
        }

        if ($name === PropertyName::TRIGGER && $property->getValueType() === 'DATE-TIME'
            && ($hasTzid || ! $this->allUtc($parts))) {
            $issues[] = $this->issue(CalendarIssue::LEVEL_ERROR, 'An absolute TRIGGER must be a UTC DATE-TIME.', $property);
        }

        foreach ($parts as $part) {
            if (! $this->isValidDateValue($part, $property->getValueType() === 'DATE')) {
                $issues[] = $this->issue(CalendarIssue::LEVEL_ERROR, 'The date or date-time must contain a valid calendar date and time.', $property);
            }

            if (\preg_match('/[+-][0-9]{4}$/', $part)) {
                $issues[] = $this->issue(CalendarIssue::LEVEL_ERROR, 'Numeric UTC offsets are not valid iCalendar DATE-TIME values.', $property);

                break;
            }
        }

        return $issues;
    }

    /**
     * Reject TZID on DATE values or any UTC date-time, including PERIOD endpoints.
     *
     * @return list<CalendarIssue>
     */
    private function timezoneParameterIssues(DateTimeProperty|PeriodProperty $property): array
    {
        if ($property[ParameterName::TZID] instanceof Parameter
            && ($property->getValueType() === 'DATE'
                || \preg_match('/Z(?:[,\/]|$)/', $property->getRawMimeDirValue()) === 1)) {
            return [$this->issue(CalendarIssue::LEVEL_ERROR, 'TZID must not be applied to a DATE or UTC value.', $property)];
        }

        return [];
    }

    /** Validate date fields before PHP can normalize impossible dates or times. */
    private function isValidDateValue(string $value, bool $isDate = false): bool
    {
        $pattern = $isDate
            ? '/\A([0-9]{4})([0-9]{2})([0-9]{2})\z/'
            : '/\A([0-9]{4})([0-9]{2})([0-9]{2})T([0-9]{2})([0-9]{2})([0-9]{2})Z?\z/';

        if (! \preg_match($pattern, $value, $parts)
            || ! \checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return false;
        }

        return $isDate || (isset($parts[4], $parts[5], $parts[6])
            && (int) $parts[4] < 24 && (int) $parts[5] < 60 && (int) $parts[6] <= 60);
    }

    /**
     * Validate every duration value before invoking Sabre's permissive parser.
     *
     * @return list<CalendarIssue>
     */
    private function durationIssues(DurationProperty $property): array
    {
        foreach ($property->getParts() as $part) {
            if (! $this->isValidDuration(ParserValue::text($part))) {
                return [$this->issue(CalendarIssue::LEVEL_ERROR, 'DURATION must use the RFC week, day, or time grammar.', $property)];
            }
        }

        return [];
    }

    /** Reject empty or mixed-week RFC durations that Sabre otherwise normalizes. */
    private function isValidDuration(string $value): bool
    {
        return (bool) \preg_match('/\A[+-]?P(?:[0-9]+W|[0-9]+D(?:T(?:[0-9]+H(?:[0-9]+M(?:[0-9]+S)?)?|[0-9]+M(?:[0-9]+S)?|[0-9]+S))?|T(?:[0-9]+H(?:[0-9]+M(?:[0-9]+S)?)?|[0-9]+M(?:[0-9]+S)?|[0-9]+S))\z/', $value);
    }

    /**
     * Validate the original integer value and its property-specific range.
     *
     * @return list<CalendarIssue>
     */
    private function integerIssues(SabreProperty $property): array
    {
        $raw = $this->originalValue($property);
        $value = $this->signedInteger($raw);

        if ($value === null) {
            return [$this->issue(CalendarIssue::LEVEL_ERROR, 'INTEGER must use a signed 32-bit lexical value.', $property)];
        }

        $valid = match (\strtoupper((string) $property->name)) {
            PropertyName::PRIORITY => $value >= 0 && $value <= 9,
            PropertyName::PERCENT_COMPLETE => $value >= 0 && $value <= 100,
            PropertyName::SEQUENCE => $value >= 0,
            PropertyName::REPEAT => $value > 0,
            default => true,
        };

        return $valid
            ? []
            : [$this->issue(CalendarIssue::LEVEL_ERROR, 'The INTEGER value is outside the property-defined range.', $property)];
    }

    /**
     * Reject scalar text before permissive parser coercion can disguise invalid values.
     *
     * @return list<CalendarIssue>
     */
    private function scalarIssues(SabreProperty $property): array
    {
        $raw = $this->originalValue($property);
        $type = \strtoupper($property->getValueType());
        $values = $property instanceof FloatValue && $property->delimiter !== ''
            ? \explode($property->delimiter, $raw)
            : [$raw];

        foreach ($values as $value) {
            $valid = $type === 'BOOLEAN'
                ? \in_array(\strtoupper($value), ['TRUE', 'FALSE'], true)
                : \preg_match('/\A[+-]?[0-9]+(?:\.[0-9]+)?\z/', $value) === 1 && \is_finite((float) $value);

            if (! $valid) {
                return [$this->issue(CalendarIssue::LEVEL_ERROR, "{$type} must contain a valid original scalar value.", $property)];
            }
        }

        return [];
    }

    /** Parse an RFC INTEGER only when its lexical value fits signed 32-bit range. */
    private function signedInteger(string $raw): ?int
    {
        if (! \preg_match('/^[+-]?[0-9]+$/', $raw)) {
            return null;
        }

        $negative = \str_starts_with($raw, '-');
        $digits = \ltrim($raw, '+-0');
        $digits = $digits === '' ? '0' : $digits;
        $limit = $negative ? '2147483648' : '2147483647';

        if (\strlen($digits) > 10 || (\strlen($digits) === 10 && \strcmp($digits, $limit) > 0)) {
            return null;
        }

        return (int) $raw;
    }

    /**
     * Reject UTC offsets before the parser or PHP can normalize their value.
     *
     * @return list<CalendarIssue>
     */
    private function utcOffsetIssues(SabreProperty $property): array
    {
        $raw = $this->originalValue($property);

        if ($property->getValueType() !== 'UTC-OFFSET'
            || ! \preg_match('/\A[+-]([0-9]{2})([0-9]{2})([0-9]{2})?\z/', $raw, $parts)
            || (int) $parts[1] > 23 || (int) $parts[2] > 59 || (int) ($parts[3] ?? '00') > 59
            || \in_array($raw, ['-0000', '-000000'], true)) {
            return [$this->issue(CalendarIssue::LEVEL_ERROR, 'UTC-OFFSET must use a signed HHMM or HHMMSS value within RFC ranges, excluding negative zero.', $property)];
        }

        return [];
    }

    /**
     * Check coercible originals before Sabre validation or temporal mapping can hide them.
     *
     * @return list<CalendarIssue>
     */
    private function originalValueTreeIssues(SabreComponent $component): array
    {
        $issues = [];

        foreach ($component->children() as $child) {
            if ($child instanceof SabreComponent) {
                $issues = [...$issues, ...$this->originalValueTreeIssues($child)];
            } elseif ($child instanceof SabreProperty) {
                $issues = [...$issues, ...match (true) {
                    $child->name === PropertyName::RRULE => $this->ruleIssues($child),
                    $child->getValueType() === 'UTC-OFFSET' || \in_array($child->name, ['TZOFFSETFROM', 'TZOFFSETTO'], true) => $this->utcOffsetIssues($child),
                    \in_array(\strtoupper($child->getValueType()), ['BOOLEAN', 'FLOAT'], true) => $this->scalarIssues($child),
                    default => [],
                }];
            }
        }

        return $issues;
    }

    /**
     * Validate original rule parts before the parser's associative representation loses duplicates.
     *
     * @return list<CalendarIssue>
     */
    private function ruleIssues(SabreProperty $property): array
    {
        $parts = [];

        foreach (\explode(';', $this->originalValue($property)) as $raw) {
            if (! \preg_match('/\A([^=;\s]+)=([^=;\s]+)\z/', $raw, $pair)) {
                return [$this->issue(CalendarIssue::LEVEL_ERROR, 'RRULE requires non-empty NAME=VALUE parts.', $property)];
            }

            $name = \strtoupper($pair[1]);
            $value = \strtoupper($pair[2]);

            if (isset($parts[$name]) || ! $this->isValidRulePart($name, $value)) {
                return [$this->issue(CalendarIssue::LEVEL_ERROR, "RRULE {$name} must be unique and use a valid original value.", $property)];
            }

            $parts[$name] = $value;
        }

        if (! isset($parts['FREQ']) || ! $this->hasValidRuleCombination($parts)) {
            return [$this->issue(CalendarIssue::LEVEL_ERROR, 'RRULE requires FREQ and a valid combination of rule parts.', $property)];
        }

        return [];
    }

    /** Validate one basic RFC rule part without interpreting extension values. */
    private function isValidRulePart(string $name, string $value): bool
    {
        if ($name === 'UNTIL') {
            return $this->isValidDateValue($value, \strlen($value) === 8);
        }

        $pattern = match ($name) {
            'FREQ' => '(?:SECONDLY|MINUTELY|HOURLY|DAILY|WEEKLY|MONTHLY|YEARLY)',
            'WKST' => '(?:MO|TU|WE|TH|FR|SA|SU)',
            'COUNT', 'INTERVAL' => '0*[1-9][0-9]*',
            'BYDAY' => '(?:[+-]?[0-9]{1,2})?(?:MO|TU|WE|TH|FR|SA|SU)',
            'BYSECOND', 'BYMINUTE', 'BYHOUR', 'BYMONTH' => '[0-9]{1,2}',
            'BYMONTHDAY', 'BYWEEKNO' => '[+-]?[0-9]{1,2}',
            'BYYEARDAY', 'BYSETPOS' => '[+-]?[0-9]{1,3}',
            default => null,
        };

        if ($pattern === null) {
            return true;
        }

        $values = \str_starts_with($name, 'BY') ? \explode(',', $value) : [$value];

        foreach ($values as $item) {
            if (\preg_match('/\A' . $pattern . '\z/', $item) !== 1) {
                return false;
            }

            $number = (int) $item;
            $valid = match ($name) {
                'BYSECOND' => $number <= 60,
                'BYMINUTE' => $number <= 59,
                'BYHOUR' => $number <= 23,
                'BYMONTH' => $number >= 1 && $number <= 12,
                'BYDAY' => \strlen($item) === 2 || (\abs($number) >= 1 && \abs($number) <= 53),
                'BYMONTHDAY' => \abs($number) >= 1 && \abs($number) <= 31,
                'BYWEEKNO' => \abs($number) >= 1 && \abs($number) <= 53,
                'BYYEARDAY', 'BYSETPOS' => \abs($number) >= 1 && \abs($number) <= 366,
                default => true,
            };

            if (! $valid) {
                return false;
            }
        }

        return true;
    }

    /**
     * Enforce explicit RFC combinations while retaining DATE receiving and extension contracts.
     *
     * @param  array<string, string>  $parts
     */
    private function hasValidRuleCombination(array $parts): bool
    {
        $frequency = $parts['FREQ'];
        $numberedDay = \preg_match('/[0-9]/', $parts['BYDAY'] ?? '') === 1;

        return ! (isset($parts['COUNT'], $parts['UNTIL'])
            || ($numberedDay && (! \in_array($frequency, ['MONTHLY', 'YEARLY'], true) || isset($parts['BYWEEKNO'])))
            || (isset($parts['BYMONTHDAY']) && $frequency === 'WEEKLY')
            || (isset($parts['BYYEARDAY']) && \in_array($frequency, ['DAILY', 'WEEKLY', 'MONTHLY'], true))
            || (isset($parts['BYWEEKNO']) && $frequency !== 'YEARLY')
            || (isset($parts['BYSETPOS']) && \array_intersect(\array_keys($parts), [
                'BYSECOND', 'BYMINUTE', 'BYHOUR', 'BYDAY', 'BYMONTHDAY', 'BYYEARDAY', 'BYWEEKNO', 'BYMONTH',
            ]) === []));
    }

    /**
     * Validate each explicit period's endpoints or positive duration.
     *
     * @return list<CalendarIssue>
     */
    private function periodIssues(PeriodProperty $property, string $floatingTimezone): array
    {
        $issues = $this->timezoneParameterIssues($property);

        foreach ($property->getParts() as $part) {
            $parts = \explode('/', ParserValue::text($part), 2);
            $start = $parts[0];
            $end = $parts[1] ?? '';

            try {
                if (! $this->isValidDateValue($start)) {
                    throw new InvalidDataException('Invalid PERIOD start.');
                }

                $startsAt = DateTimeParser::parseDateTime($start);

                if (\str_starts_with($end, 'P') || \str_starts_with($end, '+P') || \str_starts_with($end, '-P')) {
                    if (! $this->isValidDuration($end)) {
                        throw new InvalidDataException('Invalid PERIOD duration.');
                    }

                    $duration = DateTimeParser::parseDuration($end);

                    if ($duration->invert === 1 || $startsAt->add($duration) <= $startsAt) {
                        $issues[] = $this->issue(CalendarIssue::LEVEL_ERROR, 'A PERIOD duration must be positive.', $property);
                    }
                } elseif (! $this->isValidDateValue($end)) {
                    throw new InvalidDataException('Invalid PERIOD end.');
                } elseif (! $this->isLater(
                    $this->periodEndpoint($property, $end),
                    $this->periodEndpoint($property, $start),
                    $floatingTimezone,
                )) {
                    $issues[] = $this->issue(CalendarIssue::LEVEL_ERROR, 'A PERIOD end must be later than its start.', $property);
                }
            } catch (Exception) {
                $issues[] = $this->issue(CalendarIssue::LEVEL_ERROR, 'PERIOD must contain a valid start and end or duration.', $property);
            }
        }

        return $issues;
    }

    /** Attach a temporary PERIOD endpoint to its source calendar for shared date mapping. */
    private function periodEndpoint(PeriodProperty $period, string $value): DateTimeProperty
    {
        $tzid = $period[ParameterName::TZID];
        $property = new DateTimeProperty(
            $period->root,
            PropertyName::DTSTART,
            $value,
            $tzid instanceof Parameter ? [ParameterName::TZID => (string) $tzid] : [],
        );
        $property->parent = $period->parent;

        return $property;
    }

    /** Return the first direct property with the requested name. */
    private function property(SabreComponent $component, string $name): ?SabreProperty
    {
        $property = $component->select($name)[0] ?? null;

        return $property instanceof SabreProperty ? $property : null;
    }

    /** Compare validated temporal values using the same timezone mapping as hydration. */
    private function isLater(DateTimeProperty $later, DateTimeProperty $earlier, string $floatingTimezone): bool
    {
        if (! $this->isValidDateValue((string) $later, $later->getValueType() === 'DATE')
            || ! $this->isValidDateValue((string) $earlier, $earlier->getValueType() === 'DATE')) {
            return true;
        }

        try {
            $laterValue = $this->dateTimeMapper->value($later, $floatingTimezone);
            $earlierValue = $this->dateTimeMapper->value($earlier, $floatingTimezone);

            return $laterValue === null || $earlierValue === null || $laterValue > $earlierValue;
        } catch (Exception) {
            return true;
        }
    }

    /** Classify one RFC date-time as DATE, UTC, floating, or a named local form. */
    private function dateTimeForm(DateTimeProperty $property): string
    {
        if ($property->getValueType() === 'DATE') {
            return 'DATE';
        }

        $tzid = $property[ParameterName::TZID];

        if ($tzid instanceof Parameter) {
            return 'TZID:' . (string) $tzid;
        }

        return $this->allUtc(\array_values(\array_map(static fn (mixed $part): string => ParserValue::text($part), $property->getParts())))
            ? 'UTC'
            : 'FLOATING';
    }

    /** Determine whether RRULE UNTIL uses the form required by DTSTART. */
    private function untilMatchesStart(string $until, DateTimeProperty $start): bool
    {
        if ($start->getValueType() === 'DATE') {
            return $this->isValidDateValue($until, true);
        }

        $form = $this->dateTimeForm($start);

        return $this->isValidDateValue($until)
            && \str_ends_with($until, 'Z') === ($form !== 'FLOATING');
    }

    /**
     * Determine whether every date-time part explicitly uses UTC.
     *
     * @param  list<string>  $parts
     */
    private function allUtc(array $parts): bool
    {
        if ($parts === []) {
            return false;
        }

        foreach ($parts as $part) {
            if (! \str_ends_with($part, 'Z')) {
                return false;
            }
        }

        return true;
    }

    /** Recover the pre-coercion content-line value retained by Sabre. */
    private function originalValue(SabreProperty $property): string
    {
        if (! isset($property->lineString)) {
            return $property->getRawMimeDirValue();
        }

        $quoted = false;
        $escaped = false;

        $length = \strlen($property->lineString);

        for ($offset = 0; $offset < $length; $offset++) {
            $character = $property->lineString[$offset];
            if ($escaped) {
                $escaped = false;

                continue;
            }

            if ($character === '\\') {
                $escaped = true;

                continue;
            }

            if ($character === '"') {
                $quoted = ! $quoted;
            } elseif ($character === ':' && ! $quoted) {
                return \substr($property->lineString, $offset + 1);
            }
        }

        return $property->getRawMimeDirValue();
    }

    /**
     * Discard Sabre's host-timezone comparison only when shared mapping confirms the order.
     *
     * @param  array{level: int, message: string, node: Node}  $validationIssue
     */
    private function isValidTodoDueIssue(array $validationIssue, string $floatingTimezone): bool
    {
        $due = $validationIssue['node'];
        $todo = $due->parent;

        if ($validationIssue['message'] !== 'DUE must occur after DTSTART'
            || ! $due instanceof DateTimeProperty || $due->name !== PropertyName::DUE
            || ! $todo instanceof VTodo) {
            return false;
        }

        $start = $this->property($todo, PropertyName::DTSTART);

        return $start instanceof DateTimeProperty && $this->isLater($due, $start, $floatingTimezone);
    }

    /**
     * Identify only Sabre's known false-positive for repeated EMAIL attachments.
     *
     * @param  array{level: int, message: string, node: Node}  $validationIssue
     */
    private function isValidEmailAttachmentIssue(array $validationIssue): bool
    {
        $node = $validationIssue['node'];

        return $validationIssue['level'] >= CalendarIssue::LEVEL_ERROR
            && $node instanceof VAlarm
            && \strtoupper((string) $this->property($node, PropertyName::ACTION)) === 'EMAIL'
            && $validationIssue['message'] === 'ATTACH MUST NOT appear more than once in a VALARM component';
    }
}
