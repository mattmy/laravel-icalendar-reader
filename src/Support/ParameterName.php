<?php

declare(strict_types=1);

namespace Mattmy\ICalendar\Support;

/** Centralize known iCalendar parameter wire names. @internal */
final class ParameterName
{
    public const string CN = 'CN';

    public const string CUTYPE = 'CUTYPE';

    public const string DELEGATED_FROM = 'DELEGATED-FROM';

    public const string DELEGATED_TO = 'DELEGATED-TO';

    public const string DIR = 'DIR';

    public const string PARTSTAT = 'PARTSTAT';

    public const string RELATED = 'RELATED';

    public const string ROLE = 'ROLE';

    public const string RSVP = 'RSVP';

    public const string SENT_BY = 'SENT-BY';

    public const string TZID = 'TZID';
}
