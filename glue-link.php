<?php
/**
 * Plugin integration between Freemius and Kit
 *
 * @package   WebberZone\Glue_Link
 * @author    WebberZone
 * @license   GPL-2.0+
 * @link      https://webberzone.com
 *
 * @wordpress-plugin
 * Plugin Name: WebberZone Glue Link - Glue for Freemius and Kit
 * Plugin URI:  https://webberzone.com/plugins/glue-link/
 * Description: Easily subscribe Freemius customers to Kit email lists.
 * Version:     1.0.0-beta1
 * Author:      WebberZone
 * Author URI:  https://webberzone.com
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: glue-link
 * Domain Path: /languages
 */

namespace WebberZone\Glue_Link;

if ( ! defined( 'WPINC' ) ) {
	die;
}

// Define plugin constants.
if ( ! defined( 'GLUE_LINK_VERSION' ) ) {
	define( 'GLUE_LINK_VERSION', '1.0.0' );
}
if ( ! defined( 'GLUE_LINK_PLUGIN_FILE' ) ) {
	define( 'GLUE_LINK_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'GLUE_LINK_PLUGIN_DIR' ) ) {
	define( 'GLUE_LINK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'GLUE_LINK_PLUGIN_URL' ) ) {
	define( 'GLUE_LINK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

// Kit OAuth defaults (same OAuth application used by the official Kit WordPress flow).
if ( ! defined( 'GLUE_LINK_KIT_OAUTH_CLIENT_ID' ) ) {
	define( 'GLUE_LINK_KIT_OAUTH_CLIENT_ID', 'HXZlOCj-K5r0ufuWCtyoyo3f688VmMAYSsKg1eGvw0Y' );
}
if ( ! defined( 'GLUE_LINK_KIT_OAUTH_REDIRECT_URI' ) ) {
	define( 'GLUE_LINK_KIT_OAUTH_REDIRECT_URI', 'https://app.kit.com/wordpress/redirect' );
}

// Load Kit shared library classes if not already loaded by another plugin.
if ( ! trait_exists( 'ConvertKit_API_Traits' ) ) {
	require_once GLUE_LINK_PLUGIN_DIR . 'vendor/convertkit/convertkit-wordpress-libraries/src/class-convertkit-api-traits.php';
}
if ( ! class_exists( 'ConvertKit_API_V4' ) ) {
	require_once GLUE_LINK_PLUGIN_DIR . 'vendor/convertkit/convertkit-wordpress-libraries/src/class-convertkit-api-v4.php';
}
if ( ! class_exists( 'ConvertKit_Log' ) ) {
	require_once GLUE_LINK_PLUGIN_DIR . 'vendor/convertkit/convertkit-wordpress-libraries/src/class-convertkit-log.php';
}
if ( ! class_exists( 'ConvertKit_Resource_V4' ) ) {
	require_once GLUE_LINK_PLUGIN_DIR . 'vendor/convertkit/convertkit-wordpress-libraries/src/class-convertkit-resource-v4.php';
}
if ( ! class_exists( 'ConvertKit_Review_Request' ) ) {
	require_once GLUE_LINK_PLUGIN_DIR . 'vendor/convertkit/convertkit-wordpress-libraries/src/class-convertkit-review-request.php';
}

// Autoloader.
require_once GLUE_LINK_PLUGIN_DIR . 'includes/autoloader.php';

// Register activation hook.
register_activation_hook( __FILE__, array( 'WebberZone\Glue_Link\Main', 'activate' ) );

/**
 * Global variable holding the current instance of Glue Link
 *
 * @since 1.0.0
 *
 * @var \WebberZone\Glue_Link\Main
 */
global $glue_link;

if ( ! function_exists( __NAMESPACE__ . '\\load' ) ) {
	/**
	 * The main function responsible for returning the one true instance of the plugin to functions everywhere.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function load() {
		glue_link();
	}
	add_action( 'plugins_loaded', __NAMESPACE__ . '\\load' );
}

if ( ! function_exists( __NAMESPACE__ . '\\glue_link' ) ) {
	/**
	 * Get the main Glue Link instance.
	 *
	 * @since 1.0.0
	 * @return Main Main instance.
	 */
	function glue_link() {
		global $glue_link;
		delete_option( 'glue_link_boot_error' );

		try {
			$glue_link = Main::get_instance();
		} catch ( \Throwable $e ) {
			update_option(
				'glue_link_boot_error',
				sprintf(
					/* translators: 1: Error message, 2: File, 3: Line. */
					__( 'Glue Link failed to initialize: %1$s in %2$s on line %3$d', 'glue-link' ),
					$e->getMessage(),
					$e->getFile(),
					$e->getLine()
				)
			);
		}

		return $glue_link;
	}
}

/**
 * Show plugin bootstrap errors in wp-admin.
 *
 * @since 1.0.0
 *
 * @return void
 */
function show_boot_error_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$error = get_option( 'glue_link_boot_error' );
	if ( empty( $error ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html( (string) $error )
	);
}
add_action( 'admin_notices', __NAMESPACE__ . '\\show_boot_error_notice' );
