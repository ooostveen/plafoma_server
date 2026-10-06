FROM php:8.3-cli

RUN docker-php-ext-install pdo pdo_mysql

WORKDIR /app

COPY . /app

ENV PORT=80

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t /app"]
