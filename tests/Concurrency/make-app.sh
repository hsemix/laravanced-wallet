#!/usr/bin/env bash
# Builds a throwaway Laravel host app that uses this checkout of the package (for the concurrency harness / CI).
#   usage: tests/Concurrency/make-app.sh <target-dir>
set -euo pipefail

PKG="$(cd "$(dirname "$0")/../.." && pwd)"
APP="$1"

composer create-project laravel/laravel "$APP" --no-interaction --prefer-dist --no-scripts
cd "$APP"
composer config repositories.wallet "{\"type\":\"path\",\"url\":\"$PKG\",\"options\":{\"symlink\":true}}"
composer config minimum-stability dev
composer config prefer-stable true
composer require "hsemix/laravanced-wallet:*@dev" --no-interaction --no-scripts -W

# The harness needs a User that can own accounts.
php -r '
$f = "app/Models/User.php";
$s = file_get_contents($f);
$s = str_replace("use Illuminate\\Notifications\\Notifiable;", "use Hsemix\\AdvancedWallet\\Concerns\\HasWallet;\nuse Illuminate\\Notifications\\Notifiable;", $s);
$s = preg_replace("/use HasFactory, Notifiable;/", "use HasFactory, HasWallet, Notifiable;", $s, 1, $n);
if ($n !== 1 || ! str_contains($s, "use Hsemix")) { fwrite(STDERR, "could not add HasWallet to User\n"); exit(1); }
file_put_contents($f, $s);
'

[ -f .env ] || cp .env.example .env
php artisan key:generate --force
