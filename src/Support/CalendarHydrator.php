<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Support;

use DateInterval;
use Illuminate\Support\Collection;
use LogicException;
use Mattmy\ICalendar\Alarm;
use Mattmy\ICalendar\AlarmTrigger;
use Mattmy\ICalendar\Attendee;
use Mattmy\ICalendar\Calendar;
use Mattmy\ICalendar\CalendarIssue;
use Mattmy\ICalendar\Component;
use Mattmy\ICalendar\Event;
use Mattmy\ICalendar\Journal;
use Mattmy\ICalendar\Organizer;
use Mattmy\ICalendar\Property;
use Mattmy\ICalendar\Todo;
use Sabre\VObject\Component as SabreComponent;
use Sabre\VObject\Component\VAlarm;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Component\VJournal;
use Sabre\VObject\Component\VTodo;
use Sabre\VObject\Property as SabreProperty;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;
use Sabre\VObject\Property\ICalendar\Duration as DurationProperty;

/** Build immutable calendar snapshots from one validated Sabre document. */
final readonly class CalendarHydrator
{
    /** Create the calendar hydrator with shared property and date-time mapping. */
    public function __construct(
        private PropertyHydrator $propertyHydrator,
        private DateTimeMapper $dateTimeMapper,
    ) {}

    /**
     * Hydrate the complete public calendar snapshot.
     *
     * @param  list<CalendarIssue>  $warnings
     */
    public function hydrate(VCalendar $component, string $floatingTimezone, array $warnings, string $contents): Calendar
    {
        $componentOrder = $this->componentOrder($component, $contents);
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

        $components = [];

        foreach ($componentOrder[\spl_object_id($component)] ?? [] as $child) {
            $components[] = $this->hydrateComponent($child, $floatingTimezone, $componentOrder);
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
            warningItems: [...$warnings, ...$this->dateTimeMapper->issues($component)],
            propertyItems: $this->propertyHydrator->hydrate($component, $floatingTimezone),
            componentItems: $components,
            component: clone $component,
            eventHydrator: fn (VEvent $event): Event => $this->hydrateEvent($event, $floatingTimezone),
        );
    }

    /** Hydrate one event without exposing mutable parser state. */
    private function hydrateEvent(VEvent $component, string $floatingTimezone): Event
    {
        $properties = $this->propertyHydrator->hydrate($component, $floatingTimezone);
        $startProperty = $this->firstProperty($component, PropertyName::DTSTART);
        $endProperty = $this->firstProperty($component, PropertyName::DTEND);
        $durationProperty = $this->firstProperty($component, PropertyName::DURATION);
        $recurrenceIdProperty = $this->firstProperty($component, PropertyName::RECURRENCE_ID);
        $startsAt = $this->dateTimeMapper->value($startProperty, $floatingTimezone);
        $endsAt = $this->dateTimeMapper->value($endProperty, $floatingTimezone);
        $allDay = $this->dateTimeMapper->isDate($startProperty);
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
            startIsDate: $this->dateTimeMapper->isDate($startProperty),
            endIsDate: $endProperty === null && $endsAt !== null
                ? $this->dateTimeMapper->isDate($startProperty)
                : $this->dateTimeMapper->isDate($endProperty),
            startIsFloating: $this->dateTimeMapper->isFloating($startProperty),
            endIsFloating: $endProperty === null && $endsAt !== null
                ? $this->dateTimeMapper->isFloating($startProperty)
                : $this->dateTimeMapper->isFloating($endProperty),
            lastDay: $allDay && $endsAt !== null ? $endsAt->subDay()->startOfDay() : null,
            duration: $duration,
            timestamp: $this->dateTimeMapper->value($this->firstProperty($component, PropertyName::DTSTAMP), $floatingTimezone),
            createdAt: $this->dateTimeMapper->value($this->firstProperty($component, PropertyName::CREATED), $floatingTimezone),
            lastModifiedAt: $this->dateTimeMapper->value($this->firstProperty($component, PropertyName::LAST_MODIFIED), $floatingTimezone),
            status: $this->upperStringProperty($component, PropertyName::STATUS),
            classification: $this->upperStringProperty($component, PropertyName::CLASSIFICATION),
            priority: $this->integerProperty($component, PropertyName::PRIORITY),
            recurrenceId: $this->dateTimeMapper->value($recurrenceIdProperty, $floatingTimezone),
            recurrenceIdIsDate: $this->dateTimeMapper->isDate($recurrenceIdProperty),
            recurrenceIdIsFloating: $this->dateTimeMapper->isFloating($recurrenceIdProperty),
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

    /** Hydrate one todo without exposing mutable parser state. */
    private function hydrateTodo(VTodo $component, string $floatingTimezone): Todo
    {
        $properties = $this->propertyHydrator->hydrate($component, $floatingTimezone);
        $startProperty = $this->firstProperty($component, PropertyName::DTSTART);
        $dueProperty = $this->firstProperty($component, PropertyName::DUE);
        $durationProperty = $this->firstProperty($component, PropertyName::DURATION);
        $recurrenceIdProperty = $this->firstProperty($component, PropertyName::RECURRENCE_ID);
        $startsAt = $this->dateTimeMapper->value($startProperty, $floatingTimezone);
        $dueAt = $this->dateTimeMapper->value($dueProperty, $floatingTimezone);
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
            timestamp: $this->dateTimeMapper->value($this->firstProperty($component, PropertyName::DTSTAMP), $floatingTimezone),
            classification: $this->upperStringProperty($component, PropertyName::CLASSIFICATION),
            completedAt: $this->dateTimeMapper->value($this->firstProperty($component, PropertyName::COMPLETED), $floatingTimezone),
            createdAt: $this->dateTimeMapper->value($this->firstProperty($component, PropertyName::CREATED), $floatingTimezone),
            description: $this->stringProperty($component, PropertyName::DESCRIPTION),
            startsAt: $startsAt,
            startIsDate: $this->dateTimeMapper->isDate($startProperty),
            startIsFloating: $this->dateTimeMapper->isFloating($startProperty),
            dueAt: $dueAt,
            dueIsDate: $dueProperty === null && $dueAt !== null
                ? $this->dateTimeMapper->isDate($startProperty)
                : $this->dateTimeMapper->isDate($dueProperty),
            dueIsFloating: $dueProperty === null && $dueAt !== null
                ? $this->dateTimeMapper->isFloating($startProperty)
                : $this->dateTimeMapper->isFloating($dueProperty),
            duration: $duration,
            lastModifiedAt: $this->dateTimeMapper->value($this->firstProperty($component, PropertyName::LAST_MODIFIED), $floatingTimezone),
            location: $this->stringProperty($component, PropertyName::LOCATION),
            organizer: ($organizer = $this->firstProperty($component, PropertyName::ORGANIZER)) === null
                ? null
                : $this->hydrateOrganizer($organizer),
            percentComplete: $this->integerProperty($component, PropertyName::PERCENT_COMPLETE),
            priority: $this->integerProperty($component, PropertyName::PRIORITY),
            recurrenceId: $this->dateTimeMapper->value($recurrenceIdProperty, $floatingTimezone),
            recurrenceIdIsDate: $this->dateTimeMapper->isDate($recurrenceIdProperty),
            recurrenceIdIsFloating: $this->dateTimeMapper->isFloating($recurrenceIdProperty),
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

    /** Hydrate one journal without exposing mutable parser state. */
    private function hydrateJournal(VJournal $component, string $floatingTimezone): Journal
    {
        $properties = $this->propertyHydrator->hydrate($component, $floatingTimezone);
        $startProperty = $this->firstProperty($component, PropertyName::DTSTART);
        $recurrenceIdProperty = $this->firstProperty($component, PropertyName::RECURRENCE_ID);

        return new Journal(
            uid: $this->stringProperty($component, PropertyName::UID),
            timestamp: $this->dateTimeMapper->value($this->firstProperty($component, PropertyName::DTSTAMP), $floatingTimezone),
            classification: $this->upperStringProperty($component, PropertyName::CLASSIFICATION),
            createdAt: $this->dateTimeMapper->value($this->firstProperty($component, PropertyName::CREATED), $floatingTimezone),
            startsAt: $this->dateTimeMapper->value($startProperty, $floatingTimezone),
            startIsDate: $this->dateTimeMapper->isDate($startProperty),
            startIsFloating: $this->dateTimeMapper->isFloating($startProperty),
            lastModifiedAt: $this->dateTimeMapper->value($this->firstProperty($component, PropertyName::LAST_MODIFIED), $floatingTimezone),
            organizer: ($organizer = $this->firstProperty($component, PropertyName::ORGANIZER)) === null
                ? null
                : $this->hydrateOrganizer($organizer),
            recurrenceId: $this->dateTimeMapper->value($recurrenceIdProperty, $floatingTimezone),
            recurrenceIdIsDate: $this->dateTimeMapper->isDate($recurrenceIdProperty),
            recurrenceIdIsFloating: $this->dateTimeMapper->isFloating($recurrenceIdProperty),
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
        $parameters = $this->propertyHydrator->parameters($property);

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
        $parameters = $this->propertyHydrator->parameters($property);

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
     * Hydrate direct attendees in document order.
     *
     * @return Collection<int, Attendee>
     */
    private function hydrateAttendees(SabreComponent $component): Collection
    {
        return collect(\array_map(
            fn (SabreProperty $property): Attendee => $this->hydrateAttendee($property),
            $this->directProperties($component, PropertyName::ATTENDEE),
        ));
    }

    /**
     * Hydrate direct VALARM children in document order.
     *
     * @return Collection<int, Alarm>
     */
    private function hydrateAlarms(SabreComponent $component, string $floatingTimezone): Collection
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
        $properties = $this->propertyHydrator->hydrate($component, $floatingTimezone);
        $triggerProperty = $this->firstProperty($component, PropertyName::TRIGGER);
        $trigger = null;

        if ($triggerProperty instanceof DurationProperty) {
            $trigger = new AlarmTrigger(
                relativeDuration: $triggerProperty->getDateInterval(),
                absoluteDateTime: null,
                relation: $this->upperParameter($this->propertyHydrator->parameters($triggerProperty), ParameterName::RELATED) ?? 'START',
            );
        } elseif ($triggerProperty instanceof DateTimeProperty) {
            $trigger = new AlarmTrigger(
                relativeDuration: null,
                absoluteDateTime: $this->dateTimeMapper->value($triggerProperty, $floatingTimezone),
                relation: null,
            );
        }

        return new Alarm(
            action: $this->upperStringProperty($component, PropertyName::ACTION),
            trigger: $trigger,
            description: $this->stringProperty($component, PropertyName::DESCRIPTION),
            summary: $this->stringProperty($component, PropertyName::SUMMARY),
            attendees: $this->hydrateAttendees($component),
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
     * Hydrate a generic component and its direct children in source order.
     *
     * @param  array<int, list<SabreComponent>>  $componentOrder
     */
    private function hydrateComponent(SabreComponent $component, string $floatingTimezone, array $componentOrder): Component
    {
        $components = [];

        foreach ($componentOrder[\spl_object_id($component)] ?? [] as $child) {
            $components[] = $this->hydrateComponent($child, $floatingTimezone, $componentOrder);
        }

        return new Component(
            name: \strtoupper($component->name),
            propertyItems: $this->propertyHydrator->hydrate($component, $floatingTimezone),
            componentItems: $components,
            component: $component,
        );
    }

    /**
     * Recover sibling order and property positions lost by Sabre's grouped tree.
     *
     * Only names from already validated input are indexed; Sabre remains the parser.
     *
     * @return array<int, list<SabreComponent>>
     */
    private function componentOrder(VCalendar $calendar, string $contents): array
    {
        $order = [];
        $stack = [];
        $positions = [];
        $unfolded = \preg_replace('/\r?\n[ \t]/', '', $contents) ?? $contents;
        $lines = \explode("\n", $unfolded);

        foreach ($lines as $ordinal => $line) {
            $line = \rtrim($line, "\r");

            if ($ordinal === 0 && \str_starts_with($line, "\xEF\xBB\xBF")) {
                $line = \substr($line, 3);
            }

            $boundary = \preg_match('/^(BEGIN|END):(.+)$/i', $line, $parts) === 1;

            if ($boundary && \strtoupper($parts[1]) === 'END') {
                \array_pop($stack);

                continue;
            }

            if ($boundary && $stack === []) {
                $stack[] = $calendar;

                continue;
            }

            if ($stack === [] || (! $boundary && \preg_match('/^([A-Z0-9-]+)[;:]/i', $line, $parts) !== 1)) {
                continue;
            }

            $parent = $stack[\array_key_last($stack)];
            $id = \spl_object_id($parent);
            $name = \strtoupper($boundary ? \substr($line, 6) : $parts[1]);
            $position = $positions[$id][$name] ?? 0;
            $child = $parent->select($name)[$position] ?? null;

            $positions[$id][$name] = $position + 1;

            if (! $boundary && $child instanceof SabreProperty) {
                $child->lineIndex = $ordinal;

                continue;
            }

            if (! $child instanceof SabreComponent) {
                throw new LogicException('Validated component boundaries do not match the parsed calendar.');
            }

            $order[$id][] = $child;
            $stack[] = $child;
        }

        return $order;
    }

    /** Read the first decoded property value without creating empty strings. */
    private function stringProperty(SabreComponent $component, string $name): ?string
    {
        $property = $this->firstProperty($component, $name);

        if ($property === null) {
            return null;
        }

        $value = (string) $property;

        return $value === '' ? null : $value;
    }

    /** Return the first direct property with the requested name. */
    private function firstProperty(SabreComponent $component, string $name): ?SabreProperty
    {
        foreach ($component->select($name) as $node) {
            if ($node instanceof SabreProperty) {
                return $node;
            }
        }

        return null;
    }

    /** Read and normalize an uppercase token property. */
    private function upperStringProperty(SabreComponent $component, string $name): ?string
    {
        $value = $this->stringProperty($component, $name);

        return $value === null ? null : \strtoupper($value);
    }

    /** Read an optional integer property after semantic validation. */
    private function integerProperty(SabreComponent $component, string $name): ?int
    {
        $value = $this->stringProperty($component, $name);

        return $value === null ? null : (int) $value;
    }

    /**
     * Read every decoded string part from repeated properties.
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
     * Read one decoded TEXT value for each repeated property.
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
     * Map a GEO property only when both coordinates are finite and in range.
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
     * Return all values of one normalized parameter.
     *
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
}
