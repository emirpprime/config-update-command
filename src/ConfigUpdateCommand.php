<?php

namespace WP_CLI;
use WP_CLI;
use WP_CLI\Utils;
use WP_CLI\Runner;

class ConfigUpdateCommand {
	/**
	 * Update a wp-config.php file.
	 *
	 * Updates a new wp-config.php with database constants.
	 *
	 * ## OPTIONS
	 *
	 * [--dbname=<dbname>]
	 * : Set the database name.
	 *
	 * [--dbuser=<dbuser>]
	 * : Set the database user.
	 *
	 * [--dbpass=<dbpass>]
	 * : Set the database user password.
	 *
	 * [--dbhost=<dbhost>]
	 * : Set the database host.
	 *
	 * [--dbprefix=<dbprefix>]
	 * : Set the database table prefix.
	 *
	 * [--dbcharset=<dbcharset>]
	 * : Set the database charset.
	 *
	 * [--dbcollate=<dbcollate>]
	 * : Set the database collation.
	 *
	 * [--wpdebug=<wpdebug>]
	 * : Set WP_DEBUG to true / false.
	 *
	 * [--locale=<locale>]
	 * : Set the WPLANG constant. Defaults to $wp_local_package variable.
	 *
	 * [--extra-php]
	 * : If set, the command copies additional PHP code into wp-config.php from STDIN.
	 *
	 * [--skip-check]
	 * : If set, the database connection is not checked.
	 *
	 * ## EXAMPLES
	 *
	 *     # Change database access credentials.
	 *     $ wp core config update --dbname=testing --dbuser=wp --dbpass=securepswd
	 *     Success: Updated 'wp-config.php' file.
	 *
	 *     # Enable WP_DEBUG.
	 *     $ wp core config update --wpdebug=true
	 *     Success: Updated 'wp-config.php' file.
	 *
	 *     # Enable WP_DEBUG and WP_DEBUG_LOG.
	 *     $ wp core config update --dbname=testing --dbuser=wp --dbpass=securepswd --extra-php <<PHP
	 *     $ define( 'WP_DEBUG', true );
	 *     $ define( 'WP_DEBUG_LOG', true );
	 *     $ PHP
	 *     Success: Updated 'wp-config.php' file.
	 *
	 * @when before_wp_load
	 */
	public function update( $_, $assoc_args ) {

		// Sanity check, updating only.
		if ( ! Utils\locate_wp_config() ) {
			WP_CLI::error( "No 'wp-config.php' file exists - please use `wp config create` instead." );
		}

		// Look for skip-check and set as var.
		$skip_check = false;
		if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'skip-check' ) === true ) {
			unset( $assoc_args['skip-check'] );
			$skip_check = true;
		}

		// Check there's some inputs to process.
		if ( empty( $assoc_args ) ) {
			// TODO: Make this more useful - display possibilities.
			WP_CLI::error( 'No values to update supplied.' );
		}

		// Defaults - just used to rename keys.
		$defaults = array(
			'dbname' => '',
			'dbuser' => '',
			'dbpass' => '',
			'dbhost' => '',
			'dbprefix' => '',
			'dbcharset' => '',
			'dbcollate' => '',
			'wpdebug' => '',
			'locale' => '',
			'extra-php' => '',
		);

		// Handle extra-php input special case.
		if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'extra-php' ) === true ) {
			$assoc_args['extra-php'] = file_get_contents( 'php://stdin' );
		}

		// BUG: Doesn't allow custom variables / constants to be updated or added.

		// Sanitize user supplied vars $assoc_args.
		if ( ! empty( $assoc_args['dbprefix'] ) && preg_match( '|[^a-z0-9_]|i', $assoc_args['dbprefix'] ) ) {
			WP_CLI::error( '--dbprefix can only contain numbers, letters, and underscores.' );
		}

		// TODO: Sanity check all inputs inc type (bool, string etc);

		// Merge user supplied vars into defaults.
		// TODO: Evaluate whether to use wp_parse_args instead.
		$assoc_args = array_merge( $defaults, $assoc_args );

		// Array of strings as found in wp_config.php to use as new array keys.
		$wp_config_map = array(
			'DB_NAME',
			'DB_USER',
			'DB_PASSWORD',
			'DB_HOST',
			'table_prefix',
			'DB_CHARSET',
			'DB_COLLATE',
			'WP_DEBUG',
			'WPLANG',
			'extra-php',
		);

		// Rename vars array keys to match values in wp_config.php.
		$assoc_args = array_combine( $wp_config_map, $assoc_args );

		// Remove empty array keys, but preserve boolean falses.
		$assoc_args = array_filter( $assoc_args, create_function( '$value', 'return $value !== "";' ) );

		// Read existing vars from wp_config.php.
		$current_values = self::get_current();

		// Values which need to be updated.
		$values_to_update = array_diff( $assoc_args, $current_values );

		// Check DB connection - only if a relevant value needs updating.
		if ( ! $skip_check && ( ! empty( $values_to_update['DB_HOST'] ) || ! empty( $values_to_update['DB_USER'] ) || ! empty( $values_to_update['DB_PASSWORD'] ) ) ) {
			Utils\run_mysql_command( '/usr/bin/env mysql --no-defaults', array(
				'execute' => ';',
				'host' => ( ! empty( $values_to_update['DB_HOST'] ) ? $values_to_update['DB_HOST'] : $current_values['DB_HOST'] ),
				'user' => ( ! empty( $values_to_update['DB_USER'] ) ? $values_to_update['DB_USER'] : $current_values['DB_USER'] ),
				'pass' => ( ! empty( $values_to_update['DB_PASSWORD'] ) ? $values_to_update['DB_PASSWORD'] : $current_values['DB_PASSWORD'] ),
			) );
		}

		// Check there's something left to do.
		if ( empty( $values_to_update ) ) {
			WP_CLI::success( 'Nothing needs to be updated.' );
			return;
		}

		// Get current wp-config.php contents.
		$wp_config_contents = self::readwrite_wp_config();
		$wp_config_contents = explode( PHP_EOL , $wp_config_contents );

		// Process wp-config.php to update values.
		foreach ( $wp_config_contents as &$line ) {
			foreach ( $values_to_update as $key => $value ) {
				if ( false !== strpos( $line, $key ) ) {
					// BUG: Doesn't handle constants defined as false as they don't appear in $current_values.
					// BUG: Doesn't handle adding constants that arn't already defined.
					$line = str_replace( $current_values[ $key ], $value, $line );
				};
			}
		}

		// Reform wp-config.php contents.
		$wp_config_contents = implode( PHP_EOL, $wp_config_contents );

		// Handle adding extra-php to wp-config.php.
		if ( $values_to_update['extra-php'] ) {
			// Find the right place to insert the content.
			$token = "/* That's all, stop editing!";
			if ( false === strpos( $wp_config_contents, $token ) ) {
				return false;
			}
			// Split wp-config.php into parts.
			list( $before, $after ) = explode( $token, $wp_config_contents );
			// Prepare extra-php.
			$extra_php = PHP_EOL . PHP_EOL . trim( $values_to_update['extra-php'] ) . PHP_EOL . PHP_EOL;
			// Rebuild $wp_config_contents with the extra-php.
			$wp_config_contents = $before . $extra_php . $token . $after;
		}

		// Write updated wp-config.php.
		return self::readwrite_wp_config( true, $wp_config_contents );

	}




	/**
	 * Get variables and constants defined in wp-config.php file.
	 *
	 * return array Defined WordPress related Constants and Vars.
	 */
	private static function get_current() {

		$wp_cli_original_defined_constants = get_defined_constants();
		$wp_cli_original_defined_vars      = get_defined_vars();

		eval( WP_CLI::get_runner()->get_wp_config_code() );

		$wp_config_vars      = array_diff( get_defined_vars(), $wp_cli_original_defined_vars );
		$wp_config_constants = array_diff( get_defined_constants(), $wp_cli_original_defined_constants );

		return array_merge( $wp_config_vars, $wp_config_constants );

	}




	/**
	 * Read or write wp-config.php file.
	 *
	 * @param bool   $write 			 Whether to read (default) or write wp-config.php.
	 * @param string $wp_config_contents New contents for wp-config.php when writing.
	 *
	 * return mixed  					 Success/Error message or string contents of wp_config.php.
	 */
	private static function readwrite_wp_config( $write = false, $wp_config_contents = null ) {

		$wp_config_path = Utils\locate_wp_config();
		if ( ! $wp_config_path ) {
			WP_CLI::error( "No 'wp-config.php' file exists - please use `wp config create` instead." );
		}

		if ( ! $write ) {
			return file_get_contents( $wp_config_path );
		}

		if ( $write && is_string( $wp_config_contents ) && '' !== $wp_config_contents ) {

			$bytes_written = file_put_contents( $wp_config_path, $wp_config_contents );
			if ( ! $bytes_written ) {
				WP_CLI::error( "Could not update 'wp-config.php'." );
			} else {
				WP_CLI::success( "Updated 'wp-config.php'." );
			}

		}

	}


}


