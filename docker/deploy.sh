#!/bin/sh
# Update the server to the latest code (what the old push-to-cPanel action did). Run from anywhere on the VPS.
set -e
cd "$(dirname "$0")/.."
git fetch origin master && git reset --hard origin/master
(cd ../kachow-web && git fetch origin master && git reset --hard origin/master)
cd docker
docker compose run --rm --no-deps php composer install --no-dev --optimize-autoloader --no-interaction
docker compose up -d --build
