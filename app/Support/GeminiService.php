<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Wrapper sederhana untuk Google Gemini (Generative Language API).
 * Pakai: app(GeminiService::class)->generateText('...')
 */
class GeminiService
{
    /**
     * Kirim prompt teks ke Gemini, kembalikan teks balasannya (atau null kalau gagal).
     *
     * @param  array{model?: string, temperature?: float, max_tokens?: int}  $options
     */
    public function generateText(string $prompt, array $options = []): ?string
    {
        $apiKey = (string) config('services.gemini.api_key');

        if ($apiKey === '') {
            Log::warning('gemini.no_api_key');

            return null;
        }

        $model = $options['model'] ?? (string) config('services.gemini.model', 'gemini-2.0-flash');

        $generationConfig = array_filter([
            'temperature' => $options['temperature'] ?? null,
            'maxOutputTokens' => $options['max_tokens'] ?? null,
        ], fn ($value) => $value !== null);

        // Model 2.5 ("thinking") memakai sebagian token output untuk berpikir.
        // Set thinking_budget=0 untuk mematikannya pada tugas singkat.
        if (array_key_exists('thinking_budget', $options)) {
            $generationConfig['thinkingConfig'] = ['thinkingBudget' => (int) $options['thinking_budget']];
        }

        $payload = [
            'contents' => [
                ['parts' => [['text' => $prompt]]],
            ],
        ];

        if ($generationConfig !== []) {
            $payload['generationConfig'] = $generationConfig;
        }

        try {
            $response = Http::timeout(30)->post(
                "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}",
                $payload,
            );
        } catch (\Throwable $e) {
            Log::error('gemini.exception', ['message' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::error('gemini.http_error', [
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            return null;
        }

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');

        return is_string($text) ? trim($text) : null;
    }

    /**
     * Contoh untuk project: buat deskripsi produk otomatis (bisa dipakai admin).
     *
     * @param  array<int, string>  $highlights
     */
    public function productDescription(string $name, ?string $category = null, array $highlights = []): ?string
    {
        $prompt = "Buatkan deskripsi produk e-commerce yang menarik dan meyakinkan dalam Bahasa Indonesia, "
            ."maksimal 3 kalimat, untuk produk pertanian berikut. Langsung tulis deskripsinya saja tanpa tanda kutip.\n\n"
            ."Nama: {$name}\n"
            .($category ? "Kategori: {$category}\n" : '')
            .($highlights !== [] ? "Keunggulan: ".implode(', ', $highlights)."\n" : '');

        // Deskripsi pendek -> matikan "thinking" biar output tidak kepotong.
        return $this->generateText($prompt, [
            'temperature' => 0.7,
            'max_tokens' => 400,
            'thinking_budget' => 0,
        ]);
    }
}
