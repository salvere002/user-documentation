#!/bin/bash

set -e

if [ ! -e /docker_build ]; then
  echo "This script should only be ran from a Dockerfile"
  exit 1
fi

cd /var/www
# init.sh removes dev dependencies as they are not required
# to run the site, but we need them here.
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y php-cli zip unzip
php /opt/composer/composer.phar install
exec bin/test-staging.sh
