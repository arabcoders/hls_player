FROM ghcr.io/arabcoders/php_container:latest
LABEL maintainer="admin@arabcoders.org"

ENV IN_DOCKER=1
ENV VP_MEDIA_PATH=/storage
ENV VP_DATA_PATH=/config
ENV VP_SENDFILE=true

RUN mkdir -p /app /config

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
