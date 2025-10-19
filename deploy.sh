set -e

composer install --no-dev
rsync -auP . zpf.io:/var/www/api.autonomo.codes-test/ --delete-after --exclude=storage --exclude=tests
