<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Exceptions;

use RuntimeException;
use Throwable;

/** Report a valid recurrence form the package cannot expand safely. */
final class UnsupportedRecurrence extends RuntimeException implements ICalendarException
{
    /** Create an unsupported-recurrence exception with its low-level cause. */
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
