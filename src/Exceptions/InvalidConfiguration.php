<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Exceptions;

use RuntimeException;

/** Report package configuration that cannot be handled with a safe fallback. */
final class InvalidConfiguration extends RuntimeException implements ICalendarException {}
