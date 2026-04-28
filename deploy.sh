#!/bin/bash
set -e

git pull

rsync -av --no-group --no-owner --omit-dir-times --exclude='.git' --exclude='deploy.sh' --exclude='.backups' --exclude='tournament' \
  ~/projects/dfwpl-standings/ /var/www/dfwpl.andaas.org/

cp --remove-destination ~/projects/dfwpl-standings/tournament/index.php /var/www/custom.andaas.org/index.php
