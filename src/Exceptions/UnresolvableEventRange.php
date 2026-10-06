<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Exceptions;

use RuntimeException;

/** Report a concrete event range whose required endpoint cannot be resolved. */
final class UnresolvableEventRange extends RuntimeException implements ICalendarException {}
