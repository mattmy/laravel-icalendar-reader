<?php

declare(strict_types=1);

namespace Mattmy\ICalendar;

use JsonSerializable;

/** Describe one structured parser, validator, configuration, or mapping issue. */
final readonly class CalendarIssue implements JsonSerializable
{
    public const int LEVEL_WARNING = 2;

    public const int LEVEL_ERROR = 3;

    /**
     * Create an immutable parser, validator, configuration, or mapping issue.
     *
     * @param  self::LEVEL_WARNING|self::LEVEL_ERROR  $level
     * @param  'invalid_timezone_configuration'|'invalid_root_component'|'parser_error'|'validation_error'|'validation_warning'|'mapping_warning'  $code
     * @param  'parser'|'validator'|'configuration'|'mapping'  $source
     */
    public function __construct(
        public int $level,
        public string $code,
        public string $message,
        public string $source,
        public ?int $line = null,
        public ?string $component = null,
        public ?string $property = null,
    ) {}

    /**
     * Convert the issue to its stable array representation.
     *
     * @return array{level: int, code: string, message: string, source: string, line: ?int, component: ?string, property: ?string}
     */
    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'code' => $this->code,
            'message' => $this->message,
            'source' => $this->source,
            'line' => $this->line,
            'component' => $this->component,
            'property' => $this->property,
        ];
    }

    /**
     * Return data suitable for JSON encoding.
     *
     * @return array{level: int, code: string, message: string, source: string, line: ?int, component: ?string, property: ?string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
