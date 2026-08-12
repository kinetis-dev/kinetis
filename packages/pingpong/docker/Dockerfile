FROM dunglas/frankenphp:latest

# curl/ext-curl (needed by pusher/pusher-php-server) is already built into
# this base image — only unzip (needed by Composer to extract downloaded
# packages) is missing here.
RUN apt-get update && apt-get install -y --no-install-recommends unzip && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["frankenphp", "php-server", "--listen", ":8080", "--root", "/repo/packages/pingpong/public", "--worker", "/repo/packages/pingpong/public/index.php"]
