<?php

use Illuminate\Contracts\Console\Kernel;

// Boots a host Laravel app (one that uses Hsemix\AdvancedWallet and App\Models\User with HasWallet)
// against a DISPOSABLE database. setup.php runs migrate:fresh, so never point this at real data.
//
//   WALLET_APP_PATH  path to the Laravel app           (required)
//   WALLET_DB        scratch database name             (default: wallet_concurrency)
$app_path = rtrim(getenv('WALLET_APP_PATH') ?: '', '/');
if ($app_path === '' || ! is_file($app_path.'/bootstrap/app.php')) {
    fwrite(STDERR, "Set WALLET_APP_PATH to a Laravel app that has the wallet package installed.\n");
    exit(1);
}
$db = getenv('WALLET_DB') ?: 'wallet_concurrency';
putenv("DB_DATABASE=$db");
$_ENV['DB_DATABASE'] = $db;
$_SERVER['DB_DATABASE'] = $db;

require $app_path.'/vendor/autoload.php';
$app = require $app_path.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

return $app;
