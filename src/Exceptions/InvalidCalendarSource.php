<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Exceptions;

use InvalidArgumentException;

final class InvalidCalendarSource extends InvalidArgumentException implements ICalendarException {}
