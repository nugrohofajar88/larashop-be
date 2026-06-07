<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Wrapper sederhana untuk OpenAI (Chat Completions API).
 * Interface-nya sengaja dibuat sama dengan GeminiService supaya mudah ditukar.
 * Pakai: app(OpenAIService::class)->generateText('...')
 */
class OpenAIService
{
    /**
     * Kirim prompt ke OpenAI, kembalikan teks balasannya (atau null kalau gagal).
     *
     * @param  array{model?: string, temperature?: float, max_tokens?: int, system?: string}  $options
     */
    public function generateText(string $prompt, array $options = []): ?string
    {
        $apiKey = (string) config('services.openai.api_key');

        if ($apiKey === '') {
            Log::warning('openai.no_api_key');

            return null;
        }

        $model = $options['model'] ?? (string) config('services.openai.model', 'gpt-4o-mini');

        $messages = [];

        if (! empty($options['system'])) {
            $messages[] = ['role' => 'system', 'content' => $options['system']];
        }

        $messages[] = ['role' => 'user', 'content' => $prompt];

        $payload = array_filter([
            'model' => $model,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? null,
            'max_tokens' => $options['max_tokens'] ?? null,
        ], fn ($value) => $value !== null);

        try {
            $response = Http::withToken($apiKey)
                ->timeout(30)
                ->post('https://api.openai.com/v1/chat/completions', $payload);
        } catch (\Throwable $e) {
            Log::error('openai.exception', ['message' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::error('openai.http_error', [
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            return null;
        }

        $text = data_get($response->json(), 'choices.0.message.content');

        return is_string($text) ? trim($text) : null;
    }

    /**
     * Contoh untuk project: buat deskripsi produk otomatis (bisa dipakai admin).
     *
     * @param  array<int, string>  $highlights
     */
    public function productDescription(string $name, ?string $category = null, array $highlights = []): ?string
    {
        $prompt = "Nama: {$name}\n"
            .($category ? "Kategori: {$category}\n" : '')
            .($highlights !== [] ? "Keunggulan: ".implode(', ', $highlights)."\n" : '');

        return $this->generateText($prompt, [
            'system' => 'Kamu copywriter e-commerce produk pertanian. Buat deskripsi menarik & meyakinkan '
                .'dalam Bahasa Indonesia, maksimal 3 kalimat. Langsung tulis deskripsinya saja tanpa tanda kutip.',
            'temperature' => 0.7,
            'max_tokens' => 256,
        ]);
    }
}
