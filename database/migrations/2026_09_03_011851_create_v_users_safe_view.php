<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * View "aman" utk tabel users - dipakai AI assistant (baca-saja) supaya
     * kolom password/remember_token TIDAK PERNAH bisa terbaca lewat query apa
     * pun, bahkan kalau suatu saat proteksi tabel-allowlist di level aplikasi
     * bocor/gagal. Defense-in-depth, bukan satu-satunya proteksi.
     */
    public function up(): void
    {
        // "CREATE OR REPLACE VIEW" itu sintaks khusus MySQL - SQLite (dipakai test
        // suite, lihat phpunit.xml) tidak support klausa OR REPLACE. Skip di driver
        // lain - view ini cuma dipakai AiAssistantService lewat koneksi MySQL nyata.
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('
            CREATE OR REPLACE VIEW v_users_safe AS
            SELECT id, code, name, username, email, phone, role, admin_role, status,
                   email_verified_at, last_login_at, created_at, updated_at
            FROM users
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_users_safe');
    }
};
