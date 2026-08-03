<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Exceptions;

use RuntimeException;

final class CalendarFileUnreadable extends RuntimeException implements ICalendarException {}
