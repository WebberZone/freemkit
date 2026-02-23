<?php
/**
 * Kit OAuth admin controller.
 *
 * @package WebberZone\Glue_Link\Admin
 * @since 1.0.0
 */

namespace WebberZone\Glue_Link\Admin;

use WebberZone\Glue_Link\Kit_API;
use WebberZone\Glue_Link\Kit_Settings;

/**
 * Class Kit_OAuth
 */
class Kit_OAuth {

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	private string $menu_slug;

	/**
	 * Constructor.
	 *
	 * @param string $menu_slug Settings page slug.
	 */
	public function __construct( string $menu_slug ) {
		$this->menu_slug = $menu_slug;
		add_action( 'admin_init', array( $this, 'maybe_handle_requests' ) );
	}

	/**
	 * Handle OAuth callback and disconnect actions.
	 *
	 * @return void
	 */
	public function maybe_handle_requests(): void {
		if ( ! $this->is_settings_page() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$api      = new Kit_API();
		$settings = new Kit_Settings();

		if ( isset( $_GET['glue_link_oauth_disconnect'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			check_admin_referer( 'glue_link_oauth_disconnect' );
			if ( $settings->using_convertkit_credentials() ) {
				Admin::add_notice( esc_html__( 'Connection is managed by the ConvertKit plugin. Disconnect it there if needed.', 'glue-link' ), 'notice-warning' );
				wp_safe_redirect( $this->get_settings_url() );
				exit;
			}

			$settings->delete_credentials();
			$this->clear_kit_cache();
			Admin::add_notice( esc_html__( 'Disconnected from Kit.', 'glue-link' ), 'notice-success' );
			wp_safe_redirect( $this->get_settings_url() );
			exit;
		}

		if ( ! isset( $_GET['code'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$authorization_code = sanitize_text_field( wp_unslash( $_GET['code'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$result             = $api->get_access_token( $authorization_code );

		if ( is_wp_error( $result ) ) {
			Admin::add_notice( sprintf( esc_html__( 'Kit OAuth failed: %s', 'glue-link' ), $result->get_error_message() ), 'notice-error' );
			wp_safe_redirect( $this->get_settings_url() );
			exit;
		}

		$settings->update_credentials( $result );
		$this->clear_kit_cache();
		Admin::add_notice( esc_html__( 'Successfully connected to Kit via OAuth.', 'glue-link' ), 'notice-success' );
		wp_safe_redirect( $this->get_settings_url() );
		exit;
	}

	/**
	 * Build HTML for OAuth connection status on settings screen.
	 *
	 * @param string $menu_slug  Settings page slug.
	 * @param array  $query_args Optional query args for OAuth callback and button URLs.
	 * @return string
	 */
	public static function get_status_html( string $menu_slug, array $query_args = array() ): string {
		$api          = new Kit_API();
		$settings     = new Kit_Settings();
		$settings_url = add_query_arg(
			array_merge(
				array(
					'page' => $menu_slug,
					'tab'  => 'kit',
				),
				$query_args
			),
			admin_url( 'admin.php' )
		);

		$tenant_name = get_option( 'blogname', '' );
		if ( ! is_string( $tenant_name ) || '' === trim( $tenant_name ) ) {
			$site_host   = wp_parse_url( get_site_url(), PHP_URL_HOST );
			$tenant_name = is_string( $site_host ) ? $site_host : '';
		}

		$oauth_url = $api->get_oauth_url( $settings_url, $tenant_name );

		if ( ! $settings->has_access_and_refresh_token() ) {
			return sprintf(
				'<p>%1$s</p><p><a class="button button-primary" href="%2$s">%3$s</a></p>',
				esc_html__( 'Not connected to Kit yet. Connect to use OAuth with Kit API v4.', 'glue-link' ),
				esc_url( $oauth_url ),
				esc_html__( 'Connect to Kit', 'glue-link' )
			);
		}

		$account      = $api->get_account();
		$account_name = ! is_wp_error( $account ) && isset( $account['account']['name'] ) ? (string) $account['account']['name'] : '';

		if ( is_wp_error( $account ) ) {
			if ( $settings->using_convertkit_credentials() ) {
				return sprintf(
					'<p>%1$s</p><p>%2$s</p>',
					esc_html__( 'Kit plugin credentials were detected, but the API token could not be verified.', 'glue-link' ),
					esc_html__( 'Reconnect in the Kit plugin to refresh that shared token.', 'glue-link' )
				);
			}

			return sprintf(
				'<p>%1$s</p><p>%2$s</p><p><a class="button button-primary" href="%3$s">%4$s</a></p>',
				esc_html__( 'Kit is connected, but we could not verify the account right now.', 'glue-link' ),
				esc_html( $account->get_error_message() ),
				esc_url( $oauth_url ),
				esc_html__( 'Reconnect to Kit', 'glue-link' )
			);
		}
		$status = $account_name
			? sprintf(
				/* translators: %s: Kit account name. */
				esc_html__( 'Connected to Kit account: %s', 'glue-link' ),
				$account_name
			)
			: esc_html__( 'Connected to Kit.', 'glue-link' );

		if ( $settings->using_convertkit_credentials() ) {
			$status .= ' ' . esc_html__( '(using ConvertKit plugin credentials)', 'glue-link' );
		}

		$disconnect_url = wp_nonce_url(
			add_query_arg(
				array_merge(
					array(
						'page'                       => $menu_slug,
						'tab'                        => 'kit',
						'glue_link_oauth_disconnect' => 1,
					),
					$query_args
				),
				admin_url( 'admin.php' )
			),
			'glue_link_oauth_disconnect'
		);

		if ( $settings->using_convertkit_credentials() ) {
			return sprintf( '<p>%s</p>', esc_html( $status ) );
		}

		return sprintf(
			'<p>%1$s</p><p><a class="button button-secondary" href="%2$s">%3$s</a></p>',
			esc_html( $status ),
			esc_url( $disconnect_url ),
			esc_html__( 'Disconnect', 'glue-link' )
		);
	}

	/**
	 * Whether current screen is this plugin settings page.
	 *
	 * @return bool
	 */
	private function is_settings_page(): bool {
		if ( ! is_admin() || ! isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}

		return sanitize_key( wp_unslash( $_GET['page'] ) ) === $this->menu_slug; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Return Kit tab URL.
	 *
	 * @return string
	 */
	private function get_settings_url(): string {
		$args = array(
			'page' => $this->menu_slug,
			'tab'  => 'kit',
		);

		// Preserve wizard step, if present, so OAuth callbacks return to the intended step.
		if ( isset( $_GET['step'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$step = absint( wp_unslash( $_GET['step'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $step > 0 ) {
				$args['step'] = $step;
			}
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Clear cached Kit resources.
	 *
	 * @return void
	 */
	private function clear_kit_cache(): void {
		foreach ( array( 'forms', 'tags', 'sequences', 'custom_fields' ) as $transient ) {
			delete_transient( 'glue_link_kit_' . $transient );
		}
	}
}
