<?php
/**
 * DFW Pinball League — upcoming events digest.
 *
 * Source of truth: the league's public Google Calendar (ICS feed). It is the
 * one machine-readable source the league keeps current — the calendar embed
 * on the league site itself says event/registration dates come from here.
 * We deliberately do NOT scrape the Google Sites page: it's hand-formatted
 * Google Docs markup with no stable structure, and parsing it would break
 * silently every time the admin re-pastes a paragraph. A link to the full
 * site is offered instead for anything not (yet) on the calendar.
 */

date_default_timezone_set('America/Chicago');

$ics_url    = 'https://calendar.google.com/calendar/ical/1dc4993689322ae4fa6b280c904495bab049f75f2a41475cf4091cc7b01fb2c5%40group.calendar.google.com/public/basic.ics';
$cache_file = sys_get_temp_dir() . '/dfwpl_events.ics.json';
$cache_ttl  = 1800; // 30 minutes

// IFPA tournament pages fill in confirmed venue address + day-of schedule as the date nears —
// often before the calendar entry itself gets updated. Same API key used by tournament/index.php.
$ifpa_api_key    = '55b97a4ccf9b9c4ee2d443b2737574ab';
$ifpa_cache_file = sys_get_temp_dir() . '/dfwpl_events.ifpa.json';

// IFPA directors whose upcoming tournaments belong in the regional view — local events that fall
// just outside the Matchplay DFW-region filter. 3849 = Chris Noah (Pottsboro).
$ifpa_director_ids = ['3849'];

$site_url = 'https://sites.google.com/view/dfwpinballleague/';
$cal_url  = 'https://calendar.google.com/calendar/embed?src=1dc4993689322ae4fa6b280c904495bab049f75f2a41475cf4091cc7b01fb2c5%40group.calendar.google.com&ctz=America%2FChicago';

// ── Fetch + cache (stale-while-error, same pattern as the other pages) ─────
$cached_data = null;
$cache_age   = null;
if (file_exists($cache_file)) {
    $raw = file_get_contents($cache_file);
    $obj = $raw ? json_decode($raw, true) : null;
    if ($obj && isset($obj['timestamp'], $obj['data'])) {
        $cache_age   = time() - $obj['timestamp'];
        $cached_data = $obj['data'];
    }
}

$ics        = null;
$error      = null;
$from_cache = false;

if ($cached_data === null || $cache_age > $cache_ttl) {
    $ch = curl_init($ics_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response !== false && $http_code === 200 && strpos($response, 'BEGIN:VCALENDAR') !== false) {
        $ics = $response;
        file_put_contents($cache_file, json_encode(['timestamp' => time(), 'data' => $response]));
    } elseif ($cached_data !== null) {
        $ics        = $cached_data;
        $from_cache = true;
    } else {
        $error = "Could not load the calendar (HTTP $http_code).";
    }
} else {
    $ics        = $cached_data;
    $from_cache = true;
}

function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** Escape plain text for HTML, turning any bare URLs into clickable links. */
function esc_linkify(string $s): string {
    $parts = preg_split('/(https?:\/\/[^\s<>")\]]+)/i', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    foreach ($parts as $i => $part) {
        if ($i % 2 === 1) {
            $url = rtrim($part, '.,;');
            $out .= '<a href="' . esc($url) . '" target="_blank" rel="noopener">' . esc($url) . '</a>';
        } else {
            $out .= esc($part);
        }
    }
    return $out;
}

// ── ICS parsing ──────────────────────────────────────────────────────────
function ics_unescape(string $s): string {
    $s = preg_replace('/\\\\[nN]/', "\n", $s);
    $s = str_replace(['\\,', '\\;'], [',', ';'], $s);
    $s = str_replace('\\\\', '\\', $s);
    return $s;
}

function ics_strip_html(string $s): string {
    $s = preg_replace('/<br\s*\/?>/i', "\n", $s);
    $s = preg_replace('/<\/p>/i', "\n\n", $s);
    $s = preg_replace('/<[^>]+>/', '', $s);
    $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
    $s = preg_replace('/[ \t]+/', ' ', $s);
    $s = preg_replace('/[ \t]*\n[ \t]*/', "\n", $s); // trim whitespace hugging each line break
    $s = preg_replace('/\n{2,}/', "\n", $s);          // collapse blank lines — every line is already its own fact
    return trim($s);
}

/** Parse an ICS DTSTART/DTEND value into a DateTime, honoring VALUE=DATE vs UTC Z-time. */
function ics_parse_dt(string $raw, bool $allday): ?DateTime {
    try {
        if ($allday || strpos($raw, 'T') === false) {
            return new DateTime(substr($raw, 0, 8), new DateTimeZone('America/Chicago'));
        }
        if (substr($raw, -1) === 'Z') {
            $dt = new DateTime($raw, new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone('America/Chicago'));
            return $dt;
        }
        return new DateTime($raw, new DateTimeZone('America/Chicago'));
    } catch (Exception $e) {
        return null;
    }
}

function parse_ics_events(string $data): array {
    $data = str_replace("\r\n", "\n", $data);
    $data = preg_replace('/\n[ \t]/', '', $data); // unfold continuation lines
    preg_match_all('/BEGIN:VEVENT\n(.*?)\nEND:VEVENT/s', $data, $blocks);

    $events = [];
    foreach ($blocks[1] as $block) {
        $fields = [];
        foreach (explode("\n", $block) as $line) {
            if (!preg_match('/^([A-Z\-]+)(;[^:]*)?:(.*)$/', $line, $m)) continue;
            $key = $m[1];
            if (!isset($fields[$key])) {
                $fields[$key] = ['params' => $m[2] ?? '', 'value' => $m[3]];
            }
        }
        if (!isset($fields['DTSTART'])) continue;

        $allday = strpos($fields['DTSTART']['params'] ?? '', 'VALUE=DATE') !== false
            || strlen($fields['DTSTART']['value']) === 8;

        $start = ics_parse_dt($fields['DTSTART']['value'], $allday);
        $end   = isset($fields['DTEND']) ? ics_parse_dt($fields['DTEND']['value'], $allday) : null;
        if (!$start) continue;
        // All-day DTEND is exclusive per the iCal spec (the day *after* the event ends) — pull it back
        // one day so a 10/23–10/26 stored range displays as the correct 10/23–10/25.
        if ($allday && $end) {
            $end->modify('-1 day');
        }

        $desc_raw = ics_unescape($fields['DESCRIPTION']['value'] ?? '');
        $events[] = [
            'summary'         => ics_unescape($fields['SUMMARY']['value'] ?? ''),
            'description'     => ics_strip_html($desc_raw),
            'description_raw' => $desc_raw,
            'location'        => ics_unescape($fields['LOCATION']['value'] ?? ''),
            'start'           => $start,
            'end'             => $end,
            'allday'          => $allday,
        ];
    }
    return $events;
}

/** Pull a labeled short name for a URL, for button text. */
function label_url(string $url): string {
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
    if (strpos($host, 'matchplay') !== false)  return 'Matchplay';
    if (strpos($host, 'eventbrite') !== false) return 'Eventbrite';
    if (strpos($host, 'facebook') !== false)   return 'Facebook';
    if (strpos($host, 'ifpapinball') !== false) return 'IFPA';
    if (strpos($host, 'tinyurl') !== false)    return 'Event Page';
    if ($host) return $host;
    return 'Link';
}

/** Extract deduped URLs from raw (pre-strip) HTML + stripped text, in order. */
function extract_urls(string $raw_desc): array {
    $urls = [];
    if (preg_match_all('/href="([^"]+)"/i', $raw_desc, $m)) {
        foreach ($m[1] as $u) $urls[] = html_entity_decode($u, ENT_QUOTES, 'UTF-8');
    }
    $plain = ics_strip_html($raw_desc);
    if (preg_match_all('/https?:\/\/[^\s<>")\]]+/i', $plain, $m)) {
        foreach ($m[0] as $u) $urls[] = rtrim($u, '.,;');
    }
    $seen = [];
    $out  = [];
    foreach ($urls as $u) {
        $key = rtrim($u, '/');
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $u;
    }
    return $out;
}

/** Grab the sentence mentioning registration/RSVP opening, straight from the event's own text. */
function reg_sentence(string $desc): ?string {
    foreach (preg_split('/\n/', $desc) as $line) {
        if (!preg_match('/\b(?:registration|rsvp)\b.*?\bopen[a-z]*\b/i', $line)) continue;
        // Cut the line off before any URL — links are already rendered as their own buttons,
        // and a URL's dots would otherwise get mistaken for sentence-ending punctuation.
        $line = preg_split('/\s*(?:at|via)?\s*:?\s*https?:\/\//i', $line)[0];
        $line = trim(preg_replace('/\s+/', ' ', $line));
        $line = rtrim($line, " \t-:");
        if ($line === '') continue;
        return $line . (substr($line, -1) === '.' ? '' : '.');
    }
    return null;
}

// ── IFPA tournament-page enrichment ─────────────────────────────────────
// Pulls confirmed venue address + doors/start times from the linked IFPA
// tournament page's own API record, once the organizer has created one.
// Best-effort only: any failure just means we fall back to calendar data.

/** Pull an IFPA tournament id out of an event's extracted links, if one exists. */
function extract_ifpa_id(array $urls): ?string {
    foreach ($urls as $u) {
        if (strpos(strtolower(parse_url($u, PHP_URL_HOST) ?: ''), 'ifpapinball') === false) continue;
        parse_str((string) parse_url($u, PHP_URL_QUERY), $q);
        if (!empty($q['t']) && ctype_digit($q['t'])) return $q['t'];
    }
    return null;
}

/** Fetch (with cache) one tournament's record from the IFPA API. Null on any failure. */
function fetch_ifpa_tournament(string $id, string $api_key, array &$cache, int $ttl): ?array {
    if (isset($cache[$id]) && (time() - $cache[$id]['timestamp']) <= $ttl) {
        return $cache[$id]['data'];
    }
    $ch = curl_init("https://api.ifpapinball.com/tournament/{$id}?api_key={$api_key}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response !== false && $http_code === 200) {
        $data = json_decode($response, true);
        if (is_array($data) && isset($data['tournament_id'])) {
            $cache[$id] = ['timestamp' => time(), 'data' => $data];
            return $data;
        }
    }
    // Fetch failed — fall back to a stale cached copy if we have one, rather than nothing.
    return $cache[$id]['data'] ?? null;
}

/** IFPA addresses come back SHOUTING IN ALL CAPS — title-case them, but keep short
 *  tokens (state codes, directionals like "N"/"S") upper so "TX" doesn't become "Tx". */
function title_case_address(string $s): string {
    $tokens = preg_split('/([\s,]+)/', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($tokens as &$tok) {
        if (!preg_match('/^[A-Za-z]+$/', $tok)) continue;
        $tok = mb_strlen($tok) <= 2 ? mb_strtoupper($tok) : mb_convert_case(mb_strtolower($tok), MB_CASE_TITLE, 'UTF-8');
    }
    return implode('', $tokens);
}

/** Build a clean one-line address from an IFPA tournament record, if it has one. */
function ifpa_venue_address(array $t): ?string {
    $raw = trim(str_replace(["\r\n", "\r", "\n"], ', ', $t['raw_address'] ?? ''));
    $raw = trim($raw, ", ");
    if ($raw !== '') return title_case_address($raw);

    $parts = array_filter([
        $t['address1'] ?? '',
        $t['city'] ?? '',
        trim(($t['stateprov'] ?? '') . ' ' . ($t['postal_code'] ?? '')),
    ]);
    return $parts ? title_case_address(implode(', ', $parts)) : null;
}

/** Normalize a matched time fragment ("11:30 AM", "NOON", "7pm") to "H:MM AM/PM". */
function normalize_time_token(string $raw): string {
    $raw = trim($raw);
    if (stripos($raw, 'noon') !== false) return '12:00 PM';
    if (stripos($raw, 'midnight') !== false) return '12:00 AM';
    if (preg_match('/(\d{1,2})(?::(\d{2}))?\s*([ap])\.?m?\.?/i', $raw, $m)) {
        $hour = (int) $m[1];
        // Optional minute group is forced into $m as '' (not unset) whenever the AM/PM group
        // after it matches — so `??` alone won't catch a bare "5PM" and would print "5: PM".
        $min = ($m[2] ?? '') !== '' ? $m[2] : '00';
        return "{$hour}:{$min} " . strtoupper($m[3]) . 'M';
    }
    return strtoupper($raw);
}

/** Look for "doors open" / tournament-start phrasing in freeform event text. */
function extract_schedule(string $text): array {
    $time = '(\d{1,2}(?::\d{2})?\s*(?:[ap]\.?m\.?)|noon|midnight)';
    $doors = $start = null;

    if (preg_match('/\bdoors?\s*open\w*[^.\n]{0,40}?' . $time . '/i', $text, $m)) {
        $doors = normalize_time_token($m[1]);
    }
    foreach ([
        '/\bfirst\s*(?:flip|ball)(?:\s*attempt)?\b[^.\n]{0,40}?' . $time . '/i',
        '/\btournament\s*(?:meeting\s*\/?\s*start|start)\b[^.\n]{0,40}?' . $time . '/i',
        '/\bstart\s*time\b[^.\n]{0,40}?' . $time . '/i',
    ] as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            $start = normalize_time_token($m[1]);
            break;
        }
    }
    return ['doors' => $doors, 'start' => $start];
}

// ── Regional sources (shown only via ?region=1) ─────────────────────────
// Matchplay's DFW-area calendar, plus the upcoming tournaments of any extra
// IFPA directors (local events just outside Matchplay's region filter).
// Both are shaped very differently from the Google Calendar feed: no
// DESCRIPTION text at all (so no registration blurb or full-details panel for
// these), but a direct tournament-page URL. The Matchplay feed also lists
// sub-bracket "Finals for X" sessions as their own events, and mixes in the
// league's own tournaments (which the primary feed already covers) and a lot
// of weekly/monthly regulars at other venues — all filtered out below.

/** Same block-splitting approach as parse_ics_events(), interpreted for the Matchplay feed's fields. */
function parse_regional_events(string $data): array {
    $data = str_replace("\r\n", "\n", $data);
    $data = preg_replace('/\n[ \t]/', '', $data); // unfold continuation lines
    preg_match_all('/BEGIN:VEVENT\n(.*?)\nEND:VEVENT/s', $data, $blocks);

    $events = [];
    foreach ($blocks[1] as $block) {
        $fields = [];
        foreach (explode("\n", $block) as $line) {
            if (!preg_match('/^([A-Z0-9\-]+)(;[^:]*)?:(.*)$/', $line, $m)) continue;
            $key = $m[1];
            if (!isset($fields[$key])) {
                $fields[$key] = ['params' => $m[2] ?? '', 'value' => $m[3]];
            }
        }
        if (!isset($fields['DTSTART'])) continue;

        $allday = strpos($fields['DTSTART']['params'] ?? '', 'VALUE=DATE') !== false
            || strlen($fields['DTSTART']['value']) === 8;

        $start = ics_parse_dt($fields['DTSTART']['value'], $allday);
        $end   = isset($fields['DTEND']) ? ics_parse_dt($fields['DTEND']['value'], $allday) : null;
        if (!$start) continue;
        // Unlike Google's feed, this one just sets DTEND = DTSTART for single-day/point events —
        // it doesn't use the "exclusive end date" convention, so only pull back a real span.
        if ($allday && $end && $end > $start) {
            $end->modify('-1 day');
        }

        // The venue name (e.g. "Free Play Arcade") lives in X-APPLE-STRUCTURED-LOCATION's X-TITLE,
        // separately from the street address in LOCATION.
        $venue_name = null;
        if (preg_match('/X-TITLE=([^:]+):geo:/', $block, $vm)) {
            $venue_name = ics_unescape(trim($vm[1]));
        }
        $location = ics_unescape($fields['LOCATION']['value'] ?? '');
        if ($venue_name && stripos($location, $venue_name) === false) {
            $location = $location !== '' ? "{$venue_name} — {$location}" : $venue_name;
        }

        $events[] = [
            'uid'      => $fields['UID']['value'] ?? '',
            'summary'  => ics_unescape($fields['SUMMARY']['value'] ?? ''),
            'location' => $location,
            'url'      => ics_unescape($fields['URL']['value'] ?? ($fields['ATTACH']['value'] ?? '')),
            'start'    => $start,
            'end'      => $end,
            'allday'   => $allday,
        ];
    }
    return $events;
}

/** GET a URL through a file cache, falling back to a stale copy if the fetch fails. Returns the
 *  body, or null when the fetch failed and nothing is cached. $is_valid rejects error pages that
 *  come back with a 200. Lets each regional source succeed or fail independently of the others. */
function fetch_cached(string $url, string $cache_file, int $ttl, callable $is_valid): ?string {
    $cached = null;
    $age    = null;
    if (file_exists($cache_file)) {
        $obj = json_decode(file_get_contents($cache_file), true);
        if ($obj && isset($obj['timestamp'], $obj['data'])) {
            $age    = time() - $obj['timestamp'];
            $cached = $obj['data'];
        }
    }
    if ($cached !== null && $age <= $ttl) return $cached;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp !== false && $code === 200 && $is_valid($resp)) {
        file_put_contents($cache_file, json_encode(['timestamp' => time(), 'data' => $resp]));
        return $resp;
    }
    return $cached;
}

/** An IFPA director's upcoming tournaments, normalized into the same record shape that
 *  parse_regional_events() produces so both flow through one dedup pipeline. The list endpoint
 *  only carries date + city, so these are date-only (all-day) entries with a city for a location. */
function parse_ifpa_director_events(string $json): array {
    $data = json_decode($json, true);
    $events = [];
    foreach ($data['tournaments'] ?? [] as $t) {
        if (empty($t['tournament_id']) || empty($t['event_start_date'])) continue;
        $start = ics_parse_dt(str_replace('-', '', $t['event_start_date']), true);
        $end   = ics_parse_dt(str_replace('-', '', $t['event_end_date'] ?? $t['event_start_date']), true);
        if (!$start) continue;
        $events[] = [
            'uid'      => 'ifpaEvent:' . $t['tournament_id'],
            'summary'  => trim($t['tournament_name'] ?? ''),
            'location' => implode(', ', array_filter([$t['city'] ?? '', $t['stateprov_code'] ?? ''])),
            'url'      => 'https://www.ifpapinball.com/tournaments/view.php?t=' . $t['tournament_id'],
            'start'    => $start,
            'end'      => $end,
            'allday'   => true,
        ];
    }
    return $events;
}

/** True when a league event's own writeup already names this tournament, on a day inside that
 *  event's date span — e.g. the Thursday warmup listed in a Halloween-weekend event's details.
 *  Exact name + date containment, not fuzzy matching, so a look-alike title can't hide a real event. */
function named_in_primary_event(array $re, array $primary): bool {
    if ($re['summary'] === '') return false;
    $day = $re['start']->format('Y-m-d');
    foreach ($primary as $pe) {
        $from = $pe['start']->format('Y-m-d');
        $to   = ($pe['end'] ?? $pe['start'])->format('Y-m-d');
        if ($day >= $from && $day <= $to && stripos($pe['description'], $re['summary']) !== false) return true;
    }
    return false;
}

/** Matchplay tournament id out of a matchplay.events URL, if any. */
function extract_matchplay_id(string $url): ?string {
    return preg_match('#matchplay\.events/tournaments/(\d+)#i', $url, $m) ? $m[1] : null;
}

/** A regional-feed entry's own identity on either platform, from its UID first (structural and
 *  reliable), falling back to parsing its one URL. Used only to cross-reference against the
 *  primary feed's own links — never to fuzzy-match by title/date, which risks false positives. */
function regional_ifpa_id(array $re): ?string {
    if (preg_match('/^ifpaEvent:(\d+)/', $re['uid'], $m)) return $m[1];
    return extract_ifpa_id([$re['url']]);
}
function regional_matchplay_id(array $re): ?string {
    if (preg_match('/^tournament:(\d+)/', $re['uid'], $m)) return $m[1];
    return extract_matchplay_id($re['url']);
}

/** Normalize a title for recurrence detection — strips the bits that make otherwise-identical
 *  weekly/monthly series look like distinct one-off events ("(9/7/26)", "11/3/2026", "#3"). */
function recurrence_key(string $summary): string {
    $s = strtolower($summary);
    $s = preg_replace('/\s*\(?\d{1,2}\/\d{1,2}(?:\/\d{2,4})?\)?\s*$/', '', $s);
    $s = preg_replace('/\s*#\d+\s*$/', '', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

// ── Load + classify ─────────────────────────────────────────────────────
$all_events = $ics ? parse_ics_events($ics) : [];

$REG_MARKER = '/^\s*(pre-?)?registration\b|registration\s*(\/\s*rsvp)?\s*(opens?|today)|register\s*\/\s*rsvp|^\s*\d+\s*(am|pm)\s*[-–]\s*pre-?registration/i';
$NOT_LEAGUE = '/not a dfw league event|not counted toward|regional event|national event/i';

$now = new DateTime('now', new DateTimeZone('America/Chicago'));
$today_midnight = new DateTime($now->format('Y-m-d'), new DateTimeZone('America/Chicago'));

$upcoming = [];
foreach ($all_events as $e) {
    if (preg_match($REG_MARKER, $e['summary'])) continue; // separate "registration opens" reminder, not the event itself
    if ($e['start'] < $today_midnight) continue;
    $upcoming[] = $e;
}

usort($upcoming, fn($a, $b) => $a['start'] <=> $b['start']);

// Load the IFPA cache once; fetch_ifpa_tournament() fills in gaps below, then we save it back once.
$ifpa_cache = [];
if (file_exists($ifpa_cache_file)) {
    $decoded = json_decode(file_get_contents($ifpa_cache_file), true);
    if (is_array($decoded)) $ifpa_cache = $decoded;
}
$ifpa_cache_dirty = false;

foreach ($upcoming as &$e) {
    $e['urls']        = extract_urls($e['description_raw']);
    $e['source']       = 'dfw';
    $e['recurring']    = false;
    $e['not_league']   = (bool) preg_match($NOT_LEAGUE, $e['summary'] . ' ' . $e['description']);
    $e['tbd']          = (bool) preg_match('/\btbd\b|forthcoming|details? (?:to come|coming soon)/i', $e['summary'] . ' ' . $e['description']);
    $e['multiday']     = $e['end'] && $e['end']->diff($e['start'])->days >= 1 &&
                          ($e['allday'] ? $e['end']->diff($e['start'])->days >= 1 : $e['end']->format('Y-m-d') !== $e['start']->format('Y-m-d'));

    // Calendar text is the baseline; an IFPA tournament page, once created, tends to carry the
    // confirmed venue address and day-of schedule earlier than the calendar entry gets updated.
    $e['venue'] = $e['location'];
    $e['venue_confirmed'] = false;
    $sched = extract_schedule($e['description']);

    $ifpa_id = extract_ifpa_id($e['urls']);
    if ($ifpa_id) {
        $tournament = fetch_ifpa_tournament($ifpa_id, $ifpa_api_key, $ifpa_cache, $cache_ttl);
        $ifpa_cache_dirty = true; // harmless to re-save even on a pure cache hit

        if ($tournament) {
            $addr = ifpa_venue_address($tournament);
            if ($addr) {
                $e['venue']           = $addr;
                $e['venue_confirmed'] = true;
            }
            if (!empty($tournament['details'])) {
                $ifpa_text = str_replace(["\r\n", "\r"], "\n", $tournament['details']);
                $ifpa_sched = extract_schedule($ifpa_text);
                $sched['doors'] = $sched['doors'] ?? $ifpa_sched['doors'];
                $sched['start'] = $sched['start'] ?? $ifpa_sched['start'];
            }
        }
    }
    $e['sched'] = $sched;
    $e['reg_sentence'] = reg_sentence($e['description']);
}
unset($e);

if ($ifpa_cache_dirty) {
    file_put_contents($ifpa_cache_file, json_encode($ifpa_cache));
}

// ── Fetch + merge the regional feed, only when the viewer asked for it ─────
$show_regional   = isset($_GET['region']) && $_GET['region'] !== '' && $_GET['region'] !== '0';
$regional_failed = []; // names of regional sources that couldn't be loaded, for a small notice

if ($show_regional) {
    // Two independent sources feed the regional view: Matchplay's DFW-area calendar, plus the
    // upcoming tournaments of any extra IFPA directors. Either can fail without hiding the other.
    $regional_raw = [];

    $matchplay_ics = fetch_cached(
        'https://app.matchplay.events/api/ical/region/dfw',
        sys_get_temp_dir() . '/dfwpl_events.regional.ics.json',
        $cache_ttl,
        fn($body) => strpos($body, 'BEGIN:VCALENDAR') !== false
    );
    if ($matchplay_ics !== null) {
        $regional_raw = array_merge($regional_raw, parse_regional_events($matchplay_ics));
    } else {
        $regional_failed[] = "Matchplay's regional calendar";
    }

    foreach ($ifpa_director_ids as $director_id) {
        $director_json = fetch_cached(
            "https://api.ifpapinball.com/director/{$director_id}/tournaments/future?api_key={$ifpa_api_key}",
            sys_get_temp_dir() . "/dfwpl_events.ifpadir{$director_id}.json",
            $cache_ttl,
            // A director with nothing scheduled returns {"tournament_count":0} with no list at all —
            // that's a valid empty answer, not a failed fetch.
            fn($body) => ($d = json_decode($body, true)) && (isset($d['tournaments']) || isset($d['tournament_count']))
        );
        if ($director_json !== null) {
            $regional_raw = array_merge($regional_raw, parse_ifpa_director_events($director_json));
        } else {
            $regional_failed[] = 'IFPA tournament listings';
        }
    }

    if ($regional_raw) {
        // Tournaments the primary DFW feed already covers, indexed by whichever platform id(s) it links.
        $primary_ifpa_ids = [];
        $primary_matchplay_ids = [];
        foreach ($upcoming as $pe) {
            $id = extract_ifpa_id($pe['urls']);
            if ($id) $primary_ifpa_ids[$id] = true;
            foreach ($pe['urls'] as $u) {
                $mid = extract_matchplay_id($u);
                if ($mid) $primary_matchplay_ids[$mid] = true;
            }
        }

        $future_regional = array_values(array_filter(
            $regional_raw,
            fn($re) => $re['start'] >= $today_midnight
        ));
        usort($future_regional, fn($a, $b) => $a['start'] <=> $b['start']);

        // Filter out: sub-bracket "Finals for X" sessions (not separate events), the league's own
        // tournaments (the primary feed already has these — matched by id, and by title prefix as
        // a backstop for whenever the calendar entry hasn't been cross-linked yet), side tournaments
        // a league event's own writeup already names.
        $candidates = [];
        foreach ($future_regional as $re) {
            if (preg_match('/\bfinals\s+for\b/i', $re['summary'])) continue;
            if (stripos($re['summary'], 'dfw pinball league') === 0) continue;

            $ifpa_id = regional_ifpa_id($re);
            if ($ifpa_id && isset($primary_ifpa_ids[$ifpa_id])) continue;
            $mp_id = regional_matchplay_id($re);
            if ($mp_id && isset($primary_matchplay_ids[$mp_id])) continue;
            if (named_in_primary_event($re, $upcoming)) continue;

            $candidates[] = $re;
        }

        // The regional sources themselves sometimes list the same tournament twice — e.g. once via
        // its IFPA listing (date only, no time) and once via its Matchplay listing (exact date + time)
        // — same title, same day. Collapse those, preferring whichever copy actually carries a time.
        $dedup_index = [];
        $deduped = [];
        foreach ($candidates as $re) {
            $key = strtolower($re['summary']) . '|' . $re['start']->format('Y-m-d');
            if (isset($dedup_index[$key])) {
                $i = $dedup_index[$key];
                if ($deduped[$i]['allday'] && !$re['allday']) $deduped[$i] = $re;
                continue;
            }
            $dedup_index[$key] = count($deduped);
            $deduped[] = $re;
        }
        $candidates = $deduped;

        // Weekly/monthly series (Free Play Denton Pinball Monday, FPPL - Season 21 - Richardson #N, …)
        // collapse down to just their next occurrence, rather than listing every future date.
        $counts = [];
        foreach ($candidates as $re) {
            $key = recurrence_key($re['summary']);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $seen_series = [];
        $regional_extra = [];
        foreach ($candidates as $re) {
            $key = recurrence_key($re['summary']);
            $is_recurring = $counts[$key] >= 3;
            if ($is_recurring) {
                if (isset($seen_series[$key])) continue;
                $seen_series[$key] = true;
            }

            $regional_extra[] = [
                'summary'         => $re['summary'],
                'description'     => '',
                'description_raw' => '',
                'location'        => $re['location'],
                'venue'           => $re['location'],
                'venue_confirmed' => false,
                'start'           => $re['start'],
                'end'             => $re['end'],
                'allday'          => $re['allday'],
                'urls'            => $re['url'] ? [$re['url']] : [],
                'not_league'      => false,
                'tbd'             => false,
                'reg_sentence'    => null,
                'sched'           => ['doors' => null, 'start' => null],
                'multiday'        => (bool) ($re['end'] && $re['end'] > $re['start']),
                'source'          => 'regional',
                'recurring'       => $is_recurring,
            ];
        }

        $upcoming = array_merge($upcoming, $regional_extra);
        usort($upcoming, fn($a, $b) => $a['start'] <=> $b['start']);
    }
}

$last_updated = null;
if (file_exists($cache_file)) {
    $obj = json_decode(file_get_contents($cache_file), true);
    if ($obj && isset($obj['timestamp'])) {
        $last_updated = (new DateTime('@' . $obj['timestamp']))->setTimezone(new DateTimeZone('America/Chicago'));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $show_regional ? 'North Texas Regional Events' : 'DFW Pinball League Events' ?></title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Mono:wght@400;500&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg: #ffffff;
    --surface: #ffffff;
    --surface2: #f4f4f7;
    --border: #dcdce6;
    --gold: #a07000;
    --accent: #d93a10;
    --green: #2a7a52;
    --text: #111118;
    --muted: #6b6b80;
  }

  body {
    background: var(--bg);
    font-family: 'DM Sans', sans-serif;
    color: var(--text);
    min-height: 100vh;
  }

  .embed { max-width: 680px; margin: 0 auto; padding: 0; position: relative; }

  .header {
    background: var(--surface);
    border: 1px solid var(--border);
    border-bottom: none;
    padding: 24px 28px 20px;
    position: relative;
    overflow: hidden;
  }
  .header::before {
    content: '';
    position: absolute; top: 0; left: 0; right: 0; height: 3px;
    background: linear-gradient(90deg, var(--accent), var(--gold), var(--accent));
    background-size: 200% 100%;
    animation: shimmer 3s linear infinite;
  }
  @keyframes shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }

  .header-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; }

  .title-row { display: flex; align-items: center; gap: 14px; }
  .header-logo { height: 52px; width: auto; flex-shrink: 0; }

  .eyebrow {
    font-family: 'DM Mono', monospace; font-size: 10px; letter-spacing: 0.2em;
    text-transform: uppercase; color: var(--accent); margin-bottom: 6px;
  }
  .title { font-family: 'Bebas Neue', sans-serif; font-size: 36px; letter-spacing: 0.04em; line-height: 1; color: var(--text); }
  .subtitle { font-size: 13px; color: var(--muted); margin-top: 6px; line-height: 1.5; }

  .live-badge {
    display: flex; align-items: center; gap: 6px;
    background: rgba(255, 77, 28, 0.12); border: 1px solid rgba(255, 77, 28, 0.3);
    border-radius: 20px; padding: 5px 12px; font-size: 11px; font-family: 'DM Mono', monospace;
    color: var(--accent); letter-spacing: 0.05em; white-space: nowrap; flex-shrink: 0;
  }
  .live-badge.cached { background: rgba(107, 107, 128, 0.1); border-color: rgba(107, 107, 128, 0.3); color: var(--muted); }
  .live-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--accent); animation: pulse 1.5s ease-in-out infinite; }
  .live-badge.cached .live-dot { background: var(--muted); animation: none; }
  @keyframes pulse { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.5; transform: scale(0.8); } }

  .links-bar {
    display: flex; gap: 8px; flex-wrap: wrap;
    border: 1px solid var(--border); border-top: none;
    background: var(--surface2);
    padding: 10px 16px;
  }
  .links-bar a {
    font-size: 11px; font-family: 'DM Mono', monospace; color: var(--muted);
    text-decoration: none; border: 1px solid var(--border); border-radius: 20px;
    padding: 5px 12px; transition: border-color 0.15s, color 0.15s;
  }
  .links-bar a:hover { border-color: var(--accent); color: var(--accent); }

  /* Two-option view switch: both choices always visible, current one filled in. */
  .view-switch {
    border: 1px solid var(--border); border-top: none; background: var(--surface2);
    padding: 10px 16px 0;
  }
  .view-switch .seg-wrap {
    display: flex; border: 1px solid var(--border); border-radius: 8px;
    overflow: hidden; background: var(--surface);
  }
  .view-switch a {
    flex: 1; text-align: center; padding: 9px 10px; text-decoration: none;
    font-family: 'DM Mono', monospace; font-size: 11px; letter-spacing: 0.06em;
    text-transform: uppercase; color: var(--muted);
    transition: color 0.15s, background 0.15s;
  }
  .view-switch a + a { border-left: 1px solid var(--border); }
  .view-switch a:hover { color: var(--accent); }
  .view-switch a[aria-current="true"] { background: var(--accent); color: #fff; cursor: default; }

  .month-head {
    font-family: 'Bebas Neue', sans-serif; font-size: 16px; letter-spacing: 0.08em;
    color: var(--muted); background: var(--surface2);
    border: 1px solid var(--border); border-top: none;
    padding: 8px 16px;
  }

  .event {
    display: grid; grid-template-columns: 64px 1fr; gap: 14px;
    border: 1px solid var(--border); border-top: none;
    background: var(--surface);
    padding: 14px 16px;
    animation: fadeIn 0.3s ease both;
  }
  @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }

  .date-badge {
    text-align: center; font-family: 'Bebas Neue', sans-serif; color: var(--text);
    padding-top: 2px;
  }
  .date-badge .dow { font-family: 'DM Mono', monospace; font-size: 10px; letter-spacing: 0.1em; color: var(--muted); text-transform: uppercase; }
  .date-badge .day { font-size: 30px; line-height: 1; color: var(--gold); }
  .date-badge .day2 { font-size: 13px; color: var(--muted); margin-top: -2px; }

  .evt-name { font-size: 15px; font-weight: 600; color: var(--text); line-height: 1.35; }
  .date-badge .evt-league-icon { height: auto; width: 36px; margin-top: 6px; }
  .evt-badge {
    display: inline-block; font-family: 'DM Mono', monospace; font-size: 9px;
    letter-spacing: 0.08em; text-transform: uppercase; color: var(--muted);
    border: 1px solid var(--border); border-radius: 10px; padding: 1px 7px;
    margin-left: 6px; vertical-align: middle;
  }
  .evt-loc { font-size: 12px; color: var(--muted); margin-top: 3px; }
  .venue-confirmed {
    color: var(--green); font-size: 10px; font-family: 'DM Mono', monospace;
    margin-left: 5px; white-space: nowrap;
  }

  .evt-sched { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 6px; }
  .sched-pill {
    font-size: 11px; font-family: 'DM Mono', monospace; color: var(--text);
    background: var(--surface2); border: 1px solid var(--border); border-radius: 5px;
    padding: 3px 8px;
  }

  .evt-reg {
    margin-top: 8px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
  }
  .reg-text {
    font-size: 12px; color: var(--green); background: rgba(42, 122, 82, 0.08);
    border: 1px solid rgba(42, 122, 82, 0.25); border-radius: 6px; padding: 5px 9px;
    line-height: 1.4;
  }
  .reg-tbd { font-size: 12px; color: var(--muted); font-style: italic; }
  .reg-link {
    font-size: 12px; font-weight: 600; color: #fff; background: var(--accent);
    text-decoration: none; border-radius: 6px; padding: 6px 12px;
    font-family: 'DM Sans', sans-serif; white-space: nowrap;
  }
  .reg-link:hover { background: #b8300d; }
  .reg-link.secondary {
    color: var(--muted); background: var(--surface2); border: 1px solid var(--border);
  }
  .reg-link.secondary:hover { border-color: var(--accent); color: var(--accent); }

  .details-wrap { margin-top: 10px; }
  .details-wrap summary {
    font-family: 'DM Mono', monospace; font-size: 10px; letter-spacing: 0.08em;
    text-transform: uppercase; color: var(--muted);
    cursor: pointer; padding: 4px 0; display: flex; align-items: center; gap: 5px;
    list-style: none; /* remove default marker so our own caret icon is the only one */
  }
  .details-wrap summary::-webkit-details-marker { display: none; } /* same, for Safari */
  .details-wrap summary:hover { color: var(--accent); }
  .details-wrap summary .car { transition: transform 0.15s; font-size: 9px; }
  .details-wrap[open] summary .car { transform: rotate(90deg); }

  .details-body {
    margin-top: 8px; padding: 12px; background: var(--surface2); border: 1px solid var(--border);
    border-radius: 8px; font-size: 12.5px; color: var(--text); line-height: 1.5;
    white-space: pre-line;
  }
  .details-body a { color: var(--accent); }

  .empty, .error-msg { padding: 32px 24px; text-align: center; color: var(--muted); font-size: 13px;
    border: 1px solid var(--border); border-top: none; }
  .error-msg { color: var(--accent); }

  .regional-notice {
    padding: 8px 16px; text-align: center; color: var(--accent); font-size: 12px;
    background: rgba(217, 58, 16, 0.06); border: 1px solid var(--border); border-top: none;
  }

  .footer {
    border: 1px solid var(--border); border-top: none; background: var(--surface2);
    padding: 12px 16px; font-size: 11px; color: var(--muted); font-family: 'DM Mono', monospace;
    text-align: center; line-height: 1.7;
  }
  .footer a { color: var(--muted); }
  .footer a:hover { color: var(--accent); }
</style>
</head>
<body>

<div class="embed">
  <div class="header">
    <div class="header-top">
      <div class="title-row">
        <img src="../assets/dfwpl-logo-small.png" alt="DFW Pinball League" class="header-logo">
        <div class="title-block">
          <div class="eyebrow"><?= $show_regional ? 'North Texas Regional' : 'DFW Pinball League' ?></div>
          <div class="title">Upcoming Events</div>
          <div class="subtitle">
            <?= $show_regional
              ? 'Everything in the DFW League view plus other North Texas tournaments, deduped and sorted by date.'
              : 'What, where, when — and a link to register. Full writeups live on the league site.' ?>
          </div>
        </div>
      </div>
      <?php
        if ($from_cache && $cache_age !== null) {
            $age_min = max(1, (int)round($cache_age / 60));
            $age_str = $age_min < 60 ? "{$age_min}m ago" : round($age_min / 60, 1) . 'h ago';
        }
      ?>
      <div class="live-badge<?= $from_cache ? ' cached' : '' ?>">
        <div class="live-dot"></div>
        <?= $from_cache ? 'CACHED &bull; ' . esc($age_str) : 'LIVE' ?>
      </div>
    </div>
  </div>

  <nav class="view-switch" aria-label="Event view">
    <div class="seg-wrap">
      <a href="?"<?= !$show_regional ? ' aria-current="true"' : '' ?>>DFW League</a>
      <a href="?region=1"<?= $show_regional ? ' aria-current="true"' : '' ?>>North Texas Regional</a>
    </div>
  </nav>

  <div class="links-bar">
    <a href="<?= esc($site_url) ?>" target="_blank" rel="noopener">Full League Site &#8599;</a>
    <a href="<?= esc($cal_url) ?>" target="_blank" rel="noopener">Google Calendar &#8599;</a>
    <a href="../index.php">Standings &amp; Rankings</a>
  </div>

  <?php if ($regional_failed): ?>
    <div class="regional-notice">&#9888; Couldn't load <?= esc(implode(' or ', array_unique($regional_failed))) ?> right now &mdash; some regional events may be missing.</div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="error-msg">&#9888; <?= esc($error) ?></div>
  <?php elseif (empty($upcoming)): ?>
    <div class="empty">No upcoming events found on the calendar right now. Check the full league site &mdash; it's sometimes ahead of the calendar.</div>
  <?php else: ?>
    <?php
      $cur_month = null;
      foreach ($upcoming as $e):
        $m = $e['start']->format('F Y');
        if ($m !== $cur_month):
          $cur_month = $m;
    ?>
    <div class="month-head"><?= esc(strtoupper($m)) ?></div>
    <?php endif; ?>

    <?php
      $dow = strtoupper($e['start']->format('D'));
      $day = $e['start']->format('j');
      $mon = strtoupper($e['start']->format('M'));
      if ($e['multiday']) {
        $mon2 = strtoupper($e['end']->format('M'));
        $day2 = $e['end']->format('j');
        $range = ($mon2 === $mon) ? "&ndash;{$day2}" : "&ndash;{$mon2} {$day2}";
      } else {
        $range = '';
      }
    ?>
    <div class="event">
      <div class="date-badge">
        <div class="dow"><?= esc($dow) ?></div>
        <div class="day"><?= esc($mon) ?> <?= esc($day) ?><?= $range ?></div>
        <?php if (!$e['allday']): ?><div class="day2"><?= esc($e['start']->format('g:ia')) ?></div><?php endif; ?>
        <?php if ($show_regional && $e['source'] === 'dfw' && !$e['not_league']): ?>
          <img src="../assets/dfwpl-logo-small.png" alt="" class="evt-league-icon" title="Official DFW Pinball League event">
        <?php endif; ?>
      </div>
      <div class="evt-body">
        <div class="evt-name">
          <?= esc($e['summary']) ?>
          <?php if ($e['not_league']): ?><span class="evt-badge">Not a League Event</span><?php endif; ?>
          <?php if ($e['source'] === 'regional'): ?><span class="evt-badge">Regional</span><?php endif; ?>
          <?php if ($e['recurring']): ?><span class="evt-badge">Recurring</span><?php endif; ?>
        </div>
        <?php if ($e['venue']): ?>
        <div class="evt-loc">
          &#128205; <?= esc($e['venue']) ?>
          <?php if ($e['venue_confirmed']): ?><span class="venue-confirmed">&#10003; confirmed via IFPA</span><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($e['sched']['doors'] || $e['sched']['start']): ?>
        <div class="evt-sched">
          <?php if ($e['sched']['doors']): ?><span class="sched-pill">&#128682; Doors <?= esc($e['sched']['doors']) ?></span><?php endif; ?>
          <?php if ($e['sched']['start']): ?><span class="sched-pill">&#127937; Start <?= esc($e['sched']['start']) ?></span><?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="evt-reg">
          <?php if ($e['reg_sentence']): ?>
            <span class="reg-text">&#127903; <?= esc($e['reg_sentence']) ?></span>
          <?php endif; ?>
          <?php
            $shown = 0;
            foreach ($e['urls'] as $u):
              if ($shown >= 3) break;
              $shown++;
          ?>
            <a class="reg-link<?= $shown > 1 ? ' secondary' : '' ?>" href="<?= esc($u) ?>" target="_blank" rel="noopener"><?= esc(label_url($u)) ?> &#8599;</a>
          <?php endforeach; ?>
          <?php if (!$e['reg_sentence'] && empty($e['urls'])): ?>
            <span class="reg-tbd"><?= $e['tbd'] ? 'Details not posted yet' : 'See full details below' ?></span>
          <?php endif; ?>
        </div>

        <?php if (trim($e['description'])): ?>
        <details class="details-wrap">
          <summary><span class="car">&#9656;</span> Full details</summary>
          <div class="details-body"><?= esc_linkify($e['description']) ?></div>
        </details>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <div class="footer">
    <?php if ($show_regional): ?>
      Regional events come from Matchplay's DFW-area calendar plus local IFPA tournament listings
      &mdash; league tournaments, sub-bracket finals, and recurring weekly/monthly nights are
      filtered or collapsed to their next date.<br>
    <?php endif; ?>
    Built from the league's public Google Calendar<?= $last_updated ? ' &bull; refreshed ' . esc($last_updated->format('M j, g:ia')) : '' ?>.
    Something missing? Check the <a href="<?= esc($site_url) ?>" target="_blank" rel="noopener">full site</a>.
  </div>
</div>
</body>
</html>
