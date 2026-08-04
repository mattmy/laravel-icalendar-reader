<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Exceptions;

use Throwable;

/** Mark exceptions that form the package's stable public failure boundary. */
interface ICalendarException extends Throwable {}
