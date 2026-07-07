# EOS Tools

[![PHP Lint](https://github.com/notedavidrinaldi/eostools-ca/actions/workflows/php-lint.yml/badge.svg)](https://github.com/notedavidrinaldi/eostools-ca/actions/workflows/php-lint.yml)
[![Release Package](https://github.com/notedavidrinaldi/eostools-ca/actions/workflows/release.yml/badge.svg)](https://github.com/notedavidrinaldi/eostools-ca/actions/workflows/release.yml)

Super app operasional untuk:

- restart app pool IIS
- restart IIS penuh
- monitor disk `C:`
- monitor jaringan IP/domain
- command Telegram
- pencarian image backup dari share folder

## Folder utama

- Dashboard: `/eos-tools/index.php`
- Disk monitor scheduler: `/eos-tools/monitor.php?key=...`
- Telegram polling: `/eos-tools/telegram_poll.php?key=...`
- Telegram webhook: `/eos-tools/telegram_webhook.php?key=...`
- Controller relay: `/eos-tools/controller.php?key=...&cmd=status`

## Ticket Storage

Sementara ticketing masih menggunakan file log sebagai sumber utama, tanpa database:

- log utama ticket: `storage/logs/tickets.log`
- cache index ticket: `storage/state/tickets.index.json`

Model ini dibuat agar tetap ringan, tetapi pencarian harian, bulanan, dan pembacaan board tiket tetap cepat karena tidak perlu parsing ulang seluruh file log setiap kali request.

## CI

Workflow GitHub Actions tersedia di:

- `.github/workflows/php-lint.yml`
- `.github/workflows/release.yml`

Fungsinya:

- lint semua file PHP saat `push` ke `main`
- lint saat `pull_request`
- cek file inti aplikasi tersedia
- membuat GitHub Release dan file zip saat push tag `v*`

## Release

Untuk membuat release baru:

```bash
git tag v1.0.0
git push origin v1.0.0
```

Setelah itu GitHub Actions akan:

- lint ulang file PHP
- membuat archive `.zip`
- publish GitHub Release otomatis

## Konfigurasi aman

1. Duplikat `config.local.example.php` menjadi `config.local.php`
2. Isi token Telegram, chat id, password login, dan webhook key
3. Jangan simpan token produksi di `config.php`

Panduan deploy lengkap ada di:

- `DEPLOYMENT.md`

## Rekomendasi scheduler Windows

Jalankan setiap 5 atau 15 menit:

```bat
curl "http://localhost/eos-tools/monitor.php?key=GANTI_KEY"
curl "http://localhost/eos-tools/telegram_poll.php?key=GANTI_KEY"
```

## Command Telegram

- `/help`
- `/disk`
- `/network`
- `/health`
- `/ticket kendala...`
- `/ticket SITE | kendala...`
- `/tickets`
- `/ticket-report YYYY-MM`
- `/ticket-day`
- `/ticket-day YYYY-MM-DD`
- `/restart AMS`
- `/restart-group CGSIN_STACK`
- `/iis`

## Panduan Cek Via Telegram

Urutan cek yang disarankan untuk operator:

1. cek status umum
2. cek apakah ada perangkat yang offline
3. jika ada masalah, lanjut cek per gate atau per perangkat

Contoh langkah pakai:

- `@Pak_Lurah_Dapit_bot ringkas status umum`
- `@Pak_Lurah_Dapit_bot mana yang offline sekarang`
- `@Pak_Lurah_Dapit_bot kamera online semua?`
- `@Pak_Lurah_Dapit_bot barrier gate 03i online tidak`
- `@Pak_Lurah_Dapit_bot adam gate 03i online tidak`
- `@Pak_Lurah_Dapit_bot timbangan gate02o bagaimana`
- `@Pak_Lurah_Dapit_bot domain cusmod hidup tidak`
- `/network`
- `/disk`
- `/health`
- `/ticket GATE03I | barrier tidak respon`
- `/ticket-day`
- `/ticket-day 2026-07-08`
- `reply ke balasan tiket: on proses`
- `reply ke balasan tiket: done barrier normal kembali`

Catatan akses Telegram:

- semua user bisa membuat tiket dan memproses tiket via Telegram
- setelah tiket dibuat, bot akan membalas dengan ringkasan dan nomor tiket
- balas pesan bot tersebut dengan `on proses` untuk memulai penanganan
- balas lagi dengan `done catatan...` untuk menutup tiket
- saat tiket ditutup, bot akan mengirim ringkasan selesai dan lama penanganan
- command `/ticket-day` akan menampilkan jumlah open/on check/done serta ringkasan tiket harian beserta waktu penanganannya
- untuk user role `eos`, site tetap mengikuti site akun yang dikunci

Arti cepat respons bot:

- jika bot menjawab semua `online`, berarti target yang dimonitor sedang normal
- jika bot menyebut nama perangkat/gate, fokus pengecekan diarahkan ke item tersebut
- jika bot memberi status `fault` atau `offline`, cek ping, power, kabel LAN, atau koneksi perangkat
- jika bot memberi status `warning`, perangkat masih merespons tetapi perlu dipantau

Pola tanya yang paling berguna:

- cek umum: `@Pak_Lurah_Dapit_bot ringkas status umum`
- cek gangguan: `@Pak_Lurah_Dapit_bot mana yang offline sekarang`
- cek gate: `@Pak_Lurah_Dapit_bot gate 03i bagaimana`
- cek perangkat gate: `@Pak_Lurah_Dapit_bot adam gate 03i online tidak`
- cek jenis perangkat: `@Pak_Lurah_Dapit_bot kamera online semua?`

## Dashboard Run Mode

Dashboard punya toggle mode:

- `User` sebagai default
- `Server` untuk browser yang memang dibiarkan standby memproses polling bot

Perilakunya:

- mode `User`: polling Telegram otomatis per 1 menit dimatikan
- mode `User`: auto logout idle aktif
- mode `Server`: polling Telegram otomatis per 1 menit tetap aktif
- mode `Server`: auto logout dimatikan

Bot juga bisa merespons lebih natural saat:

- pesannya di-reply
- namanya disebut
- diajak chat singkat seperti sapaan atau permintaan bantuan
- ditanya status online/offline perangkat inventori seperti camera, barrier/adam, timbangan, gate tertentu, domain, atau server tertentu
- diminta ringkasan status umum atau daftar target yang sedang bermasalah

Setiap balasan interaktif Telegram juga bisa menyertakan identitas program yang menjawab, termasuk label server dan IP responder. Untuk hasil yang akurat di produksi, isi:

Contoh chat natural:

- `bot mana yang offline sekarang`
- `pak lurah ringkas status umum`
- `dapit bot barrier gate 03i online tidak`
- `@Pak_Lurah_Dapit_bot adam gate 03i online tidak`
- `pak lurah dapit timbangan gate02o bagaimana`

- `runtime.responder_label`
- `runtime.responder_ip`

di `config.local.php`.

## Checklist Ticketing

Uji minimal fitur ticketing setelah deploy:

1. login sebagai `halotec / halotec`
2. buat tiket dari web dengan jam, site, dan kendala
3. ubah tiket ke `ON CHECK` dari web
4. tutup tiket ke `DONE` dan isi catatan
5. cek report bulanan apakah durasi dan catatan tampil
6. buat satu user role `eos` dan pastikan site user terkunci
7. buat tiket dari Telegram dengan `/ticket ...`
8. reply tiket dari Telegram dengan `on proses`
9. reply lagi dengan `done catatan...`
10. cek `/ticket-day` dan pastikan waktu penanganan ikut tampil

## Controller commands

- `controller.php?key=...&cmd=status`
- `controller.php?key=...&cmd=arm`
- `controller.php?key=...&cmd=reset`
- `controller.php?key=...&cmd=fire&action=restart_pool&target=CGSIN`
- `controller.php?key=...&cmd=fire&action=restart_group&target=CGSIN_STACK`
- `controller.php?key=...&cmd=fire&action=restart_iis`
- `controller.php?key=...&cmd=fire&action=disk_report`
- `controller.php?key=...&cmd=fire&action=telegram_ping`
- `controller.php?key=...&cmd=fire&action=image_fetch&gate=GATE02I&datetime=24-02-2024%2010:16`

Alur seperti mikrocontroller:

1. `arm`
2. `fire`
3. otomatis `disarm` setelah eksekusi jika `auto_disarm_after_fire=true`

## Network Monitoring

Dashboard memantau target berikut:

- `10.15.42.34` Server Pulau Payung
- `172.27.0.21` Server Cloud
- `172.27.0.26` LB Server
- `https://cusmod-ca.multiterminal.co.id/`
- `10.116.224.48` CA Cam 3IN
- `10.116.224.48` CA Cam 3OUT

Jika salah satu target berubah status:

- `online -> offline`
- `offline -> online`

maka sistem akan kirim notifikasi perubahan ke Telegram dan mencatatnya di `network.log`.

Inventori perangkat juga ikut masuk ke monitor jaringan, sehingga status camera, barrier/adam, dan timbangan muncul di dashboard dan ikut terbaca oleh bot Telegram.

## Catatan migrasi

Folder lama berikut sudah diarahkan ke dashboard baru:

- `/eos`
- `/eos-dev`
- `/eos-panel`
- `/restartcgsin`
- `/image-container`
- `/diskspace (1)/check_disk_space`
