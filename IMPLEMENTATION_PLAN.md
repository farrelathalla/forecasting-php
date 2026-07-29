# IMPLEMENTATION_PLAN.md

Rencana eksekusi bertahap untuk `PLAN.md`. Metode: **TDD** — tes ditulis lebih dulu, dijalankan
sampai merah, baru implementasinya ditulis sampai hijau.

Setiap fase punya **gerbang keluar** yang bisa diperiksa. Fase tidak boleh dilewati kalau
gerbangnya belum hijau.

---

## Aturan yang berlaku di semua fase

1. **Tanpa Composer.** XAMPP tidak membawa Composer, dan menambah prasyarat instalasi adalah
   cara tercepat membuat sistem ini tidak pernah dipasang. Autoloader PSR-4 ditulis sendiri
   (± 15 baris), test runner ditulis sendiri. **Nol dependensi PHP eksternal.**
2. **Tanpa dependensi JS eksternal.** Grafik digambar dengan `<canvas>` + JS biasa. Tidak ada
   CDN, karena jaringan plant sering tertutup dan grafik yang gagal muat = dashboard mati.
3. **Semua tulis ke DB idempoten.** Setiap tabel hasil punya UNIQUE key + `INSERT ... ON
   DUPLICATE KEY UPDATE`. Alasannya ada di PLAN §4 (cacat `RESULTS` append-only di notebook).
4. **Angka apa pun yang muncul di UI harus bisa ditelusuri** ke tabel dan ke temuan di
   `../CLAUDE.md`. Kalau tidak bisa direproduksi dari data, tidak ditampilkan.
5. **Tes tidak boleh menyentuh API produksi.** `SensorApiClient` menerima transport yang bisa
   diganti; tes memakai transport palsu. Ada satu tes integrasi terpisah yang benar-benar
   memanggil API, dan itu di-skip kecuali dijalankan eksplisit.
6. **Tes DB memakai database terpisah** `tbw_forecast_test`, dibuat dan dihapus oleh runner.

**Perintah tes:** `php tests/run.php` (semua) · `php tests/run.php Cusum` (filter nama).

---

## Fase 1 — Fondasi

**Tujuan:** kerangka repo, konfigurasi, autoloader, test runner, skema DB.

**Tes lebih dulu**
- `AutoloaderTest` — kelas di `src/` bisa dimuat lewat namespace `Tbw\`.
- `ConfigTest` — baca `config/config.php`, override dari `.env`, nilai default waras,
  konstanta domain (`HORIZON=96`, `CONTEXT=1344`, `MODEL_FREQ=15`, `SEASONALITY=96`) cocok
  dengan `output/deploy_bundle/inference_state.json`.
- `MigrationTest` — jalankan `db/schema.sql` di DB tes, semua tabel ada, UNIQUE key ada,
  jalankan dua kali tidak error (idempoten).

**Implementasi**
```
composer-free autoloader   src/autoload.php
Config                     src/Config.php
Db (PDO, singleton, exceptions on)   src/Db.php
skema                      db/schema.sql
runner                     tests/run.php  +  tests/TestCase.php
migrator                   bin/migrate.php
```

**Gerbang keluar:** `php tests/run.php` hijau; `php bin/migrate.php` membuat 11 tabel.

---

## Fase 2 — Ingest

**Tujuan:** menarik API tiap menit dan menyimpannya tanpa duplikat.

**Tes lebih dulu**
- `SensorApiClientTest`
  - parsing `AIR_INSTRUMENT/TBW1/FLOWRATE` → `(asset=TBW1, signal=FLOWRATE)`.
  - `value` string → float; string non-numerik → baris ditolak, bukan jadi 0.
  - **filter sentinel (F5):** nilai ≥ 10000 pada `INLET_PRESS` dibuang dan dicatat.
    Uji dengan 65535, 65529, 65527.
  - `success:false` → lempar exception, bukan diam-diam menyimpan nol baris.
  - HTTP 401/500 → exception dengan pesan yang menyebut status.
  - respons bukan JSON → exception.
  - tag aset tak dikenal (TBW2 kalau muncul lagi) → tetap disimpan, tidak bikin crash.
- `ReadingRepositoryTest`
  - insert 16 baris → 16 tersimpan.
  - insert ulang payload yang sama → tetap 16 (dedup `(asset,signal,observed_at)`), **F6**.
  - `latestPerTag()` mengembalikan 1 baris per tag, yang terbaru.

**Implementasi:** `src/Ingest/SensorApiClient.php`, `src/Ingest/HttpTransport.php` (interface +
cURL impl), `src/Repository/ReadingRepository.php`, `bin/poll.php`, `bin/poll_loop.php`
(loop berdurasi, untuk dijalankan sebagai layanan latar).

**Gerbang keluar:** `php bin/poll.php` menambah baris pada panggilan pertama, **nol baris**
pada panggilan kedua dalam menit yang sama.

---

## Fase 3 — Grid & fisika

**Tujuan:** dari titik-titik mentah tak beraturan → grid 15 menit → 9 target → kanal fisika.

**Tes lebih dulu**
- `LocfResamplerTest` — **inti dari F6, tes paling penting di fase ini.**
  - dua sampel dalam satu bucket → yang diambil **yang terakhir**, bukan rata-ratanya.
    (`.mean()` salah karena hanya merata-rata menit yang tertulis dan membuang stretch konstan.)
  - lubang 30 menit → di-hold, `is_held=1`.
  - lubang 90 menit dengan `HOLD_LIMIT=60` → **berhenti di-hold**, sisanya `null`.
    Tanpa ini, outage 3 minggu TBW1 tampak seperti operasi normal.
  - bucket ditandai pada batas 15 menit (`13:30`, `13:45`), bukan waktu sampel.
- `TargetBuilderTest`
  - `HEADER_PRESSURE` = median OUTLET_PRESSURE aset aktif (**F7**); satu aset hilang → tetap
    terbentuk dari sisanya; semua hilang → `null`, bukan 0.
  - `MOTOR_RPM` **tidak pernah** muncul sebagai target (**F8**); `is_running` = rpm > 1.
  - `MOTOR_CURRENT` tidak diramal, diturunkan dari POWER / rasio (**F7**).
  - `INLET_PRESS` tidak pernah jadi target level (**F4**); yang dihitung `time_since_reset`
    dan `ramp_slope`; reset terdeteksi saat turun tajam.
  - persis **9** target dihasilkan, namanya sama persis dengan `inference_state.json`.
- `PhysicsTest`
  - `dT = OUTLET_TEMP − INLET_TEMP`.
  - `P_over_I = POWER / MOTOR_CURRENT`, aman saat arus 0 → `null`, bukan pembagian nol.
  - `flow_per_kW = FLOWRATE / POWER`; `hyd_eff` dari flow × Δp / daya, satuan **m³/min** dan
    **kg/cm²** (PLAN §2).
  - saat aset berhenti (`is_running=0`) semua kanal fisika `null` — bukan angka sampah yang
    nanti memicu alarm palsu.

**Implementasi:** `src/Grid/LocfResampler.php`, `src/Grid/TargetBuilder.php`,
`src/Physics/PhysicsCalculator.php`, `src/Repository/GridRepository.php`, `bin/aggregate.php`.

**Gerbang keluar:** `bin/aggregate.php` mengisi `grid_15min`, `target_15min`, `physics_15min`;
dijalankan dua kali tidak menggandakan baris.

---

## Fase 4 — Forecast Chronos-2

**Tujuan:** ramalan 24 jam ke depan dengan interval, tiap 15 menit.

**Tes lebih dulu (PHP)**
- `ForecastClientTest` (transport palsu)
  - membangun payload: 9 seri, panjang ≤ 1344, lubang jadi `null`.
  - respons diurai jadi 96 langkah × 9 kuantil per target.
  - **timeout/koneksi ditolak → fallback Naive-Seasonal**, run ditandai `degraded`,
    bukan exception yang membunuh cron.
  - jumlah langkah balasan tidak sama dengan 96 → tolak run, jangan simpan separuh.
- `NaiveSeasonalTest` — prediksi = nilai 96 langkah lalu; kuantil dari sebaran residual musiman;
  dipakai sebagai fallback **dan** sebagai baseline pembanding permanen (§6 notebook: tanpa
  baseline naive, `OUTLET_TEMP` akan menyanjung setiap model).
- `ForecastRepositoryTest` — simpan run + 864 titik; simpan ulang origin yang sama tidak
  menggandakan; `contextCoverage` tersimpan.
- `ContextBuilderTest` — **gotcha off-by-one dari notebook:** konteks harus berakhir **tepat
  sebelum** origin, bukan inklusif. Diuji eksplisit karena di notebook ini pernah membocorkan
  titik tes pertama.

**Tes lebih dulu (Python sidecar)**
- `service/test_service.py`
  - `/health` melaporkan model termuat + device.
  - `/forecast` mengembalikan bentuk `(n_series, 96, n_quantiles)`.
  - seri berisi `null` tetap menghasilkan angka waras (sudah diverifikasi manual).
  - kuantil **monoton naik** — q10 ≤ q50 ≤ q90 untuk tiap langkah. Kalau ini pecah, seluruh
    pita interval dan cov80 tidak bermakna.
  - seri lebih pendek dari minimum → 422 dengan pesan jelas, bukan 500.

**Implementasi:** `service/app.py` (FastAPI, model dimuat sekali saat startup),
`service/requirements.txt`, `src/Forecast/ForecastClient.php`,
`src/Forecast/NaiveSeasonal.php`, `src/Forecast/ContextBuilder.php`,
`src/Repository/ForecastRepository.php`, `bin/forecast.php`.

**Gerbang keluar:** `bin/forecast.php` menghasilkan satu `forecast_run` dengan
`model='chronos-2'` dan 864 baris `forecast_point`; mematikan sidecar → run berikutnya
`model='naive-seasonal'`, `degraded=1`, sistem tetap hidup.

---

## Fase 5 — Early warning

**Tujuan:** residual dan fisika jadi tier OK/WARN/ALARM yang bisa ditindak.

**Tes lebih dulu**
- `CusumTest` — **kedua bug notebook diabadikan sebagai tes regresi:**
  - deret ber-mean nol, `h=20` → **tidak ada** alarm.
  - deret dengan **bias konstan** → tanpa pemusatan meramp dan alarm selamanya; **dengan
    pemusatan median per episode → tidak alarm.** (Mendeteksi bias konstan itu tugas SPC.)
  - dua episode disambung: episode 2 dimulai dari nol → statistik **direset di batas run**.
  - pergeseran nyata di tengah episode → **terdeteksi**. (Tes ini menjaga agar perbaikan di
    atas tidak berubah jadi detektor yang tidak pernah bunyi.)
  - spike tunggal diabaikan; 20 deviasi kecil beruntun memicu.
- `SpcTest`
  - limit **dibaca dari file referensi beku**, tidak dihitung ulang dari data berjalan.
    Tes menegaskan ini: memberi data drift baru **tidak** menggeser `mu`/`sigma`.
  - `dT|TBW3` dengan nilai terkini dari bundle → `drift_sigma ≈ +8.57`, tier ALARM.
  - `dT` melewati nol → **tidak ada** perhitungan "% dari mean" (gotcha notebook: mean-nya
    menyeberang nol, rasionya tidak bermakna).
- `HuberTrendTest`
  - garis bersih → slope OLS ≈ slope Huber.
  - garis + 3 outlier ekstrem (mensimulasikan trip) → **Huber bertahan, OLS meleset**.
    Inilah alasan Huber dipilih; tesnya membuktikan pilihannya, bukan sekadar memakainya.
  - slope menjauhi limit → `days_to_limit = null`, bukan angka negatif atau tak hingga.
- `AlarmPolicyTest`
  - **`min_consecutive` ditegakkan** — 3 sampel di atas ambang dengan `min_consecutive=4`
    → tetap OK; sampel ke-4 → naik tier. (Roadmap E5: di notebook field ini tidak pernah
    dipakai `classify()`. Tes ini yang membuatnya tidak bisa mati diam-diam lagi.)
  - tier naik cepat, turun dengan histeresis — supaya alarm tidak berkedip.
  - setiap `alarm_event` menyimpan bukti pemicunya (kanal, nilai, ambang).

**Implementasi:** `src/EarlyWarning/Cusum.php`, `Spc.php`, `HuberTrend.php`,
`AlarmPolicy.php`, `src/Repository/AlarmRepository.php`, `bin/evaluate.php`,
`config/spc_limits.csv` (disalin dari deploy bundle).

**Gerbang keluar:** `bin/evaluate.php` menandai `dT|TBW1` dan `dT|TBW3` ALARM (konfirmasi F2),
alarm rate residual ≤ 2%.

---

## Fase 6 — Scoring & web

**Tujuan:** membuktikan sistemnya benar-benar meramal dengan baik, dan menampilkannya.

**Tes lebih dulu**
- `MetricsTest`
  - **MASE** — prediksi sempurna → 0; sama persis dengan seasonal naive → 1,0;
    denominator memakai **seasonal naive musim 96**, bukan lag-1.
  - **WQL** — pinball loss rata-rata 9 kuantil; prediksi sempurna → 0; asimetris dengan arah
    yang benar (under-forecast dan over-forecast tidak dihukum sama).
  - **cov80** — 80 dari 100 aktual di dalam pita → 0,80. Dilaporkan **terpisah** dari WQL,
    karena model bisa punya WQL bagus sambil terlalu percaya diri, dan alarm yang
    under-covering memuntahkan false positive.
  - deret dengan `null` → diabaikan dari skor, tidak diperlakukan sebagai nol.
- `ScorecardTest` — rata-rata dan **SD lintas run** (SD-nya yang penting: keunggulan MASE
  LGBM-Local ternyata lebih kecil dari SD-nya sendiri); dikelompokkan per model.
- `ErrorGrowthTest` — error per bucket horizon 0–1j / 1–4j / 4–12j / 12–24j.
- `JsonEndpointTest` — tiap endpoint mengembalikan JSON valid + `Content-Type` benar; DB kosong
  → struktur kosong yang sah, bukan fatal error.

**Implementasi**
```
src/Scoring/Metrics.php, Scorecard.php
public/index.php          dashboard live
public/forecast.php       grafik + pita 80%
public/alarms.php         panel alarm + SPC + proyeksi
public/model.php          scorecard, error growth, kesehatan sistem
public/api/*.php          endpoint JSON
public/assets/app.js|css  chart canvas, nol dependensi
src/Web/Renderer.php, src/Web/Json.php
```

**Gerbang keluar:** keempat halaman render dengan data nyata; semua endpoint JSON valid.

---

## Fase 7 — Semai, jalankan, kirim

**Tujuan:** sistem hidup dengan konteks yang cukup, dan ditinggal berjalan.

**Langkah**
1. `service/export_history.py` — `output/cache/grid_1min.parquet` → CSV 15 menit
   (LOCF, sesuai F6). Hanya kolom yang dibutuhkan 9 target.
2. `bin/seed_history.php` — impor CSV ke `grid_15min`/`target_15min` dengan `source='seed'`,
   supaya data semai selalu bisa dibedakan dari data live. Idempoten.
3. Migrasi + semai + agregasi + forecast pertama + evaluasi.
4. **Verifikasi kriteria selesai PLAN §10 satu per satu**, termasuk uji cabut-colok: matikan
   sidecar, pastikan sistem turun ke naive dan tidak mati.
5. `README.md` — cara pasang di XAMPP, cara jalankan, cara jadwalkan (Task Scheduler),
   pemecahan masalah.
6. `git commit` + `push`. **Tanpa `Co-Authored-By`** sesuai permintaan.
7. Tinggalkan berjalan: sidecar `:8008`, web `:8080`, poller, scheduler 15 menit.

**Gerbang keluar:** keenam kriteria PLAN §10 terpenuhi, tes hijau, ter-push, layanan hidup.

---

## Urutan pengerjaan & ketergantungan

```
F1 fondasi
 └─ F2 ingest ─────────┐
     └─ F3 grid+fisika ─┼─ F4 forecast ─┐
                        └─ F5 early warning ─┴─ F6 scoring+web ─ F7 semai & kirim
```

F5 hanya butuh grid untuk bagian SPC-nya, jadi SPC bisa jalan lebih dulu dari forecast —
dan itu memang benar secara operasional: **temuan paling substantif (§15) tidak bergantung
pada model sama sekali.**

## Risiko yang sudah diketahui

| Risiko | Penanganan |
|---|---|
| Sidecar mati saat operator tidak ada | Fallback Naive-Seasonal otomatis + banner (F4 fase 4) |
| Konteks berlubang 7 hari | `null` dikirim apa adanya; Chronos-2 sudah diuji tahan; coverage ditampilkan |
| Model muat 177 detik | Dimuat sekali saat startup sidecar, bukan per panggilan |
| API berubah bentuk | Parsing punya tes; `success:false` melempar; `job_run` mencatat kegagalan |
| Alarm terlalu berisik → dimute operator | Anggaran ≤2% diuji; `h` disetel terhadap anggaran, bukan ditebak |
| Jam server ≠ jam historian | Semua waktu memakai `updated_at` dari API, bukan jam lokal |
