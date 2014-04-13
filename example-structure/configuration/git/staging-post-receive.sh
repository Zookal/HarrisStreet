#!/bin/sh
GIT_WORK_TREE=/usr/share/nginx/html/zookal-staging git checkout -f staging
rm -Rf /tmp/magento
php ./n98-magerun.phar script postInstallations.magerun
