# defaults for `make test`
PHP ?= '7.2'
ADAPTER ?= 'Apc,Couchbase,Filesystem,Flysystem,Memcached,Memory,MySQLi,NullCache,PdoMySQL,PdoPgSQL,PdoSQLite,Redis'
UP ?= 1
DOWN ?= 1
SERVICES = php
SERVICES += $(if $(filter Couchbase,$(ADAPTER)),couchbase,)
SERVICES += $(if $(filter Memcached,$(ADAPTER)),memcached,)
SERVICES += $(if $(filter PdoMySQL,$(ADAPTER)),mysql,)
SERVICES += $(if $(filter PdoPgSQL,$(ADAPTER)),postgresql,)
SERVICES += $(if $(filter Redis,$(ADAPTER)),redis,)

install:
	wget -q -O - https://getcomposer.org/installer | php
	./composer.phar install
	cp composer.json composer.bak
	./composer.phar require league/flysystem
	./composer.phar require cache/integration-tests
	mv composer.bak composer.json
	rm composer.phar

docs:
	make install
	wget http://apigen.org/apigen.phar
	chmod +x apigen.phar
	php apigen.phar generate --source=src,vendor/cache,vendor/psr --skip-doc-path="*/vendor/*,*/psr-simplecache/*" --destination=docs --template-theme=bootstrap
	rm apigen.phar

up:
	# Adapter: $(ADAPTER)
	# Services: $(SERVICES)
	docker-compose -f docker-compose.yml -f tests/Docker/docker-compose.$(PHP).yml up --no-deps -d $(SERVICES)

down:
	docker-compose -f docker-compose.yml -f tests/Docker/docker-compose.$(PHP).yml stop -t0 $(SERVICES)

test:
	# Usage:
	# make test - tests all adapters on latest PHP version
	# make test PHP=5.6 ADAPTER=Memcached - tests Memcached on PHP 5.6
	[ $(UP) -eq 1 ] && make up PHP=$(PHP) ADAPTER=$(ADAPTER) || true
	$(eval cmd='docker-compose -f docker-compose.yml -f tests/Docker/docker-compose.$(PHP).yml run --no-deps php vendor/bin/phpunit --group $(ADAPTER)')
	eval $(cmd); status=$$?; [ $(DOWN) -eq 1 ] && make down PHP=$(PHP) ADAPTER=$(ADAPTER); exit $$status
