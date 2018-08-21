#!/bin/bash

apt-get update -y && apt-get install -my wget gnupg
#apt-key adv --keyserver keyserver.ubuntu.com 2
# --recv-keys A3FAA648D9223EDA

#curl -sS http://packages.couchbase.com/ubuntu/couchbase.key | apt-key add -
#curl -sS -o /etc/apt/sources.list.d/couchbase.list http://packages.couchbase.com/ubuntu/couchbase-ubuntu1404.list
echo 'deb http://packages.couchbase.com/ubuntu jessie jessie/main' > /etc/apt/sources.list.d/couchbase.list
#apt-get update

apt-get install -y libcouchbase2-libevent
apt-get install -y libcouchbase-dev
apt-get install -y libmemcached-dev
apt-get install -y zlib1g-dev
apt-get install -y libpq-dev

# install PHP extensions
pecl install -f xdebug
pecl install -f pcs-1.3.3
pecl install -f igbinary
pecl install -f couchbase
#echo extension=couchbase.so > /usr/local/etc/php/conf.d/99-couchbase.ini
echo extension=couchbase.so > /usr/local/etc/php/php.ini

if [[ `php-config --vernum` -ge 70000 ]]; then # PHP>=7.0
    pecl install -f apcu
    pecl install -f memcached
    pecl install -f redis
else # PHP<7.0
    pecl install -f apcu-4.0.10
    pecl install -f memcached-2.2.0
    pecl install -f redis-2.2.7
fi

docker-php-ext-enable apcu
echo "apc.enable_cli=1" >> /usr/local/etc/php/php.ini
docker-php-ext-enable couchbase
docker-php-ext-enable igbinary
docker-php-ext-enable memcached
docker-php-ext-enable pcs
docker-php-ext-enable redis
docker-php-ext-enable xdebug

docker-php-ext-install pdo
docker-php-ext-install pdo_mysql
docker-php-ext-configure pgsql -with-pgsql=/usr/local/pgsql
docker-php-ext-install pdo_pgsql
docker-php-ext-install mysqli
docker-php-ext-enable mysqli

# cache dir for flysystem
mkdir /tmp/cache

rm -rf /tmp/pear

# composer requirements
apt-get install -y wget git zip unzip
docker-php-ext-install zip pcntl

# install dependencies
make install
