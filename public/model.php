<?php
declare(strict_types=1);

[$config, $db] = require __DIR__ . '/bootstrap.php';

use Tbw\Forecast\ForecastClient;
use Tbw\Repository\ForecastRepository;
use Tbw\Scoring\Scorecard;
use Tbw\Web\Page;

$scorecard = new Scorecard($db);
$models = $scorecard->byModel(14);
$best = $models[0]['model'] ?? null;
$perTarget = $scorecard->byTarget($best, 14);
$growth = $scorecard->errorGrowth($best, 14);
$runs = (new ForecastRepository($db))->recentRuns(15);
$health = $scorecard->health();
$sidecar = ForecastClient::fromConfig($config)->health();

Page::open('Kesehatan Model', 'model.php');
?>

<h1>Kesehatan Model &amp; Sistem</h1>
<p class="lede">
  Skor dihitung hanya setelah jendela 24 jam sebuah run matang sepenuhnya. Menilai sebagian
  akan membuat horizon dekat — tempat semua model terlihat bagus — mendominasi rata-rata.
</p>

<div class="grid grid-4">
  <div class="card"><div class="stat">
    <span class="big" style="color:<?= ($sidecar['status'] ?? '') === 'ok' ? 'var(--ok)' : 'var(--alarm)' ?>">
      <?= ($sidecar['status'] ?? '') === 'ok' ? 'OK' : 'DOWN' ?>
    </span>
    <span class="cap">Sidecar Chronos-2</span>
  </div></div>
  <div class="card"><div class="stat">
    <span class="big"><?= Page::e($sidecar['device'] ?? '—') ?></span>
    <span class="cap">Perangkat inferensi</span>
  </div></div>
  <div class="card"><div class="stat">
    <span class="big"><?= number_format($health['readings_total']) ?></span>
    <span class="cap">Bacaan mentah</span>
  </div></div>
  <div class="card"><div class="stat">
    <span class="big"><?= count($runs) ?></span>
    <span class="cap">Run terakhir tercatat</span>
  </div></div>
</div>

<?php if ($health['failing_jobs'] !== []): ?>
  <div class="banner banner-warn" style="margin-top:1rem">
    Job gagal dalam 24 jam terakhir:
    <?php foreach ($health['failing_jobs'] as $j): ?>
      <strong><?= Page::e($j['job']) ?></strong> (<?= (int) $j['n'] ?>&times;)
    <?php endforeach; ?>
    &mdash; periksa tabel <code>job_run</code>.
  </div>
<?php endif; ?>

<h2>Papan skor (14 hari)</h2>
<div class="card table-wrap">
  <table>
    <thead>
      <tr>
        <th>Model</th>
        <th class="num">MASE</th>
        <th class="num">SD MASE</th>
        <th class="num">WQL</th>
        <th class="num">cov80</th>
        <th class="num">Run</th>
        <th class="num">Skor</th>
      </tr>
    </thead>
    <tbody>
    <?php if ($models === []): ?>
      <tr><td colspan="7" class="na">
        Belum ada run yang matang. Skor pertama muncul 24 jam setelah forecast pertama —
        itu memang biayanya untuk menilai horizon penuh, bukan cuma jam pertama.
      </td></tr>
    <?php endif; ?>
    <?php foreach ($models as $i => $row): ?>
      <tr>
        <td><strong><?= Page::e($row['model']) ?></strong><?= $i === 0 ? ' <span class="pill tier-ok">terbaik</span>' : '' ?></td>
        <td class="num"><?= Page::num($row['mase'], 4) ?></td>
        <td class="num"><?= Page::num($row['mase_sd'], 3) ?></td>
        <td class="num"><?= Page::num($row['wql'], 4) ?></td>
        <td class="num"><?= $row['cov80'] === null ? '<span class="na">&mdash;</span>' : number_format($row['cov80'] * 100, 1) . '%' ?></td>
        <td class="num"><?= (int) $row['n_runs'] ?></td>
        <td class="num"><?= (int) $row['n_scores'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted" style="font-size:.84rem;margin-top:.75rem">
    Baca <strong>SD MASE bersama rata-ratanya</strong>. Dalam benchmark riset, keunggulan
    MASE LGBM-Local atas Chronos-2 (6,4% relatif) lebih kecil daripada simpangan bakunya
    sendiri (0,690) — jadi keunggulan itu tidak nyata. Sebaliknya cov80 LGBM 68,4% terhadap
    nominal 80% adalah kegagalan nyata, dan seluruh lapisan alarm berdiri di atas lebar
    interval.
  </p>
</div>

<h2>Pertumbuhan error per horizon</h2>
<div class="card">
  <div class="grid grid-4">
    <?php foreach (['0-1h' => '0–1 jam', '1-4h' => '1–4 jam', '4-12h' => '4–12 jam', '12-24h' => '12–24 jam'] as $key => $label): ?>
      <div class="stat">
        <span class="big"><?= $growth[$key] === null ? '—' : number_format($growth[$key], 4) ?></span>
        <span class="cap"><?= Page::e($label) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="muted" style="font-size:.84rem;margin-top:.75rem">
    Error absolut relatif terhadap level tiap target, supaya bucket-nya bisa dibandingkan
    pada panel yang satuannya membentang dari 4,9 kg/cm² sampai 187 kW. Lapisan alarm
    bekerja pada 24 jam, jadi bucket terjauh yang menentukan pilihan model — bukan jam
    pertama.
  </p>
</div>

<h2>Skor per target<?= $best === null ? '' : ' — ' . Page::e($best) ?></h2>
<div class="card table-wrap">
  <table>
    <thead>
      <tr><th>Target</th><th class="num">MASE</th><th class="num">SD</th><th class="num">WQL</th><th class="num">cov80</th><th class="num">n</th></tr>
    </thead>
    <tbody>
    <?php foreach ($perTarget as $row): ?>
      <tr>
        <td><?= Page::e($row['target']) ?></td>
        <td class="num"><?= Page::num($row['mase'], 4) ?></td>
        <td class="num"><?= Page::num($row['mase_sd'], 3) ?></td>
        <td class="num"><?= Page::num($row['wql'], 4) ?></td>
        <td class="num"><?= $row['cov80'] === null ? '<span class="na">&mdash;</span>' : number_format($row['cov80'] * 100, 1) . '%' ?></td>
        <td class="num"><?= (int) $row['n'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<h2>Run terakhir</h2>
<div class="card table-wrap">
  <table>
    <thead>
      <tr><th>#</th><th>Model</th><th>Asal</th><th class="num">ms</th><th class="num">Konteks</th><th class="num">Target</th><th>Status</th></tr>
    </thead>
    <tbody>
    <?php foreach ($runs as $row): ?>
      <tr>
        <td><?= (int) $row['id'] ?></td>
        <td><?= Page::e($row['model']) ?></td>
        <td class="muted"><?= Page::e($row['origin_ts']) ?></td>
        <td class="num"><?= number_format((int) $row['elapsed_ms']) ?></td>
        <td class="num"><?= number_format((float) $row['context_coverage'] * 100, 1) ?>%</td>
        <td class="num"><?= (int) $row['n_targets'] ?></td>
        <td>
          <?php if ((int) $row['degraded'] === 1): ?>
            <span class="pill tier-warn">degraded</span>
          <?php else: ?>
            <span class="pill tier-ok">ok</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="note">
  <strong>Batasan yang harus tetap disebut.</strong>
  90 hari lebih pendek dari satu siklus musiman, sehingga kenaikan suhu discharge belum
  bisa dipisahkan dari musiman tahunan. Tidak ada label kegagalan — stoppage disimpulkan
  dari motor current, jadi stop terencana dan trip proteksi tidak terbedakan; menyambungkan
  log CMMS adalah tambahan data bernilai tertinggi yang tersedia. Satuan sudah dikonfirmasi
  plant, sehingga angka bisnis kini boleh dihitung, tetapi tarif dan biaya outage di
  <code>config/config.php</code> masih placeholder.
</div>

<?php Page::close(); ?>
