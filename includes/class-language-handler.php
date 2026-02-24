<?php
/**
 * Language handler.
 *
 * @package WebberZone\FreemKit
 */

namespace WebberZone\FreemKit;

use WebberZone\FreemKit\Util\Hook_Registry;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Language_Handler.
 */
class Language_Handler {

	/**
	 * Constructor.
	 */
	public function __construct() {
		Hook_Registry::add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Load plugin textdomain.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'freemkit', false, dirname( plugin_basename( FREEMKIT_PLUGIN_FILE ) ) . '/languages/' );
	}
}
