<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Tertaut PERMANEN ke reseller yang mereferensikan user ini (first-touch
            // wins - diisi sekali via ResellerService::bindReferral, tidak pernah
            // ditimpa). nullOnDelete: kalau reseller dihapus, histori transaksi user
            // tetap aman, cuma tautannya yang lepas.
            $table->foreignId('reseller_id')->nullable()->after('role')
                ->constrained('resellers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reseller_id');
        });
    }
};
