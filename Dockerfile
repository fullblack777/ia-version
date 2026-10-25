FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    curl \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo pdo_sqlite sqlite3

COPY . /var/www/html/
WORKDIR /var/www/html

RUN sed -i 's/80/10000/g' /etc/apache2/sites-available/000-default.conf
RUN sed -i 's/80/10000/g' /etc/apache2/ports.conf
RUN a2enmod rewrite

EXPOSE 10000

CMD ["sh", "-c", "php bot.php & apache2-foreground"]
