#!/usr/bin/env bash
# Regenera las traducciones: .pot, .po, .mo y los .json de JavaScript.
#
# Las cadenas de JavaScript se extraen de los bundles compilados (assets/build/*.js), no de los
# fuentes: así cada cadena lleva la referencia de TODOS los bundles que la contienen y make-json
# la reparte a todos los .json que la necesitan (index.js, editor.js, classic.js). Si el .po se
# queda con referencias antiguas, un componente compartido (SuggestionCard, Pager...) sale en
# inglés en el Informe aunque esté traducido en el editor.
#
# Los .po existentes se actualizan con msgmerge contra el .pot nuevo: se conservan las traducciones,
# se renuevan las referencias y las cadenas nuevas quedan sin traducir (hay que escribirlas a mano;
# TranslationsTest falla si falta alguna en es_ES).
#
# Necesita wp-env arrancado (WP-CLI vive en su contenedor) y msgmerge/msgattrib/msgfmt (gettext).
set -euo pipefail

cd "$(dirname "$0")/.."

# .wp-env.json monta el plugin siempre en esta ruta, también si se ejecuta desde un worktree.
PLUGIN_DIR="wp-content/plugins/magiclinking-wordpress-plugin"
wpcli() {
	npx wp-env run cli --env-cwd="${PLUGIN_DIR}" wp "$@"
}

npm run build --silent

wpcli i18n make-pot . languages/magic-linking.pot --slug=magic-linking --domain=magic-linking \
	--exclude=node_modules,vendor,dist,tests,bin,.claude,assets/src

for po in languages/magic-linking-*.po; do
	msgmerge --update --backup=none --no-fuzzy-matching --no-wrap "${po}" languages/magic-linking.pot
	msgattrib --no-wrap --no-obsolete -o "${po}" "${po}"
	msgfmt -o "${po%.po}.mo" "${po}"
done

rm -f languages/*.json
wpcli i18n make-json languages --no-purge
