<?php

namespace App\Support\Loops;

use App\Models\LoopEvent;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * « Ajouter à l'agenda » — TASK-1802 (demande Cyril, 10/11).
 *
 * Aucun lien unique ne couvre Google, Outlook, Yahoo ET Apple/le reste : les
 * trois premiers veulent une URL web, chacune son format ; les autres ne
 * savent lire qu'un fichier `.ics`. D'ou les quatre liens — a l'ecran, un
 * pop-up Alpine laisse choisir.
 *
 * Toujours en UTC : un lien d'agenda qui se trompe de fuseau est pire qu'un
 * lien absent.
 */
class LoopEventCalendarLinks
{
    /** @return array{google: string, outlook: string, yahoo: string, ics: string} */
    public static function forEvent(LoopEvent $event): array
    {
        $start = $event->starts_at;
        // Sans fin declaree, meme repli qu'ailleurs dans cette Card (menu
        // Durée, grille semaine) : une heure, jamais un evenement « sans
        // duree » qu'aucun agenda ne sait representer.
        //
        // `copy()` est systematique ici : `starts_at`/`ends_at` sont des
        // `Carbon` MUTABLES (cast `'datetime'`, pas `'immutable_datetime'`).
        // Sans lui, `addHour()` modifierait `$start` EN PLACE — `$start` et
        // `$end` deviendraient le MEME instant, et chaque lien d'agenda
        // prendrait une duree de zero seconde au lieu d'une heure.
        $end = $event->ends_at ?? $start->copy()->addHour();

        $location = $event->format === LoopEvent::FORMAT_IN_PERSON
            ? (string) $event->location
            : (string) ($event->meeting_url ?: $event->location);

        $description = trim(
            ($event->description ? $event->description."\n\n" : '')
            .($event->meeting_url ? $event->meeting_url : ''),
        );

        return [
            'google' => self::google($event->title, $description, $location, $start, $end),
            'outlook' => self::outlook($event->title, $description, $location, $start, $end),
            'yahoo' => self::yahoo($event->title, $description, $location, $start, $end),
            'ics' => self::icsDataUri($event, $description, $location, $start, $end),
        ];
    }

    private static function google(string $title, string $description, string $location, CarbonInterface $start, CarbonInterface $end): string
    {
        return 'https://calendar.google.com/calendar/render?'.http_build_query([
            'action' => 'TEMPLATE',
            'text' => $title,
            'dates' => self::utcStamp($start).'/'.self::utcStamp($end),
            'details' => $description,
            'location' => $location,
        ]);
    }

    private static function outlook(string $title, string $description, string $location, CarbonInterface $start, CarbonInterface $end): string
    {
        return 'https://outlook.live.com/calendar/0/deeplink/compose?'.http_build_query([
            'path' => '/calendar/action/compose',
            'rru' => 'addevent',
            'subject' => $title,
            'body' => $description,
            'location' => $location,
            'startdt' => $start->toIso8601String(),
            'enddt' => $end->toIso8601String(),
        ]);
    }

    private static function yahoo(string $title, string $description, string $location, CarbonInterface $start, CarbonInterface $end): string
    {
        $minutes = max(1, $start->diffInMinutes($end));

        return 'https://calendar.yahoo.com/?'.http_build_query([
            'v' => 60,
            'view' => 'd',
            'type' => 20,
            'title' => $title,
            'st' => self::utcStamp($start),
            'dur' => sprintf('%02d%02d', intdiv((int) $minutes, 60), $minutes % 60),
            'desc' => $description,
            'in_loc' => $location,
        ]);
    }

    private static function icsDataUri(LoopEvent $event, string $description, string $location, CarbonInterface $start, CarbonInterface $end): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//BouclePro//Evenements//FR',
            'BEGIN:VEVENT',
            'UID:'.$event->id.'@bouclepro',
            'DTSTAMP:'.self::utcStamp(CarbonImmutable::now()),
            'DTSTART:'.self::utcStamp($start),
            'DTEND:'.self::utcStamp($end),
            'SUMMARY:'.self::escapeIcsText($event->title),
        ];

        if ($description !== '') {
            $lines[] = 'DESCRIPTION:'.self::escapeIcsText($description);
        }

        if ($location !== '') {
            $lines[] = 'LOCATION:'.self::escapeIcsText($location);
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return 'data:text/calendar;charset=utf8,'.rawurlencode(implode("\r\n", $lines));
    }

    private static function utcStamp(CarbonInterface $moment): string
    {
        // `clone()` : `->utc()` mute un `Carbon` MUTABLE en place, et ce
        // meme instant est relu par plusieurs constructeurs de lien
        // (`google()`, `outlook()`, `yahoo()`, `icsDataUri()`) — jamais
        // toucher l'instant du RECEVEUR pour en lire une seule facette.
        return $moment->clone()->utc()->format('Ymd\THis\Z');
    }

    /** RFC 5545 §3.3.11 — ordre impose : le backslash d'abord, sinon on echappe nos propres echappements. */
    private static function escapeIcsText(string $text): string
    {
        return str_replace(
            ["\\", ',', ';', "\r\n", "\n"],
            ['\\\\', '\\,', '\\;', '\\n', '\\n'],
            $text,
        );
    }
}
