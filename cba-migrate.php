<?php
/*
Plugin Name: CBA Migration Plugin
Description: Adds a WP-CLI command to migrate incompatible metadata from CBA-Theme to fields provided by Colleges-Theme's supported plugins.
Version: 1.0.0
Authors: UCF Web Communications
*/
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once 'includes/class-cba-migrate.php';

	WP_CLI::add_command( 'cba migrate', 'CBA_Migrate_Command', $args = array() );
}

?>
