<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Exceptions;

use RuntimeException;

/** Report a local calendar file or stream that cannot be read safely. */
final class CalendarFileUnreadable extends RuntimeException implements ICalendarException {}
