FROM php:8.2-cli-bookworm

RUN apt-get update \
	&& apt-get install -y --no-install-recommends git unzip zip curl jq nodejs npm \
	&& rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_HOME=/opt/composer
ENV PATH="/opt/composer/vendor/bin:${PATH}"

RUN composer global config allow-plugins.dealerdirect/phpcodesniffer-composer-installer true \
	&& composer global require --no-interaction --prefer-dist \
		dealerdirect/phpcodesniffer-composer-installer:^1.1 \
		wp-coding-standards/wpcs:^3.1 \
		phpcompatibility/php-compatibility:^9.3 \
		phpstan/phpstan:^1.12 \
		szepeviktor/phpstan-wordpress:^1.3 \
		php-stubs/wordpress-stubs:^6.8 \
	&& phpcs --config-set installed_paths /opt/composer/vendor/wp-coding-standards/wpcs,/opt/composer/vendor/phpcompatibility/php-compatibility,/opt/composer/vendor/phpcsstandards/phpcsextra,/opt/composer/vendor/phpcsstandards/phpcsutils

RUN npm install -g eslint@9

WORKDIR /workspace
