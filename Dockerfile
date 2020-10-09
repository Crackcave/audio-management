ARG PHP_IMAGE=7.2-alpine
FROM php:${PHP_IMAGE}

WORKDIR /var/www

EXPOSE 8000

RUN apk add --no-cache --virtual .phpize-deps $PHPIZE_DEPS \
    && export CFLAGS="$PHP_CFLAGS" CPPFLAGS="$PHP_CPPFLAGS" LDFLAGS="$PHP_LDFLAGS" \
    && pecl install -o -f xdebug \
    && docker-php-ext-enable xdebug \
    && apk del .phpize-deps \
    && rm -rf /tmp/pear

COPY mpc.sh /usr/local/bin/mpc
RUN chmod +x /usr/local/bin/mpc
RUN apk add --update --no-cache curl py-pip \
    && ln -s /usr/bin/python3 /usr/bin/python \
    && wget https://yt-dl.org/latest/youtube-dl -O /usr/local/bin/youtube-dl \
    && chmod a+x /usr/local/bin/youtube-dl

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ADD index.php .
ADD login.php .
RUN mkdir tracks && mkdir tmp && chown www-data:www-data tracks && chown www-data:www-data tmp

ENTRYPOINT ["entrypoint.sh"]
CMD ["php", "-S", "0.0.0.0:8000"]
