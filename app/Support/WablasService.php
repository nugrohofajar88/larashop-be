<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WablasService
{
    /**
     * Kirim pesan WhatsApp via Wablas.
     *
     * @param  string  $phone    Nomor tujuan (mis. 628123456789)
     * @param  string  $message  Isi pesan
     * @return bool  true kalau Wablas membalas sukses
     */
    public function sendMessage(string $phone, string $message): bool
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
                ->post($base.'/api/send-message', [
                    'phone' => $phone,
                    'message' => $message,
                ]);
        } catch (\Throwable $e) {
            Log::channel('wablas')->error('wablas.send.exception', [
                'phone' => $phone,
                'message' => $e->getMessage(),
            ]);

            return false;
        }

        $ok = $response->successful() && (bool) data_get($response->json(), 'status', false);

        Log::channel('wablas')->info('wablas.send', [
            'phone' => $phone,
            'http' => $response->status(),
            'ok' => $ok,
            'response' => $response->json() ?? $response->body(),
        ]);

        return $ok;
    }
}
