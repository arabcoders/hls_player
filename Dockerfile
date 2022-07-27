FROM alpine:3.16

LABEL maintainer="admin@arabcoders.org"

ENV IN_CONTAINER=1
ENV PHP_V=php81
ENV TOOL_PATH=/opt/app
ENV PHP_INI_DIR=/etc/${PHP_V}
ENV VP_MEDIA_PATH=/srv
ENV VP_SENDFILE=true

# Setup the required environment.
#
RUN apk add --no-cache mailcap bash caddy nano curl procps net-tools iproute2 shadow sqlite redis tzdata gettext fcgi ffmpeg \
    ${PHP_V} ${PHP_V}-common ${PHP_V}-ctype ${PHP_V}-curl ${PHP_V}-dom ${PHP_V}-fileinfo ${PHP_V}-fpm \
    ${PHP_V}-intl ${PHP_V}-mbstring ${PHP_V}-opcache ${PHP_V}-pcntl ${PHP_V}-pdo_sqlite ${PHP_V}-phar \
    ${PHP_V}-posix ${PHP_V}-session ${PHP_V}-shmop ${PHP_V}-simplexml ${PHP_V}-snmp ${PHP_V}-sockets \
    ${PHP_V}-sodium ${PHP_V}-sysvmsg ${PHP_V}-sysvsem ${PHP_V}-sysvshm ${PHP_V}-tokenizer ${PHP_V}-xml ${PHP_V}-openssl \
    ${PHP_V}-xmlreader ${PHP_V}-xmlwriter ${PHP_V}-zip ${PHP_V}-pecl-igbinary ${PHP_V}-pecl-redis ${PHP_V}-pecl-xhprof

# Update Caddy and add packages to it.
#
RUN echo 'Adding modules to HTTP Server.' && \
    # add modules to caddy.
    caddy add-package github.com/lolPants/caddy-requestid github.com/caddyserver/transform-encoder >/dev/null 2>&1

# Basic setup
#
RUN echo '' && \
    # Delete unused users change users group gid to allow unRaid users to use gid 100
    deluser redis && deluser caddy && groupmod -g 1588787 users && \
    # Create our own user.
    useradd -u 1000 -U -d /opt/app/var -s /bin/bash user && \
    # Add user to video group.
    usermod -aG video user

# Copy source code to container.
#
COPY ./ /opt/app

# Copy Composer.
COPY --from=composer:2 /usr/bin/composer /opt/composer

# install composer & packages.
#
RUN echo '' && \
    # Create basic directories.
    bash -c 'mkdir -p /srv /opt/app/var/{cache,views,logs}' && \
    # Link PHP shortcuts.
    ln -s /usr/bin/${PHP_V} /usr/bin/php && \
    # we are running rootless, so user,group config options has no affect.
    sed -i 's/user = nobody/; user = user/' /etc/${PHP_V}/php-fpm.d/www.conf && \
    sed -i 's/group = nobody/; group = users/' /etc/${PHP_V}/php-fpm.d/www.conf && \
    # Install dependencies.
    /opt/composer --working-dir=/opt/app/ -no --no-progress --no-dev --no-cache --quiet -- install && \
    # Copy configuration files to the expected directories.
    cp ${TOOL_PATH}/container/files/Caddyfile /etc/caddy/Caddyfile && \
    cp ${TOOL_PATH}/container/files/init-container.sh /opt/init-container && \
    cp ${TOOL_PATH}/container/files/php-fpm-healthcheck.sh /usr/bin/php-fpm-healthcheck && \
    cp ${TOOL_PATH}/container/files/php.ini "${PHP_INI_DIR}/conf.d/zz-custom-php.ini" && \
    cp ${TOOL_PATH}/container/files/fpm.conf "${PHP_INI_DIR}/php-fpm.d/zz-custom-pool.conf" && \
    caddy fmt -overwrite /etc/caddy/Caddyfile && \
    # Make sure console,init-container,job-runner are given executable flag.
    chmod +x /opt/init-container /usr/bin/php-fpm-healthcheck && \
    # Remove unneeded directories and tools.
    bash -c 'rm -rf /opt/composer ${TOOL_PATH}/{container,.github,.git,.env}' && \
    # Change Permissions.
    chown -R user:user /opt /var/log/ /etc/caddy/ && chmod -R 775 /var/log/ /opt/app && chmod -R 777 /opt/app/var

# Set the entrypoint.
#
ENTRYPOINT ["/opt/init-container"]

# Change working directory.
#
WORKDIR /opt/app/var

# Switch to user
#
USER user

# Expose the ports.
#
EXPOSE 9000 8080 8443

# Health check.
#
HEALTHCHECK CMD /usr/bin/php-fpm-healthcheck -v

# Run php-fpm
#
CMD ["php-fpm81"]
