<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Exceptions;

use RuntimeException;

/** Report a recurrence query exceeding its fixed candidate-evaluation limit. */
final class RecurrenceLimitExceeded extends RuntimeException implements ICalendarException {}
