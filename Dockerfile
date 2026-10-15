FROM php:8.2-apache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install PostgreSQL PDO driver requirements
RUN apt-get update && apt-get install -y libpq-dev unzip \
    && docker-php-ext-install pdo pdo_pgsql

# Enable Apache rewrite module
RUN a2enmod rewrite

# Configure Apache document root and allow .htaccess overrides
RUN sed -ri -e 's!/var/www/html!/var/www/html!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!/var/www/html!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf
RUN echo '<Directory /var/www/html/>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' >> /etc/apache2/apache2.conf

# Copy project files into container
COPY . /var/www/html/

# Set working directory and permissions
WORKDIR /var/www/html
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
RUN chown -R www-data:www-data /var/www/html

# Render routes traffic to the port in $PORT (10000 unless set) and waits for
# the service to listen on it; Apache's image default is 80. At startup, point
# Apache's Listen directive and default VirtualHost at $PORT (falling back to
# 80 when it is unset or not a number, e.g. local `docker run`), then start
# Apache in the foreground as before. "Listen <port>" binds all interfaces.
EXPOSE 80
CMD ["sh", "-c", "PORT=\"${PORT:-80}\"; case \"$PORT\" in ''|*[!0-9]*) PORT=80 ;; esac; sed -ri \"s/^Listen [0-9]+$/Listen $PORT/\" /etc/apache2/ports.conf && sed -ri \"s/<VirtualHost \\*:[0-9]+>/<VirtualHost *:$PORT>/\" /etc/apache2/sites-available/000-default.conf && echo \"ParishHub: Apache listening on port $PORT\" && exec apache2-foreground"]
