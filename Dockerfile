FROM pkpofficial/ojs:3_4_0-10

RUN apk add --no-cache php82-pecl-xdebug

COPY --chown=100:101 config/override.config.inc.ini /tmp/config-overrides.ini
RUN cat /tmp/config-overrides.ini >> /var/www/html/config.inc.php && rm /tmp/config-overrides.ini

COPY --chown=100:101 config/custom.ini /etc/php82/conf.d/custom.ini
COPY --chown=100:101 config/xdebug.ini /etc/php82/conf.d/xdebug.ini