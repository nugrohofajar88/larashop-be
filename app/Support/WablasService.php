<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WablasService
{
    /**
     * Kirim pesan teks WhatsApp via Wablas.
     */
    public function sendMessage(string $phone, string $message): bool
    {
        return $this->send('/api/send-message', ['phone' => $phone, 'message' => $message], $phone);
    }

    /**
     * Kirim gambar (dari URL) via Wablas. Dipakai mis. meneruskan bukti transfer.
     */
    public function sendImage(string $phone, string $imageUrl, string $caption = ''): bool
    {
        return $this->send('/api/send-image', [
            'phone' => $phone,
            'image' => $imageUrl,
            'caption' => $caption,
        ], $phone);
    }

    protected function send(string $endpoint, array $payload, string $phone): bool
    {
        $base = rtrim((string) config('services.wablas.base_url'), '/');
        $token = (string) config('services.wablas.token');
        $secret = (string) config('services.wablas.secret_key');

        if ($token === '') {
            Log::channel('wablas')->error('wablas.send.no_token');

            return false;
        }

        // Wablas v2: Authorization = "{token}.{secret_key}"; v1: cukup "{token}".
        $authorization = $secret !== '' ? $token.'.'.$secret : $token;

        try {
            $response = Http::withHeaders(['Authorization' => $authorization])
                ->asForm()
                ->timeout(15)
                ->post($base.$endpoint, $payload);
        } catch (\Throwable $e) {
            Log::channel('wablas')->error('wablas.send.exception', [
                'phone' => $phone,
                'endpoint' => $endpoint,
                'message' => $e->getMessage(),
            ]);

            return false;
        }

        $ok = $response->successful() && (bool) data_get($response->json(), 'status', false);

        Log::channel('wablas')->info('wablas.send', [
            'phone' => $phone,
            'endpoint' => $endpoint,
            'http' => $response->status(),
            'ok' => $ok,
            'response' => $response->json() ?? $response->body(),
        ]);

        return $ok;
    }
}
