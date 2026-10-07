#!/usr/bin/env bash
# Publica dist/magic-linking/ en el SVN de wordpress.org: trunk, assets y tags/<versión>.
# Uso: VERSION=0.9.3 bin/deploy-wporg.sh        (antes: npm ci && npm run zip)
# Con DRY_RUN=1 hace todo menos el «svn ci». Para publicar de verdad hacen falta
# SVN_USERNAME y SVN_PASSWORD en el entorno. Lo llama .github/workflows/deploy-wporg.yml.
set -euo pipefail

cd "$(dirname "$0")/.."

SLUG="magic-linking"
SVN_URL="https://plugins.svn.wordpress.org/${SLUG}"
BUILD="dist/${SLUG}"
WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

fallo() {
	echo "ERROR: $*" >&2
	exit 1
}

VERSION="${VERSION:-}"
VERSION="${VERSION#v}"
[ -n "${VERSION}" ] || fallo "falta la versión (variable VERSION, p. ej. VERSION=0.9.3)."
[[ "${VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || fallo "la versión «${VERSION}» no tiene el formato X.Y.Z."

DRY_RUN="${DRY_RUN:-0}"
case "${DRY_RUN}" in 1 | true) DRY_RUN=1 ;; *) DRY_RUN=0 ;; esac

# 1. Coherencia de versiones: etiqueta == cabecera del plugin == Stable tag.
PLUGIN_VERSION="$(sed -n 's/^[[:space:]*]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' "${SLUG}.php" | head -1)"
STABLE_TAG="$(sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\).*/\1/p' readme.txt | head -1)"
[ "${PLUGIN_VERSION}" = "${VERSION}" ] || fallo "la etiqueta es ${VERSION} pero «Version:» de ${SLUG}.php es «${PLUGIN_VERSION}»."
[ "${STABLE_TAG}" = "${VERSION}" ] || fallo "la etiqueta es ${VERSION} pero «Stable tag:» de readme.txt es «${STABLE_TAG}»."
echo "Versión ${VERSION}: etiqueta, cabecera del plugin y Stable tag coinciden."

# 2. La etiqueta no debe existir ya en el SVN (las versiones publicadas no se reescriben).
if svn info --non-interactive "${SVN_URL}/tags/${VERSION}" > /dev/null 2>&1; then
	fallo "tags/${VERSION} ya existe en ${SVN_URL}. Sube la versión antes de etiquetar."
fi
echo "tags/${VERSION} no existe en el SVN todavía."

# 3. El paquete sale de npm run zip, no de la copia de trabajo.
[ -f "${BUILD}/${SLUG}.php" ] || fallo "falta ${BUILD}/. Ejecuta antes «npm ci && npm run zip»."

# 4. Copia de trabajo del SVN: solo lo necesario.
SVN="${WORK}/svn"
svn checkout --non-interactive --depth immediates "${SVN_URL}" "${SVN}"
svn update --non-interactive --set-depth infinity "${SVN}/trunk" "${SVN}/assets"

rsync -a --delete --exclude=.svn "${BUILD}/" "${SVN}/trunk/"
rsync -a --delete --exclude=.svn .wordpress-org/ "${SVN}/assets/"

cd "${SVN}"

# 5. Altas y bajas. Se procesa línea a línea para soportar espacios y acentos en las rutas.
svn add --force --quiet trunk assets
# grep sale con 1 si no hay bajas; con pipefail eso pararía el script sin mensaje.
{ svn status | grep '^!' || true; } | while IFS= read -r linea; do
	ruta="${linea:8}"
	svn rm --quiet --force -- "${ruta}@"
done

# 6. Tipos MIME de las imágenes de assets.
find assets -type f \( -iname '*.png' -o -iname '*.jpg' -o -iname '*.jpeg' -o -iname '*.svg' \) -not -path '*/.svn/*' |
	while IFS= read -r fichero; do
		minuscula="$(printf '%s' "${fichero}" | tr '[:upper:]' '[:lower:]')"
		case "${minuscula}" in
			*.png) mime="image/png" ;;
			*.jpg | *.jpeg) mime="image/jpeg" ;;
			*) mime="image/svg+xml" ;;
		esac
		svn propset --quiet svn:mime-type "${mime}" -- "${fichero}@"
	done

# 7. Etiqueta: copia de trunk ya preparado.
svn cp --quiet trunk "tags/${VERSION}"

echo "--- Resumen de cambios (svn st) ---"
svn status | cut -c1-1 | sort | uniq -c
svn status | grep -v '^ ' | grep -v 'tags/' | head -40 || true
echo "-----------------------------------"

MENSAJE="Magic Linking ${VERSION}"
if [ "${DRY_RUN}" = "1" ]; then
	echo "DRY_RUN: no se hace svn ci. Habría publicado «${MENSAJE}»."
	exit 0
fi

[ -n "${SVN_USERNAME:-}" ] && [ -n "${SVN_PASSWORD:-}" ] || fallo "faltan SVN_USERNAME y SVN_PASSWORD."
svn ci --non-interactive --no-auth-cache --username "${SVN_USERNAME}" --password "${SVN_PASSWORD}" -m "${MENSAJE}"
echo "Publicado ${MENSAJE}."
