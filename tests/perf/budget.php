<?php
/**
 * Presupuesto de memoria por petición de `docs/09 §4`, compartido por `suggest.php` y `rest-memory.php`.
 *
 * @package MagicLinking
 */

// phpcs:disable -- Script de banco de pruebas, no forma parte del plugin.

// Pico total de una petición REST con WordPress incluido, en MB.
const MAGICLINKING_PERF_TOTAL_MB = 64.0;
// Lo que añade el plugin a esa petición (pico menos la base de WordPress), en MB.
const MAGICLINKING_PERF_OWN_MB = 24.0;
