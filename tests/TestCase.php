<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Tests;

use Mattmy\ICalendar\CalendarServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Register the package service provider.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [CalendarServiceProvider::class];
    }

    /**
     * Configure deterministic package defaults for each test.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.timezone', 'Asia/Taipei');
        $app['config']->set('icalendar_reader.max_bytes', 10 * 1024 * 1024);
        $app['config']->set('icalendar_reader.floating_timezone');
    }
}
