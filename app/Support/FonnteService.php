<?php

namespace App\Support;

use App\Support\Contracts\WhatsappGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gateway WhatsApp via Fonnte (fonnte.com).
 *   - Kirim: POST {base}/send  (form: target, message, url?, filename?)
 *   - Auth: header Authorization = token device.
 *   - Sukses: response JSON status=true.
 */
class FonnteService implements WhatsappGateway
{
    public function sendMessage(string $phone, string $message): bool
    {
        return $this->send([
            'target' => $this->normalize($phone),
            'message' => $message,
        ], $phone);
    }

    public function sendImage(string $phone, string $imageUrl, string $caption = ''): bool
    {
        // Fonnte kirim media via `url`; `message` jadi caption.
        return $this->send([
            'target' => $this->normalize($phone),
            'message' => $caption,
            'url' => $imageUrl,
        ], $phone);
    }

    protected function send(array $payload, string $phone): bool
    {
        $base = rtrim((string) config('services.fonnte.base_url', 'https://api.fonnte.com'), '/');
        $token = (string) config('services.fonnte.token');

        if ($token === '') {
            Log::channel('wablas')->error('fonnte.send.no_token');

            return false;
        }

        try {
            $response = Http::withHeaders(['Authorization' => $token])
                ->asForm()
                ->timeout(15)
                ->post($base.'/send', $payload);
        } catch (\Throwable $e) {
            Log::channel('wablas')->error('fonnte.send.exception', [
                'phone' => $phone,
                'message' => $e->getMessage(),
            ]);

            return false;
        }

        $ok = $response->successful() && (bool) data_get($response->json(), 'status', false);

        Log::channel('wablas')->info('fonnte.send', [
            'phone' => $phone,
            'http' => $response->status(),
            'ok' => $ok,
            'response' => $response->json() ?? $response->body(),
        ]);

        return $ok;
    }

    /** Fonnte terima 08xx maupun 62xx; normalkan ke 62 utk konsisten. */
    protected function normalize(string $phone): string
    {
        $p = preg_replace('/[^0-9]/', '', $phone) ?? '';
        if (str_starts_with($p, '0')) {
            $p = '62'.substr($p, 1);
        }

        return $p;
    }
}
