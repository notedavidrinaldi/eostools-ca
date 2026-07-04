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
- `/restart AMS`
- `/restart-group CGSIN_STACK`
- `/iis`

Bot juga bisa merespons lebih natural saat:

- pesannya di-reply
- namanya disebut
- diajak chat singkat seperti sapaan atau permintaan bantuan

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

## Catatan migrasi

Folder lama berikut sudah diarahkan ke dashboard baru:

- `/eos`
- `/eos-dev`
- `/eos-panel`
- `/restartcgsin`
- `/image-container`
- `/diskspace (1)/check_disk_space`
