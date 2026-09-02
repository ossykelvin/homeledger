FROM php:8.3-apache

COPY docker/apache.conf /etc/apache2/conf-available/homeledger.conf

RUN docker-php-ext-install pdo_mysql \
    && a2enmod headers rewrite \
    && a2enconf homeledger

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri -e "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/sites-available/*.conf

WORKDIR /var/www/html
COPY . /var/www/html

EXPOSE 80
