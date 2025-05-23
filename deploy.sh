#!/bin/bash

# 确保脚本在错误时停止执行
set -e

# 显示执行的命令
set -x

# 只更新包列表，不进行完整的系统更新
apt-get update

# 安装必要的依赖
apt-get install -y \
    apt-transport-https \
    ca-certificates \
    curl \
    software-properties-common \
    git

# 安装 Docker
if ! command -v docker &> /dev/null; then
    curl -fsSL https://get.docker.com -o get-docker.sh
    sh get-docker.sh
    usermod -aG docker $USER
    systemctl enable docker
    systemctl start docker
fi

# 安装 Docker Compose
if ! command -v docker-compose &> /dev/null; then
    curl -L "https://github.com/docker/compose/releases/download/v2.20.3/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
    chmod +x /usr/local/bin/docker-compose
fi

# 创建必要的目录
mkdir -p .docker/.data/redis

# 构建并启动容器
docker-compose -f docker-compose.production.yaml build
docker-compose -f docker-compose.production.yaml up -d

# 显示容器状态
docker-compose -f docker-compose.production.yaml ps

echo "部署完成！Xboard 现在应该可以通过 http://服务器IP:7001 访问"
echo "如果这是首次安装，请运行以下命令初始化数据库："
echo "docker-compose -f docker-compose.production.yaml exec web php artisan xboard:install"
