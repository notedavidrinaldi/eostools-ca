# Deployment Guide

Panduan ini ditujukan untuk server XAMPP / Apache / PHP yang menjalankan `EOS Tools` sebagai control board operasional.

## 1. Clone repo

```bash
git clone git@github.com:notedavidrinaldi/eostools-ca.git
```

Atau pull update terbaru:

```bash
git pull origin main
```

## 2. Tempatkan di web root

Contoh Windows XAMPP:

```text
C:\xampp\htdocs\eos-tools
```

## 3. Buat konfigurasi lokal

Salin:

```text
config.local.example.php
```

menjadi:

```text
config.local.php
```

Lalu isi minimal:

- user login
- token bot Telegram
- chat id Telegram
- webhook key
- path share image backup bila berbeda

## 4. Pastikan folder runtime bisa ditulis

Folder yang harus writable:

```text
storage/cache
storage/logs
storage/state
```

## 5. Uji manual

Endpoint utama:

- `/eos-tools/index.php`
- `/eos-tools/monitor.php?key=...`
- `/eos-tools/telegram_poll.php?key=...`
- `/eos-tools/telegram_webhook.php?key=...`
- `/eos-tools/controller.php?key=...&cmd=status`

Checklist cepat setelah deploy:

- login ke dashboard berhasil
- tabel inventori menampilkan status `ONLINE/OFFLINE` untuk camera, barrier/adam, dan timbangan
- `NET BUS` menampilkan jumlah target online yang sesuai
- toggle `Run Mode` muncul di dashboard
- mode `User` menjadi default
- saat mode `User`, status Telegram poll menunjukkan `USER MODE`
- saat mode `Server`, Telegram poll tetap berjalan tiap 1 menit
- saat mode `Server`, bot tetap membalas dan kiriman Telegram tetap masuk

Checklist chat bot:

- `@boot ringkas status umum`
- `@boot mana yang offline sekarang`
- `@boot kamera online semua?`
- `@boot barrier gate 03i online tidak`
- `@boot adam gate 03i online tidak`
- `@boot timbangan gate02o bagaimana`

## 6. Scheduler Windows

Jalankan periodik lewat Task Scheduler:

```bat
curl "http://localhost/eos-tools/monitor.php?key=GANTI_KEY"
curl "http://localhost/eos-tools/telegram_poll.php?key=GANTI_KEY"
```

Rekomendasi:

- `monitor.php`: setiap 5 atau 15 menit
- `telegram_poll.php`: setiap 1 menit

Catatan mode dashboard:

- browser operator biasa sebaiknya dibiarkan di mode `User`
- browser khusus monitor server bisa di-set ke mode `Server`
- mode `Server` cocok untuk mesin yang memang disiapkan menerima polling Telegram terus-menerus

## 7. Alur controller mode

Urutan seperti mikrocontroller relay:

1. `arm`
2. `fire`
3. auto `disarm`

Contoh:

```text
/eos-tools/controller.php?key=GANTI_KEY&cmd=arm
/eos-tools/controller.php?key=GANTI_KEY&cmd=fire&action=restart_pool&target=CGSIN
```

## 8. Update aplikasi

Saat ada perubahan baru:

```bash
git pull origin main
```

Jika perlu, restart Apache / XAMPP setelah update.
