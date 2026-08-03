<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Exceptions;

use RuntimeException;

final class CalendarFileNotFound extends RuntimeException implements ICalendarException {}
