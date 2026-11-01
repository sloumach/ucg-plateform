FROM node:24-alpine@sha256:8a32c8cf649a49915a66e81e7a609223c708d0adabf288ee3f4077d765542f85 AS node
FROM php:8.5-fpm-alpine@sha256:4c2818074ec49f6abe46620f3965e0cb75bc3a5f2b417edd1aacaba6f091c40e
COPY --from=node /usr/local/bin/node /usr/local/bin/node
RUN apk add --no-cache nginx redis postgresql17 postgresql17-client libstdc++ icu-libs libpq \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libpq-dev linux-headers \
    && docker-php-ext-install -j4 pdo_pgsql pcntl intl bcmath sockets \
    && apk del .build-deps \
    && addgroup -g 1000 bench && adduser -D -u 1000 -G bench bench
USER bench
WORKDIR /work/back
ENTRYPOINT ["node", "/work/front/tests/real-api/load-runtime.mjs"]
