<?php

declare(strict_types=1);

namespace Fahoba\IcsCalendar;

use RuntimeException;

/** The address gave no calendar; the message is meant for the household. */
final class IcsUnavailable extends RuntimeException {}
