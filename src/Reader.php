<?php

declare(strict_types=1);

namespace Mattmy\ICalendar;

use Carbon\CarbonImmutable;
use DateInterval;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\UploadedFile;
use LogicException;
use Mattmy\ICalendar\Exceptions\CalendarFileNotFound;
use Mattmy\ICalendar\Exceptions\CalendarFileUnreadable;
use Mattmy\ICalendar\Exceptions\CalendarTooLarge;
use Mattmy\ICalendar\Exceptions\InvalidCalendar;
use Mattmy\ICalendar\Exceptions\InvalidCalendarSource;
use Mattmy\ICalendar\Exceptions\InvalidConfiguration;
use Mattmy\ICalendar\Support\BoundedInputReader;
use Mattmy\ICalendar\Support\CalendarValidator;
use Mattmy\ICalendar\Support\ParameterName;
use Mattmy\ICalendar\Support\PropertyName;
use Mattmy\ICalendar\Support\TimezoneResolver;
use Sabre\VObject\Component as SabreComponent;
use Sabre\VObject\Component\VAlarm;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Component\VJournal;
use Sabre\VObject\Component\VTodo;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\InvalidDataException;
use Sabre\VObject\Parameter;
use Sabre\VObject\Property as SabreProperty;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;
use Sabre\VObject\Property\ICalendar\Duration as DurationProperty;
use Sabre\VObject\Recur\RRuleIterator;

/**
 * Read, validate, and hydrate iCalendar input from explicit source types.
 *
 * @phpstan-type StructuredValue array<array-key, string|list<string>>
 * @phpstan-type PropertyAtom bool|int|float|string|CarbonImmutable|DateInterval|StructuredValue
 */
final readonly class Reader
{
    /**
     * Create a stateless reader using the Laravel configuration repository.
     */
    public function __construct(
        private Repository $config,
        private BoundedInputReader $inputReader,
        private CalendarValidator $validator,
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
     * @throws CalendarFileNotFound
     * @throws CalendarFileUnreadable
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
     *
     * @throws CalendarFileNotFound
     * @throws CalendarFileUnreadable
     * @throws CalendarTooLarge
     * @throws InvalidConfiguration
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
     *
     * @throws CalendarFileUnreadable
     * @throws CalendarTooLarge
     * @throws InvalidCalendar
     * @throws InvalidCalendarSource
     * @throws InvalidConfiguration
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
     *
     * @throws CalendarFileUnreadable
     * @throws CalendarTooLarge
     * @throws InvalidCalendarSource
     * @throws InvalidConfiguration
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
     *
     * @throws CalendarFileNotFound
     * @throws CalendarFileUnreadable
     * @throws CalendarTooLarge
     * @throws InvalidCalendar
     * @throws InvalidCalendarSource
     * @throws InvalidConfiguration
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
     *
     * @throws CalendarFileNotFound
     * @throws CalendarFileUnreadable
     * @throws CalendarTooLarge
     * @throws InvalidCalendarSource
     * @throws InvalidConfiguration
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
        $validated = $this->validator->validate($contents);
        $document = $validated['calendar'];

        return $this->hydrateCalendar(
            component: $document,
            floatingTimezone: $floatingTimezone,
            warnings: [...$configurationWarnings, ...$validated['warnings'], ...$this->mappingIssues($document)],
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
        $todos = [];
        $journals = [];

        foreach ($component->select('VEVENT') as $eventComponent) {
            if ($eventComponent instanceof VEvent) {
                $events[] = $this->hydrateEvent($eventComponent, $floatingTimezone);
            }
        }

        foreach ($component->select('VTODO') as $todoComponent) {
            if ($todoComponent instanceof VTodo) {
                $todos[] = $this->hydrateTodo($todoComponent, $floatingTimezone);
            }
        }

        foreach ($component->select('VJOURNAL') as $journalComponent) {
            if ($journalComponent instanceof VJournal) {
                $journals[] = $this->hydrateJournal($journalComponent, $floatingTimezone);
            }
        }

        $properties = $this->hydrateProperties($component, $floatingTimezone);
        $components = [];

        foreach ($component->children() as $child) {
            if ($child instanceof SabreComponent) {
                $components[] = $this->hydrateComponent($child, $floatingTimezone);
            }
        }

        return new Calendar(
            version: $this->stringProperty($component, PropertyName::VERSION),
            productId: $this->stringProperty($component, PropertyName::PRODID),
            method: $this->stringProperty($component, PropertyName::METHOD),
            calendarScale: $this->stringProperty($component, PropertyName::CALSCALE),
            floatingTimezone: $floatingTimezone,
            eventItems: $events,
            todoItems: $todos,
            journalItems: $journals,
            warningItems: $warnings,
            propertyItems: $properties,
            componentItems: $components,
            component: clone $component,
            eventHydrator: fn (VEvent $event): Event => $this->hydrateEvent($event, $floatingTimezone),
        );
    }

    /**
     * Hydrate one event without exposing the mutable Sabre component.
     */
    private function hydrateEvent(VEvent $component, string $floatingTimezone): Event
    {
        $properties = $this->hydrateProperties($component, $floatingTimezone);
        $startProperty = $this->firstProperty($component, PropertyName::DTSTART);
        $endProperty = $this->firstProperty($component, PropertyName::DTEND);
        $durationProperty = $this->firstProperty($component, PropertyName::DURATION);
        $recurrenceIdProperty = $this->firstProperty($component, PropertyName::RECURRENCE_ID);
        $startsAt = $this->dateTimeValue($startProperty, $floatingTimezone);
        $endsAt = $this->dateTimeValue($endProperty, $floatingTimezone);
        $allDay = $this->isDate($startProperty);
        $duration = $durationProperty instanceof DurationProperty
            ? $durationProperty->getDateInterval()
            : null;

        if ($endsAt === null && $startsAt !== null && $duration !== null) {
            $endsAt = $startsAt->add($duration);
        } elseif ($endsAt === null && $startsAt !== null && $allDay) {
            $duration = new DateInterval('P1D');
            $endsAt = $startsAt->addDay();
        } elseif ($startsAt !== null && $endsAt !== null) {
            $duration = $startsAt->toDateTimeImmutable()->diff($endsAt->toDateTimeImmutable());
        }

        return new Event(
            uid: $this->stringProperty($component, PropertyName::UID),
            summary: $this->stringProperty($component, PropertyName::SUMMARY),
            description: $this->stringProperty($component, PropertyName::DESCRIPTION),
            location: $this->stringProperty($component, PropertyName::LOCATION),
            startsAt: $startsAt,
            endsAt: $endsAt,
            startIsDate: $this->isDate($startProperty),
            endIsDate: $endProperty === null && $endsAt !== null
                ? $this->isDate($startProperty)
                : $this->isDate($endProperty),
            startIsFloating: $this->isFloating($startProperty),
            endIsFloating: $endProperty === null && $endsAt !== null
                ? $this->isFloating($startProperty)
                : $this->isFloating($endProperty),
            lastDay: $allDay && $endsAt !== null ? $endsAt->subDay()->startOfDay() : null,
            duration: $duration,
            timestamp: $this->dateTimeValue($this->firstProperty($component, PropertyName::DTSTAMP), $floatingTimezone),
            createdAt: $this->dateTimeValue($this->firstProperty($component, PropertyName::CREATED), $floatingTimezone),
            lastModifiedAt: $this->dateTimeValue($this->firstProperty($component, PropertyName::LAST_MODIFIED), $floatingTimezone),
            status: $this->upperStringProperty($component, PropertyName::STATUS),
            classification: $this->upperStringProperty($component, PropertyName::CLASSIFICATION),
            priority: $this->integerProperty($component, PropertyName::PRIORITY),
            recurrenceId: $this->dateTimeValue($recurrenceIdProperty, $floatingTimezone),
            recurrenceIdIsDate: $this->isDate($recurrenceIdProperty),
            recurrenceIdIsFloating: $this->isFloating($recurrenceIdProperty),
            sequence: $this->integerProperty($component, PropertyName::SEQUENCE),
            url: $this->stringProperty($component, PropertyName::URL),
            organizer: ($organizer = $this->firstProperty($component, PropertyName::ORGANIZER)) === null
                ? null
                : $this->hydrateOrganizer($organizer),
            attendees: $this->hydrateAttendees($component),
            alarms: $this->hydrateAlarms($component, $floatingTimezone),
            categories: collect($this->stringValues($properties, PropertyName::CATEGORIES)),
            allDay: $allDay,
            geo: $this->geoValue($this->firstHydratedProperty($properties, PropertyName::GEO)),
            transparency: $this->upperProperty($this->firstHydratedProperty($properties, PropertyName::TRANSP)),
            comments: collect($this->textValues($properties, PropertyName::COMMENT)),
            contacts: collect($this->textValues($properties, PropertyName::CONTACT)),
            resources: collect($this->stringValues($properties, PropertyName::RESOURCES)),
            recurrenceRule: $this->firstHydratedProperty($properties, PropertyName::RRULE),
            attachments: collect($this->hydratedProperties($properties, PropertyName::ATTACH)),
            exceptionDates: collect($this->hydratedProperties($properties, PropertyName::EXDATE)),
            requestStatuses: collect($this->hydratedProperties($properties, PropertyName::REQUEST_STATUS)),
            relatedTo: collect($this->hydratedProperties($properties, PropertyName::RELATED_TO)),
            recurrenceDates: collect($this->hydratedProperties($properties, PropertyName::RDATE)),
            propertyItems: $properties,
            component: clone $component,
        );
    }

    /** Hydrate one todo without exposing the mutable Sabre component. */
    private function hydrateTodo(VTodo $component, string $floatingTimezone): Todo
    {
        $properties = $this->hydrateProperties($component, $floatingTimezone);
        $startProperty = $this->firstProperty($component, PropertyName::DTSTART);
        $dueProperty = $this->firstProperty($component, PropertyName::DUE);
        $durationProperty = $this->firstProperty($component, PropertyName::DURATION);
        $recurrenceIdProperty = $this->firstProperty($component, PropertyName::RECURRENCE_ID);
        $startsAt = $this->dateTimeValue($startProperty, $floatingTimezone);
        $dueAt = $this->dateTimeValue($dueProperty, $floatingTimezone);
        $duration = $durationProperty instanceof DurationProperty
            ? $durationProperty->getDateInterval()
            : null;

        if ($dueProperty === null && $startsAt !== null && $duration !== null) {
            $dueAt = $startsAt->add($duration);
        } elseif ($duration === null && $startsAt !== null && $dueAt !== null) {
            $duration = $startsAt->toDateTimeImmutable()->diff($dueAt->toDateTimeImmutable());
        }

        return new Todo(
            uid: $this->stringProperty($component, PropertyName::UID),
            timestamp: $this->dateTimeValue($this->firstProperty($component, PropertyName::DTSTAMP), $floatingTimezone),
            classification: $this->upperStringProperty($component, PropertyName::CLASSIFICATION),
            completedAt: $this->dateTimeValue($this->firstProperty($component, PropertyName::COMPLETED), $floatingTimezone),
            createdAt: $this->dateTimeValue($this->firstProperty($component, PropertyName::CREATED), $floatingTimezone),
            description: $this->stringProperty($component, PropertyName::DESCRIPTION),
            startsAt: $startsAt,
            startIsDate: $this->isDate($startProperty),
            startIsFloating: $this->isFloating($startProperty),
            dueAt: $dueAt,
            dueIsDate: $dueProperty === null && $dueAt !== null
                ? $this->isDate($startProperty)
                : $this->isDate($dueProperty),
            dueIsFloating: $dueProperty === null && $dueAt !== null
                ? $this->isFloating($startProperty)
                : $this->isFloating($dueProperty),
            duration: $duration,
            lastModifiedAt: $this->dateTimeValue($this->firstProperty($component, PropertyName::LAST_MODIFIED), $floatingTimezone),
            location: $this->stringProperty($component, PropertyName::LOCATION),
            organizer: ($organizer = $this->firstProperty($component, PropertyName::ORGANIZER)) === null
                ? null
                : $this->hydrateOrganizer($organizer),
            percentComplete: $this->integerProperty($component, PropertyName::PERCENT_COMPLETE),
            priority: $this->integerProperty($component, PropertyName::PRIORITY),
            recurrenceId: $this->dateTimeValue($recurrenceIdProperty, $floatingTimezone),
            recurrenceIdIsDate: $this->isDate($recurrenceIdProperty),
            recurrenceIdIsFloating: $this->isFloating($recurrenceIdProperty),
            sequence: $this->integerProperty($component, PropertyName::SEQUENCE),
            status: $this->upperStringProperty($component, PropertyName::STATUS),
            summary: $this->stringProperty($component, PropertyName::SUMMARY),
            url: $this->stringProperty($component, PropertyName::URL),
            attendees: $this->hydrateAttendees($component),
            categories: collect($this->stringValues($properties, PropertyName::CATEGORIES)),
            alarms: $this->hydrateAlarms($component, $floatingTimezone),
            geo: $this->geoValue($this->firstHydratedProperty($properties, PropertyName::GEO)),
            comments: collect($this->textValues($properties, PropertyName::COMMENT)),
            contacts: collect($this->textValues($properties, PropertyName::CONTACT)),
            resources: collect($this->stringValues($properties, PropertyName::RESOURCES)),
            recurrenceRule: $this->firstHydratedProperty($properties, PropertyName::RRULE),
            attachments: collect($this->hydratedProperties($properties, PropertyName::ATTACH)),
            exceptionDates: collect($this->hydratedProperties($properties, PropertyName::EXDATE)),
            requestStatuses: collect($this->hydratedProperties($properties, PropertyName::REQUEST_STATUS)),
            relatedTo: collect($this->hydratedProperties($properties, PropertyName::RELATED_TO)),
            recurrenceDates: collect($this->hydratedProperties($properties, PropertyName::RDATE)),
            propertyItems: $properties,
            component: clone $component,
        );
    }

    /** Hydrate one journal without exposing the mutable Sabre component. */
    private function hydrateJournal(VJournal $component, string $floatingTimezone): Journal
    {
        $properties = $this->hydrateProperties($component, $floatingTimezone);
        $startProperty = $this->firstProperty($component, PropertyName::DTSTART);
        $recurrenceIdProperty = $this->firstProperty($component, PropertyName::RECURRENCE_ID);

        return new Journal(
            uid: $this->stringProperty($component, PropertyName::UID),
            timestamp: $this->dateTimeValue($this->firstProperty($component, PropertyName::DTSTAMP), $floatingTimezone),
            classification: $this->upperStringProperty($component, PropertyName::CLASSIFICATION),
            createdAt: $this->dateTimeValue($this->firstProperty($component, PropertyName::CREATED), $floatingTimezone),
            startsAt: $this->dateTimeValue($startProperty, $floatingTimezone),
            startIsDate: $this->isDate($startProperty),
            startIsFloating: $this->isFloating($startProperty),
            lastModifiedAt: $this->dateTimeValue($this->firstProperty($component, PropertyName::LAST_MODIFIED), $floatingTimezone),
            organizer: ($organizer = $this->firstProperty($component, PropertyName::ORGANIZER)) === null
                ? null
                : $this->hydrateOrganizer($organizer),
            recurrenceId: $this->dateTimeValue($recurrenceIdProperty, $floatingTimezone),
            recurrenceIdIsDate: $this->isDate($recurrenceIdProperty),
            recurrenceIdIsFloating: $this->isFloating($recurrenceIdProperty),
            sequence: $this->integerProperty($component, PropertyName::SEQUENCE),
            status: $this->upperStringProperty($component, PropertyName::STATUS),
            summary: $this->stringProperty($component, PropertyName::SUMMARY),
            url: $this->stringProperty($component, PropertyName::URL),
            recurrenceRule: $this->firstHydratedProperty($properties, PropertyName::RRULE),
            attachments: collect($this->hydratedProperties($properties, PropertyName::ATTACH)),
            attendees: $this->hydrateAttendees($component),
            categories: collect($this->stringValues($properties, PropertyName::CATEGORIES)),
            comments: collect($this->textValues($properties, PropertyName::COMMENT)),
            contacts: collect($this->textValues($properties, PropertyName::CONTACT)),
            descriptions: collect($this->textValues($properties, PropertyName::DESCRIPTION)),
            exceptionDates: collect($this->hydratedProperties($properties, PropertyName::EXDATE)),
            relatedTo: collect($this->hydratedProperties($properties, PropertyName::RELATED_TO)),
            recurrenceDates: collect($this->hydratedProperties($properties, PropertyName::RDATE)),
            requestStatuses: collect($this->hydratedProperties($properties, PropertyName::REQUEST_STATUS)),
            propertyItems: $properties,
            component: clone $component,
        );
    }

    /** Hydrate one organizer from a cal-address property. */
    private function hydrateOrganizer(SabreProperty $property): Organizer
    {
        $address = (string) $property;
        $parameters = $this->parameters($property);

        return new Organizer(
            address: $address,
            email: $this->emailAddress($address),
            name: $this->singleParameter($parameters, ParameterName::CN),
            sentBy: $this->singleParameter($parameters, ParameterName::SENT_BY),
            directory: $this->singleParameter($parameters, ParameterName::DIR),
            parameterItems: $parameters,
        );
    }

    /** Hydrate one attendee while preserving all parameters. */
    private function hydrateAttendee(SabreProperty $property): Attendee
    {
        $address = (string) $property;
        $parameters = $this->parameters($property);

        return new Attendee(
            address: $address,
            email: $this->emailAddress($address),
            name: $this->singleParameter($parameters, ParameterName::CN),
            role: $this->upperParameter($parameters, ParameterName::ROLE),
            status: $this->upperParameter($parameters, ParameterName::PARTSTAT),
            rsvp: match ($this->upperParameter($parameters, ParameterName::RSVP)) {
                'TRUE' => true,
                'FALSE' => false,
                default => null,
            },
            type: $this->upperParameter($parameters, ParameterName::CUTYPE),
            delegatedFrom: collect($this->parameterList($parameters, ParameterName::DELEGATED_FROM)),
            delegatedTo: collect($this->parameterList($parameters, ParameterName::DELEGATED_TO)),
            parameterItems: $parameters,
        );
    }

    /**
     * Hydrate direct attendees while preserving their document order.
     *
     * @return \Illuminate\Support\Collection<int, Attendee>
     */
    private function hydrateAttendees(SabreComponent $component): \Illuminate\Support\Collection
    {
        return collect(\array_map(
            fn (SabreProperty $property): Attendee => $this->hydrateAttendee($property),
            $this->directProperties($component, PropertyName::ATTENDEE),
        ));
    }

    /**
     * Hydrate direct VALARM children while preserving their document order.
     *
     * @return \Illuminate\Support\Collection<int, Alarm>
     */
    private function hydrateAlarms(SabreComponent $component, string $floatingTimezone): \Illuminate\Support\Collection
    {
        $alarms = [];

        foreach ($component->children() as $child) {
            if ($child instanceof VAlarm) {
                $alarms[] = $this->hydrateAlarm($child, $floatingTimezone);
            }
        }

        return collect($alarms);
    }

    /** Hydrate one VALARM and its typed trigger. */
    private function hydrateAlarm(VAlarm $component, string $floatingTimezone): Alarm
    {
        $properties = $this->hydrateProperties($component, $floatingTimezone);
        $triggerProperty = $this->firstProperty($component, PropertyName::TRIGGER);
        $trigger = null;

        if ($triggerProperty instanceof DurationProperty) {
            $trigger = new AlarmTrigger(
                relativeDuration: $triggerProperty->getDateInterval(),
                absoluteDateTime: null,
                relation: $this->upperParameter($this->parameters($triggerProperty), ParameterName::RELATED) ?? 'START',
            );
        } elseif ($triggerProperty instanceof DateTimeProperty) {
            $trigger = new AlarmTrigger(
                relativeDuration: null,
                absoluteDateTime: $this->dateTimeValue($triggerProperty, $floatingTimezone),
                relation: null,
            );
        }

        return new Alarm(
            action: $this->upperStringProperty($component, PropertyName::ACTION),
            trigger: $trigger,
            description: $this->stringProperty($component, PropertyName::DESCRIPTION),
            summary: $this->stringProperty($component, PropertyName::SUMMARY),
            attendees: collect(\array_map(
                fn (SabreProperty $property): Attendee => $this->hydrateAttendee($property),
                $this->directProperties($component, PropertyName::ATTENDEE),
            )),
            attachments: collect($this->hydratedProperties($properties, PropertyName::ATTACH)),
            repeat: $this->integerProperty($component, PropertyName::REPEAT),
            duration: ($duration = $this->firstProperty($component, PropertyName::DURATION)) instanceof DurationProperty
                ? $duration->getDateInterval()
                : null,
            propertyItems: $properties,
            component: clone $component,
        );
    }

    /**
     * Read the first decoded property value without creating empty strings.
     */
    private function stringProperty(SabreComponent $component, string $name): ?string
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
    private function firstProperty(SabreComponent $component, string $name): ?SabreProperty
    {
        foreach ($component->select($name) as $node) {
            if ($node instanceof SabreProperty) {
                return $node;
            }
        }

        return null;
    }

    /** Convert an iCalendar date or date-time property to an immutable value. */
    private function dateTimeValue(?SabreProperty $property, string $floatingTimezone): ?CarbonImmutable
    {
        if (! $property instanceof DateTimeProperty) {
            return null;
        }

        if (! $this->hasResolvableTimezone($property)) {
            return null;
        }

        $dateTime = $this->dateTimes($property, $floatingTimezone)[0] ?? null;

        return $dateTime === null ? null : CarbonImmutable::instance($dateTime);
    }

    /** Determine whether a date property has floating semantics. */
    private function isFloating(?SabreProperty $property): bool
    {
        if (! $property instanceof DateTimeProperty) {
            return false;
        }

        if ($property->getValueType() === 'DATE') {
            return true;
        }

        return $property[ParameterName::TZID] === null
            && ! \str_ends_with(\strtoupper($property->getRawMimeDirValue()), 'Z');
    }

    /** Determine whether a date property uses the iCalendar DATE value type. */
    private function isDate(?SabreProperty $property): bool
    {
        return $property instanceof DateTimeProperty && $property->getValueType() === 'DATE';
    }

    /** Read and normalize an uppercase token property. */
    private function upperStringProperty(SabreComponent $component, string $name): ?string
    {
        $value = $this->stringProperty($component, $name);

        return $value === null ? null : \strtoupper($value);
    }

    /** Read an optional integer property. */
    private function integerProperty(SabreComponent $component, string $name): ?int
    {
        $value = $this->stringProperty($component, $name);

        return $value === null ? null : (int) $value;
    }

    /**
     * Read every decoded string part from repeated hydrated properties.
     *
     * @param  list<Property>  $properties
     * @return list<string>
     */
    private function stringValues(array $properties, string $name): array
    {
        $values = [];

        foreach ($this->hydratedProperties($properties, $name) as $property) {
            foreach ($property->values as $value) {
                if (\is_string($value)) {
                    $values[] = $value;
                }
            }
        }

        return $values;
    }

    /**
     * Read one decoded TEXT value for each repeated hydrated property.
     *
     * @param  list<Property>  $properties
     * @return list<string>
     */
    private function textValues(array $properties, string $name): array
    {
        return \array_values(\array_filter(\array_map(
            static fn (Property $property): mixed => $property->values[0] ?? null,
            $this->hydratedProperties($properties, $name),
        ), \is_string(...)));
    }

    /**
     * Return the first hydrated property with the requested name.
     *
     * @param  list<Property>  $properties
     */
    private function firstHydratedProperty(array $properties, string $name): ?Property
    {
        return $this->hydratedProperties($properties, $name)[0] ?? null;
    }

    /**
     * Return hydrated properties with the requested name in document order.
     *
     * @param  list<Property>  $properties
     * @return list<Property>
     */
    private function hydratedProperties(array $properties, string $name): array
    {
        return \array_values(\array_filter(
            $properties,
            static fn (Property $property): bool => $property->name === $name,
        ));
    }

    /**
     * Map a GEO property only when it is an in-range latitude and longitude pair.
     *
     * @return array{latitude: float, longitude: float}|null
     */
    private function geoValue(?Property $property): ?array
    {
        if ($property === null) {
            return null;
        }

        $parts = \explode(';', $property->rawValue());

        if (\count($parts) !== 2 || ! \is_numeric($parts[0]) || ! \is_numeric($parts[1])) {
            return null;
        }

        $latitude = (float) $parts[0];
        $longitude = (float) $parts[1];

        if (! \is_finite($latitude) || ! \is_finite($longitude)
            || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return null;
        }

        return ['latitude' => $latitude, 'longitude' => $longitude];
    }

    /** Read an optional uppercase token from an already hydrated property. */
    private function upperProperty(?Property $property): ?string
    {
        if (! \is_string($property?->value) || $property->value === '') {
            return null;
        }

        return \strtoupper($property->value);
    }

    /**
     * Return repeated direct properties without collapsing document order.
     *
     * @return list<SabreProperty>
     */
    private function directProperties(SabreComponent $component, string $name): array
    {
        return \array_values(\array_filter(
            $component->select($name),
            static fn (mixed $property): bool => $property instanceof SabreProperty,
        ));
    }

    /**
     * Normalize all property parameters while preserving multi-values.
     *
     * @return array<string, string|list<string>>
     */
    private function parameters(SabreProperty $property): array
    {
        $parameters = [];

        foreach ($property->parameters() as $parameter) {
            $parts = \array_values(\array_map(
                static fn (mixed $part): string => (string) $part,
                $parameter->getParts(),
            ));
            $parameters[\strtoupper((string) $parameter->name)] = \count($parts) === 1
                ? $parts[0]
                : $parts;
        }

        return $parameters;
    }

    /**
     * Return the first value of a normalized parameter.
     *
     * @param  array<string, string|list<string>>  $parameters
     */
    private function singleParameter(array $parameters, string $name): ?string
    {
        $value = $parameters[$name] ?? null;

        return \is_string($value) ? $value : ($value[0] ?? null);
    }

    /**
     * Return one normalized uppercase parameter token.
     *
     * @param  array<string, string|list<string>>  $parameters
     */
    private function upperParameter(array $parameters, string $name): ?string
    {
        $value = $this->singleParameter($parameters, $name);

        return $value === null ? null : \strtoupper($value);
    }

    /**
     * @param  array<string, string|list<string>>  $parameters
     * @return list<string>
     */
    private function parameterList(array $parameters, string $name): array
    {
        $value = $parameters[$name] ?? null;

        return $value === null ? [] : (\is_array($value) ? $value : [$value]);
    }

    /** Return the address portion only for mailto cal-address values. */
    private function emailAddress(string $address): ?string
    {
        return \str_starts_with(\strtolower($address), 'mailto:')
            ? \substr($address, 7)
            : null;
    }

    /**
     * Hydrate a generic component and every direct child in document order.
     */
    private function hydrateComponent(SabreComponent $component, string $floatingTimezone): Component
    {
        $components = [];

        foreach ($component->children() as $child) {
            if ($child instanceof SabreComponent) {
                $components[] = $this->hydrateComponent($child, $floatingTimezone);
            }
        }

        return new Component(
            name: \strtoupper($component->name),
            propertyItems: $this->hydrateProperties($component, $floatingTimezone),
            componentItems: $components,
            component: $component,
        );
    }

    /**
     * Hydrate direct properties without collapsing repeated names.
     *
     * @return list<Property>
     */
    private function hydrateProperties(SabreComponent $component, string $floatingTimezone): array
    {
        $properties = [];

        foreach ($component->children() as $child) {
            if ($child instanceof SabreProperty) {
                $properties[] = $this->hydrateProperty($child, $floatingTimezone);
            }
        }

        return $properties;
    }

    /**
     * Hydrate one property with typed values, raw value, and all parameters.
     */
    private function hydrateProperty(SabreProperty $property, string $floatingTimezone): Property
    {
        if ($property->name === null || \trim($property->name) === '') {
            throw new LogicException('Sabre returned a property without a name.');
        }

        $values = $this->propertyValues($property, $floatingTimezone);
        $parameters = $this->parameters($property);

        $value = match (\count($values)) {
            0 => null,
            1 => $values[0],
            default => $values,
        };

        return new Property(
            name: \strtoupper($property->name),
            type: \strtolower($property->getValueType()),
            value: $value,
            values: $values,
            parameterItems: $parameters,
            rawValue: $property->getRawMimeDirValue(),
        );
    }

    /**
     * Convert known Sabre values while preserving unknown values as decoded strings.
     *
     * @return list<PropertyAtom>
     */
    private function propertyValues(SabreProperty $property, string $floatingTimezone): array
    {
        if ($property instanceof DateTimeProperty) {
            if (! $this->hasResolvableTimezone($property)) {
                return \array_values(\array_map(
                    static fn (mixed $part): string => (string) $part,
                    $property->getParts(),
                ));
            }

            return \array_map(
                static fn (DateTimeInterface $value): CarbonImmutable => CarbonImmutable::instance($value),
                $this->dateTimes($property, $floatingTimezone),
            );
        }

        if ($property instanceof DurationProperty) {
            return [$property->getDateInterval()];
        }

        $type = \strtoupper($property->getValueType());
        $parts = $property->getParts();

        if (! \array_is_list($parts)) {
            return [self::structuredPropertyValue($parts)];
        }

        return \array_map(
            static function (mixed $part) use ($type): bool|int|float|string|array {
                if (\is_array($part)) {
                    return self::structuredPropertyValue($part);
                }

                return match ($type) {
                    'BOOLEAN' => \strtoupper((string) $part) === 'TRUE',
                    'FLOAT' => (float) $part,
                    'INTEGER' => (int) $part,
                    default => (string) $part,
                };
            },
            $parts,
        );
    }

    /**
     * Normalize one structured parser value without discarding named rule parts.
     *
     * @param  array<array-key, mixed>  $value
     * @return StructuredValue
     */
    private static function structuredPropertyValue(array $value): array
    {
        $structured = [];

        foreach ($value as $key => $item) {
            $structured[(string) $key] = \is_array($item)
                ? \array_values(\array_map(static fn (mixed $part): string => (string) $part, $item))
                : (string) $item;
        }

        return $structured;
    }

    /**
     * Report date-time properties whose TZID cannot be resolved without guessing.
     *
     * @return list<CalendarIssue>
     */
    private function mappingIssues(SabreComponent $component): array
    {
        $issues = [];

        foreach ($component->children() as $child) {
            if ($child instanceof DateTimeProperty && ! $this->hasResolvableTimezone($child)) {
                $issues[] = new CalendarIssue(
                    level: CalendarIssue::LEVEL_WARNING,
                    code: 'mapping_warning',
                    message: 'A date-time property uses a TZID that could not be resolved reliably.',
                    source: 'mapping',
                    component: $component->name,
                    property: $child->name,
                );
            } elseif ($child instanceof SabreComponent) {
                $issues = [...$issues, ...$this->mappingIssues($child)];
            }
        }

        return $issues;
    }

    /** Determine whether a date-time property has an exact or calendar-defined timezone. */
    private function hasResolvableTimezone(DateTimeProperty $property): bool
    {
        $parameter = $property[ParameterName::TZID];

        if ($parameter === null) {
            return true;
        }

        if (! $parameter instanceof Parameter) {
            return false;
        }

        $timezone = $parameter->getValue();

        if (! \is_string($timezone)) {
            return false;
        }

        $definition = $this->matchingTimezone($property, $timezone);

        if ($definition === null) {
            return false;
        }

        foreach ($property->getParts() as $part) {
            if ($this->timezoneOffsetAt($definition, (string) $part) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve all values, preferring a matching VTIMEZONE over host tzdata.
     *
     * @return list<DateTimeInterface>
     */
    private function dateTimes(DateTimeProperty $property, string $floatingTimezone): array
    {
        $parameter = $property[ParameterName::TZID];

        if (! $parameter instanceof Parameter) {
            return \array_values(\array_filter(
                $property->getDateTimes(new DateTimeZone($floatingTimezone)),
                static fn (mixed $value): bool => $value instanceof DateTimeInterface,
            ));
        }

        $timezone = $parameter->getValue();

        if (! \is_string($timezone)) {
            return [];
        }

        $definition = $this->matchingTimezone($property, $timezone);

        if ($definition === null) {
            return [];
        }

        $values = [];

        foreach ($property->getParts() as $part) {
            $raw = (string) $part;
            $offset = $this->timezoneOffsetAt($definition, $raw);

            if ($offset === null) {
                return [];
            }

            $resolved = new DateTimeZone($offset);

            try {
                $candidate = new DateTimeZone($timezone);

                if (DateTimeParser::parseDateTime($raw, $candidate)->format('P') === $offset) {
                    $resolved = $candidate;
                }
            } catch (\Exception) {
                // The calendar offset remains authoritative when no equivalent host zone exists.
            }

            $values[] = DateTimeParser::parseDateTime($raw, $resolved);
        }

        return $values;
    }

    /** Find the same-calendar VTIMEZONE definition for a TZID. */
    private function matchingTimezone(DateTimeProperty $property, string $timezone): ?SabreComponent
    {
        $root = $property->parent;

        while ($root?->parent !== null) {
            $root = $root->parent;
        }

        if (! $root instanceof VCalendar) {
            return null;
        }

        foreach ($root->select('VTIMEZONE') as $definition) {
            if ($definition instanceof SabreComponent && (string) ($definition->TZID ?? '') === $timezone) {
                return $definition;
            }
        }

        return null;
    }

    /** Resolve the effective observance offset for one local calendar date-time. */
    private function timezoneOffsetAt(SabreComponent $definition, string $raw): ?string
    {
        try {
            $target = DateTimeParser::parseDateTime($raw);
        } catch (InvalidDataException) {
            return null;
        }

        $effectiveAt = null;
        $effectiveOffset = null;
        $initialAt = null;
        $initialOffset = null;

        foreach ($definition->children() as $observance) {
            if (! $observance instanceof SabreComponent || ! \in_array($observance->name, ['STANDARD', 'DAYLIGHT'], true)) {
                continue;
            }

            $startProperty = $this->firstProperty($observance, PropertyName::DTSTART);
            $offsetTo = $this->firstProperty($observance, 'TZOFFSETTO');
            $offsetFrom = $this->firstProperty($observance, 'TZOFFSETFROM');

            if (! $startProperty instanceof DateTimeProperty || $offsetTo === null || $offsetFrom === null) {
                continue;
            }

            try {
                $start = DateTimeParser::parseDateTime((string) $startProperty);
            } catch (InvalidDataException) {
                continue;
            }

            if ($initialAt === null || $start < $initialAt) {
                $initialAt = $start;
                $initialOffset = $this->normalizeUtcOffset((string) $offsetFrom);
            }

            $transitions = [$start];

            foreach ($observance->select(PropertyName::RRULE) as $rule) {
                if (! $rule instanceof SabreProperty) {
                    continue;
                }

                try {
                    $iterator = new RRuleIterator($rule->getParts(), $start);
                    $count = 0;

                    while ($iterator->valid() && $count++ < 3500) {
                        $transition = $iterator->current();

                        if (! $transition instanceof DateTimeInterface || $transition > $target) {
                            break;
                        }

                        $transitions[] = $transition;
                        $iterator->next();
                    }

                    if ($iterator->valid() && $iterator->current() <= $target) {
                        return null;
                    }
                } catch (InvalidDataException) {
                    continue;
                }
            }

            foreach ($observance->select(PropertyName::RDATE) as $date) {
                if ($date instanceof DateTimeProperty) {
                    $transitions = [...$transitions, ...$date->getDateTimes(new DateTimeZone('UTC'))];
                }
            }

            foreach ($transitions as $transition) {
                if ($transition <= $target && ($effectiveAt === null || $transition > $effectiveAt)) {
                    $effectiveAt = $transition;
                    $effectiveOffset = $this->normalizeUtcOffset((string) $offsetTo);
                }
            }
        }

        return $effectiveOffset ?? $initialOffset;
    }

    /** Convert RFC UTC-OFFSET syntax to a PHP fixed-offset timezone name. */
    private function normalizeUtcOffset(string $offset): ?string
    {
        if (! \preg_match('/^([+-])(\d{2})(\d{2})(\d{2})?$/', $offset, $parts)) {
            return null;
        }

        if (($parts[4] ?? '00') !== '00') {
            return null;
        }

        return $parts[1] . $parts[2] . ':' . $parts[3];
    }
}
