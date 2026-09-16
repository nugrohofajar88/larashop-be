<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raja_ongkir_withdrawals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('amount');
            // Tanggal transaksi ASLI dari file mutasi (kolom "Tanggal") - beda dari
            // created_at (waktu baris ini disinkronkan/diinsert ke DB kita).
            $table->timestamp('withdrawn_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raja_ongkir_withdrawals');
    }
};
