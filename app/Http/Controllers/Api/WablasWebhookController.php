<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WablasWebhookController extends Controller
{
    /**
     * Terima panggilan webhook dari Wablas (pesan masuk / update status pesan).
     *
     * Wablas memanggil URL ini setiap ada event. Kita verifikasi secret,
     * catat payload ke log khusus `wablas`, lalu arahkan ke handler sesuai
     * jenis event. Selalu balas 200 cepat supaya Wablas tidak retry.
     */
    public function handle(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            Log::channel('wablas')->warning('wablas.webhook.unauthorized', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $payload = $request->all();
        $event = $this->detectEvent($payload);

        Log::channel('wablas')->info('wablas.webhook.received', [
            'event' => $event,
            'payload' => Arr::except($payload, ['secret']),
        ]);

        match ($event) {
            'message' => $this->handleIncomingMessage($payload),
            'status' => $this->handleStatusUpdate($payload),
            default => null,
        };

        return response()->json(['status' => 'ok']);
    }

    /**
     * Verifikasi secret webhook (kalau di-set). Wablas tidak menandatangani
     * request, jadi kita pakai shared secret via header atau query string.
     * Kalau WABLAS_WEBHOOK_SECRET kosong, request diterima (mode dev).
     */
    /** Normalisasi nomor ke digit dengan awalan 62 (mis. "0857.." -> "62857..."). */
    protected function normalizeNumber($raw): string
    {
        $digits = preg_replace('/\D/', '', (string) $raw) ?? '';

        return $digits !== '' && str_starts_with($digits, '0') ? '62'.substr($digits, 1) : $digits;
    }

    protected function isAuthorized(Request $request): bool
    {
        $secret = (string) config('services.wablas.webhook_secret');

        if ($secret === '') {
            return true;
        }

        $provided = (string) ($request->header('X-Webhook-Secret')
            ?? $request->query('secret', ''));

        return $provided !== '' && hash_equals($secret, $provided);
    }

    /**
     * Tebak jenis event dari bentuk payload Wablas.
     */
    protected function detectEvent(array $payload): string
    {
        if (array_key_exists('message', $payload) || array_key_exists('messageType', $payload)) {
            return 'message';
        }

        if (array_key_exists('status', $payload)) {
            return 'status';
        }

        return 'unknown';
    }

    /**
     * Pesan WhatsApp masuk dari customer.
     * TODO: tentukan aksinya (auto-reply, cek status order, simpan, dsb).
     */
    protected function handleIncomingMessage(array $payload): void
    {
        // Abaikan pesan dari device sendiri (cegah loop auto-reply) & pesan grup.
        $isFromMe = filter_var($payload['isFromMe'] ?? false, FILTER_VALIDATE_BOOL);
        $isGroup = filter_var($payload['isGroup'] ?? false, FILTER_VALIDATE_BOOL);

        if ($isFromMe || $isGroup) {
            Log::channel('wablas')->info('wablas.message.ignored', [
                'reason' => $isFromMe ? 'from_me' : 'group',
                'phone' => $payload['phone'] ?? null,
            ]);

            return;
        }

        // Balas ke PELANGGAN. Wablas tidak konsisten menaruh nomor pelanggan di
        // `phone` atau `sender` antar versi/device, jadi jangan andalkan nama field:
        // pilih nomor yang BUKAN nomor WA toko. Kalau store_whatsapp belum di-set,
        // fallback ke `phone` lalu `sender`.
        $storeWa = $this->normalizeNumber(Setting::get('store_whatsapp', ''));
        $candidates = array_values(array_unique(array_filter([
            $this->normalizeNumber($payload['phone'] ?? ''),
            $this->normalizeNumber($payload['sender'] ?? ''),
        ])));

        $phone = null;
        foreach ($candidates as $candidate) {
            if ($storeWa === '' || $candidate !== $storeWa) {
                $phone = $candidate;
                break;
            }
        }
        $phone = $phone ?: ($candidates[0] ?? null);
        $message = trim((string) ($payload['message'] ?? ''));
        $messageType = strtolower((string) ($payload['messageType'] ?? 'text'));
        $mediaUrl = trim((string) ($payload['url'] ?? $payload['image'] ?? $payload['file'] ?? $payload['media'] ?? ''));

        Log::channel('wablas')->info('wablas.message.in', [
            'phone' => $phone,
            'type' => $messageType,
            'message' => $message,
            'media' => $mediaUrl !== '' ? $mediaUrl : null,
        ]);

        if ($phone === null) {
            return;
        }

        // Dedup: Wablas bisa mengirim webhook yang SAMA berkali-kali (retry saat balasan
        // kita lambat — mis. generate QRIS + kirim 2 pesan). Tanpa ini, pesan "ya" bisa
        // diproses 2x → order dobel. Proses tiap pesan sekali saja (atomik via Cache::add).
        $messageId = trim((string) ($payload['id'] ?? ''));
        $dedupKey = 'wablas:msg:'.($messageId !== ''
            ? $messageId
            : md5($phone.'|'.$messageType.'|'.$message.'|'.$mediaUrl));

        if (! Cache::add($dedupKey, 1, now()->addMinutes(3))) {
            Log::channel('wablas')->info('wablas.message.duplicate_skipped', [
                'phone' => $phone,
                'id' => $messageId !== '' ? $messageId : null,
            ]);

            return;
        }

        $bot = app(\App\Support\WaBotService::class);

        // Pesan bergambar (mis. bukti transfer) -> tangani khusus (teruskan ke admin).
        if (str_contains($messageType, 'image')
            || ($mediaUrl !== '' && preg_match('/\.(jpe?g|png|webp|gif)(\?|$)/i', $mediaUrl) === 1)) {
            $bot->handleImage((string) $phone, $mediaUrl, $message);

            return;
        }

        // Pesan teks.
        if ($message !== '') {
            $bot->handle((string) $phone, $message);
        }
    }

    /**
     * Update status pesan keluar (sent / delivered / read).
     * TODO: simpan status kalau diperlukan untuk pelacakan notifikasi.
     */
    protected function handleStatusUpdate(array $payload): void
    {
        Log::channel('wablas')->info('wablas.status.update', [
            'id' => $payload['id'] ?? null,
            'status' => $payload['status'] ?? null,
        ]);
    }
}
