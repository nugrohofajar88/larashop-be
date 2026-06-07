<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            'payload' => $payload,
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

        $phone = $payload['phone'] ?? $payload['sender'] ?? null;
        $message = trim((string) ($payload['message'] ?? ''));

        Log::channel('wablas')->info('wablas.message.in', [
            'phone' => $phone,
            'message' => $message,
        ]);

        // Proses pesan lewat bot tes (balasan dikirim via WhatsApp).
        if ($phone !== null && $message !== '') {
            app(\App\Support\WaBotService::class)->handle((string) $phone, $message);
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
