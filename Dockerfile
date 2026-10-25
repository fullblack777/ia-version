FROM php:8.2-apache
RUN docker-php-ext-install pdo pdo_sqlite sqlite3
COPY . /var/www/html/
RUN a2enmod rewrite
EXPOSE 80
