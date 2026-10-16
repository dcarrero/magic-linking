#!/usr/bin/env bash
# Pasa Plugin Check sobre el ZIP generado (no sobre la carpeta de desarrollo) y FALLA si hay algún ERROR.
# `wp plugin check` sale siempre con código 0, aunque informe de errores; por eso se lee la salida en JSON.
# Los avisos (WARNING) se muestran pero no hacen fallar.
set -uo pipefail

cd "$(dirname "$0")/.."

npm run zip || exit 1

OUT="$(mktemp)"
trap 'rm -f "${OUT}"' EXIT

npx wp-env run cli bash -c "ln -sfn /var/www/html/wp-content/plugins/magiclinking-wordpress-plugin/dist/magic-linking /var/www/html/wp-content/plugins/magic-linking && (wp plugin is-installed plugin-check || wp plugin install plugin-check) && wp plugin activate plugin-check --quiet && wp plugin check magic-linking --format=json" 2>&1 | tee "${OUT}"

if grep -q '"type":"ERROR"' "${OUT}"; then
	echo "Plugin Check ha encontrado ERRORES (ver arriba)." >&2
	exit 1
fi
if ! grep -q 'Ran `' "${OUT}"; then
	echo "Plugin Check no se ha ejecutado correctamente." >&2
	exit 1
fi
echo "Plugin Check: sin errores."
