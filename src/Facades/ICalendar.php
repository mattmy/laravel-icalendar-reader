<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Facades;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Facade;
use Mattmy\ICalendar\Calendar;
use Mattmy\ICalendar\Reader;

/**
 * Provide static access to the same Reader instance available through dependency injection.
 *
 * @method static Calendar read(string $contents)
 * @method static Calendar|null tryRead(string $contents)
 * @method static Calendar fromPath(string $path)
 * @method static Calendar|null tryFromPath(string $path)
 * @method static Calendar fromStream(mixed $stream)
 * @method static Calendar|null tryFromStream(mixed $stream)
 * @method static Calendar fromUploadedFile(UploadedFile $file)
 * @method static Calendar|null tryFromUploadedFile(UploadedFile $file)
 *
 * @see Reader
 */
final class ICalendar extends Facade
{
    /**
     * Return the service container binding represented by the facade.
     *
     * @return class-string<Reader>
     */
    protected static function getFacadeAccessor(): string
    {
        return Reader::class;
    }
}
