<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\WaBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook Fonnte — pesan WhatsApp masuk dari customer.
 * Field Fonnte (incoming): sender (nomor), message (teks), url (media),
 *   filename, extension, device, group (terisi kalau dari grup).
 *
 * CATATAN: nama field di-asumsikan dari format Fonnte umum. Payload mentah
 * SELALU dicatat ke log `fonnte.webhook.received` — verifikasi dari webhook
 * pertama, sesuaikan parsing kalau ada beda.
 */
class FonnteWebhookController extends Controller
{
    public function handle(Request $request, ?string $secret = null): JsonResponse
    {
        $expected = (string) config('services.fonnte.webhook_secret');
        $provided = ($secret !== null && $secret !== '')
            ? $secret
            : (string) ($request->header('X-Webhook-Secret') ?? $request->query('secret', ''));

        if ($expected !== '' && ! hash_equals($expected, $provided)) {
            Log::channel('wablas')->warning('fonnte.webhook.unauthorized', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $payload = $request->all();
        Log::channel('wablas')->info('fonnte.webhook.received', ['payload' => $payload]);

        $phone = trim((string) ($payload['sender'] ?? ''));
        $message = trim((string) ($payload['message'] ?? ''));
        $mediaUrl = trim((string) ($payload['url'] ?? ''));
        $extension = strtolower((string) ($payload['extension'] ?? ''));
        $isGroup = trim((string) ($payload['group'] ?? '')) !== '';

        // Abaikan pesan grup atau tanpa pengirim. Balas 200 supaya Fonnte tak retry.
        if ($phone === '' || $isGroup) {
            return response()->json(['status' => 'ignored']);
        }

        $bot = app(WaBotService::class);

        // Pesan bergambar (mis. bukti transfer) -> teruskan ke admin.
        $isImage = $mediaUrl !== '' && (
            in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)
            || preg_match('/\.(jpe?g|png|webp|gif)(\?|$)/i', $mediaUrl) === 1
        );

        if ($isImage) {
            $bot->handleImage($phone, $mediaUrl, $message);

            return response()->json(['status' => 'ok']);
        }

        if ($message !== '') {
            $bot->handle($phone, $message);
        }

        return response()->json(['status' => 'ok']);
    }
}
