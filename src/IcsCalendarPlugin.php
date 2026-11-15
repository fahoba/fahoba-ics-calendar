<?php

declare(strict_types=1);

namespace Fahoba\IcsCalendar;

use Fahoba\PluginApi\Plugins\Catalog;
use Fahoba\PluginApi\Plugins\Plugin;

final class IcsCalendarPlugin extends Plugin
{
    protected function registerPlugin(): void
    {
        $this->app->make(Catalog::class)->offer('calendar', IcsCalendarProvider::KEY, IcsCalendarProvider::class);

        // In register: boot already loads the German texts (see example-area).
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
    }
}
