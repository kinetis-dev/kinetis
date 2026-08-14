FROM dunglas/frankenphp:latest

# curl/ext-curl (needed by pusher/pusher-php-server) is already built into
# this base image — unzip is for Composer's package extraction. mysqli and
# pdo_mysql back kinetis/persistence's DB_DRIVER=auto split: this one image
# serves both the FrankenPHP worker (native mysqli driver) and the migrate
# service's CLI run (PDO driver). Built while the phpize toolchain is
# present, then purged.
RUN apt-get update && apt-get install -y --no-install-recommends unzip $PHPIZE_DEPS \
    && docker-php-ext-install mysqli pdo_mysql \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["frankenphp", "php-server", "--listen", ":8080", "--root", "/repo/packages/pingpong/public", "--worker", "/repo/packages/pingpong/public/index.php"]
