<?php
/**
 * Plugin Name: OpenAR member meetup
 * Description: Invites new members to the current member meetup from the welcome email, with a calendar file, until its last meeting has passed.
 * Version:     1.0.0
 * License:     Apache-2.0
 *
 * The meetup itself is set on the Tools screen and kept as a WordPress option,
 * not in this repository, because its call link admits anyone who has it and
 * this repository is public. Only the shape of the invitation lives here.
 *
 * A meetup is one weekly series: a first and a last meeting on the same
 * weekday, at one time of day in Central time. The welcome email mentions it
 * only while a meeting is still ahead, so a series nobody remembers to take
 * down stops being advertised on its own, and a member admitted partway
 * through gets a calendar file holding only the meetings still to come.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
  exit;
}

const OPENAR_MEETUP_OPTION = 'openar_meetup';

// The VTIMEZONE block in openar_meetup_ics() describes this zone and no other,
// so the two change together or not at all.
const OPENAR_MEETUP_TZ = 'America/Chicago';

/** The stored meetup, or NULL when none is set. */
function openar_meetup_settings(): ?array {
  $m = get_option(OPENAR_MEETUP_OPTION);
  return (is_array($m) && !empty($m['title'])) ? $m : NULL;
}

/**
 * Checks and tidies what the Tools screen form posted.
 *
 * @return array{0: ?array, 1: string} The settings to store, or NULL and the
 *   reason they cannot be stored.
 */
function openar_meetup_clean(array $in): array {
  $title = trim(sanitize_text_field((string) ($in['title'] ?? '')));
  $link = trim((string) ($in['link'] ?? ''));
  $dial = trim(sanitize_text_field((string) ($in['dial'] ?? '')));
  $about = trim(sanitize_textarea_field((string) ($in['about'] ?? '')));
  $first = trim((string) ($in['first'] ?? ''));
  $last = trim((string) ($in['last'] ?? ''));
  $start = trim((string) ($in['start'] ?? ''));
  $end = trim((string) ($in['end'] ?? ''));

  if ($title === '') {
    return [NULL, 'The meetup needs a title.'];
  }
  if (!filter_var($link, FILTER_VALIDATE_URL) || parse_url($link, PHP_URL_SCHEME) !== 'https'
    || preg_match('/[\s"<>]/', $link)) {
    return [NULL, 'The call link has to be a full https:// address.'];
  }

  $tz = new DateTimeZone(OPENAR_MEETUP_TZ);
  $f = DateTimeImmutable::createFromFormat('!Y-m-d', $first, $tz);
  $l = DateTimeImmutable::createFromFormat('!Y-m-d', $last, $tz);
  if (!$f || $f->format('Y-m-d') !== $first || !$l || $l->format('Y-m-d') !== $last) {
    return [NULL, 'Both the first and the last meeting date are needed.'];
  }
  if ($l < $f) {
    return [NULL, 'The last meeting cannot come before the first.'];
  }
  if ($l->format('N') !== $f->format('N')) {
    return [NULL, 'The last meeting has to fall on the same weekday as the first, because the meetup repeats weekly.'];
  }

  $time = '/^([01]\d|2[0-3]):[0-5]\d$/';
  if (!preg_match($time, $start) || !preg_match($time, $end)) {
    return [NULL, 'Both the start and the end time are needed.'];
  }
  if ($end <= $start) {
    return [NULL, 'The meetup has to end after it starts.'];
  }

  return [compact('title', 'link', 'dial', 'about', 'first', 'last', 'start', 'end'), ''];
}

/**
 * The meetup as it stands for somebody reading about it now: the stored
 * settings plus the next meeting, the last, and how many remain. NULL when
 * nothing is set or every meeting is over.
 *
 * A meeting counts as ahead until it ends, so a welcome sent during a meeting
 * still points at it.
 */
function openar_meetup_upcoming(?DateTimeImmutable $now = NULL): ?array {
  $m = openar_meetup_settings();
  if (!$m) {
    return NULL;
  }

  $tz = new DateTimeZone(OPENAR_MEETUP_TZ);
  $now = ($now ?? new DateTimeImmutable('now'))->setTimezone($tz);
  $last = new DateTimeImmutable("{$m['last']} {$m['start']}", $tz);

  // Stepping by calendar week in the meetup's own zone keeps the wall-clock
  // time fixed across a daylight saving change, which adding seconds would not.
  $next = new DateTimeImmutable("{$m['first']} {$m['start']}", $tz);
  while ($next <= $last && new DateTimeImmutable($next->format('Y-m-d') . " {$m['end']}", $tz) <= $now) {
    $next = $next->modify('+1 week');
  }
  if ($next > $last) {
    return NULL;
  }

  $count = 0;
  for ($d = $next; $d <= $last; $d = $d->modify('+1 week')) {
    $count++;
  }

  return $m + [
    'next' => $next,
    'next_end' => new DateTimeImmutable($next->format('Y-m-d') . " {$m['end']}", $tz),
    'last_at' => $last,
    'count' => $count,
  ];
}

/** "3:00 to 4:00 pm", or "11:30 am to 12:30 pm" when the meridiem changes. */
function openar_meetup_time_range(string $start, string $end): string {
  $s = DateTimeImmutable::createFromFormat('!H:i', $start);
  $e = DateTimeImmutable::createFromFormat('!H:i', $end);
  return ($s->format('a') === $e->format('a'))
    ? $s->format('g:i') . ' to ' . $e->format('g:i a')
    : $s->format('g:i a') . ' to ' . $e->format('g:i a');
}

/** The sentence that says when the meetup happens, for the welcome email. */
function openar_meetup_schedule(array $u): string {
  $time = openar_meetup_time_range($u['start'], $u['end']);
  if ($u['count'] === 1) {
    return sprintf('It meets on %s, from %s Central time.', $u['next']->format('l, F j'), $time);
  }
  return sprintf('It meets every %s from %s Central time through %s, and the next one is %s.',
    $u['next']->format('l'), $time, $u['last_at']->format('F j'), $u['next']->format('l, F j'));
}

/** The event description shared by the calendar file and the Google link. */
function openar_meetup_details(array $u): string {
  $lines = [];
  if ($u['about'] !== '') {
    $lines[] = $u['about'];
    $lines[] = '';
  }
  $lines[] = 'Join the call: ' . $u['link'];
  if ($u['dial'] !== '') {
    $lines[] = 'Or dial in: ' . $u['dial'];
  }
  return implode("\n", $lines);
}

/**
 * The remaining meetings as an iCalendar file.
 *
 * METHOD:PUBLISH with no organizer and no attendees, so calendar apps treat it
 * as an event to add rather than an invitation to answer, and nobody's RSVP
 * lands anywhere. The UID is fixed by the first meeting date, so a member who
 * adds the series twice, from the welcome email and from a mailing, gives
 * their calendar app the means to see it as one series rather than two.
 */
function openar_meetup_ics(array $u): string {
  $esc = static function (string $s): string {
    return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $s);
  };

  // Lines longer than 75 octets must be folded, and a fold must not split a
  // multibyte character, which a pasted description can easily contain.
  $fold = static function (string $line): string {
    $out = [];
    while (strlen($line) > 75) {
      $chunk = mb_strcut($line, 0, 75, 'UTF-8');
      $out[] = $chunk;
      $line = ' ' . substr($line, strlen($chunk));
    }
    $out[] = $line;
    return implode("\r\n", $out);
  };

  $tzid = OPENAR_MEETUP_TZ;
  $lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//The OpenAR Collective//Member Meetup//EN',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    'BEGIN:VTIMEZONE',
    "TZID:{$tzid}",
    'BEGIN:DAYLIGHT',
    'TZOFFSETFROM:-0600',
    'TZOFFSETTO:-0500',
    'TZNAME:CDT',
    'DTSTART:19700308T020000',
    'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=2SU',
    'END:DAYLIGHT',
    'BEGIN:STANDARD',
    'TZOFFSETFROM:-0500',
    'TZOFFSETTO:-0600',
    'TZNAME:CST',
    'DTSTART:19701101T020000',
    'RRULE:FREQ=YEARLY;BYMONTH=11;BYDAY=1SU',
    'END:STANDARD',
    'END:VTIMEZONE',
    'BEGIN:VEVENT',
    'UID:meetup-' . str_replace('-', '', $u['first']) . '@openarcollective.org',
    'DTSTAMP:' . gmdate('Ymd\THis\Z'),
    'SEQUENCE:0',
    "DTSTART;TZID={$tzid}:" . $u['next']->format('Ymd\THis'),
    "DTEND;TZID={$tzid}:" . $u['next_end']->format('Ymd\THis'),
  ];
  if ($u['count'] > 1) {
    $lines[] = 'RRULE:FREQ=WEEKLY;COUNT=' . $u['count'];
  }
  array_push($lines,
    'SUMMARY:' . $esc($u['title']),
    'LOCATION:' . $esc($u['link']),
    'URL:' . $u['link'],
    'DESCRIPTION:' . $esc(openar_meetup_details($u)),
    'STATUS:CONFIRMED',
    'END:VEVENT',
    'END:VCALENDAR'
  );

  return implode("\r\n", array_map($fold, $lines)) . "\r\n";
}

/** A link that opens Google Calendar with the remaining meetings filled in. */
function openar_meetup_gcal_url(array $u): string {
  $q = [
    'action' => 'TEMPLATE',
    'text' => $u['title'],
    'dates' => $u['next']->format('Ymd\THis') . '/' . $u['next_end']->format('Ymd\THis'),
    'ctz' => OPENAR_MEETUP_TZ,
  ];
  if ($u['count'] > 1) {
    $q['recur'] = 'RRULE:FREQ=WEEKLY;COUNT=' . $u['count'];
  }
  $q['location'] = $u['link'];
  $q['details'] = openar_meetup_details($u);

  return 'https://calendar.google.com/calendar/render?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
}

/**
 * What the welcome email needs: template parameters, and the calendar file
 * as an attachment the caller deletes after sending.
 *
 * meetupTitle is always present, empty when there is no meetup to mention,
 * because it is the variable the template tests.
 *
 * @return array{params: array, attachment: ?array}
 */
function openar_meetup_for_email(?DateTimeImmutable $now = NULL): array {
  $u = openar_meetup_upcoming($now);
  if (!$u) {
    return ['params' => ['meetupTitle' => ''], 'attachment' => NULL];
  }

  $attachment = NULL;
  $path = tempnam(get_temp_dir(), 'openar-meetup-');
  if ($path !== FALSE) {
    if (file_put_contents($path, openar_meetup_ics($u)) !== FALSE) {
      $attachment = [
        'fullPath' => $path,
        'mime_type' => 'text/calendar',
        'cleanName' => 'openar-meetup.ics',
      ];
    }
    else {
      @unlink($path);
    }
  }

  return [
    'params' => [
      'meetupTitle' => $u['title'],
      'meetupSchedule' => openar_meetup_schedule($u),
      'meetupAbout' => $u['about'],
      'meetupLink' => $u['link'],
      'meetupDial' => $u['dial'],
      'meetupGcal' => openar_meetup_gcal_url($u),
      'meetupAttached' => $attachment !== NULL,
    ],
    'attachment' => $attachment,
  ];
}

/** One line for the Tools screen saying what the welcome email does now. */
function openar_meetup_status(): string {
  $m = openar_meetup_settings();
  if (!$m) {
    return 'The welcome email does not mention a meetup right now.';
  }

  $lastDay = DateTimeImmutable::createFromFormat('!Y-m-d', $m['last'], new DateTimeZone(OPENAR_MEETUP_TZ))
    ->format('l, F j');
  $u = openar_meetup_upcoming();
  if (!$u) {
    return "The {$m['title']} ended {$lastDay}, so the welcome email no longer mentions it. Remove it here, or set up the next one.";
  }
  return "The welcome email invites new members to the {$m['title']}. The next meeting is "
    . $u['next']->format('l, F j') . ", and the invitation drops out of the email by itself after {$lastDay}.";
}
