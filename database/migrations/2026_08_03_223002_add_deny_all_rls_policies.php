<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
        'order_items'
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tables as $table) {
            DB::statement("CREATE POLICY \"allow_backend\" ON \"{$table}\" FOR ALL USING (current_user NOT IN ('anon', 'authenticated'));");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->tables as $table) {
            DB::statement("DROP POLICY IF EXISTS \"allow_backend\" ON \"{$table}\";");
        }
    }
};
