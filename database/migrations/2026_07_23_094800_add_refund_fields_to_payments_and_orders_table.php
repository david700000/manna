<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Alter orders.payment_status enum to include refund_pending and refunded
        // MySQL doesn't support ALTER COLUMN for ENUMs easily, so we use a raw query
        DB::statement("ALTER TABLE orders MODIFY COLUMN payment_status ENUM('unpaid','paid','failed','refund_pending','refunded') NOT NULL DEFAULT 'unpaid'");

        // 2. Add refund tracking columns to payments table
        Schema::table('payments', function (Blueprint $table) {
            $table->string('refund_reference')->nullable()->after('raw_response');
            $table->enum('refund_status', ['pending', 'success', 'failed'])->nullable()->after('refund_reference');
            $table->decimal('refund_amount', 12, 2)->nullable()->after('refund_status');
            $table->timestamp('refunded_at')->nullable()->after('refund_amount');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['refund_reference', 'refund_status', 'refund_amount', 'refunded_at']);
        });

        DB::statement("ALTER TABLE orders MODIFY COLUMN payment_status ENUM('unpaid','paid','failed') NOT NULL DEFAULT 'unpaid'");
    }
};
