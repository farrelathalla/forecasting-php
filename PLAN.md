# PLAN.md — Sistem Realtime Forecasting & Early Warning TBW

Rencana arsitektur untuk mengoperasikan hasil riset notebook (`../CLAUDE.md`) sebagai sistem
berjalan di lingkungan **XAMPP** milik tim plant.

Stasiun: TBW Water Pump Station, Jl. Raya Driyorejo No.265, Driyorejo, Gresik.

---

## 1. Pertanyaan yang dijawab dokumen ini

> "Apakah bisa jika menggunakan PHP untuk web-nya, karena di sini mereka bisanya pakai XAMPP?"

**Bisa — dan PHP memang jadi tulang punggung sistemnya.** Tapi ada satu bagian yang tidak bisa
PHP kerjakan, dan itu perlu dinyatakan di depan supaya tidak jadi kejutan waktu deploy.

Pemenang benchmark adalah **Chronos-2 zero-shot** (bukan fine-tune — sesuai instruksi).
Chronos-2 adalah model transformer 120 juta parameter yang bobotnya dijalankan lewat PyTorch.
**Tidak ada runtime PyTorch untuk PHP.** Menulis ulang inferensinya dengan tangan di PHP bukan
pekerjaan yang masuk akal, dan hasilnya tidak akan bisa diverifikasi terhadap angka benchmark
yang sudah kita punya (MASE 0.4282 / WQL 0.0059 / cov80 84.2).

Jadi pembagiannya:

| Lapisan | Teknologi | Alasan |
|---|---|---|
| Web UI, dashboard, halaman alarm | **PHP 8.1 (XAMPP/Apache)** | Yang dikuasai tim. Tidak ada build step, tidak ada Node. |
| Database | **MariaDB (XAMPP)** | Sudah ada di XAMPP, tidak menambah komponen baru. |
| Ingest dari API sensor | **PHP CLI** | cURL + PDO, tidak butuh apa-apa lagi. |
| Grid 15 menit, LOCF, fisika, CUSUM, SPC, proyeksi tren, scoring | **PHP murni** | Semuanya aritmetika. Tim plant bisa baca dan rawat sendiri. |
| **Inferensi Chronos-2 saja** | **Python sidecar (FastAPI, localhost)** | Satu-satunya bagian yang wajib Python. |

Sidecar-nya sengaja dibuat **setipis mungkin**: satu endpoint, tanpa database, tanpa state.
Dia menerima array angka, mengembalikan array angka. Semua logika bisnis — target mana yang
diramal, kapan, hasilnya diapakan — tetap di PHP. Kalau suatu hari Chronos-2 diganti model
lain, yang berubah hanya satu file Python; PHP-nya tidak tersentuh.

**Sudah diukur di mesin ini, bukan diperkirakan:** Chronos-2 berjalan di **CPU** (tanpa GPU),
memuat model 177 detik sekali di awal, lalu **5,5 detik untuk meramal 9 target sekaligus**.
Karena forecast hanya dijalankan tiap 15 menit, ini sangat longgar. **GPU tidak diperlukan.**

### Kalau Python benar-benar tidak boleh masuk ke server

Ada jalan keluar, dan konsekuensinya harus jujur disebut: sistem tetap jalan penuh dengan
**Naive-Seasonal** sebagai forecaster (100% PHP, MASE 0.6808). Lapisan early warning SPC —
yang justru menghasilkan temuan paling substantif di §15, yaitu `dT|TBW3` +8.57σ — **tidak
butuh model sama sekali** dan tetap berfungsi utuh. Yang hilang adalah kualitas interval:
cov80 Naive-Seasonal 90.5% terlihat bagus tapi intervalnya lebar, sehingga deteksi dini
melambat. Mode ini sudah disiapkan sebagai fallback otomatis (lihat §6), bukan sebagai
rencana utama.

---

## 2. Sumber data: API `latest.php`

```
GET https://apps.daesang.net/api/mqtt/latest.php
Authorization: Bearer apps-mqtt-static-7f3c9e2a1b8d4f60
```

Respons (sudah diverifikasi live 2026-07-29 13:29):

```json
{"success":true,"count":16,"data":[
  {"tag":"AIR_INSTRUMENT/TBW1/FLOWRATE","value":"33.4","updated_at":"2026-07-29 13:29:10"},
  ...
]}
```

**Karakter API ini yang menentukan seluruh desain ingest:**

1. **Ini snapshot, bukan histori.** Hanya nilai terakhir per tag. Tidak ada endpoint range.
   ⇒ Sistem harus **membangun histori sendiri** dengan polling dan menyimpannya. Database
   bukan cache, dia satu-satunya sumber histori yang kita punya ke depan.
2. **16 tag = 2 aset × 8 sinyal.** TBW2 offline, konsisten dengan **F1** (TBW2 berhenti
   permanen 2026-05-14). API mengonfirmasi temuan notebook dari sisi lapangan.
3. **`value` dikirim sebagai string.** Harus di-cast, dan harus divalidasi.
4. **`updated_at` adalah stempel waktu historian, bukan waktu request.** Dedup wajib memakai
   `(tag, updated_at)` — kalau tidak, polling tiap menit pada sinyal yang jarang berubah akan
   menyimpan ribuan baris duplikat. Ini konsekuensi langsung dari **F6** (report-by-exception:
   tidak ada baris baru = nilai tidak berubah, bukan nilai tidak diketahui).

### Satuan (dikonfirmasi pengguna)

| Sinyal | Satuan |
|---|---|
| POWER | kW |
| MOTOR_CURRENT | A |
| INLET_PRESS | Pa *(lihat catatan)* |
| INLET_TEMP | °C |
| FLOWRATE | **m³/min** |
| MOTOR_RPM | rpm |
| OUTLET_PRESSURE | kg/cm² |
| OUTLET_TEMP | °C |

Dua hal yang berubah dari asumsi notebook karena konfirmasi ini:

- **FLOWRATE = m³/min menutup pertanyaan terbuka §10.2.** Notebook menyimpulkan lewat neraca
  daya bahwa flow ~34 dengan POWER ~187 kW dan Δp ~3,9 bar hanya masuk akal kalau satuannya
  m³/min (efisiensi ~85%); kalau m³/h efisiensinya ~2%, mustahil. Konfirmasi ini cocok.
  ⇒ **Angka bisnis (Rp) sekarang boleh dihitung dan dikutip.**
- **OUTLET_PRESSURE = kg/cm², bukan bar.** Selisihnya ~2% (1 kg/cm² = 0,981 bar). Tidak
  mengubah temuan apa pun, tapi label di UI harus benar.
- **INLET_PRESS "Pa" tidak mengubah F4.** Nilai 90–265 Pa itu setara 0,0009–0,0027 bar —
  secara fisik mustahil sebagai tekanan isap pompa. Ditambah bukti gigi-gergaji dengan reset
  tepat saat setiap stop, **F4 tetap berlaku: tag ini salah label.** Sistem memperlakukannya
  sebagai counter, bukan tekanan, dan UI memberi tanda peringatan pada tag ini.

---

## 3. Yang diramal: 9 target, bukan 24

Diambil langsung dari keputusan arsitektur §10 notebook, tanpa perubahan:

| Tier | Target | Aksi |
|---|---|---|
| DROP | MOTOR_RPM ×2 | CV≈0 (**F8**). Disimpan hanya sebagai flag `is_running`. |
| STATION | `HEADER_PRESSURE` | 3 tag OUTLET_PRESSURE → 1 (**F7**, korelasi 0.964–0.993). |
| POOLED | `FLOWRATE`, `POWER`, `OUTLET_TEMP`, `INLET_TEMP` × {TBW1, TBW3} | 8 target, **satu model per seri** (bukan panel global). |
| SPECIAL | `INLET_PRESS` | **Tidak diramal levelnya** (**F4**). Disimpan `time_since_reset` + `ramp_slope`. |
| DERIVED | `MOTOR_CURRENT` | Diturunkan dari POWER / rasio (**F7**). Residual rasio jadi kanal deteksi gangguan listrik. |

**9 target: `HEADER_PRESSURE` + FLOWRATE|{TBW1,TBW3} + POWER|… + OUTLET_TEMP|… + INLET_TEMP|…**

Catatan penting yang harus diikuti implementasi: notebook **membantah** klaim pooling
(LGBM-Global kalah di 9 dari 9 target). Karena itu sistem ini memanggil Chronos-2 dengan
**konteks per-seri**, bukan panel gabungan — persis mode yang memenangkan benchmark.

---

## 4. Alur data

```
                    ┌──────────────────────────────────────┐
  apps.daesang.net  │  bin/poll.php        (tiap 1 menit)  │
   /mqtt/latest.php ├─►  cURL + Bearer                     │
                    │    filter sentinel 2^16  (F5)        │
                    │    dedup (tag, updated_at)  (F6)     │
                    └──────────────┬───────────────────────┘
                                   ▼  tabel reading_raw
                    ┌──────────────────────────────────────┐
                    │  bin/aggregate.php   (tiap 5 menit)  │
                    │    resample 15 menit pakai LAST       │
                    │    ffill dibatasi 60 menit  (F6)      │
                    │    collapse HEADER_PRESSURE (F7)      │
                    │    is_running dari MOTOR_RPM (F8)     │
                    │    kanal fisika: dT, P/I,             │
                    │      flow_per_kW, hyd_eff             │
                    └──────────────┬───────────────────────┘
                                   ▼  grid_15min + physics_15min
        ┌──────────────────────────┴───────────────────────┐
        ▼                                                  ▼
┌────────────────────────────────┐        ┌────────────────────────────────┐
│ bin/forecast.php (tiap 15 mnt) │        │ bin/evaluate.php (tiap 15 mnt) │
│  ambil 1344 langkah konteks    │        │  cocokkan forecast matang      │
│  POST ──► sidecar Python :8008 │        │    dengan aktual               │
│  Chronos-2 zero-shot, 9 kuantil│        │  residual → z → CUSUM          │
│  simpan run + 96×9 titik       │        │  SPC fisika vs limit beku      │
└──────────────┬─────────────────┘        │  proyeksi Huber → tanggal      │
               │                          │  AlarmPolicy → OK/WARN/ALARM   │
               ▼                          │  skor MASE / WQL / cov80       │
       forecast_run, forecast_point       └──────────────┬─────────────────┘
                                                          ▼
                                          alarm_event, spc_state, score_window
                                                          │
                    ┌─────────────────────────────────────┴─────────────┐
                    │  public/  — Apache XAMPP, PHP murni               │
                    │  dashboard live · grafik forecast + pita 80%      │
                    │  panel alarm · scorecard model · halaman aset     │
                    └───────────────────────────────────────────────────┘
```

Semua langkah berjalan **idempoten**: menjalankan ulang `aggregate`/`forecast`/`evaluate`
untuk jendela waktu yang sama tidak menggandakan baris. Ini pelajaran langsung dari gotcha
notebook — `RESULTS` yang append-only membuat PatchTST tercatat n=81 dan angkanya jadi tidak
bisa dipercaya. Di sini setiap tabel hasil punya UNIQUE key, jadi cacat yang sama tidak bisa
terulang.

---

## 5. Skema database (MariaDB `tbw_forecast`)

| Tabel | Isi | Kunci unik |
|---|---|---|
| `reading_raw` | Satu baris per (tag, updated_at) dari API. Mentah, apa adanya. | `(asset, signal, observed_at)` |
| `grid_15min` | Grid 15 menit hasil LOCF per (asset, signal). `is_held` menandai nilai hasil hold. | `(asset, signal, ts)` |
| `target_15min` | 9 target siap model, sesudah collapse/derivasi. | `(target, ts)` |
| `physics_15min` | `dT`, `P_over_I`, `flow_per_kW`, `hyd_eff` per aset. | `(channel, ts)` |
| `forecast_run` | Satu baris per eksekusi forecast: origin, model, latency, coverage konteks. | `(model, origin_ts)` |
| `forecast_point` | 96 horizon × 9 target × 9 kuantil + median. | `(run_id, target, ts)` |
| `forecast_score` | Skor per (run, target) setelah aktual matang: MASE, WQL, cov80. | `(run_id, target)` |
| `alarm_event` | Transisi tier OK/WARN/ALARM dengan bukti pemicunya. | — |
| `alarm_state` | Tier terkini + penghitung breach beruntun per kanal, supaya `min_consecutive` bertahan lintas eksekusi job. | `(channel, detector)` |
| `spc_state` | Nilai σ-drift terkini per kanal fisika vs limit referensi beku. | `(channel, ts)` |
| `projection` | Proyeksi tren Huber → tanggal tembus limit. | `(channel, computed_at)` |
| `job_run` | Audit tiap job CLI: mulai, selesai, status, pesan error. | — |

`spc_state` memakai **limit referensi beku** dari `output/deploy_bundle/spc_control_limits.csv`
(jendela sehat 2026-05-20 → 2026-06-05). Limit **tidak** dihitung ulang dari data berjalan —
kalau dihitung ulang, degradasi pelan akan menggeser baseline-nya sendiri dan alarm tidak
akan pernah bunyi. Ini kesalahan klasik SPC dan harus dicegah secara struktural.

---

## 6. Forecasting: kontrak sidecar

**Endpoint:** `POST http://127.0.0.1:8008/forecast`

```json
{ "prediction_length": 96,
  "quantile_levels": [0.1,0.2,0.3,0.4,0.5,0.6,0.7,0.8,0.9],
  "series": [ {"id": "POWER|TBW1", "values": [186.2, null, 187.1, ...]} ] }
```

`null` diperbolehkan dan **disengaja**. Chronos-2 menangani missing value di konteks secara
native — sudah diuji di mesin ini dengan lubang 700 langkah dan hasilnya tetap waras. Ini
penting karena histori kita akan berlubang: ekstrak CSV berhenti 2026-07-22, sedangkan
polling live baru mulai hari ini, sehingga ada celah ~7 hari. Menambal celah itu dengan
interpolasi justru melanggar semangat **F1/F6** (jangan mengarang operasi mesin). Lebih jujur
mengirim `null` dan menampilkan **context coverage** di UI.

**Respons:** median + 9 kuantil per seri, plus `model`, `device`, `elapsed_ms`.

**Degradasi bertingkat, bukan kegagalan total** — ini yang membuat sistem aman ditinggal jalan:

1. Sidecar sehat → Chronos-2, `model='chronos-2'`.
2. Sidecar mati / timeout → PHP otomatis pakai **Naive-Seasonal** (lag 96), `model='naive-seasonal'`,
   dan menandai run sebagai `degraded`. Dashboard menampilkan banner.
3. Konteks terlalu pendek (< 192 langkah = 2 hari) → target dilewati dengan alasan tercatat,
   bukan diramal asal.

Perbandingan skor tetap sah karena `forecast_score` menyimpan nama model per run — persis
alasan kenapa notebook bisa menggabungkan dua sesi leaderboard (`Naive-Seasonal` = 0.6808 di
kedua sesi). Prinsipnya dibawa ke produksi: **jangan pernah mencampur skor tanpa label model.**

---

## 7. Early warning

Tiga detektor, sama seperti §15 notebook, karena masing-masing menangkap kelas masalah berbeda.

**1. CUSUM residual** — menangkap yang tak terduga.
Dua bug yang sudah pernah menghanguskan angka di notebook **wajib** ikut terbawa perbaikannya:
- CUSUM mengasumsikan proses in-control ber-mean nol. Bias forecast konstan membuat statistik
  meramp selamanya. ⇒ **kurangi median `z` per episode.**
- Tiap run adalah episode forecast 24 jam yang independen. ⇒ **reset di tiap batas run.**

Tanpa dua ini, alarm rate 53%. Dengan keduanya, 4,34%. Ini bukan penyetelan, ini koreksi cacat.

**2. SPC fisika** — menangkap yang kelihatan normal padahal tidak.
Sinyal bisa berada di dalam rentang wajarnya sementara *hubungannya* dengan sinyal lain rusak.
Kanal: `dT`, `P_over_I`, `flow_per_kW`, `hyd_eff`. Limit dibekukan dari jendela sehat.

**3. Proyeksi ambang** — menangkap yang bisa dijadwalkan.
Regresi **Huber** (bukan OLS — trip akan menyeret slope) → limit operasi → **sebuah tanggal**.
Tanggal bisa dijadwalkan; lampu alarm tidak.

**Tiering (`AlarmPolicy`) sama pentingnya dengan deteksi.** Kalau glitch sensor dan trip yang
sudah di depan mata diberi severity sama, operator akan belajar mengabaikan keduanya.

Dua hal yang diperbaiki dari notebook, keduanya cacat yang sudah terdokumentasi:
- **`min_consecutive` ditegakkan di `classify()`.** Di notebook field ini didefinisikan tapi
  tidak pernah dipakai (roadmap E5). Di sini dites, jadi tidak bisa diam-diam mati lagi.
- **Anggaran alarm ≤ 2%.** `CUSUM_H = 20` dengan Chronos-2 memberi 1,92%. Angka ini hanya
  valid selama forecaster-nya terkalibrasi — kalau sistem jatuh ke fallback naive, ambangnya
  ikut disesuaikan otomatis, karena §15 sudah membuktikan alarm rate adalah fungsi dari
  kalibrasi model yang dipantau (LGBM cov80 68% → 16,6% alarm; Chronos-2 cov80 84% → 7,8%).

**Yang sudah kita tahu akan langsung menyala begitu sistem hidup**, dari deploy bundle:
`dT|TBW3` **+8.57σ ALARM**, `dT|TBW1` **+4.72σ ALARM**, `flow_per_kW|TBW1` −2.55σ WARN.
Ini bukan false positive dan tidak boleh "ditenangkan" — ini temuan F2 yang sedang berlanjut,
dan kanal efisiensi yang mulai ikut bergerak persis seperti yang F2 prediksikan.

---

## 8. Yang **tidak** dikerjakan sistem ini

Dinyatakan supaya batasnya jelas:

- **Tidak fine-tune.** Sesuai instruksi: pemenangnya Chronos-2 biasa (zero-shot). Justru itu
  keunggulan operasionalnya — tanpa pipeline training, tanpa jadwal retraining, tanpa risiko drift.
- **Tidak meramal mingguan/bulanan.** Horizon 24 jam. Rentang panjang adalah tugas proyeksi tren (§7.3).
- **Tidak menulis balik ke plant.** Sistem ini read-only terhadap historian. Murni observasi.
- **Tidak memakai covariate cuaca dulu.** F10 mengukur manfaatnya pada model linier, bukan pada
  Chronos-2 (roadmap E3, belum dijalankan). Feed cuaca live juga membawa error-nya sendiri.
  Hook-nya disiapkan; defaultnya mati sampai ada yang mengukurnya.
- **Tidak memantau TBW2.** Mesin ini pensiun (F1) dan API mengonfirmasi. UI menampilkannya
  sebagai *retired*, bukan sebagai aset dengan data hilang — supaya tidak ada yang salah baca.

---

## 9. Batasan yang harus tetap disebut

Dibawa utuh dari §11 notebook, karena semuanya masih berlaku:

- **90 hari < satu siklus musiman.** Kenaikan suhu discharge (F2) belum bisa dipisahkan dari
  musiman tahunan. Butuh 12 bulan untuk menyelesaikannya.
- **Tidak ada label kegagalan.** Stoppage disimpulkan dari motor current, jadi stop terencana
  dan trip proteksi tidak terbedakan. Menyambungkan log CMMS adalah tambahan data bernilai
  tertinggi yang tersedia — itu mengubah §15 dari anomaly detection jadi failure prediction.
- **Backtest divalidasi pada reanalysis, bukan forecast cuaca** (F10).
- **`INLET_PRESS` masih salah label** (F4) dan underflow 16-bit masih ada di sumber (F5).
  Sistem menyaringnya, tapi perbaikannya ada di firmware, bukan di sini.
- **Sistem baru punya konteks tipis di hari-hari pertama.** Histori disemai dari cache notebook
  (s/d 2026-07-22), lalu ada celah ~7 hari sebelum polling live. UI menampilkan coverage
  konteks apa adanya, dan skor 24 jam pertama harus dibaca dengan itu di kepala.

---

## 10. Kriteria selesai

Sistem dianggap berhasil kalau, tanpa campur tangan:

1. `reading_raw` bertambah tiap menit dan tidak menyimpan duplikat.
2. `forecast_run` bertambah tiap 15 menit dengan `model='chronos-2'` dan latency < 30 detik.
3. Dashboard membuka nilai live 2 aset + grafik 24 jam ke depan dengan pita 80%.
4. `dT|TBW1` dan `dT|TBW3` muncul sebagai ALARM (konfirmasi F2 di sistem live).
5. Alarm rate residual ≤ 2%.
6. Mematikan sidecar Python tidak mematikan sistem — dia turun ke Naive-Seasonal dan
   memberi tanda, bukan berhenti.
