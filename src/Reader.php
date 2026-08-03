<?php

declare(strict_types=1);

namespace Mattmy\ICalendar;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\UploadedFile;
use Mattmy\ICalendar\Exceptions\CalendarTooLarge;
use Mattmy\ICalendar\Exceptions\InvalidCalendar;
use Mattmy\ICalendar\Exceptions\InvalidConfiguration;
use Mattmy\ICalendar\Support\BoundedInputReader;
use Mattmy\ICalendar\Support\TimezoneResolver;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Node;
use Sabre\VObject\ParseException;
use Sabre\VObject\Property;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;
use Sabre\VObject\Reader as SabreReader;

final readonly class Reader
{
    /**
     * Create a stateless reader using the Laravel configuration repository.
     */
    public function __construct(
        private Repository $config,
        private BoundedInputReader $inputReader,
        private TimezoneResolver $timezoneResolver,
    ) {}

    /**
     * Parse and validate iCalendar string contents.
     *
     * @throws CalendarTooLarge
     * @throws InvalidCalendar
     * @throws InvalidConfiguration
     */
    public function read(string $contents): Calendar
    {
        $maxBytes = $this->maxBytes();
        $timezone = $this->timezoneResolver->resolve();

        return $this->parse(
            contents: $this->inputReader->contents($contents, $maxBytes),
            floatingTimezone: $timezone['timezone'],
            configurationWarnings: $timezone['warnings'],
        );
    }

    /**
     * Parse valid contents or return null only for invalid iCalendar data.
     *
     * @throws CalendarTooLarge
     * @throws InvalidConfiguration
     */
    public function tryRead(string $contents): ?Calendar
    {
        try {
            return $this->read($contents);
        } catch (InvalidCalendar) {
            return null;
        }
    }

    /**
     * Read and validate an iCalendar document from a local path.
     *
     * @throws \Mattmy\ICalendar\Exceptions\CalendarFileNotFound
     * @throws \Mattmy\ICalendar\Exceptions\CalendarFileUnreadable
     * @throws CalendarTooLarge
     * @throws InvalidCalendar
     * @throws InvalidConfiguration
     */
    public function fromPath(string $path): Calendar
    {
        $maxBytes = $this->maxBytes();
        $timezone = $this->timezoneResolver->resolve();

        return $this->parse(
            contents: $this->inputReader->path($path, $maxBytes),
            floatingTimezone: $timezone['timezone'],
            configurationWarnings: $timezone['warnings'],
        );
    }

    /**
     * Read a local path or return null only for invalid iCalendar data.
     */
    public function tryFromPath(string $path): ?Calendar
    {
        try {
            return $this->fromPath($path);
        } catch (InvalidCalendar) {
            return null;
        }
    }

    /**
     * Read and validate an iCalendar document from a caller-owned stream.
     *
     * @param  mixed  $stream
     */
    public function fromStream(mixed $stream): Calendar
    {
        $maxBytes = $this->maxBytes();
        $timezone = $this->timezoneResolver->resolve();

        return $this->parse(
            contents: $this->inputReader->stream($stream, $maxBytes),
            floatingTimezone: $timezone['timezone'],
            configurationWarnings: $timezone['warnings'],
        );
    }

    /**
     * Read a stream or return null only for invalid iCalendar data.
     *
     * @param  mixed  $stream
     */
    public function tryFromStream(mixed $stream): ?Calendar
    {
        try {
            return $this->fromStream($stream);
        } catch (InvalidCalendar) {
            return null;
        }
    }

    /**
     * Read and validate a Laravel uploaded iCalendar file.
     */
    public function fromUploadedFile(UploadedFile $file): Calendar
    {
        $maxBytes = $this->maxBytes();
        $timezone = $this->timezoneResolver->resolve();

        return $this->parse(
            contents: $this->inputReader->uploadedFile($file, $maxBytes),
            floatingTimezone: $timezone['timezone'],
            configurationWarnings: $timezone['warnings'],
        );
    }

    /**
     * Read an upload or return null only for invalid iCalendar data.
     */
    public function tryFromUploadedFile(UploadedFile $file): ?Calendar
    {
        try {
            return $this->fromUploadedFile($file);
        } catch (InvalidCalendar) {
            return null;
        }
    }

    /**
     * Parse, validate, and hydrate contents already accepted by an input boundary.
     *
     * @param  list<CalendarIssue>  $configurationWarnings
     *
     * @throws InvalidCalendar
     */
    private function parse(
        string $contents,
        string $floatingTimezone,
        array $configurationWarnings,
    ): Calendar {
        try {
            $document = SabreReader::read($contents, 0);
        } catch (ParseException $exception) {
            $issue = new CalendarIssue(
                level: 3,
                code: 'parser_error',
                message: 'The contents could not be parsed as an iCalendar document.',
                source: 'parser',
            );

            throw new InvalidCalendar(
                message: 'The contents are not a valid iCalendar document.',
                issues: [$issue],
                previous: $exception,
            );
        }

        if (! $document instanceof VCalendar) {
            $issue = new CalendarIssue(
                level: 3,
                code: 'invalid_root_component',
                message: 'The root component must be VCALENDAR.',
                source: 'parser',
                component: $document?->name,
            );

            throw new InvalidCalendar(
                message: 'The contents are not a valid iCalendar document.',
                issues: [$issue],
            );
        }

        [$errors, $warnings] = $this->validationIssues($document);

        if ($errors !== []) {
            throw new InvalidCalendar(
                message: 'The iCalendar document failed validation.',
                issues: $errors,
            );
        }

        return $this->hydrateCalendar(
            component: $document,
            floatingTimezone: $floatingTimezone,
            warnings: [...$configurationWarnings, ...$warnings],
        );
    }

    /**
     * Return the configured safe byte limit.
     *
     * @throws InvalidConfiguration
     */
    private function maxBytes(): int
    {
        $maxBytes = $this->config->get('icalendar_reader.max_bytes');

        if (! \is_int($maxBytes) || $maxBytes < 1) {
            throw new InvalidConfiguration(
                'The icalendar_reader.max_bytes configuration must be a positive integer.',
            );
        }

        return $maxBytes;
    }

    /**
     * Convert Sabre validation results to stable package issues.
     *
     * @return array{list<CalendarIssue>, list<CalendarIssue>}
     */
    private function validationIssues(VCalendar $calendar): array
    {
        $errors = [];
        $warnings = [];

        foreach ($calendar->validate() as $validationIssue) {
            $level = (int) $validationIssue['level'];
            $node = $validationIssue['node'];
            $issue = $this->validationIssue(
                level: $level,
                message: (string) $validationIssue['message'],
                node: $node,
            );

            if ($level >= 3) {
                $errors[] = $issue;
            } elseif ($level === 2) {
                $warnings[] = $issue;
            }
        }

        return [$errors, $warnings];
    }

    /**
     * Create one stable issue from a structured Sabre validation result.
     */
    private function validationIssue(int $level, string $message, Node $node): CalendarIssue
    {
        $component = $node instanceof Component
            ? $node->name
            : ($node->parent instanceof Component ? $node->parent->name : null);
        $property = $node instanceof Property ? $node->name : null;

        return new CalendarIssue(
            level: $level,
            code: $level >= 3 ? 'validation_error' : 'validation_warning',
            message: $message,
            source: 'validator',
            component: $component,
            property: $property,
        );
    }

    /**
     * Hydrate the first vertical slice of the public calendar model.
     *
     * @param  list<CalendarIssue>  $warnings
     */
    private function hydrateCalendar(
        VCalendar $component,
        string $floatingTimezone,
        array $warnings,
    ): Calendar {
        $events = [];

        foreach ($component->select('VEVENT') as $eventComponent) {
            if ($eventComponent instanceof VEvent) {
                $events[] = $this->hydrateEvent($eventComponent, $floatingTimezone);
            }
        }

        return new Calendar(
            version: $this->stringProperty($component, 'VERSION'),
            productId: $this->stringProperty($component, 'PRODID'),
            method: $this->stringProperty($component, 'METHOD'),
            calendarScale: $this->stringProperty($component, 'CALSCALE'),
            floatingTimezone: $floatingTimezone,
            eventItems: $events,
            warningItems: $warnings,
            component: clone $component,
        );
    }

    /**
     * Hydrate one event without exposing the mutable Sabre component.
     */
    private function hydrateEvent(VEvent $component, string $floatingTimezone): Event
    {
        $startsAt = null;
        $allDay = false;
        $dateProperty = $this->firstProperty($component, 'DTSTART');

        if ($dateProperty instanceof DateTimeProperty) {
            $allDay = $dateProperty->getValueType() === 'DATE';
            $dateTime = $dateProperty->getDateTime(new DateTimeZone($floatingTimezone));
            $startsAt = $dateTime === null ? null : CarbonImmutable::instance($dateTime);
        }

        return new Event(
            uid: $this->stringProperty($component, 'UID'),
            summary: $this->stringProperty($component, 'SUMMARY'),
            startsAt: $startsAt,
            allDay: $allDay,
            component: clone $component,
        );
    }

    /**
     * Read the first decoded property value without creating empty strings.
     */
    private function stringProperty(Component $component, string $name): ?string
    {
        $property = $this->firstProperty($component, $name);

        if ($property === null) {
            return null;
        }

        $value = (string) $property;

        return $value === '' ? null : $value;
    }

    /**
     * Return the first direct property with the requested name.
     */
    private function firstProperty(Component $component, string $name): ?Property
    {
        foreach ($component->select($name) as $node) {
            if ($node instanceof Property) {
                return $node;
            }
        }

        return null;
    }
}
