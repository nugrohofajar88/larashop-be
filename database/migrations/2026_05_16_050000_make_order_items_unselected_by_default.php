<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->boolean('is_selected')->default(false)->change();
        });

        $draftOrderIds = DB::table('orders')->where('status', 'draft')->pluck('id');

        if ($draftOrderIds->isNotEmpty()) {
            DB::table('order_items')
                ->whereIn('order_id', $draftOrderIds)
                ->update(['is_selected' => false]);
        }
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->boolean('is_selected')->default(true)->change();
        });
    }
};
