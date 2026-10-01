<?php

declare(strict_types=1);

namespace Mattmy\ICalendar;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\UploadedFile;
use Mattmy\ICalendar\Exceptions\CalendarFileNotFound;
use Mattmy\ICalendar\Exceptions\CalendarFileUnreadable;
use Mattmy\ICalendar\Exceptions\CalendarTooLarge;
use Mattmy\ICalendar\Exceptions\InvalidCalendar;
use Mattmy\ICalendar\Exceptions\InvalidCalendarSource;
use Mattmy\ICalendar\Exceptions\InvalidConfiguration;
use Mattmy\ICalendar\Support\BoundedInputReader;
use Mattmy\ICalendar\Support\CalendarHydrator;
use Mattmy\ICalendar\Support\CalendarValidator;
use Mattmy\ICalendar\Support\TimezoneResolver;

/** Read and validate iCalendar input from explicit source types. */
final readonly class Reader
{
    /** Create a stateless reader using Laravel configuration and package modules. */
    public function __construct(
        private Repository $config,
        private BoundedInputReader $inputReader,
        private CalendarValidator $validator,
        private TimezoneResolver $timezoneResolver,
        private CalendarHydrator $hydrator,
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
        return $this->readContents($this->inputReader->contents($contents, $this->maxBytes()));
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
        return $this->readContents($this->inputReader->path($path, $this->maxBytes()));
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
     * @throws CalendarFileUnreadable
     * @throws CalendarTooLarge
     * @throws InvalidCalendar
     * @throws InvalidCalendarSource
     * @throws InvalidConfiguration
     */
    public function fromStream(mixed $stream): Calendar
    {
        return $this->readContents($this->inputReader->stream($stream, $this->maxBytes()));
    }

    /**
     * Read a stream or return null only for invalid iCalendar data.
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
        return $this->readContents($this->inputReader->uploadedFile($file, $this->maxBytes()));
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
     * Validate and hydrate bytes already accepted by an input module.
     *
     * @throws InvalidCalendar
     */
    private function readContents(string $contents): Calendar
    {
        $timezone = $this->timezoneResolver->resolve();
        $validated = $this->validator->validate($contents, $timezone['timezone']);

        return $this->hydrator->hydrate(
            component: $validated['calendar'],
            floatingTimezone: $timezone['timezone'],
            warnings: [...$timezone['warnings'], ...$validated['warnings']],
            contents: $contents,
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
}
