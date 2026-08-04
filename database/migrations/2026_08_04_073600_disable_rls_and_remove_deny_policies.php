<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * Reversal of the two 2026-08-03 migrations that accidentally broke all
 * data access by enabling RLS with a deny-all policy on every table.
 *
 * The Supabase pooled connection user does NOT have BYPASSRLS, so any
 * RLS policy that does not explicitly allow the backend user will return
 * 0 rows for every query — causing the "no products" bug.
 */
return new class extends Migration
{
    protected array $tables = [
        'users',
        'password_reset_tokens',
        'migrations',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'personal_access_tokens',
        'categories',
        'products',
        'hero_slides',
        'settings',
        'banners',
        'payments',
        'support_messages',
        'wishlists',
        'activity_logs',
        'admin_notifications',
        'product_ratings',
        'orders',
        'order_items',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            // 1. Drop the deny-all policy
            DB::statement("DROP POLICY IF EXISTS \"allow_backend\" ON \"{$table}\";");

            // 2. Disable RLS entirely so the Laravel DB user can read all rows
            DB::statement("ALTER TABLE \"{$table}\" DISABLE ROW LEVEL SECURITY;");
        }

        // 3. Clear the product cache that was populated with 0 rows while RLS was active
        Cache::forget('public_products');
    }

    public function down(): void
    {
        // Re-enable RLS + policy (i.e. re-applies the broken state — not recommended)
        foreach ($this->tables as $table) {
            DB::statement("ALTER TABLE \"{$table}\" ENABLE ROW LEVEL SECURITY;");
            DB::statement("CREATE POLICY \"allow_backend\" ON \"{$table}\" FOR ALL USING (current_user NOT IN ('anon', 'authenticated'));");
        }
    }
};
