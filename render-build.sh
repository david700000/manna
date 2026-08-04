#!/usr/bin/env bash
set -e

echo "Installing dependencies..."
composer install --no-dev --optimize-autoloader

echo "Running migrations..."
php artisan migrate --force

echo "Seeding database (idempotent — safe to re-run)..."
php artisan db:seed --force

echo "Caching config..."
php artisan config:cache

echo "Caching routes..."
php artisan route:cache

echo "Clearing application cache..."
php artisan cache:clear

echo "Clearing views..."
php artisan view:clear

echo "Build complete."
