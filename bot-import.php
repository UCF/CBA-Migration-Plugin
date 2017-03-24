<?php
/*
Plugin Name: BOT Import Plugin
Description: Adds a WP-CLI command to import content from the old BOT site.
Version: 1.0.0
Authors: UCF Web Communications
*/
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once 'includes/class-bot-import.php';
	require_once ABSPATH . 'wp-content/plugins/wordpress-importer/parsers.php';

	WP_CLI::add_command( 'bot', 'BOT_Import_Command' );
}

?>
