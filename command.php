<?php

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

require_once 'src/ConfigUpdateCommand.php';

WP_CLI::add_command( 'config update', array( 'WP_CLI\ConfigUpdateCommand', 'update' ) );
