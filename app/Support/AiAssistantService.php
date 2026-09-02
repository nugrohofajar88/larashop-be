<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Asisten AI admin (baca-saja) - jawab pertanyaan data bisnis lewat Groq
 * (tool-calling), yang menyusun & menjalankan query SQL SELECT sendiri.
 *
 * PROTEKSI BERLAPIS (independen satu sama lain, keduanya harus lolos):
 * 1. Koneksi DB 'ai_readonly' (config/database.php) - user MySQL terpisah yang
 *    HANYA punya izin SELECT ke tabel yang di-whitelist (lihat setup di bawah).
 *    Walau kode ini ada bug, database sendiri yang menolak tulis/baca tabel lain.
 * 2. Validasi SQL di runQuery() - cuma boleh 1 statement SELECT, tanpa kata
 *    kunci tulis, dan cuma menyentuh tabel di ALLOWED_TABLES.
 *
 * SETUP USER MYSQL READ-ONLY (lokal sudah dibuat, PRODUCTION PERLU DIBUAT ULANG):
 *   CREATE USER 'ai_readonly'@'localhost' IDENTIFIED BY '<password random>';
 *   GRANT SELECT ON <db>.orders TO 'ai_readonly'@'localhost';
 *   GRANT SELECT ON <db>.order_items TO 'ai_readonly'@'localhost';
 *   ... (ulangi utk semua nama di ALLOWED_TABLES, termasuk view v_users_safe)
 *   FLUSH PRIVILEGES;
 * Simpan usernamenya/passwordnya di AI_READONLY_DB_USERNAME / AI_READONLY_DB_PASSWORD.
 * Kalau hosting cPanel cuma bisa grant SELECT utk SATU DATABASE (bukan per-tabel),
 * proteksi #2 (app-level) jadi lapisan utama - jangan skip.
 */
class AiAssistantService
{
    private const ALLOWED_TABLES = [
        'orders', 'order_items', 'order_trackings', 'products', 'product_variants',
        'product_images', 'categories', 'customer_addresses', 'v_users_safe',
        'user_unique_codes', 'qris_generation_logs', 'raja_ongkir_topups', 'shipment_origins',
    ];

    private const BLOCKED_KEYWORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE', 'TRUNCATE',
        'GRANT', 'REVOKE', 'REPLACE', 'OUTFILE', 'LOAD_FILE',
    ];

    private const MAX_TOOL_ROUNDS = 4;

    private const MAX_ROWS = 200;

    public function enabled(): bool
    {
        return filled(config('services.groq.api_key'));
    }

    /** @return array{answer:string, queries:array<int,string>} */
    public function ask(string $question): array
    {
        if (! $this->enabled()) {
            return ['answer' => 'AI assistant belum dikonfigurasi (API key belum diisi).', 'queries' => []];
        }

        $messages = [
            ['role' => 'system', 'content' => file_get_contents(resource_path('ai/system-knowledge.md'))],
            ['role' => 'user', 'content' => $question],
        ];

        $tools = [[
            'type' => 'function',
            'function' => [
                'name' => 'run_readonly_query',
                'description' => 'Jalankan 1 query SQL SELECT read-only ke database toko untuk menjawab pertanyaan data.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'sql' => ['type' => 'string', 'description' => 'Query SQL SELECT tunggal'],
                    ],
                    'required' => ['sql'],
                ],
            ],
        ]];

        $executedQueries = [];

        for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
            $response = $this->chatCompletion($messages, $tools);

            if (! $response->successful()) {
                Log::error('ai_assistant.http_error', ['status' => $response->status(), 'body' => $response->body()]);

                return ['answer' => 'Maaf, ada gangguan saat menghubungi AI assistant. Coba lagi nanti.', 'queries' => $executedQueries];
            }

            $message = data_get($response->json(), 'choices.0.message', []);
            $toolCalls = $message['tool_calls'] ?? null;

            if (! $toolCalls) {
                $answer = trim((string) ($message['content'] ?? ''));

                return ['answer' => $answer !== '' ? $answer : 'Maaf, saya tidak bisa menjawab pertanyaan itu.', 'queries' => $executedQueries];
            }

            $messages[] = $message;

            foreach ($toolCalls as $toolCall) {
                $args = json_decode(data_get($toolCall, 'function.arguments', '{}'), true) ?? [];
                $sql = trim((string) ($args['sql'] ?? ''));
                if ($sql !== '') {
                    $executedQueries[] = $sql;
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'],
                    'content' => $this->runQuery($sql),
                ];
            }
        }

        return ['answer' => 'Maaf, pertanyaan ini butuh terlalu banyak langkah untuk dijawab. Coba pertanyaan yang lebih spesifik.', 'queries' => $executedQueries];
    }

    /**
     * Retry sederhana khusus 429 (rate limit) - free tier Groq mudah kena limit
     * token/menit begitu prompt+riwayat percakapan mulai panjang. Nunggu sesuai
     * hint "coba lagi dalam Xs" dari body error kalau ada, else default 3s.
     */
    private function chatCompletion(array $messages, array $tools, int $attempt = 1): \Illuminate\Http\Client\Response
    {
        $response = Http::timeout(30)
            ->withToken(config('services.groq.api_key'))
            ->post('https://api.groq.com/openai/v1/chat/completions', [
                'model' => config('services.groq.model'),
                'messages' => $messages,
                'tools' => $tools,
                'tool_choice' => 'auto',
                'temperature' => 0.2,
            ]);

        if ($response->status() === 429 && $attempt < 3) {
            $waitSeconds = 3.0;
            if (preg_match('/try again in ([\d.]+)s/i', (string) $response->body(), $m)) {
                $waitSeconds = min(10.0, (float) $m[1] + 0.5);
            }
            usleep((int) ($waitSeconds * 1_000_000));

            return $this->chatCompletion($messages, $tools, $attempt + 1);
        }

        return $response;
    }

    private function runQuery(string $sql): string
    {
        if ($sql === '' || ! preg_match('/^SELECT\s/i', $sql)) {
            return 'ERROR: hanya query SELECT yang diperbolehkan.';
        }

        $withoutTrailingSemicolon = rtrim($sql, "; \t\n\r");
        if (str_contains($withoutTrailingSemicolon, ';')) {
            return 'ERROR: hanya 1 statement SQL per query.';
        }

        foreach (self::BLOCKED_KEYWORDS as $keyword) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/i', $withoutTrailingSemicolon)) {
                return "ERROR: query mengandung kata terlarang ({$keyword}).";
            }
        }

        if (! $this->onlyReferencesAllowedTables($withoutTrailingSemicolon)) {
            return 'ERROR: query menyentuh tabel yang tidak diizinkan.';
        }

        $finalSql = $withoutTrailingSemicolon;
        if (! preg_match('/\bLIMIT\s+\d+/i', $finalSql)) {
            $finalSql .= ' LIMIT '.self::MAX_ROWS;
        }

        try {
            $rows = DB::connection('ai_readonly')->select($finalSql);
        } catch (\Throwable $e) {
            Log::warning('ai_assistant.query_error', ['sql' => $finalSql, 'message' => $e->getMessage()]);

            return 'ERROR menjalankan query: '.$e->getMessage();
        }

        if (count($rows) === 0) {
            return 'Query berhasil, tidak ada baris hasil.';
        }

        return json_encode(array_map(fn ($r): array => (array) $r, $rows), JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /** Ekstrak nama tabel setelah FROM/JOIN, cek semuanya ada di ALLOWED_TABLES. */
    private function onlyReferencesAllowedTables(string $sql): bool
    {
        preg_match_all('/\b(?:FROM|JOIN)\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i', $sql, $matches);
        $referenced = array_unique(array_map('strtolower', $matches[1] ?? []));

        if ($referenced === []) {
            return false;
        }

        $allowed = array_map('strtolower', self::ALLOWED_TABLES);

        foreach ($referenced as $table) {
            if (! in_array($table, $allowed, true)) {
                return false;
            }
        }

        return true;
    }
}
