<?php

declare(strict_types=1);

namespace Mattmy\ICalendar;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Mattmy\ICalendar\Concerns\QueriesProperties;
use Sabre\VObject\Component\VJournal;

/** Represent an immutable typed view of one VJOURNAL component. */
final readonly class Journal
{
    use QueriesProperties;

    /**
     * Hydrate an immutable journal snapshot.
     *
     * @param  list<Property>  $propertyItems
     *
     * @internal
     */
    public function __construct(
        public ?string $uid,
        public ?CarbonImmutable $timestamp,
        public ?string $classification,
        public ?CarbonImmutable $createdAt,
        public ?CarbonImmutable $startsAt,
        /** Whether DTSTART uses the iCalendar DATE value type. */
        public bool $startIsDate,
        /** Whether DTSTART has DATE or floating DATE-TIME semantics. */
        public bool $startIsFloating,
        public ?CarbonImmutable $lastModifiedAt,
        public ?Organizer $organizer,
        public ?CarbonImmutable $recurrenceId,
        /** Whether RECURRENCE-ID uses the iCalendar DATE value type. */
        public bool $recurrenceIdIsDate,
        /** Whether RECURRENCE-ID has DATE or floating DATE-TIME semantics. */
        public bool $recurrenceIdIsFloating,
        public ?int $sequence,
        public ?string $status,
        public ?string $summary,
        public ?string $url,
        public ?Property $recurrenceRule,
        /** @var Collection<int, Property> */
        public Collection $attachments,
        /** @var Collection<int, Attendee> */
        public Collection $attendees,
        /** @var Collection<int, string> */
        public Collection $categories,
        /** @var Collection<int, string> */
        public Collection $comments,
        /** @var Collection<int, string> */
        public Collection $contacts,
        /** @var Collection<int, string> */
        public Collection $descriptions,
        /** @var Collection<int, Property> */
        public Collection $exceptionDates,
        /** @var Collection<int, Property> */
        public Collection $relatedTo,
        /** @var Collection<int, Property> */
        public Collection $recurrenceDates,
        /** @var Collection<int, Property> */
        public Collection $requestStatuses,
        private array $propertyItems,
        private VJournal $component,
    ) {}

    /** Return a deep clone of the underlying low-level journal component. */
    public function rawComponent(): VJournal
    {
        return clone $this->component;
    }

    /**
     * Return the journal's ordered direct properties for the internal query trait.
     *
     * @return list<Property>
     *
     * @internal
     */
    protected function propertyItems(): array
    {
        return $this->propertyItems;
    }
}
