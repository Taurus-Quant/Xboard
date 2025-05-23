FROM phpswoole/swoole:php8.2-alpine

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# Install PHP extensions one by one with lower optimization level for ARM64 compatibility
RUN CFLAGS="-O0" install-php-extensions pcntl && \
    CFLAGS="-O0 -g0" install-php-extensions bcmath && \
    install-php-extensions zip && \
    install-php-extensions redis && \
    apk --no-cache add shadow sqlite mysql-client mysql-dev mariadb-connector-c git patch supervisor redis && \
    addgroup -S -g 1000 www && adduser -S -G www -u 1000 www && \
    (getent group redis || addgroup -S redis) && \
    (getent passwd redis || adduser -S -G redis -H -h /data redis)

WORKDIR /www

COPY .docker /

# 我们将使用本地代码，不需要从远程仓库克隆
# 创建必要的目录
RUN mkdir -p /www/storage/logs /www/storage/app/public /www/bootstrap/cache /www/.docker/.data/redis && \
    chown -R www:www /www && \
    chmod -R 755 /www/storage /www/bootstrap/cache

COPY .docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# u5728u6784u5efau65f6u5b89u88c5u57fau672cu5de5u5177
RUN mkdir -p /data && chown redis:redis /data

# u5728u542fu52a8u65f6u8fd0u884cu7684u811au672c
COPY .docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
    
ENV ENABLE_WEB=true \
    ENABLE_HORIZON=true \
    ENABLE_REDIS=false 

EXPOSE 7001
CMD ["/entrypoint.sh"]