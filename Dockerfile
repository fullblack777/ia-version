FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    curl \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo pdo_sqlite

# Verifica se já estão disponíveis
RUN php -m | grep -Ei 'pdo|sqlite'

# Habilita .htaccess
RUN a2enmod rewrite

COPY . /var/www/html/
WORKDIR /var/www/html

RUN sed -i 's/80/10000/g' /etc/apache2/sites-available/000-default.conf
RUN sed -i 's/80/10000/g' /etc/apache2/ports.conf
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

EXPOSE 10000

CMD ["sh", "-c", "php bot.php & apache2-foreground"]
