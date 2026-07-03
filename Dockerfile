FROM richarvey/nginx-php-fpm:3.1.6

# Copy application files
COPY . .

# Install production dependencies during build
RUN composer install --no-interaction --optimize-autoloader --no-dev

# Image configuration
ENV SKIP_COMPOSER 1
ENV WEBROOT /var/www/html/public
ENV PHP_ERRORS_STDERR 1
ENV RUN_SCRIPTS 1
ENV REAL_IP_HEADER 1

# Laravel configuration
ENV APP_ENV production
ENV APP_DEBUG false
ENV LOG_CHANNEL stderr

# Allow composer to run as root if needed
ENV COMPOSER_ALLOW_SUPERUSER 1

CMD ["/start.sh"]
