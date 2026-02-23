<?php
/**
 * Kit OAuth credential hook handlers.
 *
 * @package WebberZone\Glue_Link
 */

namespace WebberZone\Glue_Link;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Kit_Credential_Hooks
 */
class Kit_Credential_Hooks {

	/**
	 * Persist refreshed OAuth credentials if they belong to this plugin client.
	 *
	 * @param array  $result    OAuth result.
	 * @param string $client_id OAuth client ID.
	 * @return void
	 */
	public function maybe_update_credentials( $result, $client_id ): void {
		if ( ! defined( 'GLUE_LINK_KIT_OAUTH_CLIENT_ID' ) || GLUE_LINK_KIT_OAUTH_CLIENT_ID !== $client_id ) {
			return;
		}

		$settings = new Kit_Settings();
		$settings->update_credentials( $result );
	}

	/**
	 * Legacy handler for invalid access tokens.
	 *
	 * Auto-disconnect is intentionally disabled. Credentials remain stored
	 * until an administrator explicitly disconnects from Kit.
	 *
	 * @param \WP_Error $error     API error.
	 * @param string    $client_id OAuth client ID.
	 * @return void
	 */
	public function maybe_delete_credentials( $error, $client_id ): void {
		unset( $error, $client_id );
	}

	/**
	 * Refresh OAuth access token via WP-Cron.
	 *
	 * @return void
	 */
	public function refresh_kit_access_token(): void {
		$settings = new Kit_Settings();
		if ( ! $settings->has_access_and_refresh_token() ) {
			return;
		}

		$api    = new Kit_API( $settings->get_access_token(), $settings->get_refresh_token() );
		$result = $api->refresh_token();
		if ( is_wp_error( $result ) ) {
			return;
		}

		$settings->update_credentials( $result );
	}
}
