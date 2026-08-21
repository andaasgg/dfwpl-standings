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

// ── Load + classify ─────────────────────────────────────────────────────
$all_events = $ics ? parse_ics_events($ics) : [];

$REG_MARKER = '/^\s*(pre-?)?registration\b|registration\s*(\/\s*rsvp)?\s*(opens?|today)|register\s*\/\s*rsvp|^\s*\d+\s*(am|pm)\s*[-–]\s*pre-?registration/i';
$RECURRING  = '/turbo tuesday/i';
$NOT_LEAGUE = '/not a dfw league event|not counted toward|regional event|national event/i';

$now = new DateTime('now', new DateTimeZone('America/Chicago'));
$today_midnight = new DateTime($now->format('Y-m-d'), new DateTimeZone('America/Chicago'));

$upcoming = [];
foreach ($all_events as $e) {
    if (preg_match($REG_MARKER, $e['summary'])) continue; // separate "registration opens" reminder, not the event itself
    if (preg_match($RECURRING, $e['summary'])) continue;  // weekly regulars — digest skips these
    if ($e['start'] < $today_midnight) continue;
    $upcoming[] = $e;
}

usort($upcoming, fn($a, $b) => $a['start'] <=> $b['start']);

foreach ($upcoming as &$e) {
    $e['urls']        = extract_urls($e['description_raw']);
    $e['reg_sentence'] = reg_sentence($e['description']);
    $e['not_league']   = (bool) preg_match($NOT_LEAGUE, $e['summary'] . ' ' . $e['description']);
    $e['tbd']          = (bool) preg_match('/\btbd\b|forthcoming|details? (?:to come|coming soon)/i', $e['summary'] . ' ' . $e['description']);
    $e['multiday']     = $e['end'] && $e['end']->diff($e['start'])->days >= 1 &&
                          ($e['allday'] ? $e['end']->diff($e['start'])->days >= 1 : $e['end']->format('Y-m-d') !== $e['start']->format('Y-m-d'));
}
unset($e);

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
<title>DFW Pinball League Events</title>
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

  .header-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }

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
  .evt-badge {
    display: inline-block; font-family: 'DM Mono', monospace; font-size: 9px;
    letter-spacing: 0.08em; text-transform: uppercase; color: var(--muted);
    border: 1px solid var(--border); border-radius: 10px; padding: 1px 7px;
    margin-left: 6px; vertical-align: middle;
  }
  .evt-loc { font-size: 12px; color: var(--muted); margin-top: 3px; }

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
      <div class="title-block">
        <div class="eyebrow">DFW Pinball League</div>
        <div class="title">Upcoming Events</div>
        <div class="subtitle">What, where, when — and a link to register. Full writeups live on the league site.</div>
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

  <div class="links-bar">
    <a href="<?= esc($site_url) ?>" target="_blank" rel="noopener">Full League Site &#8599;</a>
    <a href="<?= esc($cal_url) ?>" target="_blank" rel="noopener">Google Calendar &#8599;</a>
    <a href="../index.php">Standings &amp; Rankings</a>
  </div>

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
      </div>
      <div class="evt-body">
        <div class="evt-name">
          <?= esc($e['summary']) ?>
          <?php if ($e['not_league']): ?><span class="evt-badge">Not a League Event</span><?php endif; ?>
        </div>
        <?php if ($e['location']): ?><div class="evt-loc">&#128205; <?= esc($e['location']) ?></div><?php endif; ?>

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
    Skips weekly Carpool Pinball &ldquo;Turbo Tuesday&rdquo; nights in Southlake &mdash; see the calendar for those dates.<br>
    Built from the league's public Google Calendar<?= $last_updated ? ' &bull; refreshed ' . esc($last_updated->format('M j, g:ia')) : '' ?>.
    Something missing? Check the <a href="<?= esc($site_url) ?>" target="_blank" rel="noopener">full site</a>.
  </div>
</div>
</body>
</html>
