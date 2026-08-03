<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Support;

use Illuminate\Contracts\Config\Repository;
use Mattmy\ICalendar\CalendarIssue;

final readonly class TimezoneResolver
{
    /**
     * Create the application timezone resolver.
     */
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * Resolve the effective floating timezone and configuration warnings.
     *
     * @return array{timezone: string, warnings: list<CalendarIssue>}
     */
    public function resolve(): array
    {
        $appTimezone = $this->config->get('app.timezone');
        $packageTimezone = $this->config->get('icalendar_reader.floating_timezone');
        $warnings = [];
        $appTimezoneIsValid = $this->isIanaTimezone($appTimezone);

        if (! $appTimezoneIsValid) {
            $warnings[] = $this->invalidTimezoneIssue('app.timezone');
        }

        if ($packageTimezone !== null) {
            if ($this->isIanaTimezone($packageTimezone)) {
                return ['timezone' => $packageTimezone, 'warnings' => $warnings];
            }

            $warnings[] = $this->invalidTimezoneIssue('icalendar_reader.floating_timezone');

            return ['timezone' => 'UTC', 'warnings' => $warnings];
        }

        return [
            'timezone' => $appTimezoneIsValid ? $appTimezone : 'UTC',
            'warnings' => $warnings,
        ];
    }

    /**
     * Determine whether a configuration value is an exact PHP IANA identifier.
     */
    private function isIanaTimezone(mixed $timezone): bool
    {
        return \is_string($timezone)
            && \in_array($timezone, \timezone_identifiers_list(), true);
    }

    /**
     * Create a safe warning for an invalid timezone configuration key.
     */
    private function invalidTimezoneIssue(string $key): CalendarIssue
    {
        return new CalendarIssue(
            level: 2,
            code: 'invalid_timezone_configuration',
            message: "The {$key} configuration is not a valid IANA timezone; UTC fallback rules were applied.",
            source: 'configuration',
        );
    }
}
