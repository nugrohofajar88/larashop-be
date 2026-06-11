<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * QRISLY (Komerce) — pembayaran QRIS dinamis.
 *   - Upload QRIS statis (sekali): POST {base}/user/api/v1/qrisly/upload-qris  -> qris_id
 *   - Generate QRIS dinamis:       POST {base}/user/api/v1/qrisly/generate-qris (Rp100/generate!)
 *   - Cek status (gratis):         GET  {base}/user/api/v1/qrisly/payment-status/{history_id}
 * Auth: header x-api-key (sama dengan komerce_delivery). Base URL juga sama.
 */
class QrislyService
{
    public function enabled(): bool
    {
        return (bool) config('services.qrisly.enabled')
            && trim((string) config('services.komerce_delivery.api_key')) !== '';
    }

    /** API asli pakai `meta.status: success`; docs menyebut `success: true` — terima keduanya. */
    protected function isSuccess(array $body): bool
    {
        return ($body['success'] ?? false) === true
            || ($body['meta']['status'] ?? '') === 'success';
    }

    /** qris_id aktif — dari tabel payment_qris, fallback Setting, fallback .env. */
    public function qrisId(): string
    {
        $active = \App\Models\PaymentQris::active();
        if ($active !== null && trim((string) $active->qris_id) !== '') {
            return (string) $active->qris_id;
        }

        $fromSetting = trim((string) Setting::get('qrisly_qris_id', ''));

        return $fromSetting !== '' ? $fromSetting : trim((string) config('services.qrisly.qris_id'));
    }

    protected function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders(['x-api-key' => (string) config('services.komerce_delivery.api_key')])
            ->baseUrl(rtrim((string) config('services.komerce_delivery.base_url'), '/'));
    }

    /**
     * Upload QRIS statis (sekali setup). Simpan qris_id ke Setting kalau sukses.
     *
     * @return array{ok:bool, qris_id?:string, data?:array, message?:string}
     */
    public function uploadQris(string $absolutePath, string $name): array
    {
        if (! is_file($absolutePath)) {
            return ['ok' => false, 'message' => 'File QRIS tidak ditemukan.'];
        }

        try {
            $resp = $this->http()
                ->attach('qris_image', (string) file_get_contents($absolutePath), basename($absolutePath))
                ->post('/user/api/v1/qrisly/upload-qris', ['name' => $name]);
        } catch (\Throwable $e) {
            Log::error('qrisly.upload.exception', ['error' => $e->getMessage()]);

            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $body = $resp->json() ?? [];

        // API asli pakai meta.status; docs menyebut `success` boolean — terima keduanya.
        if (! $resp->successful() || ! $this->isSuccess($body)) {
            return ['ok' => false, 'message' => (string) ($body['message'] ?? $body['meta']['message'] ?? 'Upload QRIS gagal.'), 'response' => $body];
        }

        $data = (array) ($body['data'] ?? []);
        $qrisId = (string) ($data['qris_id'] ?? '');

        if ($qrisId !== '') {
            Setting::set('qrisly_qris_id', $qrisId); // back-compat

            // Simpan ke tabel + jadikan AKTIF (nonaktifkan yang lain).
            \App\Models\PaymentQris::query()->where('is_active', true)->update(['is_active' => false]);
            \App\Models\PaymentQris::updateOrCreate(
                ['qris_id' => $qrisId],
                [
                    'name' => (string) ($data['name'] ?? $name),
                    'merchant_name' => (string) ($data['merchant_name'] ?? ''),
                    'provider' => (string) ($data['provider'] ?? ''),
                    'is_active' => true,
                ],
            );
        }

        return ['ok' => true, 'qris_id' => $qrisId, 'data' => $data];
    }

    /**
     * Generate QRIS dinamis (kena charge Rp100). Pakai generateForOrder() utk hemat.
     *
     * @return array{ok:bool, history_id?:string, qris_string?:string, original_amount?:int, final_amount?:int, payment_status?:string, expiry_time?:string, message?:string}
     */
    public function generateQris(int $amount, string $outputType = 'string', bool $uniqueAmount = true): array
    {
        $qrisId = $this->qrisId();
        if ($qrisId === '') {
            return ['ok' => false, 'message' => 'qris_id belum ada. Upload QRIS statis dulu.'];
        }
        if ($amount < 1000) {
            return ['ok' => false, 'message' => 'Nominal minimal Rp1.000.'];
        }

        // Sandbox minta qris_id INT; produksi (docs) pakai UUID string. Kirim sesuai bentuk.
        $qrisIdValue = ctype_digit($qrisId) ? (int) $qrisId : $qrisId;

        try {
            $resp = $this->http()->asJson()->post('/user/api/v1/qrisly/generate-qris', [
                'qris_id' => $qrisIdValue,
                'amount' => $amount,
                'output_type' => $outputType,
                'unique_amount' => $uniqueAmount,
            ]);
        } catch (\Throwable $e) {
            Log::error('qrisly.generate.exception', ['error' => $e->getMessage()]);

            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $body = $resp->json() ?? [];

        if (! $resp->successful() || ! $this->isSuccess($body)) {
            Log::warning('qrisly.generate.failed', ['status' => $resp->status(), 'body' => $body]);

            return ['ok' => false, 'message' => (string) ($body['message'] ?? $body['meta']['message'] ?? 'Generate QRIS gagal.'), 'response' => $body];
        }

        $d = (array) ($body['data'] ?? []);

        return [
            'ok' => true,
            'history_id' => (string) ($d['history_id'] ?? ''),
            'qris_string' => (string) ($d['qris_string'] ?? ''),
            'original_amount' => (int) ($d['original_amount'] ?? $amount),
            'final_amount' => (int) ($d['final_amount'] ?? $amount),
            'payment_status' => (string) ($d['payment_status'] ?? 'unpaid'),
            'expiry_time' => (string) ($d['expiry_time'] ?? ''),
        ];
    }

    /**
     * Cek status pembayaran (GRATIS, aman dipanggil berkali-kali). Fallback webhook.
     *
     * @return array{ok:bool, payment_status?:string, amount?:int, paid_at?:?string, message?:string}
     */
    public function paymentStatus(string $historyId): array
    {
        if (trim($historyId) === '') {
            return ['ok' => false, 'message' => 'history_id kosong.'];
        }

        try {
            $resp = $this->http()->get('/user/api/v1/qrisly/payment-status/'.urlencode($historyId));
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $body = $resp->json() ?? [];

        if (! $resp->successful() || ($body['meta']['status'] ?? '') !== 'success') {
            return ['ok' => false, 'message' => (string) ($body['meta']['message'] ?? 'Cek status gagal.'), 'response' => $body];
        }

        $d = (array) ($body['data'] ?? []);

        return [
            'ok' => true,
            'payment_status' => (string) ($d['payment_status'] ?? ''),
            'amount' => (int) ($d['amount'] ?? 0),
            'paid_at' => $d['paid_at'] ?? null,
        ];
    }

    /**
     * Generate QRIS untuk sebuah order — REUSE kalau masih valid & belum expired
     * (hemat Rp100/generate). Simpan hasil ke kolom qris_* order.
     *
     * @return array{ok:bool, reused?:bool, history_id?:string, qris_string?:string, final_amount?:int, expired_at?:?Carbon, message?:string}
     */
    public function generateForOrder(Order $order, bool $force = false): array
    {
        if (! $force
            && trim((string) $order->qris_string) !== ''
            && $order->qris_expired_at !== null
            && $order->qris_expired_at->isFuture()
            && $order->qris_status !== 'paid'
        ) {
            return [
                'ok' => true,
                'reused' => true,
                'history_id' => (string) $order->qris_history_id,
                'qris_string' => (string) $order->qris_string,
                'final_amount' => (int) $order->qris_amount,
                'expired_at' => $order->qris_expired_at,
            ];
        }

        $result = $this->generateQris((int) $order->grand_total);
        if (! ($result['ok'] ?? false)) {
            return $result;
        }

        $expiry = null;
        if (($result['expiry_time'] ?? '') !== '') {
            try {
                $expiry = Carbon::parse($result['expiry_time'], 'Asia/Jakarta');
            } catch (\Throwable $e) {
                $expiry = null;
            }
        }

        $order->update([
            'qris_history_id' => $result['history_id'],
            'qris_amount' => $result['final_amount'],
            'qris_string' => $result['qris_string'],
            'qris_status' => $result['payment_status'] ?? 'unpaid',
            'qris_expired_at' => $expiry,
        ]);

        return [
            'ok' => true,
            'reused' => false,
            'history_id' => (string) $result['history_id'],
            'qris_string' => (string) $result['qris_string'],
            'final_amount' => (int) $result['final_amount'],
            'expired_at' => $expiry,
        ];
    }

    /**
     * Render qris_string order jadi gambar QR PNG (publik, tanpa auth) lalu kembalikan
     * URL-nya. Dipakai utk <img> di web & dikirim via WhatsApp (Wablas sendImage).
     * File disimpan di public/qris/qris-{token}.png (token tak bisa ditebak).
     */
    public function qrImagePublicUrl(Order $order): string
    {
        $qris = trim((string) $order->qris_string);
        if ($qris === '') {
            return '';
        }

        $token = substr(hash('sha256', $order->code.'|'.$order->qris_history_id.'|'.(string) config('app.key')), 0, 24);
        $filename = 'qris-'.$token.'.png';
        $dir = public_path('qris');
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $path = $dir.DIRECTORY_SEPARATOR.$filename;

        if (! is_file($path)) {
            try {
                (new \Endroid\QrCode\Builder\Builder(
                    writer: new \Endroid\QrCode\Writer\PngWriter(),
                    data: $qris,
                    errorCorrectionLevel: \Endroid\QrCode\ErrorCorrectionLevel::Medium,
                    size: 420,
                    margin: 16,
                ))->build()->saveToFile($path);
            } catch (\Throwable $e) {
                Log::error('qrisly.qr_render.exception', ['order' => $order->code, 'error' => $e->getMessage()]);

                return '';
            }
        }

        return rtrim((string) config('app.url'), '/').'/qris/'.$filename;
    }

    /** QR sebagai data-URI base64 (untuk <img> di web — tanpa file/URL). */
    public function qrImageDataUri(Order $order): string
    {
        $qris = trim((string) $order->qris_string);
        if ($qris === '') {
            return '';
        }

        try {
            return (new \Endroid\QrCode\Builder\Builder(
                writer: new \Endroid\QrCode\Writer\PngWriter(),
                data: $qris,
                errorCorrectionLevel: \Endroid\QrCode\ErrorCorrectionLevel::Medium,
                size: 420,
                margin: 16,
            ))->build()->getDataUri();
        } catch (\Throwable $e) {
            Log::error('qrisly.qr_datauri.exception', ['order' => $order->code, 'error' => $e->getMessage()]);

            return '';
        }
    }
}
