<?php
declare(strict_types=1);

[$config, $db] = require __DIR__ . '/bootstrap.php';

use Tbw\Domain;
use Tbw\Repository\ForecastRepository;
use Tbw\Web\Page;

$run = (new ForecastRepository($db))->latestRun();

Page::open('Forecast', 'forecast.php');
?>

<h1>Ramalan 24 Jam</h1>
<p class="lede">
  96 langkah 15 menit dengan interval 80%. Garis putus-putus menandai titik asal ramalan:
  di kirinya aktual, di kanannya prediksi. Konteks berakhir <em>tepat sebelum</em> titik asal.
</p>

<?php if ($run !== null): ?>
  <div class="banner banner-info">
    Run #<?= (int) $run['id'] ?> &middot; model <strong><?= Page::e($run['model']) ?></strong>
    &middot; asal <?= Page::e($run['origin_ts']) ?>
    &middot; <?= number_format((int) $run['elapsed_ms']) ?> ms
    &middot; cakupan konteks <?= number_format((float) $run['context_coverage'] * 100, 1) ?>%
    <?= (int) $run['degraded'] === 1 ? ' &middot; <strong>TERDEGRADASI</strong>' : '' ?>
  </div>
<?php endif; ?>

<div class="controls">
  <label for="target">Target</label>
  <select id="target">
    <?php foreach (Domain::TARGETS as $t): ?>
      <option value="<?= Page::e($t) ?>"><?= Page::e($t) ?></option>
    <?php endforeach; ?>
  </select>

  <label for="hours">Riwayat</label>
  <select id="hours">
    <option value="24">24 jam</option>
    <option value="48">48 jam</option>
    <option value="168">7 hari</option>
    <option value="336" selected>14 hari (konteks penuh)</option>
  </select>

  <button id="refresh">Muat ulang</button>
  <span id="unit" class="muted"></span>
</div>

<div id="gapnote"></div>

<div class="card">
  <canvas id="chart"></canvas>
  <div class="legend">
    <span><i class="swatch" style="background:var(--text)"></i> aktual</span>
    <span><i class="swatch" style="background:var(--accent)"></i> ramalan (median q50)</span>
    <span><i class="swatch band"></i> interval 80% (q10&ndash;q90)</span>
  </div>
</div>

<div class="note">
  <strong>Kenapa intervalnya yang diutamakan, bukan titik tengahnya.</strong>
  Chronos-2 kalah MASE 0.028 dari LGBM-Local tapi tetap dipakai, karena selisih itu lebih
  kecil dari simpangan baku LGBM sendiri (0.690), sementara interval 80% LGBM hanya
  mencakup 68.4% aktual — percaya diri tapi salah. Seluruh lapisan alarm berdiri di atas
  lebar interval, jadi kalibrasi lebih penting daripada titik tengah.
</div>

<h2>Semua target</h2>
<div class="grid grid-2" id="tiles"></div>

<script>
var accent = getComputedStyle(document.documentElement).getPropertyValue('--accent').trim();
var textCol = getComputedStyle(document.documentElement).getPropertyValue('--text').trim();

function toPoints(rows, key) {
  return rows.map(function (p) { return { x: toDate(p.ts), y: p[key] }; });
}

function render(canvas, d, height) {
  drawChart(canvas, {
    height: height,
    marker: d.origin ? toDate(d.origin) : null,
    band: {
      points: d.forecast.map(function (p) { return { x: toDate(p.ts), lo: p.q10, hi: p.q90 }; }),
      label: 'interval 80%'
    },
    series: [
      { points: d.history.map(function (p) { return { x: toDate(p.ts), y: p.value }; }),
        color: textCol, width: 1.4, label: 'aktual' },
      { points: toPoints(d.forecast, 'q50'), color: accent, width: 2, label: 'ramalan q50' },
      { points: d.realised.map(function (p) { return { x: toDate(p.ts), y: p.value }; }),
        color: textCol, width: 1.4, label: 'terealisasi' }
    ]
  });
}

function load() {
  var target = document.getElementById('target').value;
  var hours = document.getElementById('hours').value;
  fetchJson('api/forecast.php?target=' + encodeURIComponent(target) + '&hours=' + hours).then(function (d) {
    document.getElementById('unit').textContent = d.unit ? '(' + d.unit + ')' : '';

    // Explain a sparse chart rather than letting it look broken. The extract ends
    // 2026-07-22 and live polling starts later, so a short window can legitimately
    // contain almost nothing.
    var note = document.getElementById('gapnote');
    if (d.history_coverage < 0.5) {
      note.className = 'banner banner-info';
      note.innerHTML = 'Riwayat pada jendela ini hanya terisi <strong>' +
        (d.history_coverage * 100).toFixed(1) + '%</strong> (' + d.history_points +
        ' titik). Ini bukan kerusakan: ekstrak semai berhenti 2026-07-22 sedangkan polling ' +
        'live baru mulai belakangan, jadi ada celah nyata di tengahnya. Garis sengaja ' +
        'diputus di celah itu, bukan disambung, karena menyambungnya berarti mengarang data.';
    } else {
      note.className = '';
      note.innerHTML = '';
    }

    render(document.getElementById('chart'), d, 380);
  });
}

document.getElementById('target').addEventListener('change', load);
document.getElementById('hours').addEventListener('change', load);
document.getElementById('refresh').addEventListener('click', load);
load();

var targets = <?= json_encode(Domain::TARGETS) ?>;
var tiles = document.getElementById('tiles');
targets.forEach(function (t) {
  var card = document.createElement('div');
  card.className = 'card';
  card.innerHTML = '<h3>' + t + '</h3><canvas></canvas>';
  tiles.appendChild(card);
  fetchJson('api/forecast.php?target=' + encodeURIComponent(t) + '&hours=168').then(function (d) {
    render(card.querySelector('canvas'), d, 190);
  });
});

</script>

<?php Page::close(); ?>
