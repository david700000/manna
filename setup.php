<?php
/**
 * Manna Bridal – DB Setup Helper
 * Run this ONCE after MySQL is started:
 *   php setup.php
 */

// 1. Create DB if missing
try {
    $pdo = new PDO('mysql:host=127.0.0.1', 'root', '');
    $pdo->exec('CREATE DATABASE IF NOT EXISTS mannabridal');
    echo "✔ Database `mannabridal` ready.\n";
} catch (PDOException $e) {
    echo "✘ MySQL not reachable: " . $e->getMessage() . "\n";
    exit(1);
}

// 2. Run migrations
echo "\n→ Running migrations...\n";
passthru('php artisan migrate --force');

// 3. Remove old demo accounts
echo "\n→ Removing demo accounts...\n";
try {
    require_once 'vendor/autoload.php';
    $app = require_once 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    \App\Models\User::whereIn('email', [
        'admin@mannabridal.com',
        'staff@mannabridal.com',
        'customer@mannabridal.com'
    ])->delete();
    echo "✔ Demo accounts removed.\n";
} catch (\Throwable $e) {
    echo "⚠ Could not delete demo accounts programmatically: " . $e->getMessage() . "\n";
}

// 4. Seed root account
echo "\n→ Seeding root account...\n";
passthru('php artisan db:seed --force');

echo "\n✔ All done! Login with:\n   Email:    david07israel@gmail.com\n   Password: admin  (you will be forced to change it on first login)\n";
