<?php
declare(strict_types=1);

[$config, $db] = require __DIR__ . '/bootstrap.php';

use Tbw\Domain;
use Tbw\Forecast\ForecastClient;
use Tbw\Repository\AlarmRepository;
use Tbw\Repository\ForecastRepository;
use Tbw\Repository\GridRepository;
use Tbw\Repository\ReadingRepository;
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
$sidecar = ForecastClient::fromConfig($config)->health();
$states = $alarms->currentStates();

$counts = ['ALARM' => 0, 'WARN' => 0, 'OK' => 0];
foreach ($states as $s) {
    $counts[(string) $s['tier']] = ($counts[(string) $s['tier']] ?? 0) + 1;
}

Page::open('Dashboard', 'index.php');
?>

<h1>Kondisi Stasiun</h1>
<p class="lede">
  Nilai live dari historian, ramalan 24 jam ke depan dari Chronos-2 zero-shot, dan status
  peringatan dini. Halaman ini menyegar sendiri tiap 30 detik.
</p>

<?php if ($run === null): ?>
  <div class="banner banner-info">
    Belum ada forecast tersimpan. Jalankan <code>php bin/forecast.php</code>.
  </div>
<?php elseif ((int) $run['degraded'] === 1): ?>
  <div class="banner banner-warn">
    <strong>Mode terdegradasi.</strong> Sidecar Chronos-2 tidak terjangkau, sistem memakai
    <strong>Naive-Seasonal</strong>. Ramalan tetap jalan, tetapi intervalnya lebih lebar
    dan deteksi dini melambat. Jalankan ulang sidecar untuk kembali normal.
    <?= Page::e($run['note'] ?? '') ?>
  </div>
<?php endif; ?>

<?php if (($sidecar['status'] ?? '') !== 'ok'): ?>
  <div class="banner banner-warn">
    Sidecar Chronos-2: <strong><?= Page::e($sidecar['status'] ?? 'unavailable') ?></strong>.
    <?= Page::e($sidecar['error'] ?? '') ?>
  </div>
<?php endif; ?>

<?php if ($counts['ALARM'] > 0): ?>
  <div class="banner banner-alarm">
    <strong><?= $counts['ALARM'] ?> kanal berstatus ALARM.</strong>
    Lihat <a href="alarms.php">Peringatan Dini</a> untuk buktinya.
  </div>
<?php endif; ?>

<div class="grid grid-4">
  <div class="card">
    <div class="stat">
      <span class="big" style="color:var(--alarm)"><?= $counts['ALARM'] ?></span>
      <span class="cap">Alarm</span>
    </div>
  </div>
  <div class="card">
    <div class="stat">
      <span class="big" style="color:var(--warn)"><?= $counts['WARN'] ?></span>
      <span class="cap">Peringatan</span>
    </div>
  </div>
  <div class="card">
    <div class="stat">
      <span class="big"><?= $run === null ? '—' : Page::e($run['model']) ?></span>
      <span class="cap">Model aktif</span>
    </div>
  </div>
  <div class="card">
    <div class="stat">
      <span class="big"><?= $run === null ? '—' : number_format((float) $run['context_coverage'] * 100, 0) . '%' ?></span>
      <span class="cap">Cakupan konteks</span>
    </div>
  </div>
</div>

<?php if ($run !== null && (float) $run['context_coverage'] < 0.9): ?>
  <div class="note">
    <strong>Konteks belum penuh (<?= number_format((float) $run['context_coverage'] * 100, 1) ?>%).</strong>
    Histori semai berhenti 2026-07-22 sedangkan polling live baru mulai belakangan, jadi ada
    lubang di tengah jendela 14 hari. Chronos-2 menangani lubang itu secara native — nilai
    kosong dikirim apa adanya, bukan diinterpolasi, karena menambalnya berarti mengarang
    operasi mesin yang tidak pernah terjadi. Akurasi hari-hari pertama harus dibaca dengan
    ini di kepala.
  </div>
<?php endif; ?>

<h2>Aset</h2>
<div class="grid grid-3">
<?php foreach (Domain::ACTIVE_ASSETS as $asset):
    $signals = $latestByTag[$asset] ?? [];
    $running = ($physics['is_running|' . $asset] ?? 0.0) > 0.5;
?>
  <div class="card">
    <h3>
      <?= Page::e($asset) ?>
      <span class="pill <?= $running ? 'tier-ok' : 'tier-warn' ?>"><?= $running ? 'RUNNING' : 'STOPPED' ?></span>
    </h3>
    <?php foreach (Domain::SIGNALS as $signal):
        $row = $signals[$signal] ?? null;
        $isCounter = $signal === 'INLET_PRESS';
    ?>
      <div class="metric">
        <span class="label">
          <?= Page::e($signal) ?>
          <?php if ($isCounter): ?>
            <span class="pill pill-muted" title="F4: tag ini bukan tekanan, melainkan pencacah yang ter-reset tiap stop">counter?</span>
          <?php endif; ?>
        </span>
        <span class="value">
          <?= $row === null ? '<span class="na">&mdash;</span>' : Page::num($row['value'], 2) ?>
          <span class="unit"><?= Page::e(Domain::UNITS[$signal] ?? '') ?></span>
        </span>
      </div>
    <?php endforeach; ?>
    <div class="metric">
      <span class="label">dT (outlet &minus; inlet)</span>
      <span class="value"><?= Page::num($physics['dT|' . $asset] ?? null, 2, ' °C') ?></span>
    </div>
    <div class="metric">
      <span class="label">flow per kW</span>
      <span class="value"><?= Page::num($physics['flow_per_kW|' . $asset] ?? null, 4) ?></span>
    </div>
  </div>
<?php endforeach; ?>

<?php foreach (Domain::RETIRED_ASSETS as $asset): ?>
  <div class="card">
    <h3><?= Page::e($asset) ?> <span class="pill pill-muted">RETIRED</span></h3>
    <p class="muted" style="font-size:.87rem">
      Berhenti permanen sejak <strong>2026-05-14</strong> dan tidak muncul di API. Ini mesin
      yang dipensiunkan, bukan sensor yang rusak — ditampilkan begini supaya tidak ada yang
      membacanya sebagai data hilang. Dashboard mana pun yang masih memperlakukan TBW2
      sebagai aktif sedang melaporkan mesin mati.
    </p>
  </div>
<?php endforeach; ?>
</div>

<h2>Ramalan 24 jam &mdash; <?= Page::e(Domain::TARGETS[3]) ?></h2>
<div class="card">
  <canvas id="mini"></canvas>
  <div class="legend">
    <span><i class="swatch" style="background:var(--text)"></i> aktual</span>
    <span><i class="swatch" style="background:var(--accent)"></i> ramalan (median)</span>
    <span><i class="swatch band"></i> interval 80%</span>
  </div>
  <p class="muted" style="font-size:.85rem;margin-top:.6rem">
    <a href="forecast.php">Lihat semua 9 target &rarr;</a>
  </p>
</div>

<script>
fetchJson('api/forecast.php?target=<?= rawurlencode(Domain::TARGETS[3]) ?>&hours=48').then(function (d) {
  var hist = d.history.map(function (p) { return { x: new Date(p.ts.replace(' ', 'T')), y: p.value }; });
  var med  = d.forecast.map(function (p) { return { x: new Date(p.ts.replace(' ', 'T')), y: p.q50 }; });
  var band = d.forecast.map(function (p) { return { x: new Date(p.ts.replace(' ', 'T')), lo: p.q10, hi: p.q90 }; });
  var real = d.realised.map(function (p) { return { x: new Date(p.ts.replace(' ', 'T')), y: p.value }; });
  drawChart(document.getElementById('mini'), {
    height: 300,
    marker: d.origin ? new Date(d.origin.replace(' ', 'T')) : null,
    band: { points: band },
    series: [
      { points: hist, color: getComputedStyle(document.documentElement).getPropertyValue('--text').trim(), width: 1.4 },
      { points: med, color: getComputedStyle(document.documentElement).getPropertyValue('--accent').trim(), width: 2 },
      { points: real, color: getComputedStyle(document.documentElement).getPropertyValue('--text').trim(), width: 1.4 }
    ]
  });
});
setTimeout(function () { location.reload(); }, 30000);
</script>

<?php Page::close(); ?>
