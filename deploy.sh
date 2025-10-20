set -e

rm -rf vendor/autonomo/ai-speaker
composer install --no-dev
rm -rf vendor/autonomo/ai-speaker/vendor
composer dumpautoload -o
rsync -auP . zpf.io:/var/www/api.autonomo.codes/ --delete-after --exclude=storage --exclude=tests
