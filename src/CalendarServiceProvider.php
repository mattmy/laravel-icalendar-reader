<?php

declare(strict_types=1);

namespace Mattmy\ICalendar;

use Illuminate\Support\ServiceProvider;

/** Register the reader, configuration, and package publishing hooks with Laravel. */
final class CalendarServiceProvider extends ServiceProvider
{
    /**
     * Register package configuration and services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/icalendar_reader.php', 'icalendar_reader');
        $this->app->singleton(Reader::class);
    }

    /**
     * Register publishable package configuration.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/icalendar_reader.php' => \config_path('icalendar_reader.php'),
        ], 'icalendar-reader-config');
    }
}
