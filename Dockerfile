FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && touch database/database.sqlite \
    && chmod -R 775 storage bootstrap/cache database

# A Render define a variável PORT; 10000 é o valor padrão deles.
ENV PORT=10000

# Cria as tabelas e os dados de demonstração a cada inicialização e sobe o servidor.
CMD ["sh", "-c", "php artisan migrate:fresh --seed --force && php artisan config:cache && php artisan serve --host=0.0.0.0 --port=${PORT}"]
