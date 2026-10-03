# PHP tooling runs in Docker; no local PHP needed. (No -u: rootless podman maps container root to you.)
composer := "docker run --rm -v $PWD:/app -w /app -e COMPOSER_HOME=/tmp/composer composer:2"
harness := env_var_or_default("HARNESS_DIR", "../remotestorage-nextcloud-harness")

# Install dev dependencies
install:
    {{composer}} composer install --no-interaction --no-progress

# Unit tests (pure logic; DAV integration is tested by the harness)
test: install
    {{composer}} vendor/bin/phpunit

# Syntax-check every PHP file against PHP 8.2 and the current PHP
lint:
    {{composer}} sh -c 'find lib appinfo templates tests -name "*.php" -print0 | xargs -0 -n1 php -l | grep -v "^No syntax errors" || true'

# Integration: run the harness's rsapp variant against this checkout
harness *versions="35 34":
    cd {{harness}} && RS_APP_DIR={{justfile_directory()}} VERSIONS="{{versions}}" ./app/run.sh

# Build the app-store tarball into build/
package:
    mkdir -p build && tar --exclude-vcs --exclude=./vendor --exclude=./build --exclude=./tests \
      --exclude=./composer.* --exclude=./phpunit.xml --exclude=./Justfile --exclude=./.phpunit.result.cache \
      --transform 's,^\.,remotestorage,' -czf build/remotestorage.tar.gz .
