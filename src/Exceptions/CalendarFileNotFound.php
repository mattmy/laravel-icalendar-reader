<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Exceptions;

use RuntimeException;

/** Report a local calendar path or upload backing file that no longer exists. */
final class CalendarFileNotFound extends RuntimeException implements ICalendarException {}
