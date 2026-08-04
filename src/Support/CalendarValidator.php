<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Support;

use Mattmy\ICalendar\CalendarIssue;
use Mattmy\ICalendar\Exceptions\InvalidCalendar;
use Sabre\VObject\Component as SabreComponent;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Node;
use Sabre\VObject\ParseException;
use Sabre\VObject\Property as SabreProperty;
use Sabre\VObject\Reader as SabreReader;

/** Parse and fully validate one iCalendar document without repairing input. */
final class CalendarValidator
{
    /**
     * Return a validated calendar and its non-fatal validation warnings.
     *
     * @return array{calendar: VCalendar, warnings: list<CalendarIssue>}
     *
     * @throws InvalidCalendar
     */
    public function validate(string $contents): array
    {
        try {
            $document = SabreReader::read($contents, 0);
        } catch (ParseException $exception) {
            throw new InvalidCalendar(
                message: 'The contents are not a valid iCalendar document.',
                issues: [new CalendarIssue(3, 'parser_error', 'The contents could not be parsed as an iCalendar document.', 'parser')],
                previous: $exception,
            );
        }

        if (! $document instanceof VCalendar) {
            throw new InvalidCalendar(
                message: 'The contents are not a valid iCalendar document.',
                issues: [new CalendarIssue(3, 'invalid_root_component', 'The root component must be VCALENDAR.', 'parser', component: $document?->name)],
            );
        }

        $errors = [];
        $warnings = [];

        foreach ($document->validate() as $validationIssue) {
            $level = (int) $validationIssue['level'];
            $issue = $this->issue($level, (string) $validationIssue['message'], $validationIssue['node']);

            if ($level >= 3) {
                $errors[] = $issue;
            } elseif ($level === 2) {
                $warnings[] = $issue;
            }
        }

        if ($errors !== []) {
            throw new InvalidCalendar('The iCalendar document failed validation.', $errors);
        }

        return ['calendar' => $document, 'warnings' => $warnings];
    }

    /** Create one stable issue from a structured Sabre validation result. */
    private function issue(int $level, string $message, Node $node): CalendarIssue
    {
        $normalizedLevel = $level >= 3 ? 3 : 2;
        $component = $node instanceof SabreComponent
            ? $node->name
            : ($node->parent instanceof SabreComponent ? $node->parent->name : null);

        return new CalendarIssue(
            level: $normalizedLevel,
            code: $normalizedLevel === 3 ? 'validation_error' : 'validation_warning',
            message: $message,
            source: 'validator',
            component: $component,
            property: $node instanceof SabreProperty ? $node->name : null,
        );
    }
}
