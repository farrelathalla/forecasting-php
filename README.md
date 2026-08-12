# TBW Realtime Forecasting & Early Warning

Sistem realtime untuk stasiun pompa air TBW (Driyorejo, Gresik). Berjalan di **XAMPP**.

- **Web + orkestrasi + semua analitik: PHP murni.** Nol dependensi, tanpa Composer.
- **Inferensi Chronos-2: sidecar Python kecil di localhost.** Satu-satunya bagian yang
  wajib Python — tidak ada runtime PyTorch untuk PHP.

Alasan lengkap tiap keputusan ada di [`PLAN.md`](PLAN.md); rencana eksekusinya di
[`IMPLEMENTATION_PLAN.md`](IMPLEMENTATION_PLAN.md). Temuan risetnya di `../CLAUDE.md`.

---

## Prasyarat

| Butuh | Versi yang diuji |
|---|---|
| XAMPP (Apache + MariaDB) | PHP 8.1.25, MariaDB 10.4.32 |
| Python | 3.10+ (diuji 3.13) |
| Ekstensi PHP | `pdo_mysql`, `curl`, `json`, `mbstring` (bawaan XAMPP) |

**GPU tidak diperlukan.** Chronos-2 berjalan di CPU: model dimuat ~3 menit sekali saat
sidecar start, lalu **~6 detik untuk meramal 9 target sekaligus**. Forecast hanya berjalan
tiap 15 menit, jadi sangat longgar.

---

## Pasang

```bash
# 1. konfigurasi
cp .env.example .env          # edit kalau user/password MySQL bukan bawaan XAMPP

# 2. database (nyalakan MySQL dari XAMPP Control Panel dulu)
php bin/migrate.php

# 3. sidecar Python
python -m venv --system-site-packages service/.venv
service/.venv/Scripts/python -m pip install -r service/requirements.txt
```

> **Jangan pernah menambahkan `-U` ke pip di sini.** Upgrade agresif pernah menyeret satu
> run melewati torch 2.12 → 2.4.1 → 2.13.0 dan mematikan 432 fit dengan `ImportError`.
> Strategi bawaan pip (`only-if-needed`) sudah benar.

### Menyemai histori (sangat disarankan)

Tanpa ini sistem mulai tanpa konteks sama sekali dan baru bisa meramal setelah dua minggu.

```bash
service/.venv/Scripts/python service/export_history.py   # parquet -> var/seed_15min.csv
php -d memory_limit=1G bin/seed_history.php
```

Menyemai memuat ekstrak 90 hari (2026-04-23 → 2026-07-22). Antara akhir ekstrak dan awal
polling live ada celah; **celah itu sengaja tidak ditambal**. Nilai kosong dikirim apa
adanya ke Chronos-2, yang menanganinya secara native. Menginterpolasinya berarti mengarang
operasi mesin yang tidak pernah terjadi. Cakupan konteks ditampilkan di dashboard.

---

## Jalankan

```bash
php bin/run_all.php --daemon      # semua job + sidecar sekaligus (paling mudah)
```

Atau per komponen:

```bash
# sidecar Chronos-2 (biarkan hidup)
service/.venv/Scripts/python -m uvicorn app:app --host 127.0.0.1 --port 8008 --app-dir service

# poller (biarkan hidup)
php bin/poll_loop.php --interval=60

# tiap 15 menit
php bin/aggregate.php && php bin/forecast.php && php bin/evaluate.php
```

Web: arahkan Apache ke `public/`, atau untuk cepat:

```bash
php -S 127.0.0.1:8080 -t public
```

### Menjadwalkan di Windows (Task Scheduler)

| Job | Interval | Perintah |
|---|---|---|
| poll | 10 detik | `C:\xampp\php\php.exe <repo>\bin\poll.php` |
| aggregate | 5 menit | `C:\xampp\php\php.exe <repo>\bin\aggregate.php` |
| forecast | 15 menit | `C:\xampp\php\php.exe <repo>\bin\forecast.php` |
| evaluate | 15 menit | `C:\xampp\php\php.exe <repo>\bin\evaluate.php` |
| **prune** | **harian** | `C:\xampp\php\php.exe <repo>\bin\prune.php` |

Task Scheduler tidak bisa di bawah 1 menit, jadi untuk polling 10 detik pakai
`bin\poll_loop.php --interval=10` atau `bin\run_all.php --daemon` (keduanya loop sendiri).

Semua job **idempoten** — jalan dobel atau terlambat tidak menggandakan baris.

### Laju poll = resolusi histori

`latest.php` adalah endpoint **snapshot**: nilai di antara dua poll hilang selamanya, tidak
ada endpoint range untuk mengambilnya kembali. Jadi laju poll bukan setelan performa, dia
menentukan seberapa halus histori yang akan pernah kita punya.

**Diukur 2026-07-29:** `updated_at` maju **tiap 5 detik** — konfirmasi lapangan atas temuan
F6 bahwa historian polling 5 detik. Jadi 5 detik adalah lantai yang berguna; lebih cepat
hanya membaca ulang tick yang sama.

| Interval | Baris/hari (16 tag) | Catatan |
|---|---|---|
| 60 s | 23 rb | terlalu kasar untuk grafik live |
| **10 s** (default) | **138 rb** | menangkap satu dari dua tick; ≈ laju rekam historian sendiri (119 rb/hari) |
| 5 s | 276 rb | menangkap semua tick |

Ubah lewat `.env`:

```
TBW_INGEST_POLL_INTERVAL_SEC=10
```

**Naikkan laju berarti wajib menjadwalkan `bin/prune.php`.** Pada 10 detik `reading_raw`
tumbuh ~50 juta baris/tahun. Yang dipangkas hanya bacaan mentah (default 30 hari) — grid
15 menit **tidak pernah** dipangkas, karena dialah penyimpan jangka panjang tempat semua
target, skor dan nilai SPC dibangun.

---

## Halaman

**Satu halaman, `index.php`.** Susunannya mengikuti satu-satunya pertanyaan yang dibawa
operator waktu membukanya — "24 jam ke depan aman atau tidak, dan ada yang melenceng?":

1. **Kartu status** — kondisi stasiun, lalu satu kartu per pompa. Daftar pompanya
   **diturunkan dari data**, bukan dari konstanta: gabungan aset yang sedang dikirim API
   dan yang ada di riwayat, dengan status (JALAN / MATI / lama tidak mengirim) dibaca dari
   pembacaan terakhir tiap aset. Kalau susunan stasiun berubah lagi seperti Mei 2026 (F1),
   halaman ikut berubah tanpa edit kode.
2. **Grafik ramalan 24 jam** — aktual, median, pita 80%, penanda titik asal. Target bisa
   diganti lewat dropdown; ke-9 target ada di situ, tidak perlu pindah halaman.
3. **Peringatan dini** — tabel kanal fisika terhadap batas beku, plus tanggal proyeksi Huber.

Teksnya ditulis untuk ruang kontrol, bukan untuk notebook yang menghasilkannya. Nama
internal (`dT`, `flow_per_kW`) dan satuan statistik (sigma) diterjemahkan oleh
`src/Web/Labels.php` — angka sigma persisnya tetap ada di *hover* tiap baris supaya setiap
nilai masih bisa ditelusuri ke barisnya, tapi bukan itu yang harus dibaca operator lebih
dulu. Ada tes yang menjaga jargon tidak bocor balik ke layar.

Sebelumnya ini tersebar di empat tab (`forecast.php`, `alarms.php`, `model.php`). Memisah
grafik dari alarm yang menilainya berarti operator harus mengklik untuk menjawab satu
pertanyaan, jadi ketiganya digabung dan tiga halaman sisanya dihapus.

Endpoint JSON di `public/api/` tetap ada kalau mau disambungkan ke SCADA atau dashboard
lain — termasuk `scorecard.php` (MASE/WQL/cov80) dan `live.php` (bacaan laju poller), yang
sekarang tidak lagi punya halaman sendiri.

---

## Tes

```bash
php tests/run.php                    # semua (butuh MySQL hidup)
php tests/run.php Cusum              # filter nama
php tests/run.php --integration      # ikut memanggil API sensor sungguhan

service/.venv/Scripts/python -m pytest service/test_service.py -q
```

213 tes PHP + 11 tes sidecar. Tes sidecar memuat bobot Chronos-2 asli, karena sifat yang
diuji — monotonisitas kuantil, ketahanan terhadap NaN, bentuk keluaran — adalah sifat
model, dan pipeline palsu tidak membuktikan apa pun tentang yang dideploy.

---

## Yang perlu diketahui sebelum percaya angkanya

**Sistem turun bertingkat, tidak mati.** Kalau sidecar mati, PHP otomatis memakai
Naive-Seasonal, menandai run sebagai `degraded`, dan dashboard memasang banner. Ambang
CUSUM ikut turun, karena §15 sudah mengukur bahwa alarm rate adalah fungsi kalibrasi model
yang dipantau — bukan konstanta.

**Batas SPC dibekukan.** Diambil dari jendela sehat 2026-05-20 → 2026-06-05 dan tidak
pernah dihitung ulang dari data berjalan. Kalau dihitung ulang, degradasi pelan menyeret
baseline-nya sendiri dan alarm tidak akan pernah bunyi.

**`INLET_PRESS` salah label di sumber.** Nilainya gigi-gergaji yang ter-reset tepat saat
tiap stoppage — pencacah, bukan tekanan. Tidak pernah diramal sebagai level. Perlu
dikonfirmasi ke instrumentasi.

**TBW2 pensiun, bukan hilang.** Berhenti permanen 2026-05-14 dan memang tidak ada di API.
UI menampilkannya sebagai *retired* supaya tidak ada yang mencari kerusakan sensor yang
tidak ada.

**Limit proyeksi masih sementara.** `config/projection_limits.php` memakai batas kendali
atas jendela sehat, bukan setelan trip pabrik. Jadi tanggalnya menjawab "kapan keluar dari
perilaku yang kita tahu sehat", bukan "kapan rusak". Ganti setelah plant mengonfirmasi.

**Tarif dan biaya outage masih placeholder** di `config/config.php`. Satuan sudah
dikonfirmasi plant (FLOWRATE m³/min menutup pertanyaan terbuka §10.2 notebook), jadi
perhitungan bisnis kini sah — tapi angka rupiahnya belum.

**90 hari lebih pendek dari satu siklus musiman.** Kenaikan suhu discharge belum bisa
dipisahkan dari musiman tahunan. Perlu 12 bulan.

**Tidak ada label kegagalan.** Stoppage disimpulkan dari motor current, jadi stop terencana
dan trip proteksi tidak terbedakan. Menyambungkan log CMMS adalah tambahan data bernilai
tertinggi yang tersedia.

---

## Struktur

```
bin/            job CLI (poll, aggregate, forecast, evaluate, seed, migrate, prune, run_all)
config/         konfigurasi, batas SPC beku, limit proyeksi
db/schema.sql   skema (idempoten)
public/         docroot XAMPP: index.php (satu halaman) + endpoint JSON + aset
service/        sidecar Chronos-2 (FastAPI) + eksportir histori
src/            Config, Db, Domain, Ingest, Grid, Physics, Forecast, EarlyWarning,
                Scoring, Repository, Web
tests/          213 tes, runner sendiri, tanpa PHPUnit
var/            file kerja (di-gitignore)
```

## Pemecahan masalah

| Gejala | Sebab & tindakan |
|---|---|
| Halaman bilang "Database tidak tersedia" | MySQL belum Start di XAMPP, atau `php bin/migrate.php` belum dijalankan |
| Banner "Mode terdegradasi" | Sidecar mati. Cek `curl http://127.0.0.1:8008/health` dan `var/sidecar.log` |
| Semua target NULL | `bin/aggregate.php` belum jalan, atau belum ada bacaan mentah |
| Forecast dilewati "thin history" | Konteks < 2 hari. Jalankan `bin/seed_history.php` |
| Papan skor kosong | Normal selama 24 jam pertama — run baru dinilai setelah jendelanya matang penuh |
| `reading_raw` membengkak | `bin/prune.php` belum dijadwalkan. Cek ukurannya dengan `bin/prune.php --dry-run` |
| Grafik live tidak bergerak | Poller mati. Cek `var/pipeline.log` dan tabel `job_run` |
| `curl error 60` saat poll | Tidak ada CA bundle. Set `TBW_API_VERIFY_TLS=false` **hanya** di jaringan tepercaya |
