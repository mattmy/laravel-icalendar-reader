<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Support;

use Illuminate\Http\UploadedFile;
use Mattmy\ICalendar\Exceptions\CalendarFileNotFound;
use Mattmy\ICalendar\Exceptions\CalendarFileUnreadable;
use Mattmy\ICalendar\Exceptions\CalendarTooLarge;
use Mattmy\ICalendar\Exceptions\InvalidCalendarSource;

/** Read supported input sources while enforcing an exact byte limit. */
final class BoundedInputReader
{
    private const int CHUNK_BYTES = 8192;

    /**
     * Validate string contents against the configured byte limit.
     *
     * @throws CalendarTooLarge
     */
    public function contents(string $contents, int $maxBytes): string
    {
        if (\strlen($contents) > $maxBytes) {
            throw CalendarTooLarge::forLimit($maxBytes);
        }

        return $contents;
    }

    /**
     * Read a regular local file without trusting metadata as the final limit.
     *
     * @throws CalendarFileNotFound
     * @throws CalendarFileUnreadable
     * @throws CalendarTooLarge
     */
    public function path(string $path, int $maxBytes): string
    {
        if (! \stream_is_local($path)) {
            throw new CalendarFileUnreadable('Only local iCalendar file paths are supported.');
        }

        if (! \file_exists($path)) {
            throw new CalendarFileNotFound('The iCalendar file does not exist.');
        }

        $resolvedPath = \realpath($path);

        if ($resolvedPath === false || ! \is_file($resolvedPath) || ! \is_readable($resolvedPath)) {
            throw new CalendarFileUnreadable('The iCalendar path is not a readable regular file.');
        }

        $stream = @\fopen($resolvedPath, 'rb');

        if ($stream === false) {
            throw new CalendarFileUnreadable('The iCalendar file could not be opened for reading.');
        }

        try {
            return $this->stream($stream, $maxBytes);
        } finally {
            \fclose($stream);
        }
    }

    /**
     * Read from the caller-owned stream at its current position.
     *
     * @param  mixed  $stream
     *
     * @throws CalendarFileUnreadable
     * @throws CalendarTooLarge
     * @throws InvalidCalendarSource
     */
    public function stream(mixed $stream, int $maxBytes): string
    {
        if (! \is_resource($stream) || \get_resource_type($stream) !== 'stream') {
            throw new InvalidCalendarSource('The calendar source must be a readable stream resource.');
        }

        $metadata = \stream_get_meta_data($stream);

        if (\strpbrk($metadata['mode'], 'r+') === false) {
            throw new InvalidCalendarSource('The calendar stream is not readable.');
        }

        $contents = '';

        while (! \feof($stream)) {
            $remaining = $maxBytes - \strlen($contents);
            $readLength = \min(self::CHUNK_BYTES, $remaining > 0 ? $remaining : 1);
            $chunk = @\fread($stream, $readLength);

            if ($chunk === false) {
                throw new CalendarFileUnreadable('The iCalendar stream could not be read.');
            }

            if ($remaining === 0 && $chunk !== '') {
                throw CalendarTooLarge::forLimit($maxBytes);
            }

            $contents .= $chunk;

            if ($chunk === '' && ! \feof($stream)) {
                throw new CalendarFileUnreadable('The iCalendar stream stopped before reaching EOF.');
            }
        }

        return $contents;
    }

    /**
     * Read a valid Laravel upload without trusting its client metadata.
     *
     * @throws CalendarFileNotFound
     * @throws CalendarFileUnreadable
     * @throws CalendarTooLarge
     * @throws InvalidCalendarSource
     */
    public function uploadedFile(UploadedFile $file, int $maxBytes): string
    {
        if (! $file->isValid()) {
            throw new InvalidCalendarSource('The uploaded iCalendar file is not valid.');
        }

        $path = $file->getRealPath();

        if ($path === false) {
            throw ! \file_exists($file->getPathname())
                ? new CalendarFileNotFound('The uploaded iCalendar file no longer exists.')
                : new CalendarFileUnreadable('The uploaded iCalendar file is not readable.');
        }

        return $this->path($path, $maxBytes);
    }
}
