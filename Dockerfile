ARG PHP_IMAGE=7.2-alpine
FROM php:${PHP_IMAGE}

EXPOSE 8000

RUN apk add --no-cache --virtual .phpize-deps $PHPIZE_DEPS \
    && export CFLAGS="$PHP_CFLAGS" CPPFLAGS="$PHP_CPPFLAGS" LDFLAGS="$PHP_LDFLAGS" \
    && pecl install -o -f xdebug \
    && docker-php-ext-enable xdebug \
    && apk del .phpize-deps \
    && rm -rf /tmp/pear

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ADD index.php .
ADD login.php .

ENTRYPOINT ["entrypoint.sh"]
CMD ["php", "-S", "0.0.0.0:8000"]
