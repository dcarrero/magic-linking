#!/usr/bin/env bash
# Genera dist/<slug>.zip con la carpeta <slug>/ dentro, sin nada de desarrollo (.distignore),
# y falla si pasa del presupuesto de 1 MB.
set -euo pipefail

cd "$(dirname "$0")/.."

SLUG="magic-linking"
MAX_BYTES=$((1024 * 1024))
BUILD="dist/${SLUG}"

rm -rf dist
mkdir -p "${BUILD}"

npm run build --silent

# Además de .distignore, fuera todo lo que git ignora (salvo assets/build, que se genera), como
# worktrees o ficheros de herramientas locales. Lo nuevo sin añadir a git sí entra: puede ser código
# que el plugin necesita. quotePath=false para que las rutas con acentos salgan tal cual.
IGNORED="$(mktemp)"
trap 'rm -f "${IGNORED}"' EXIT
git -c core.quotePath=false ls-files --others --ignored --exclude-standard --directory | grep -v '^assets/build/$' | sed 's#^#/#' > "${IGNORED}" || true

rsync -a --exclude-from=.distignore --exclude-from="${IGNORED}" --exclude=/vendor ./ "${BUILD}/"

# vendor/ del ZIP se genera aparte, sin dependencias de desarrollo, para no tocar la copia de trabajo.
cp composer.lock "${BUILD}/"
composer install --working-dir="${BUILD}" --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --quiet
# composer.json se queda: wordpress.org lo pide cuando hay vendor/.
rm "${BUILD}/composer.lock"

(cd dist && zip -rq "${SLUG}.zip" "${SLUG}")

# Comprobación final: nada oculto ni de desarrollo dentro de <slug>/.
HIDDEN="$(unzip -Z1 "dist/${SLUG}.zip" | grep -E "^${SLUG}/(.*/)?\.[^/]" || true)"
DEVDIRS="$(unzip -Z1 "dist/${SLUG}.zip" | grep -E "^${SLUG}/(tests|node_modules|bin|dist)(/|$)" || true)"
if [ -n "${HIDDEN}${DEVDIRS}" ]; then
	echo "El ZIP contiene ficheros ocultos o de desarrollo:" >&2
	printf '%s\n%s\n' "${HIDDEN}" "${DEVDIRS}" | sed '/^$/d' | head -20 >&2
	exit 1
fi

SIZE=$(wc -c < "dist/${SLUG}.zip" | tr -d ' ')
echo "dist/${SLUG}.zip: ${SIZE} bytes (límite ${MAX_BYTES})"

if [ "${SIZE}" -gt "${MAX_BYTES}" ]; then
	echo "El ZIP supera 1 MB." >&2
	exit 1
fi
