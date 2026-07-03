#!/bin/bash

# Run database migrations
echo "Running migrations..."
php artisan migrate --force

# Seed the database (creates root user if not exists)
echo "Seeding database..."
php artisan db:seed --force

# Cache configuration, routes, and views
echo "Caching configurations..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
