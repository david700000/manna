#!/bin/bash

# Run database migrations
echo "Running migrations..."
php artisan migrate --force

# Cache configuration, routes, and views
echo "Caching configurations..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
