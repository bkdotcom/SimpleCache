#!/bin/bash

apt-get update
apt-get install -y gnupg

apt-get install -y wget git zip unzip

curl -sS http://packages.couchbase.com/ubuntu/couchbase.key | apt-key add -
curl -sS -o /etc/apt/sources.list.d/couchbase.list http://packages.couchbase.com/ubuntu/couchbase-ubuntu1404.list
apt-get update

#curl -O -J "http://ftp.se.debian.org/debian/pool/main/o/openssl/libssl1.0.0_1.0.2l-1~bpo8+1_amd64.deb"
#dpkg -i libssl1.0.0_1.0.2l-1~bpo8+1_amd64.deb

#wget "http://packages.couchbase.com/releases/couchbase-release/couchbase-release-1.0-6-amd64.deb"
#dpkg -i couchbase-release-1.0-6-amd64.deb

wget "http://ftp.se.debian.org/debian/pool/main/o/openssl/libssl1.0.0_1.0.1t-1+deb8u8_amd64.deb"
dpkg -i libssl1.0.0_1.0.1t-1+deb8u8_amd64.deb

apt-get update

echo "brad: installing libcouchbase??"
apt-get install -y libcouchbase-dev libcouchbase2-bin build-essential
apt-get install -y libmemcached-dev
apt-get install -y zlib1g-dev
apt-get install -y libpq-dev

pecl channel-update pecl.php.net

# install PHP extensions
pecl install -f xdebug
pecl install -f pcs-1.3.3
pecl install -f igbinary

pecl install -f --alldeps couchbase

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
docker-php-ext-enable xdebug
docker-php-ext-enable pcs
docker-php-ext-enable igbinary
docker-php-ext-enable couchbase
docker-php-ext-enable memcached
docker-php-ext-enable redis

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
docker-php-ext-install zip pcntl

# install dependencies
make install
