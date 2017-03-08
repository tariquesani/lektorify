<?php
/*
 * Run the exporter from the command line and write to exports directory.
 *
 * Usage:
 *
 *     $ php lektor-export-cli.php
 *
 * Must be run in the wordpress-to-lektor-exporter/ directory.
 *
 */

include "../../../wp-load.php";
include "../../../wp-admin/includes/file.php";
require_once "lektorify.php"; //ensure plugin is "activated"

if (php_sapi_name() != 'cli')
   wp_die("Lektor export must be run via the command line or administrative dashboard.");

$lektorify = new Lektorify();
$lektorify->export();
