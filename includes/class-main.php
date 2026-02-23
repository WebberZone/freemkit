<?php
/**
 * Main plugin bootstrap class.
 *
 * @package WebberZone\Glue_Link
 */

namespace WebberZone\Glue_Link;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Main class.
 */
class Main {

	/**
	 * Plugin instance.
	 *
	 * @var Main|null
	 */
	private static ?Main $instance = null;

	/**
	 * Runtime manager.
	 *
	 * @var Runtime
	 */
	private Runtime $runtime;

	/**
	 * OAuth credential hooks.
	 *
	 * @var Kit_Credential_Hooks
	 */
	private Kit_Credential_Hooks $credential_hooks;

	/**
	 * Returns the singleton instance.
	 *
	 * @return Main
	 */
	public static function get_instance(): Main {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->runtime          = new Runtime();
		$this->credential_hooks = new Kit_Credential_Hooks();
		$this->hooks();
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	private function hooks(): void {
		add_action( 'init', array( $this->runtime, 'init' ), 1 );
		add_action( 'init', array( $this->runtime, 'init_admin' ) );
		add_action( 'glue_link_api_get_access_token', array( $this->credential_hooks, 'maybe_update_credentials' ), 10, 2 );
		add_action( 'glue_link_api_refresh_token', array( $this->credential_hooks, 'maybe_update_credentials' ), 10, 2 );
		add_action( Kit_Settings::CRON_REFRESH_HOOK, array( $this->credential_hooks, 'refresh_kit_access_token' ) );
	}

	/**
	 * Plugin activation handler.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Runtime::activate();
	}
}
