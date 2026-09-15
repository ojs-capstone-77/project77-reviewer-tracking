#!/bin/sh

set -a
. ./.env
set +a

echo "[OJS CLI Install] Calling the install using pre-defined variables..."

RESPONSE=$(curl -s "http://localhost:${OJS_WEB_PORT}/index/install/install" \
	--data "installing=0" \
	--data "adminUsername=${ADMIN_USERNAME}" \
	--data "adminPassword=${ADMIN_PASSWORD}" \
	--data "adminPassword2=${ADMIN_PASSWORD}" \
	--data "adminEmail=${ADMIN_EMAIL}" \
	--data "locale=en" \
	--data "additionalLocales%5B%5D=en" \
	--data "filesDir=%2Fvar%2Fwww%2Ffiles" \
	--data "databaseDriver=mysqli" \
	--data "databaseHost=db" \
	--data "databaseUsername=${DB_USER}" \
	--data "databasePassword=${DB_PASSWORD}" \
	--data "databaseName=${DB_NAME}" \
	--data "oaiRepositoryId=ojs.localhost" \
	--data "timeZone=Australia/Melbourne" \
	--compressed)

if echo "$RESPONSE" | grep -q "Installation of OJS has completed successfully."; then
	echo "[OJS CLI Install] SUCCESS: Installation of OJS has completed successfully. (http://localhost:${OJS_WEB_PORT}/)"
else
	echo "[OJS CLI Install] ERROR: install did not complete successfully."
fi