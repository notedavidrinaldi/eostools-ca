# EOS Tools

Super app operasional untuk:

- restart app pool IIS
- restart IIS penuh
- monitor disk `C:`
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

Fungsinya:

- lint semua file PHP saat `push` ke `main`
- lint saat `pull_request`
- cek file inti aplikasi tersedia

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
- `/health`
- `/restart AMS`
- `/restart-group CGSIN_STACK`
- `/iis`

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

## Catatan migrasi

Folder lama berikut sudah diarahkan ke dashboard baru:

- `/eos`
- `/eos-dev`
- `/eos-panel`
- `/restartcgsin`
- `/image-container`
- `/diskspace (1)/check_disk_space`
