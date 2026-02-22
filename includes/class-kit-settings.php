<?php
/**
 * Kit OAuth settings class.
 *
 * @package WebberZone\Glue_Link
 * @since 1.0.0
 */

namespace WebberZone\Glue_Link;

/**
 * Class Kit_Settings
 *
 * Stores and retrieves Kit OAuth credentials.
 */
class Kit_Settings {

	/**
	 * Option keys.
	 */
	private const OPTION_ACCESS_TOKEN  = 'kit_access_token';
	private const OPTION_REFRESH_TOKEN = 'kit_refresh_token';
	private const OPTION_TOKEN_EXPIRES = 'kit_token_expires';
	private const CONVERTKIT_OPTION    = '_wp_convertkit_settings';
	private const CONVERTKIT_PLUGIN    = 'convertkit/wp-convertkit.php';

	/**
	 * Cron hook name.
	 */
	public const CRON_REFRESH_HOOK = 'glue_link_refresh_token';

	/**
	 * Returns access token.
	 *
	 * @return string
	 */
	public function get_access_token(): string {
		$convertkit = $this->get_convertkit_settings();
		if ( ! empty( $convertkit['access_token'] ) ) {
			return (string) $convertkit['access_token'];
		}

		$settings = $this->get_glue_link_settings();
		$value    = isset( $settings[ self::OPTION_ACCESS_TOKEN ] ) ? (string) $settings[ self::OPTION_ACCESS_TOKEN ] : '';
		return Options_API::decrypt_api_key( $value );
	}

	/**
	 * Returns refresh token.
	 *
	 * @return string
	 */
	public function get_refresh_token(): string {
		$convertkit = $this->get_convertkit_settings();
		if ( ! empty( $convertkit['refresh_token'] ) ) {
			return (string) $convertkit['refresh_token'];
		}

		$settings = $this->get_glue_link_settings();
		$value    = isset( $settings[ self::OPTION_REFRESH_TOKEN ] ) ? (string) $settings[ self::OPTION_REFRESH_TOKEN ] : '';
		return Options_API::decrypt_api_key( $value );
	}

	/**
	 * Returns token expiry unix timestamp.
	 *
	 * @return int
	 */
	public function get_token_expiry(): int {
		$convertkit = $this->get_convertkit_settings();
		if ( ! empty( $convertkit['token_expires'] ) ) {
			return (int) $convertkit['token_expires'];
		}

		$settings = $this->get_glue_link_settings();
		return isset( $settings[ self::OPTION_TOKEN_EXPIRES ] ) ? (int) $settings[ self::OPTION_TOKEN_EXPIRES ] : 0;
	}

	/**
	 * Whether shared ConvertKit credentials are available.
	 *
	 * @return bool
	 */
	public function using_convertkit_credentials(): bool {
		$convertkit = $this->get_convertkit_settings();

		return ! empty( $convertkit['access_token'] ) && ! empty( $convertkit['refresh_token'] );
	}

	/**
	 * Whether access token exists.
	 *
	 * @return bool
	 */
	public function has_access_token(): bool {
		return ! empty( $this->get_access_token() );
	}

	/**
	 * Whether refresh token exists.
	 *
	 * @return bool
	 */
	public function has_refresh_token(): bool {
		return ! empty( $this->get_refresh_token() );
	}

	/**
	 * Whether access and refresh tokens exist.
	 *
	 * @return bool
	 */
	public function has_access_and_refresh_token(): bool {
		return $this->has_access_token() && $this->has_refresh_token();
	}

	/**
	 * Save OAuth credentials and schedule cron refresh.
	 *
	 * @param array $result OAuth response.
	 * @return void
	 */
	public function update_credentials( array $result ): void {
		if ( empty( $result['access_token'] ) || empty( $result['refresh_token'] ) ) {
			return;
		}

		// Keep ConvertKit credentials as the single source of truth when available.
		if ( $this->using_convertkit_credentials() ) {
			return;
		}

		$expires_in   = isset( $result['expires_in'] ) ? (int) $result['expires_in'] : 0;
		$token_expiry = $expires_in > 0 ? ( time() + $expires_in ) : 0;

		Options_API::update_option( self::OPTION_ACCESS_TOKEN, Options_API::encrypt_api_key( (string) $result['access_token'] ) );
		Options_API::update_option( self::OPTION_REFRESH_TOKEN, Options_API::encrypt_api_key( (string) $result['refresh_token'] ) );
		Options_API::update_option( self::OPTION_TOKEN_EXPIRES, $token_expiry );

		wp_clear_scheduled_hook( self::CRON_REFRESH_HOOK );
		if ( $token_expiry > 0 ) {
			wp_schedule_single_event( $token_expiry, self::CRON_REFRESH_HOOK );
		}
	}

	/**
	 * Delete OAuth credentials and clear scheduled refresh.
	 *
	 * @return void
	 */
	public function delete_credentials(): void {
		// Do not remove credentials owned by the ConvertKit plugin.
		if ( $this->using_convertkit_credentials() ) {
			return;
		}

		Options_API::delete_option( self::OPTION_ACCESS_TOKEN );
		Options_API::delete_option( self::OPTION_REFRESH_TOKEN );
		Options_API::delete_option( self::OPTION_TOKEN_EXPIRES );
		wp_clear_scheduled_hook( self::CRON_REFRESH_HOOK );
	}

	/**
	 * Fetch ConvertKit plugin settings if available.
	 *
	 * @return array
	 */
	private function get_convertkit_settings(): array {
		if ( ! $this->is_convertkit_plugin_active() ) {
			return array();
		}

		$settings = get_option( self::CONVERTKIT_OPTION, array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Whether the official Kit plugin is active.
	 *
	 * @return bool
	 */
	private function is_convertkit_plugin_active(): bool {
		$active_plugins = (array) get_option( 'active_plugins', array() );
		if ( in_array( self::CONVERTKIT_PLUGIN, $active_plugins, true ) ) {
			return true;
		}

		if ( ! is_multisite() ) {
			return false;
		}

		$network_active = (array) get_site_option( 'active_sitewide_plugins', array() );
		return isset( $network_active[ self::CONVERTKIT_PLUGIN ] );
	}

	/**
	 * Return Glue Link raw settings from the wp option table.
	 *
	 * @return array<string,mixed>
	 */
	private function get_glue_link_settings(): array {
		$settings = get_option( Options_API::SETTINGS_OPTION, array() );
		return is_array( $settings ) ? $settings : array();
	}
}
