<?php
declare(strict_types=1);

/**
 * The whole application, on one page.
 *
 * An operator has one question when they open this: "is the station going to be fine for
 * the next day, and is anything drifting?" That is a forecast chart and an early-warning
 * verdict. Everything else was navigation for its own sake.
 *
 * Everything on screen is written for a control room, not for the notebook that produced
 * it. Internal names (dT, flow_per_kW) and statistical units (sigma) are translated by
 * Tbw\Web\Labels; the exact figures stay in hover text so any number can still be traced
 * back to its row.
 */

[$config, $db] = require __DIR__ . '/bootstrap.php';

use Tbw\Domain;
use Tbw\Repository\AlarmRepository;
use Tbw\Repository\ForecastRepository;
use Tbw\Repository\GridRepository;
use Tbw\Repository\ReadingRepository;
use Tbw\Web\Labels;
use Tbw\Web\Page;

$readings = new ReadingRepository($db);
$grid = new GridRepository($db);
$forecasts = new ForecastRepository($db);
$alarms = new AlarmRepository($db);

$latestByTag = [];
foreach ($readings->latestPerTag() as $row) {
    $latestByTag[(string) $row['asset']][(string) $row['signal_name']] = $row;
}

$physics = $grid->latestPhysics();
$run = $forecasts->latestRun();
$spc = $alarms->latestSpc();
$now = new DateTimeImmutable();

// Projections are keyed by channel so the early-warning table can carry a date in the
// same row as the drift that earned it. A tier tells an operator to look; a date tells
// them when to schedule.
$eta = [];
foreach ($alarms->latestProjections() as $row) {
    $eta[(string) $row['channel']] = $row;
}

/**
 * True when the channel is already the wrong side of its limit.
 *
 * HuberTrend::daysToLimit() returns null for two opposite situations -- drifting away from
 * the limit, and having already crossed it -- because in neither case is there a future
 * date to put on a work order. Rendering both as "not reached" tells an operator the worst
 * channel on the station is the one they can ignore, so the page has to separate them.
 * The drift sign says which side of the healthy mean the limit sits on.
 */
$breached = static function (array $projection, float $drift): bool {
    if ($projection['current_value'] === null || $projection['limit_value'] === null) {
        return false;
    }
    $current = (float) $projection['current_value'];
    $limit = (float) $projection['limit_value'];
    return $drift > 0 ? $current >= $limit : $current <= $limit;
};

$counts = ['ALARM' => 0, 'WARN' => 0, 'OK' => 0];
foreach ($spc as $row) {
    $tier = (string) $row['tier'];
    $counts[$tier] = ($counts[$tier] ?? 0) + 1;
}
$stationTier = $counts['ALARM'] > 0 ? 'ALARM' : ($counts['WARN'] > 0 ? 'WARN' : 'OK');
$stationLabel = ['ALARM' => 'PERLU DIPERIKSA', 'WARN' => 'DIAWASI', 'OK' => 'AMAN'][$stationTier];

/**
 * Every pump the station has ever reported, and what its own data says about it now.
 *
 * Nothing here is asserted from a constant. The asset list is the union of what the live
 * feed is sending and what the stored history contains, and each pump's state is read off
 * its own last reading. That is what makes the strip survive the station changing shape:
 * TBW2 is dormant because its last value is from May, not because it is named in a list,
 * and if it were fired up tomorrow it would simply appear as a running pump. The same
 * applies in reverse to TBW1 and TBW3.
 */
$lastSeen = $grid->lastSeenPerAsset();
foreach ($latestByTag as $asset => $signals) {
    foreach ($signals as $row) {
        $at = (string) $row['observed_at'];
        if (!isset($lastSeen[$asset]) || $at > $lastSeen[$asset]) {
            $lastSeen[$asset] = $at;
        }
    }
}

$assetNames = array_unique(array_merge(
    array_keys($lastSeen),
    Domain::ACTIVE_ASSETS,
    Domain::RETIRED_ASSETS
));
sort($assetNames);

$assets = [];
foreach ($assetNames as $asset) {
    $signals = $latestByTag[$asset] ?? [];
    $amps = isset($signals['MOTOR_CURRENT']) ? (float) $signals['MOTOR_CURRENT']['value'] : null;
    $seenAt = $lastSeen[$asset] ?? null;

    $assets[$asset] = [
        'signals' => $signals,
        // A reading only proves the pump is running if the reading itself is current.
        'running' => $amps !== null && $amps > Domain::RUNNING_AMPS && !Labels::isStale($seenAt, 30, $now),
        'seen_at' => $seenAt,
        'stale'   => Labels::isStale($seenAt, 30, $now),
        'dormant' => Labels::isDormant($seenAt, $now),
    ];
}

Page::open('TBW Pump Station');
?>

<section class="tiles">
  <div class="tile tile-<?= strtolower($stationTier) ?>">
    <span class="cap">Kondisi stasiun</span>
    <span class="big"><?= Page::e($stationLabel) ?></span>
    <span class="sub">
      <?= $counts['ALARM'] ?> perlu diperiksa &middot; <?= $counts['WARN'] ?> diawasi &middot;
      <?= $counts['OK'] ?> aman
    </span>
  </div>

<?php foreach ($assets as $asset => $state):
    $signals = $state['signals'];
?>
  <div class="tile<?= $state['dormant'] ? ' tile-retired' : ($state['stale'] ? ' tile-stale' : '') ?>">
    <span class="cap">
      Pompa <?= Page::e($asset) ?>
      <?php if ($state['running']): ?>
        <span class="pill tier-ok">JALAN</span>
      <?php else: ?>
        <span class="pill pill-muted">MATI</span>
      <?php endif; ?>
    </span>

    <?php if ($state['dormant']): ?>
      <span class="big muted">&mdash;</span>
      <span class="sub">
        <?php if ($state['seen_at'] === null): ?>
          Belum pernah mengirim data
        <?php else: ?>
          Sudah mati sejak <?= Page::e(Labels::dateId($state['seen_at'])) ?>
        <?php endif; ?>
      </span>
    <?php else: ?>
      <span class="big"><?= Page::num($signals['POWER']['value'] ?? null, 1) ?><small>kW</small></span>
      <span class="sub">
        Debit <?= Page::num($signals['FLOWRATE']['value'] ?? null, 1) ?> m&sup3;/min
      </span>
      <span class="sub <?= $state['stale'] ? 'is-stale' : '' ?>">
        <?php if ($state['stale']): ?>
          &#9888; data terakhir <?= Page::e(Labels::ago($state['seen_at'], $now)) ?>
        <?php else: ?>
          data <?= Page::e(Labels::ago($state['seen_at'], $now)) ?>
        <?php endif; ?>
      </span>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</section>

<?php if ($run !== null && (int) $run['degraded'] === 1): ?>
  <div class="banner banner-warn">
    <strong>Ramalan cadangan sedang dipakai.</strong> Layanan model tidak terjangkau, jadi
    ramalan di bawah dibuat dengan metode sederhana (pengulangan pola harian). Sistem tetap
    jalan, tetapi rentang ramalannya lebih lebar dan peringatan datang lebih lambat.
  </div>
<?php endif; ?>

<section class="card chart-card">
  <div class="card-head">
    <h2>Ramalan 24 jam ke depan</h2>
    <?php if ($run !== null):
        // The model name and its latency are deliberately absent -- an operator cannot act
        // on either. The forecast's age stays, as a chip rather than a paragraph: a chart
        // drawn from a two-week-old run is indistinguishable from a fresh one on screen,
        // and would otherwise be read as the current outlook.
        $forecastStale = Labels::isStale((string) $run['origin_ts'], 60, $now);
    ?>
      <span class="pill <?= $forecastStale ? 'tier-warn' : 'pill-muted' ?>">
        dibuat <?= Page::e(Labels::ago((string) $run['origin_ts'], $now)) ?>
      </span>
    <?php endif; ?>
    <div class="controls">
      <select id="target">
        <?php foreach (Domain::TARGETS as $t): ?>
          <option value="<?= Page::e($t) ?>"<?= $t === 'POWER|TBW1' ? ' selected' : '' ?>>
            <?= Page::e(Labels::target($t)) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <select id="hours">
        <option value="24">riwayat 24 jam</option>
        <option value="48" selected>riwayat 48 jam</option>
        <option value="168">riwayat 7 hari</option>
      </select>
      <span class="muted" id="unit"></span>
    </div>
  </div>

  <canvas id="chart"></canvas>

  <div class="legend">
    <span><i class="swatch" style="background:var(--text)"></i> data terukur</span>
    <span><i class="swatch" style="background:var(--accent)"></i> ramalan</span>
    <span><i class="swatch band"></i> rentang kemungkinan</span>
    <span class="muted">garis putus-putus memisahkan data yang sudah terjadi dari ramalan</span>
  </div>
</section>

<section class="card">
  <div class="card-head">
    <h2>Peringatan dini</h2>
    <span class="pill <?= Page::tierClass($stationTier) ?>"><?= Page::e($stationLabel) ?></span>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Yang dipantau</th>
          <th class="num">Sekarang</th>
          <th class="num">Batas wajar</th>
          <th>Seberapa jauh dari normal</th>
          <th>Status</th>
          <th>Perkiraan lewat batas</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($spc === []): ?>
        <tr><td colspan="6" class="na">Belum ada hasil pemeriksaan.</td></tr>
      <?php endif; ?>
      <?php foreach ($spc as $row):
          $drift = $row['drift_sigma'] === null ? null : (float) $row['drift_sigma'];
          $tier = (string) $row['tier'];
          $p = $eta[(string) $row['channel']] ?? null;
          $label = Labels::channel((string) $row['channel']);
          $words = Labels::drift($drift);
          $driftClass = $tier === 'ALARM' ? 'is-alarm' : ($tier === 'WARN' ? 'is-warn' : '');

          // A projected date that has already passed is not a schedule, it is a channel
          // that crossed while nobody was looking. Rendering it as a future work order
          // would be worse than saying nothing.
          $etaDate = $p !== null && $p['eta'] !== null ? substr((string) $p['eta'], 0, 10) : null;
          $etaPassed = $etaDate !== null && $etaDate < $now->format('Y-m-d');
      ?>
        <tr>
          <td>
            <strong><?= Page::e($label['name']) ?></strong>
            <?= $label['asset'] === null ? '' : '<span class="asset-tag">' . Page::e($label['asset']) . '</span>' ?>
            <span class="help"><?= Page::e($label['help']) ?></span>
          </td>
          <td class="num">
            <?= Page::num($row['value'], 2) ?>
            <span class="unit"><?= Page::e($label['unit']) ?></span>
          </td>
          <td class="num muted">
            <?= Page::num($row['lcl'], 2) ?> &ndash; <?= Page::num($row['ucl'], 2) ?>
          </td>
          <td>
            <div class="gauge" title="<?= $drift === null ? 'belum terukur' : Page::e(sprintf('%+.2f sigma dari rata-rata sehat', $drift)) ?>">
              <span class="gauge-label <?= $driftClass ?>"><?= Page::e($words['short']) ?></span>
              <span class="gauge-track">
                <span class="gauge-fill <?= $driftClass ?>" style="width: <?= round($words['fill'] * 100) ?>%"></span>
              </span>
            </div>
          </td>
          <td><span class="pill <?= Page::tierClass($tier) ?>"><?= Page::e(Labels::tier($tier)) ?></span></td>
          <td class="muted">
            <?php if ($etaDate !== null && !$etaPassed): ?>
              <?= Page::e($etaDate) ?>
              (<?= number_format((float) $p['days_to_limit'], 0) ?> hari lagi)
            <?php elseif ($etaPassed || ($p !== null && $drift !== null && $breached($p, $drift))): ?>
              <span class="drift is-alarm">sudah lewat batas</span>
            <?php else: ?>
              <span class="na">tidak menuju batas</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<script>
var ACCENT = cssVar('--accent');
var TEXT = cssVar('--text');

function load() {
  var target = document.getElementById('target').value;
  var hours = document.getElementById('hours').value;
  fetchJson('api/forecast.php?target=' + encodeURIComponent(target) + '&hours=' + hours)
    .then(function (d) {
      document.getElementById('unit').textContent = d.unit ? d.unit : '';
      drawChart(document.getElementById('chart'), {
        height: 380,
        marker: d.origin ? toDate(d.origin) : null,
        band: {
          points: d.forecast.map(function (p) { return { x: toDate(p.ts), lo: p.q10, hi: p.q90 }; }),
          label: 'rentang kemungkinan'
        },
        series: [
          { points: d.history.map(function (p) { return { x: toDate(p.ts), y: p.value }; }),
            color: TEXT, width: 1.4, label: 'data terukur' },
          { points: d.forecast.map(function (p) { return { x: toDate(p.ts), y: p.q50 }; }),
            color: ACCENT, width: 2.2, label: 'ramalan' },
          { points: d.realised.map(function (p) { return { x: toDate(p.ts), y: p.value }; }),
            color: TEXT, width: 1.4, label: 'sudah terjadi' }
        ]
      });
    });
}

document.getElementById('target').addEventListener('change', load);
document.getElementById('hours').addEventListener('change', load);
load();

// The pipeline writes a new forecast every 15 minutes; reloading far faster than that
// would only throw away the operator's hover for a picture that has not changed.
setTimeout(function () { location.reload(); }, 300000);
</script>

<?php Page::close(); ?>
