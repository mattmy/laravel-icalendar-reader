<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Exceptions;

use InvalidArgumentException;

/** Report a stream or UploadedFile that is not a supported readable source. */
final class InvalidCalendarSource extends InvalidArgumentException implements ICalendarException {}
