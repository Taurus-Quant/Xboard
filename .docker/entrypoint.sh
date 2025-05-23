#!/bin/sh

set -e

# 设置权限
chown -R www:www /www
chmod -R 755 /www/storage /www/bootstrap/cache

# 如果 vendor 目录不存在，安装依赖
if [ ! -d "/www/vendor" ]; then
  echo "Installing dependencies..."
  composer install --no-dev --no-interaction
fi

# 创建存储链接
if [ ! -L "/www/public/storage" ]; then
  echo "Creating storage link..."
  php artisan storage:link
fi

# 启动 supervisord
exec /usr/bin/supervisord -c /etc/supervisor/supervisord.conf
