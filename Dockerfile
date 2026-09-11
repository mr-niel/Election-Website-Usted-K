FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
    default-mysql-client \
    libpng-dev libjpeg-dev libwebp-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install gd pdo pdo_mysql

RUN a2enmod rewrite headers

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/public/id-photos

COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
