<?php
$api_key  = '55b97a4ccf9b9c4ee2d443b2737574ab';
$api_base = 'https://api.ifpapinball.com';

// ── Parse tournament IDs from query string ──────────────────────────────────
$ids_raw = trim($_GET['ids'] ?? '');
$tournament_ids = [];
if ($ids_raw) {
    foreach (array_map('trim', explode(',', $ids_raw)) as $id) {
        if (preg_match('/^\d+$/', $id)) {
            $tournament_ids[] = (int)$id;
        }
    }
    $tournament_ids = array_unique($tournament_ids);
}

function fetch_tournament(string $url): array|null {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $http_code !== 200) return null;
    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}

function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// ── Fetch & aggregate ───────────────────────────────────────────────────────
$player_map   = [];
$tournaments  = [];
$errors       = [];
$has_results  = false;

foreach ($tournament_ids as $tid) {
    $url  = "$api_base/tournament/$tid/results?api_key=$api_key";
    $data = fetch_tournament($url);

    if ($data === null) {
        $errors[] = "Could not load tournament #$tid — check the ID or try again.";
        continue;
    }

    $t_info = $data['tournament'] ?? [];
    $t_name = $t_info['tournament_name'] ?? "Tournament #$tid";
    $t_date = $t_info['start_date'] ?? '';
    $tournaments[] = ['id' => $tid, 'name' => $t_name, 'date' => $t_date];

    $results = $data['tournament_results'] ?? $data['results'] ?? [];
    foreach ($results as $r) {
        $player_id = $r['player_id'] ?? null;
        $first     = $r['first_name'] ?? '';
        $last      = $r['last_name']  ?? '';
        $name      = trim("$first $last") ?: ($r['name'] ?? 'Unknown');
        $key       = $player_id ? "id:$player_id" : 'name:' . strtolower($name);

        $points  = (float)($r['wppr_points'] ?? $r['points'] ?? 0);
        $city    = $r['city']         ?? '';
        $state   = $r['stateprov']    ?? '';
        $country = $r['country_code'] ?? '';

        if (!isset($player_map[$key])) {
            $player_map[$key] = [
                'player_id'        => $player_id,
                'name'             => $name,
                'city'             => $city,
                'stateprov'        => $state,
                'country_code'     => $country,
                'total_points'     => 0.0,
                'tournament_count' => 0,
            ];
        }

        $player_map[$key]['total_points']     += $points;
        $player_map[$key]['tournament_count'] += 1;

        // Fill in location if previously empty
        if (!$player_map[$key]['city'] && $city) {
            $player_map[$key]['city']         = $city;
            $player_map[$key]['stateprov']    = $state;
            $player_map[$key]['country_code'] = $country;
        }
    }

    if (!empty($results)) $has_results = true;
}

// ── Sort and rank ───────────────────────────────────────────────────────────
$players = array_values($player_map);
usort($players, fn($a, $b) => $b['total_points'] <=> $a['total_points']);
foreach ($players as $i => &$p) {
    $p['position'] = $i + 1;
}
unset($p);

// ── Derived stats ───────────────────────────────────────────────────────────
$top_points       = $players[0]['total_points']     ?? 0;
$max_tournaments  = $players ? max(array_column($players, 'tournament_count')) : 0;
$t_count          = count($tournaments);
$player_count     = count($players);

// Page title
if ($t_count === 1) {
    $page_title = $tournaments[0]['name'];
    $subtitle   = number_format($player_count) . ' player' . ($player_count !== 1 ? 's' : '');
} elseif ($t_count > 1) {
    $page_title = "$t_count Tournament Series";
    $subtitle   = number_format($player_count) . ' players across ' . $t_count . ' tournaments';
} else {
    $page_title = 'Tournament Report';
    $subtitle   = '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>IFPA Tournament Report</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Mono:wght@400;500&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:       #ffffff;
    --surface:  #ffffff;
    --surface2: #f4f4f7;
    --border:   #dcdce6;
    --gold:     #a07000;
    --silver:   #5a6270;
    --bronze:   #8a4e1a;
    --accent:   #d93a10;
    --text:     #111118;
    --muted:    #6b6b80;
    --green:    #2a7a52;
  }

  body {
    background: var(--bg);
    font-family: 'DM Sans', sans-serif;
    color: var(--text);
    min-height: 100vh;
  }

  .embed {
    max-width: 680px;
    margin: 0 auto;
    padding: 0;
  }

  /* ── Header ── */
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
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: linear-gradient(90deg, var(--accent), var(--gold), var(--accent));
    background-size: 200% 100%;
    animation: shimmer 3s linear infinite;
  }

  @keyframes shimmer {
    0%   { background-position: 200% 0; }
    100% { background-position: -200% 0; }
  }

  .header-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
  }

  .eyebrow {
    font-family: 'DM Mono', monospace;
    font-size: 10px;
    letter-spacing: 0.2em;
    text-transform: uppercase;
    color: var(--accent);
    margin-bottom: 6px;
  }

  .title {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 36px;
    letter-spacing: 0.04em;
    line-height: 1;
    color: var(--text);
  }

  .subtitle {
    font-size: 13px;
    color: var(--muted);
    margin-top: 6px;
  }

  .live-badge {
    display: flex;
    align-items: center;
    gap: 6px;
    background: rgba(255, 77, 28, 0.12);
    border: 1px solid rgba(255, 77, 28, 0.3);
    border-radius: 20px;
    padding: 5px 12px;
    font-size: 11px;
    font-family: 'DM Mono', monospace;
    color: var(--accent);
    letter-spacing: 0.05em;
    white-space: nowrap;
    flex-shrink: 0;
  }

  .live-dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    background: var(--accent);
    animation: pulse 1.5s ease-in-out infinite;
  }

  @keyframes pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50%       { opacity: 0.5; transform: scale(0.8); }
  }

  /* ── Tournament chips ── */
  .tournament-list {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 12px;
  }

  .t-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 4px;
    padding: 4px 8px;
    font-size: 11px;
    font-family: 'DM Mono', monospace;
    color: var(--muted);
  }

  .t-chip-name {
    color: var(--text);
    font-weight: 500;
  }

  /* ── ID input form ── */
  .id-form {
    border: 1px solid var(--border);
    border-top: none;
    border-bottom: none;
    background: var(--surface2);
    padding: 14px 16px;
    display: flex;
    gap: 8px;
    align-items: center;
  }

  .id-input {
    flex: 1;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 6px;
    color: var(--text);
    font-family: 'DM Mono', monospace;
    font-size: 13px;
    padding: 8px 14px;
    outline: none;
    transition: border-color 0.15s;
    min-width: 0;
  }
  .id-input:focus { border-color: var(--accent); }
  .id-input::placeholder { color: var(--muted); font-family: 'DM Sans', sans-serif; }

  .id-submit {
    background: var(--accent);
    border: none;
    border-radius: 6px;
    color: #fff;
    font-family: 'DM Mono', monospace;
    font-size: 12px;
    letter-spacing: 0.08em;
    padding: 9px 18px;
    cursor: pointer;
    white-space: nowrap;
    transition: opacity 0.15s;
    flex-shrink: 0;
  }
  .id-submit:hover { opacity: 0.85; }

  /* ── Stats bar ── */
  .stats-bar {
    display: flex;
    border: 1px solid var(--border);
    border-top: none;
    border-bottom: none;
    background: var(--surface2);
  }

  .stat {
    flex: 1;
    padding: 10px 16px;
    text-align: center;
    border-right: 1px solid var(--border);
  }
  .stat:last-child { border-right: none; }

  .stat-val {
    font-family: 'Bebas Neue', monospace;
    font-size: 20px;
    color: var(--gold);
    line-height: 1;
  }

  .stat-label {
    font-size: 10px;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.1em;
    margin-top: 2px;
  }

  /* ── Search ── */
  .search-wrap {
    border: 1px solid var(--border);
    border-top: none;
    border-bottom: none;
    background: var(--surface);
    padding: 12px 16px;
  }

  .search-input {
    width: 100%;
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 6px;
    color: var(--text);
    font-family: 'DM Sans', sans-serif;
    font-size: 13px;
    padding: 8px 14px 8px 36px;
    outline: none;
    transition: border-color 0.15s;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%236b6b80' stroke-width='2'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cpath d='m21 21-4.35-4.35'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: 12px center;
  }
  .search-input:focus { border-color: var(--accent); }
  .search-input::placeholder { color: var(--muted); }

  /* ── Table ── */
  .table-wrap {
    border: 1px solid var(--border);
    background: var(--surface);
    overflow: hidden;
  }

  .table-head {
    display: grid;
    grid-template-columns: 52px 1fr 110px 90px;
    padding: 8px 16px;
    background: var(--surface2);
    border-bottom: 1px solid var(--border);
  }

  .th {
    font-family: 'DM Mono', monospace;
    font-size: 10px;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--muted);
  }
  .th.right { text-align: right; }

  .row {
    display: grid;
    grid-template-columns: 52px 1fr 110px 90px;
    align-items: center;
    padding: 0 16px;
    height: 52px;
    border-bottom: 1px solid var(--border);
    transition: background 0.12s;
    animation: fadeIn 0.3s ease both;
    cursor: default;
  }
  .row:last-child { border-bottom: none; }
  .row:hover { background: var(--surface2); }

  @keyframes fadeIn {
    from { opacity: 0; transform: translateY(4px); }
    to   { opacity: 1; transform: translateY(0); }
  }

  .row.top-3 { background: rgba(160, 112, 0, 0.06); }
  .row.top-3:hover { background: rgba(160, 112, 0, 0.1); }

  .rank {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 20px;
    letter-spacing: 0.03em;
    display: flex;
    align-items: center;
    gap: 4px;
  }

  .rank-num { color: var(--muted); }
  .rank-num.r1 { color: var(--gold); }
  .rank-num.r2 { color: var(--silver); }
  .rank-num.r3 { color: var(--bronze); }

  .medal { font-size: 14px; line-height: 1; }

  .player { min-width: 0; }

  .player-name {
    font-size: 14px;
    font-weight: 600;
    color: var(--text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .player-meta {
    font-size: 11px;
    color: var(--muted);
    margin-top: 1px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .points {
    text-align: right;
    font-family: 'DM Mono', monospace;
    font-size: 13px;
    font-weight: 500;
    color: var(--gold);
  }

  .played {
    text-align: right;
    font-family: 'DM Mono', monospace;
    font-size: 13px;
    color: var(--muted);
  }

  /* ── Empty / error states ── */
  .splash {
    border: 1px solid var(--border);
    background: var(--surface);
    padding: 48px 32px;
    text-align: center;
  }

  .splash-icon {
    font-size: 36px;
    margin-bottom: 12px;
  }

  .splash-title {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 22px;
    letter-spacing: 0.06em;
    color: var(--text);
    margin-bottom: 8px;
  }

  .splash-sub {
    font-size: 13px;
    color: var(--muted);
    max-width: 360px;
    margin: 0 auto;
    line-height: 1.6;
  }

  .error-box {
    border: 1px solid var(--border);
    border-top: none;
    border-bottom: none;
    background: #fff8f6;
    padding: 10px 16px;
  }

  .error-item {
    font-size: 12px;
    color: var(--accent);
    font-family: 'DM Mono', monospace;
    padding: 3px 0;
  }

  .empty {
    padding: 32px 24px;
    text-align: center;
    color: var(--muted);
    font-size: 13px;
  }

  /* ── Footer ── */
  .footer {
    border: 1px solid var(--border);
    border-top: none;
    background: var(--surface2);
    padding: 10px 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }

  .footer-left {
    font-size: 11px;
    color: var(--muted);
    font-family: 'DM Mono', monospace;
  }

  .ifpa-link {
    font-size: 11px;
    color: var(--muted);
    text-decoration: none;
    font-family: 'DM Mono', monospace;
    transition: color 0.15s;
  }
  .ifpa-link:hover { color: var(--accent); }
</style>
</head>
<body>

<div class="embed">

  <!-- Header -->
  <div class="header">
    <div class="header-top">
      <div class="title-block">
        <div class="eyebrow">IFPA Tournament Results</div>
        <div class="title"><?= esc($t_count > 0 ? $page_title : 'Tournament Report') ?></div>
        <?php if ($subtitle): ?>
          <div class="subtitle"><?= esc($subtitle) ?></div>
        <?php endif; ?>
      </div>
      <?php if ($t_count > 0): ?>
        <div class="live-badge">
          <div class="live-dot"></div>
          LIVE
        </div>
      <?php endif; ?>
    </div>

    <?php if ($t_count > 1): ?>
      <div class="tournament-list">
        <?php foreach ($tournaments as $t): ?>
          <div class="t-chip">
            <span class="t-chip-name"><?= esc($t['name']) ?></span>
            <?php if ($t['date']): ?>
              &mdash; <span><?= esc($t['date']) ?></span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- ID input form -->
  <form class="id-form" method="get" action="">
    <input
      class="id-input"
      type="text"
      name="ids"
      value="<?= esc($ids_raw) ?>"
      placeholder="Tournament IDs — comma-separated, e.g. 12345, 67890"
      autocomplete="off"
      spellcheck="false"
    >
    <button class="id-submit" type="submit">LOAD</button>
  </form>

  <?php if (!empty($errors)): ?>
    <div class="error-box">
      <?php foreach ($errors as $e): ?>
        <div class="error-item">&#9888; <?= esc($e) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (empty($tournament_ids)): ?>
    <!-- Splash: no IDs entered -->
    <div class="splash">
      <div class="splash-icon">&#127918;</div>
      <div class="splash-title">Enter Tournament IDs Above</div>
      <div class="splash-sub">
        Paste one or more IFPA tournament IDs separated by commas.
        Results will be aggregated and ranked by total WPPR points.
      </div>
    </div>

  <?php elseif (!$has_results && empty($errors)): ?>
    <!-- IDs were valid but returned no results -->
    <div class="splash">
      <div class="splash-icon">&#128269;</div>
      <div class="splash-title">No Results Found</div>
      <div class="splash-sub">
        The provided tournament IDs returned no player results.
        Double-check the IDs on the IFPA website.
      </div>
    </div>

  <?php else: ?>
    <!-- Stats bar -->
    <div class="stats-bar">
      <div class="stat">
        <div class="stat-val"><?= $player_count ?: '—' ?></div>
        <div class="stat-label">Players</div>
      </div>
      <div class="stat">
        <div class="stat-val"><?= $top_points ? number_format($top_points, 2) : '—' ?></div>
        <div class="stat-label">Top Points</div>
      </div>
      <div class="stat">
        <div class="stat-val"><?= $t_count ?: '—' ?></div>
        <div class="stat-label">Tournaments</div>
      </div>
    </div>

    <!-- Search -->
    <div class="search-wrap">
      <input class="search-input" type="text" placeholder="Search players…" id="search" oninput="filterRows()">
    </div>

    <!-- Results table -->
    <div class="table-wrap">
      <div class="table-head">
        <div class="th">Rank</div>
        <div class="th">Player</div>
        <div class="th right">Points</div>
        <div class="th right">Played</div>
      </div>
      <div class="table-body" id="table-body">
        <?php if (empty($players)): ?>
          <div class="empty">No players to display.</div>
        <?php else: ?>
          <?php foreach ($players as $i => $p):
            $pos      = $p['position'];
            $name     = $p['name'];
            $city     = $p['city']         ?? '';
            $state    = $p['stateprov']    ?? '';
            $country  = $p['country_code'] ?? '';
            $location = implode(', ', array_filter([$city, $state, $country]));
            $points   = $p['total_points'];
            $played   = $p['tournament_count'];
            $isTop3   = $pos <= 3;
            $medals   = ['🥇','🥈','🥉'];
            $rankClass = match($pos) { 1 => 'r1', 2 => 'r2', 3 => 'r3', default => '' };
            $delay    = min($i * 0.025, 0.5);
          ?>
          <div class="row <?= $isTop3 ? 'top-3' : '' ?>"
               style="animation-delay:<?= $delay ?>s"
               data-name="<?= esc(strtolower($name)) ?>"
               data-loc="<?= esc(strtolower($location)) ?>">
            <div class="rank">
              <span class="rank-num <?= $rankClass ?>"><?= $pos ?></span>
              <?php if ($isTop3): ?><span class="medal"><?= $medals[$pos - 1] ?></span><?php endif; ?>
            </div>
            <div class="player">
              <div class="player-name"><?= esc($name) ?></div>
              <?php if ($location): ?>
                <div class="player-meta"><?= esc($location) ?></div>
              <?php endif; ?>
            </div>
            <div class="points"><?= number_format($points, 2) ?></div>
            <div class="played"><?= $played ?>/<?= $t_count ?></div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Footer -->
    <div class="footer">
      <span class="footer-left" id="footer-count">
        <?= $player_count ?> player<?= $player_count !== 1 ? 's' : '' ?>
      </span>
      <a class="ifpa-link" href="https://www.ifpapinball.com" target="_blank">Powered by IFPA &#8599;</a>
    </div>

  <?php endif; ?>

</div><!-- /.embed -->

<script>
const rows  = Array.from(document.querySelectorAll('#table-body .row'));
const total = rows.length;

function filterRows() {
  const q = document.getElementById('search').value.toLowerCase().trim();
  let visible = 0;
  rows.forEach(row => {
    const show = !q || row.dataset.name.includes(q) || row.dataset.loc.includes(q);
    row.style.display = show ? '' : 'none';
    if (show) visible++;
  });
  const el = document.getElementById('footer-count');
  if (el) {
    el.textContent = q
      ? `Showing ${visible} of ${total} players`
      : `${total} player${total !== 1 ? 's' : ''}`;
  }
}
</script>
</body>
</html>
