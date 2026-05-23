FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libpng-dev \
    && docker-php-ext-install pdo pdo_mysql \
    && a2enmod rewrite

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html