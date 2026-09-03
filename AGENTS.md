# AGENTS.md — larashop-be (Backend API)

Backend **headless REST API** untuk toko "Akar Tani Kimia" (UMKM agrokimia, Indonesia).
Pasangannya: **larashop-fe** (storefront + panel admin, BFF yang memanggil API ini).

- Stack: **Laravel 12, PHP 8.2, MySQL, Sanctum** (token auth).
- Produksi: `https://be.akartanikimia.com`. Frontend: `https://akartanikimia.com`.
- Semua endpoint di bawah prefix **`/api/v1`**. Admin di `/api/v1/admin/*` (auth:sanctum + role `admin`).

## Menjalankan
```bash
php artisan serve --port=8001     # FE default menunjuk ke localhost:8001
php artisan migrate               # + php artisan db:seed untuk data awal
php -l <file>                     # lint cepat
```
Setelah ubah `.env`: **`php artisan config:clear`** (produksi sering cache config).
Host produksi **mematikan `shell_exec`** → hindari proc terminal, pakai pendekatan pure-PHP (mis. dump DB via `ifsnop/mysqldump`).

## Arsitektur & konvensi
- **`app/Support/ApiData.php`** = sumber tunggal bentuk JSON respons (`ApiData::product`, `order`, `productVariant`, dst). Ubah bentuk data di sini, bukan di tiap controller.
- **Service (`app/Support/`)** memuat logika integrasi:
  - `ShippingService` — cari wilayah & hitung ongkir (Komerce Collaborator, header `x-api-key`); **tracking** (`trackWaybill`, RajaOngkir, header `key`).
  - `KomerceShipmentService` — booking order, pickup, print-label, **cancel** ke Komerce.
  - `QrislyService` — QRIS dinamis (QRISLY).
  - `WaBotService` + `WaOrderService` — bot WhatsApp.
  - `StockService` — reserve/release stok.
  - `OrderPaymentService` — tandai lunas.
- Controller tipis; taruh logika di service/model.

## Model data (PENTING)
- **Varian = sumber kebenaran** harga/stok/berat/dimensi. Tabel `products` **tidak punya** kolom price/stock/weight/dims (sudah di-drop) — `Product` punya **accessor** yang menurunkannya dari `defaultVariant()`.
- `order_items` menyimpan **snapshot** (`product_name`, `product_sku`, `variant_label`, `price`, `subtotal`) dengan FK `product_id`/`product_variant_id` = **`nullOnDelete`** → riwayat order aman walau produk/varian dihapus.
- **SoftDeletes** di `Product` & `ProductVariant`.
  - Hapus produk: **soft** kalau pernah diorder (order non-draft), **hard** (force + cascade varian/gambar) kalau belum. Lihat `AdminProductController::destroy`.
  - `syncVariants` = **UPSERT by id** (id varian STABIL; jangan kembali ke delete-all-recreate — itu memutus tautan `order_items` tiap edit). Varian yang dihapus di editor → soft kalau diorder, else hard; SKU ter-arsip dipulihkan (restore) saat di-add lagi. Editor FE mengirim `variants.*.id`.
- Kode order: **`ATK{YYYYMMDD}{urut-5-digit-bulanan}`**, dibuat race-safe pakai `retry()` (unik).
- **Stok reserve-at-order**: `StockService` decrement atomik `variant.stock` (`WHERE stock >= qty`) + `order_items.reserved_qty`. Order `pending_payment` > 24 jam dibatalkan otomatis (command `orders:expire-unpaid`) → stok dikembalikan.

## Integrasi eksternal (dua Komerce API, dua key BERBEDA)
- **Komerce Collaborator (delivery)** — header **`x-api-key`** = `KOMERCE_DELIVERY_API_KEY`. Base prod `https://api.collaborator.komerce.id` (sandbox `api-sandbox.collaborator.komerce.id`). Dipakai: search wilayah, calculate ongkir, store order, pickup, print-label, cancel. **QRISLY pakai key & base ini juga.**
- **RajaOngkir (tracking + shipping cost)** — header **`key`** = `RAJAONGKIR_API_KEY`, host `https://rajaongkir.komerce.id/api/v1` (produksi; tak ada sandbox). Endpoint `track/waybill?awb=&courier=&last_phone_number=` (courier huruf kecil; map "IDEXPRESS"→`ide`).
- AWB terbit lewat **pickup** (produksi). Di sandbox hanya kurir tertentu (JNT EZ / Ninja) yang menerbitkan AWB; `create order` memvalidasi `shipping_cost` **dan** `shipping_cashback` harus = hasil calculate.
- **QRIS (QRISLY)**: qris_id sumbernya `PaymentQris::active()` (upload via admin) → `Setting('qrisly_qris_id')` → `.env`. Sandbox qris_id = angka; produksi juga bisa angka (jangan asumsikan UUID).
- **WhatsApp**: bisa Wablas⇄Fonnte via `WHATSAPP_DRIVER`. **Gotcha Wablas**: field `phone`/`sender` tidak konsisten antar versi → balas ke nomor yang **BUKAN** `store_whatsapp` (Setting). Secret webhook **kita generate sendiri**.

## Webhook (secret di PATH, kita yang generate)
- Delivery: `POST /api/v1/webhooks/komerce/{KOMERCE_WEBHOOK_SECRET}` → update timeline order.
- QRISLY: `POST /api/v1/webhooks/qrisly/{KOMERCE_QRISLY_WEBHOOK_SECRET}` (tab **Outbound Webhook** di dashboard).
- Wablas: `POST /api/v1/webhooks/wablas?secret=WABLAS_WEBHOOK_SECRET` (secret via query/header).
- Webhook masuk di-**dedup** via `Cache::add`.

## Scheduler (butuh cron di hosting)
`routes/console.php`: `orders:expire-unpaid` (hourly), `db:backup` (harian 02:00 → Google Drive `GOOGLE_DRIVE_*`).
Wajib cron OS: **`* * * * * php artisan schedule:run`** (tiap menit; jadwal detail diatur Laravel).

## Ide fitur (belum dikerjakan)
- **Flag "toko libur"**: `Setting` key baru `store_is_holiday` (bool) + `holiday_message` (text, custom) + `operating_hours` (text, jam operasional statis). Pola sama persis dgn `unique_code_enabled`/`payment_qris_enabled` yang sudah ada di `AdminController::updateStoreSettings` (larashop-fe) - tinggal tambah field, tanpa migrasi (Setting key-value).
  - Kalau `store_is_holiday` true, sisipkan catatan libur ke pesan konfirmasi order WA: `WaOrderService::orderConfirmation()` / `orderConfirmationCod()` / `orderConfirmationQris()`.
  - `operating_hours` ditampilkan statis di homepage (larashop-fe) buat info jam & hari buka.
  - Estimasi: kecil-menengah, ~6-10 file di kedua repo, semua edit aditif (reuse Setting + form settings yang sudah ada), gak ada migrasi DB.

## Deploy
`git pull` → `php artisan migrate` (kalau ada migrasi) → `php artisan config:clear`.
Produksi: `APP_ENV=production`, `APP_DEBUG=false`, key/base URL Komerce **produksi**.
`.env` gitignored — jangan commit secret; kalau perlu berbagi, sensor nilainya.
