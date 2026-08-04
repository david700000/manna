<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->json('pickup_info')->nullable();
        });

        // Drop the old check constraint for the enum
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check');
        
        // Add the new check constraint with the new values
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status::text = ANY (ARRAY['pending'::character varying, 'processing'::character varying, 'shipped'::character varying, 'delivered'::character varying, 'cancelled'::character varying, 'ready_for_pickup'::character varying, 'picked_up'::character varying]::text[]))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('pickup_info');
        });

        // Revert check constraint
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status::text = ANY (ARRAY['pending'::character varying, 'processing'::character varying, 'shipped'::character varying, 'delivered'::character varying, 'cancelled'::character varying]::text[]))");
    }
};
