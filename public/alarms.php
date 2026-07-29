<?php
declare(strict_types=1);

[$config, $db] = require __DIR__ . '/bootstrap.php';

use Tbw\Repository\AlarmRepository;
use Tbw\Web\Page;

$alarms = new AlarmRepository($db);
$states = $alarms->currentStates();
$spc = $alarms->latestSpc();
$projections = $alarms->latestProjections();
$events = $alarms->recentEvents(25);

Page::open('Peringatan Dini', 'alarms.php');
?>

<h1>Peringatan Dini</h1>
<p class="lede">
  Tiga detektor, karena masing-masing menangkap kelas masalah yang berbeda:
  <strong>CUSUM residual</strong> menangkap yang tak terduga,
  <strong>SPC fisika</strong> menangkap yang kelihatan normal padahal tidak, dan
  <strong>proyeksi tren</strong> menangkap yang bisa dijadwalkan.
</p>

<h2>Kanal fisika (SPC)</h2>
<div class="note">
  Batas kendali <strong>dibekukan</strong> dari jendela sehat 2026-05-20 &rarr; 2026-06-05
  dan tidak pernah dihitung ulang dari data berjalan. Kalau dihitung ulang, degradasi pelan
  akan menyeret baseline-nya sendiri dan alarm tidak akan pernah bunyi. Penyimpangan
  dinyatakan dalam satuan sigma saja, tidak pernah sebagai persen dari rata-rata — mean
  <code>dT</code> menyeberangi nol, sehingga persentase pada kanal itu tidak bermakna.
</div>

<div class="card table-wrap">
  <table>
    <thead>
      <tr>
        <th>Kanal</th>
        <th class="num">Nilai kini</th>
        <th class="num">μ (sehat)</th>
        <th class="num">σ</th>
        <th class="num">Penyimpangan</th>
        <th>Tier</th>
      </tr>
    </thead>
    <tbody>
    <?php if ($spc === []): ?>
      <tr><td colspan="6" class="na">Belum ada data SPC — jalankan <code>php bin/evaluate.php</code>.</td></tr>
    <?php endif; ?>
    <?php foreach ($spc as $row):
        $drift = $row['drift_sigma'] === null ? null : (float) $row['drift_sigma'];
    ?>
      <tr>
        <td><strong><?= Page::e($row['channel']) ?></strong></td>
        <td class="num"><?= Page::num($row['value'], 4) ?></td>
        <td class="num"><?= Page::num($row['mu'], 4) ?></td>
        <td class="num"><?= Page::num($row['sigma'], 4) ?></td>
        <td class="num" style="<?= $drift !== null && abs($drift) >= 3 ? 'color:var(--alarm);font-weight:700' : ($drift !== null && abs($drift) >= 2 ? 'color:var(--warn)' : '') ?>">
          <?= $drift === null ? '<span class="na">&mdash;</span>' : sprintf('%+.2f σ', $drift) ?>
        </td>
        <td><span class="pill <?= Page::tierClass((string) $row['tier']) ?>"><?= Page::e($row['tier']) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="note">
  <strong>Yang sudah diketahui dari riset dan diharapkan muncul di sini.</strong>
  <code>dT|TBW3</code> dan <code>dT|TBW1</code> naik tajam — itu temuan F2, kenaikan suhu
  discharge yang bergerak bersama di kedua pompa dengan jeda ~5 hari pada tanjakan
  pertengahan Juni. Karena <em>common-mode</em>, penyebabnya bersama (sumber isap, sirkuit
  pendingin, jalur resirkulasi), <em>bukan</em> keausan impeler satu mesin.
  <code>flow_per_kW|TBW1</code> yang ikut bergerak negatif adalah tahap kedua yang F2
  prediksikan: sinyal termal memimpin, sinyal efisiensi menyusul. Itu juga berarti jendela
  untuk memperbaikinya dengan murah sedang menutup.
</div>

<h2>Proyeksi ambang</h2>
<p class="muted" style="font-size:.88rem">
  Regresi <strong>Huber</strong>, bukan OLS — stasiun ini punya 16 episode berhenti dalam
  90 hari dan trip akan menyeret slope kuadrat-terkecil ke arah yang salah. Tanggal bisa
  dijadwalkan; lampu alarm tidak.
</p>

<div class="card table-wrap">
  <table>
    <thead>
      <tr>
        <th>Kanal</th>
        <th class="num">Slope / hari</th>
        <th class="num">Nilai kini</th>
        <th class="num">Limit</th>
        <th class="num">Hari lagi</th>
        <th>Perkiraan tanggal</th>
      </tr>
    </thead>
    <tbody>
    <?php if ($projections === []): ?>
      <tr><td colspan="6" class="na">Belum ada proyeksi.</td></tr>
    <?php endif; ?>
    <?php foreach ($projections as $row):
        $days = $row['days_to_limit'] === null ? null : (float) $row['days_to_limit'];
    ?>
      <tr>
        <td><strong><?= Page::e($row['channel']) ?></strong></td>
        <td class="num"><?= Page::num($row['slope_per_day'], 4) ?></td>
        <td class="num"><?= Page::num($row['current_value'], 3) ?></td>
        <td class="num"><?= Page::num($row['limit_value'], 3) ?></td>
        <td class="num" style="<?= $days !== null && $days < 30 ? 'color:var(--warn);font-weight:600' : '' ?>">
          <?= $days === null ? '<span class="na">tak tercapai</span>' : number_format($days, 1) ?>
        </td>
        <td><?= $row['eta'] === null ? '<span class="na">&mdash;</span>' : Page::e(substr((string) $row['eta'], 0, 16)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted" style="font-size:.83rem;margin-top:.75rem">
    Limit di <code>config/projection_limits.php</code> masih memakai batas kendali atas
    jendela sehat, bukan setelan trip pabrik. Jadi tanggal ini menjawab "kapan kanal ini
    keluar dari perilaku yang kita tahu sehat", bukan "kapan rusak".
    <strong>Ganti dengan limit operasi sebenarnya begitu plant mengonfirmasi.</strong>
  </p>
</div>

<h2>Status kanal saat ini</h2>
<div class="card table-wrap">
  <table>
    <thead>
      <tr><th>Kanal</th><th>Detektor</th><th>Tier</th><th class="num">Beruntun</th><th class="num">Nilai</th><th>Terakhir</th></tr>
    </thead>
    <tbody>
    <?php if ($states === []): ?>
      <tr><td colspan="6" class="na">Belum ada status.</td></tr>
    <?php endif; ?>
    <?php foreach ($states as $row): ?>
      <tr>
        <td><?= Page::e($row['channel']) ?></td>
        <td class="muted"><?= Page::e($row['detector']) ?></td>
        <td><span class="pill <?= Page::tierClass((string) $row['tier']) ?>"><?= Page::e($row['tier']) ?></span></td>
        <td class="num"><?= (int) $row['consecutive'] ?></td>
        <td class="num"><?= Page::num($row['last_value'], 3) ?></td>
        <td class="muted"><?= Page::e(substr((string) ($row['last_ts'] ?? ''), 0, 16)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted" style="font-size:.83rem;margin-top:.75rem">
    Kolom <strong>Beruntun</strong> adalah penghitung <code>min_consecutive</code>: tier
    hanya naik setelah <?= (int) $config->int('alarm.min_consecutive', 4) ?> sampel
    berturut-turut melewati ambang. Di notebook field ini didefinisikan tapi tidak pernah
    dipakai <code>classify()</code>, jadi syarat persistensinya sebenarnya mati. Di sini
    ditegakkan dan diuji.
  </p>
</div>

<h2>Transisi terakhir</h2>
<div class="card table-wrap">
  <table>
    <thead>
      <tr><th>Waktu</th><th>Kanal</th><th>Detektor</th><th>Perubahan</th><th class="num">Nilai</th><th>Bukti</th></tr>
    </thead>
    <tbody>
    <?php if ($events === []): ?>
      <tr><td colspan="6" class="na">Belum ada transisi. Hanya perubahan tier yang dicatat — alarm tetap yang dicatat tiap siklus itu derau, bukan informasi.</td></tr>
    <?php endif; ?>
    <?php foreach ($events as $row): ?>
      <tr>
        <td class="muted"><?= Page::e(substr((string) $row['created_at'], 0, 16)) ?></td>
        <td><?= Page::e($row['channel']) ?></td>
        <td class="muted"><?= Page::e($row['detector']) ?></td>
        <td>
          <span class="pill <?= Page::tierClass((string) $row['prev_tier']) ?>"><?= Page::e($row['prev_tier']) ?></span>
          &rarr;
          <span class="pill <?= Page::tierClass((string) $row['tier']) ?>"><?= Page::e($row['tier']) ?></span>
        </td>
        <td class="num"><?= Page::num($row['value'], 3) ?></td>
        <td class="muted" style="font-size:.8rem"><?= Page::e(substr((string) ($row['evidence'] ?? ''), 0, 90)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php Page::close(); ?>
