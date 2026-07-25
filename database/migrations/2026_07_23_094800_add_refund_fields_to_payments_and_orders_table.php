<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL check constraint for enum (Laravel implementation)
        DB::statement("ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_payment_status_check");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_status_check CHECK (payment_status::text = ANY (ARRAY['unpaid'::character varying, 'paid'::character varying, 'failed'::character varying, 'refund_pending'::character varying, 'refunded'::character varying]::text[]))");

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

        DB::statement("ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_payment_status_check");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_status_check CHECK (payment_status::text = ANY (ARRAY['unpaid'::character varying, 'paid'::character varying, 'failed'::character varying]::text[]))");
    }
};
