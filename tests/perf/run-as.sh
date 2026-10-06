#!/usr/bin/env bash
# Mide el proceso completo vía Action Scheduler: lanza el indexado en segundo plano y ejecuta
# `wp action-scheduler run` en bucle hasta que termina. Uso: tests/perf/run-as.sh  (desde la raíz, con wp-env)
P=wp-content/plugins/magiclinking-wordpress-plugin
W="npx wp-env run cli wp"
$W eval "global \$wpdb; foreach(['docs','links','jobs','terms','postings'] as \$t){\$wpdb->query('TRUNCATE '.\MagicLinking\Core\Schema::table(\$wpdb->prefix,\$t));} \$wpdb->query(\"DELETE FROM {\$wpdb->prefix}actionscheduler_actions\");" >/dev/null 2>&1
$W magic-linking index --background >/dev/null 2>&1
S=$(date +%s); RUNS=0
while true; do
  $W action-scheduler run >/dev/null 2>&1; RUNS=$((RUNS+1))
  N=$($W db query "SELECT COUNT(*) FROM wp_magiclinking_jobs WHERE status IN ('queued','running')" --skip-column-names 2>/dev/null | grep -E '^[0-9]+$' | tail -1)
  [ "$N" = "0" ] && break
  [ $RUNS -gt 60 ] && break
done
echo "AS: ejecuciones_de_runner=$RUNS tiempo=$(( $(date +%s) - S ))s"
