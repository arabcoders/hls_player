FROM php:8.1-fpm-alpine

ENV IN_DOCKER=1
ENV VP_MEDIA_PATH=/storage
ENV VP_DATA_PATH=/config
ENV VP_SENDFILE=true

LABEL maintainer="admin@arabcoders.org"

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/bin/

RUN mv "${PHP_INI_DIR}/php.ini-production" "${PHP_INI_DIR}/php.ini" && chmod +x /usr/bin/install-php-extensions && \
    sync && install-php-extensions fileinfo mbstring redis ctype json opcache && \
    apk add --no-cache nginx redis ffmpeg nano curl procps shadow runuser && \
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer && \
    mkdir -p /app /config

COPY . /app

RUN cp /app/docker/nginx.conf /etc/nginx/http.d/default.conf && \
    cp /app/docker/entrypoint.sh /usr/bin/entrypoint-docker && \
    rm -rf /app/var/ /app/.github/ && \
    chmod +x /usr/bin/entrypoint-docker && \
    /usr/bin/composer --working-dir=/app/ -o --no-progress --no-dev --no-cache install && \
    chown -R www-data:www-data /app /config

ENTRYPOINT ["/usr/bin/entrypoint-docker"]

WORKDIR /config

EXPOSE 9000 80 443

CMD ["php-fpm"]