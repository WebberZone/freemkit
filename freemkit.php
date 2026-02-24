<?php
/**
 * Plugin integration between Freemius and Kit
 *
 * @package   WebberZone\FreemKit
 * @author    WebberZone
 * @license   GPL-2.0+
 * @link      https://webberzone.com
 *
 * @wordpress-plugin
 * Plugin Name: FreemKit - Glue for Freemius and Kit
 * Plugin URI:  https://webberzone.com/plugins/freemkit/
 * Description: Easily subscribe Freemius customers to Kit email lists.
 * Version:     1.0.0-beta1
 * Author:      WebberZone
 * Author URI:  https://webberzone.com
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: freemkit
 * Domain Path: /languages
 */

namespace WebberZone\FreemKit;

if ( ! defined( 'WPINC' ) ) {
	die;
}

// Define plugin constants.
if ( ! defined( 'FREEMKIT_VERSION' ) ) {
	define( 'FREEMKIT_VERSION', '1.0.0' );
}
if ( ! defined( 'FREEMKIT_PLUGIN_FILE' ) ) {
	define( 'FREEMKIT_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'FREEMKIT_PLUGIN_DIR' ) ) {
	define( 'FREEMKIT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'FREEMKIT_PLUGIN_URL' ) ) {
	define( 'FREEMKIT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

// Kit OAuth defaults (same OAuth application used by the official Kit WordPress flow).
if ( ! defined( 'FREEMKIT_KIT_OAUTH_CLIENT_ID' ) ) {
	define( 'FREEMKIT_KIT_OAUTH_CLIENT_ID', 'HXZlOCj-K5r0ufuWCtyoyo3f688VmMAYSsKg1eGvw0Y' );
}
if ( ! defined( 'FREEMKIT_KIT_OAUTH_REDIRECT_URI' ) ) {
	define( 'FREEMKIT_KIT_OAUTH_REDIRECT_URI', 'https://app.kit.com/wordpress/redirect' );
}

// Load Kit shared library classes if not already loaded by another plugin.
if ( ! trait_exists( 'ConvertKit_API_Traits' ) ) {
	require_once FREEMKIT_PLUGIN_DIR . 'vendor/convertkit/convertkit-wordpress-libraries/src/class-convertkit-api-traits.php';
}
if ( ! class_exists( 'ConvertKit_API_V4' ) ) {
	require_once FREEMKIT_PLUGIN_DIR . 'vendor/convertkit/convertkit-wordpress-libraries/src/class-convertkit-api-v4.php';
}
if ( ! class_exists( 'ConvertKit_Log' ) ) {
	require_once FREEMKIT_PLUGIN_DIR . 'vendor/convertkit/convertkit-wordpress-libraries/src/class-convertkit-log.php';
}
if ( ! class_exists( 'ConvertKit_Resource_V4' ) ) {
	require_once FREEMKIT_PLUGIN_DIR . 'vendor/convertkit/convertkit-wordpress-libraries/src/class-convertkit-resource-v4.php';
}
if ( ! class_exists( 'ConvertKit_Review_Request' ) ) {
	require_once FREEMKIT_PLUGIN_DIR . 'vendor/convertkit/convertkit-wordpress-libraries/src/class-convertkit-review-request.php';
}

// Autoloader.
require_once FREEMKIT_PLUGIN_DIR . 'includes/autoloader.php';

// Register activation hook.
register_activation_hook( __FILE__, array( 'WebberZone\FreemKit\Main', 'activate' ) );

/**
 * Global variable holding the current instance of FreemKit
 *
 * @since 1.0.0
 *
 * @var \WebberZone\FreemKit\Main
 */
global $freemkit;

if ( ! function_exists( __NAMESPACE__ . '\\load' ) ) {
	/**
	 * The main function responsible for returning the one true instance of the plugin to functions everywhere.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function load() {
		freemkit();
	}
	add_action( 'plugins_loaded', __NAMESPACE__ . '\\load' );
}

if ( ! function_exists( __NAMESPACE__ . '\\freemkit' ) ) {
	/**
	 * Get the main FreemKit instance.
	 *
	 * @since 1.0.0
	 * @return Main Main instance.
	 */
	function freemkit() {
		global $freemkit;
		delete_option( 'freemkit_boot_error' );

		try {
			$freemkit = Main::get_instance();
		} catch ( \Throwable $e ) {
			update_option(
				'freemkit_boot_error',
				sprintf(
					/* translators: 1: Error message, 2: File, 3: Line. */
					__( 'FreemKit failed to initialize: %1$s in %2$s on line %3$d', 'freemkit' ),
					$e->getMessage(),
					$e->getFile(),
					$e->getLine()
				)
			);
		}

		return $freemkit;
	}
}
