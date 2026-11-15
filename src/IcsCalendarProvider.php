<?php

declare(strict_types=1);

namespace Fahoba\IcsCalendar;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Fahoba\PluginApi\Calendar\CalendarProvider;
use Fahoba\PluginApi\Calendar\ChangePage;
use Fahoba\PluginApi\Calendar\ConnectedCalendar;
use Fahoba\PluginApi\Calendar\ListsCalendars;
use Fahoba\PluginApi\Calendar\RemoteCalendar;
use Fahoba\PluginApi\Calendar\RemoteEvent;
use Fahoba\PluginApi\Calendar\SyncsEvents;
use Fahoba\PluginApi\Connections\ConnectedAccount;
use Fahoba\PluginApi\Connections\CredentialField;
use Fahoba\PluginApi\Connections\DescribesSetup;
use Fahoba\PluginApi\Connections\UsesCredentials;
use Fahoba\PluginApi\Http\Outbound;
use RuntimeException;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;
use Throwable;

/**
 * A calendar subscription: one published iCal address, read-only.
 *
 * What school, kindergarten, the football club and the waste collection
 * publish, and what Outlook, iCloud and Google hand out as a "secret
 * address" — no sign-in, only a link. One connection is one address, so a
 * household subscribes to as many as it likes and removes each alone.
 *
 * There is no delta: the file is read whole, and only when its content
 * changed (`DTSTAMP` left out — some servers stamp every answer anew).
 * Series are expanded here, as with CalDAV; what the window no longer
 * holds was removed there.
 */
final class IcsCalendarProvider implements CalendarProvider, DescribesSetup, ListsCalendars, SyncsEvents, UsesCredentials
{
    public const KEY = 'fahoba/ics-calendar';

    public const PAST_MONTHS = 3;

    public const FUTURE_MONTHS = 12;

    /** A busy club calendar with years of history stays well below this. */
    public const MAX_BYTES = 10_000_000;

    public function __construct(private readonly Outbound $outbound) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('iCal calendar (link)');
    }

    public function icon(): string
    {
        return 'heroicon-o-link';
    }

    public function description(): string
    {
        return __('Shows a published calendar — school, kindergarten, club, Outlook, iCloud or Google — from its iCal address.');
    }

    public function setupNotes(): array
    {
        return [
            __('The address usually ends in .ics or starts with webcal://. Outlook calls it “Publish calendar”, Google “Secret address in iCal format”.'),
            __('The calendar is read only: new events go into another calendar, and changes made here are overwritten by the next update.'),
            __('It is read again every few minutes, but many publishers update their file only a few times a day.'),
            __('Connect each further address the same way; every subscription can be removed on its own.'),
        ];
    }

    public function credentialFields(): array
    {
        return [
            new CredentialField(name: 'url', label: __('Calendar address'), placeholder: 'webcal://example.org/calendar.ics'),
            new CredentialField(name: 'name', label: __('Name'), required: false, placeholder: __('Football club'), hint: __('Empty: the name the calendar gives itself.')),
        ];
    }

    public function verifyCredentials(array $credentials): ?string
    {
        $url = self::address($credentials['url'] ?? null);

        if ($url === null) {
            return __('The address starts with https://, http:// or webcal://.');
        }

        try {
            $this->read($url);
        } catch (Throwable $exception) {
            return $exception instanceof IcsUnavailable ? $exception->getMessage() : __('There is no calendar at this address.');
        }

        return null;
    }

    public function accountLabel(array $credentials): string
    {
        $name = trim((string) ($credentials['name'] ?? ''));

        return $name !== '' ? $name : __('Subscription').' – '.parse_url((string) self::address($credentials['url'] ?? null), PHP_URL_HOST);
    }

    /** @return array<int, RemoteCalendar> */
    public function calendars(ConnectedAccount $source): array
    {
        $url = self::address($source->credentials()['url'] ?? null);

        if ($url === null) {
            return [];
        }

        $name = trim((string) ($source->credentials()['name'] ?? ''));

        if ($name === '') {
            $published = trim((string) ($this->read($url)['calendar']->{'X-WR-CALNAME'} ?? ''));
            $name = $published !== '' ? $published : (string) parse_url($url, PHP_URL_HOST);
        }

        return [new RemoteCalendar($url, $name, null)];
    }

    public function changes(ConnectedCalendar $calendar, ?string $cursor, ?string $page): ChangePage
    {
        $url = self::address($calendar->externalId());

        if ($url === null) {
            return new ChangePage(nextCursor: $cursor);
        }

        ['calendar' => $vcalendar, 'fingerprint' => $fingerprint] = $this->read($url);

        if ($cursor === $fingerprint) {
            return new ChangePage(nextCursor: $cursor);
        }

        $from = CarbonImmutable::now()->subMonths(self::PAST_MONTHS);
        $to = CarbonImmutable::now()->addMonths(self::FUTURE_MONTHS);
        $events = $this->instances($vcalendar, $calendar->zoneName(), $from, $to);

        return new ChangePage(
            events: [...$events, ...$this->vanished($calendar, $events, $from, $to)],
            nextCursor: $fingerprint,
        );
    }

    /**
     * People paste whatever their calendar app shows: `webcal://` is plain
     * HTTPS by another name.
     */
    public static function address(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);

        if (preg_match('#^webcals?://#i', $url) === 1) {
            $url = 'https://'.preg_replace('#^webcals?://#i', '', $url);
        }

        return preg_match('#^https?://[^/\s]+#i', $url) === 1 ? $url : null;
    }

    /**
     * The file, parsed, and what identifies its content.
     *
     * @return array{calendar: VCalendar, fingerprint: string}
     */
    private function read(string $url): array
    {
        $response = $this->outbound->householdGet($url, 20);

        if ($response === null) {
            throw new IcsUnavailable(__('Not reachable or not a public address.'));
        }

        if (! $response->successful()) {
            throw new IcsUnavailable(__('The calendar answered with an error (:status).', ['status' => $response->status()]));
        }

        $text = $response->body();

        if (strlen($text) > self::MAX_BYTES) {
            throw new IcsUnavailable(__('The calendar is too large.'));
        }

        if (! str_contains($text, 'BEGIN:VCALENDAR')) {
            throw new IcsUnavailable(__('There is no calendar at this address.'));
        }

        $calendar = Reader::read($text, Reader::OPTION_FORGIVING);

        if (! $calendar instanceof VCalendar) {
            throw new RuntimeException('Not a VCALENDAR.');
        }

        $stable = (string) preg_replace('/^DTSTAMP[:;].*$/mi', '', $text);

        return ['calendar' => $calendar, 'fingerprint' => sha1($stable)];
    }

    /** @return list<RemoteEvent> */
    private function instances(VCalendar $vcalendar, string $zone, CarbonImmutable $from, CarbonImmutable $to): array
    {
        try {
            $expanded = $vcalendar->expand($from, $to, new DateTimeZone($zone));
        } catch (Throwable) {
            // A series the library cannot expand: the plain dates still come.
            $expanded = $vcalendar;
        }

        $events = [];

        foreach ($expanded->select('VEVENT') as $vevent) {
            try {
                $event = $this->map($vevent, $zone);
            } catch (Throwable) {
                // One broken entry costs that entry, not the calendar.
                continue;
            }

            if ($event->endsAt > $from && $event->startsAt < $to) {
                $events[$event->externalId] = $event;
            }
        }

        return array_values($events);
    }

    /**
     * @param  list<RemoteEvent>  $seen
     * @return list<RemoteEvent>
     */
    private function vanished(ConnectedCalendar $calendar, array $seen, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $seenIds = array_map(static fn (RemoteEvent $event): string => $event->externalId, $seen);

        return array_values(array_map(
            static fn (string $id): RemoteEvent => RemoteEvent::cancelled($id),
            array_diff($calendar->knownExternalIds($from, $to), $seenIds),
        ));
    }

    private function map(VEvent $vevent, string $zone): RemoteEvent
    {
        $start = $vevent->DTSTART;
        $allDay = ! $start->hasTime();
        $startsAt = $allDay
            ? CarbonImmutable::parse($start->getValue(), $zone)->startOfDay()
            : CarbonImmutable::instance($start->getDateTime(new DateTimeZone($zone)));

        $endsAt = match (true) {
            isset($vevent->DTEND) && $allDay => CarbonImmutable::parse($vevent->DTEND->getValue(), $zone)->startOfDay(),
            isset($vevent->DTEND) => CarbonImmutable::instance($vevent->DTEND->getDateTime(new DateTimeZone($zone))),
            isset($vevent->DURATION) => $startsAt->add($vevent->DURATION->getDateInterval()),
            // RFC 5545: a date without an end is that one day.
            $allDay => $startsAt->addDay(),
            default => $startsAt->addHour(),
        };

        $title = trim((string) ($vevent->SUMMARY ?? ''));
        $uid = trim((string) ($vevent->UID ?? ''));
        $recurrence = $vevent->{'RECURRENCE-ID'} ?? null;

        // Without a UID the entry is known by what it says and when.
        $base = $uid !== '' ? $uid : 'nouid-'.sha1($title.'|'.$start->getValue());
        $id = $recurrence !== null ? $base.'#'.$recurrence->getValue() : $base;

        return new RemoteEvent(
            externalId: $id,
            title: $title !== '' ? $title : __('(no title)'),
            description: isset($vevent->DESCRIPTION) ? (string) $vevent->DESCRIPTION : null,
            location: isset($vevent->LOCATION) ? (string) $vevent->LOCATION : null,
            startsAt: $startsAt,
            endsAt: $endsAt->lessThanOrEqualTo($startsAt) ? ($allDay ? $startsAt->addDay() : $startsAt) : $endsAt,
            allDay: $allDay,
            version: sha1((string) preg_replace('/^DTSTAMP[:;].*$/mi', '', $vevent->serialize())),
            updatedAt: isset($vevent->{'LAST-MODIFIED'}) ? CarbonImmutable::instance($vevent->{'LAST-MODIFIED'}->getDateTime()) : null,
            recurringEventId: $recurrence !== null ? $base : null,
        );
    }
}
