<?php
/**
 * Kit API wrapper class.
 *
 * @package WebberZone\Glue_Link
 * @since 1.0.0
 */

namespace WebberZone\Glue_Link;

/**
 * Class Kit_API
 *
 * Wraps Kit's official ConvertKit_API_V4 library.
 */
class Kit_API extends \ConvertKit_API_V4 {

	/**
	 * Error codes.
	 */
	private const ERROR_NO_CONNECTION = 'invalid_connection';
	private const ERROR_NO_EMAIL      = 'invalid_email';
	private const ERROR_API_ERROR     = 'api_error';

	/**
	 * Whether credentials are sourced from the ConvertKit plugin.
	 *
	 * @var bool
	 */
	protected bool $using_convertkit_credentials = false;

	/**
	 * Constructor.
	 *
	 * @param string $access_token Access token override.
	 * @param string $refresh_token Refresh token override.
	 */
	public function __construct( string $access_token = '', string $refresh_token = '' ) {
		$settings              = new Kit_Settings();
		$client_id             = defined( 'GLUE_LINK_KIT_OAUTH_CLIENT_ID' ) ? (string) GLUE_LINK_KIT_OAUTH_CLIENT_ID : '';
		$redirect_uri          = defined( 'GLUE_LINK_KIT_OAUTH_REDIRECT_URI' ) ? (string) GLUE_LINK_KIT_OAUTH_REDIRECT_URI : '';
		$resolved_access_token = $access_token ? $access_token : $settings->get_access_token();
		$resolved_refresh      = $refresh_token ? $refresh_token : $settings->get_refresh_token();

		parent::__construct(
			$client_id,
			$redirect_uri,
			$resolved_access_token ? $resolved_access_token : false,
			$resolved_refresh ? $resolved_refresh : false
		);

		$this->using_convertkit_credentials = $settings->using_convertkit_credentials();
	}

	/**
	 * Whether access and refresh token exist.
	 *
	 * @return bool
	 */
	public function has_access_and_refresh_token(): bool {
		return ! empty( $this->access_token ) && ! empty( $this->refresh_token );
	}

	/**
	 * Validate API credentials.
	 *
	 * @return true|\WP_Error
	 */
	public function validate_api_credentials() {
		if ( $this->has_access_and_refresh_token() ) {
			return true;
		}

		return new \WP_Error( self::ERROR_NO_CONNECTION, esc_html__( 'Connect to Kit using OAuth to continue.', 'glue-link' ) );
	}

	/**
	 * Exchange authorization code for OAuth credentials.
	 *
	 * @param string $authorization_code Authorization code.
	 * @return array|\WP_Error
	 */
	public function get_access_token( $authorization_code ) {
		$result = parent::get_access_token( $authorization_code );

		if ( is_wp_error( $result ) ) {
			do_action( 'glue_link_api_get_access_token_error', $result, $this->client_id );
			return $result;
		}

		do_action( 'glue_link_api_get_access_token', $result, $this->client_id );
		return $result;
	}

	/**
	 * Refresh OAuth token.
	 *
	 * @return array|\WP_Error
	 */
	public function refresh_token() {
		$previous_access_token  = (string) $this->access_token;
		$previous_refresh_token = (string) $this->refresh_token;
		$result                 = parent::refresh_token();

		if ( is_wp_error( $result ) ) {
			do_action( 'glue_link_api_refresh_token_error', $result, $this->client_id );
			return $result;
		}

		do_action( 'glue_link_api_refresh_token', $result, $this->client_id, $previous_access_token, $previous_refresh_token );
		return $result;
	}

	/**
	 * Get current account.
	 *
	 * @return array|\WP_Error|null
	 */
	public function get_account() {
		if ( ! $this->has_access_and_refresh_token() ) {
			return new \WP_Error( self::ERROR_NO_CONNECTION, esc_html__( 'Connect to Kit using OAuth to continue.', 'glue-link' ) );
		}

		return parent::get_account();
	}

	/**
	 * Subscribe to form.
	 *
	 * @param int    $form_id Form ID.
	 * @param string $email Email.
	 * @param string $first_name First name.
	 * @param array  $fields Fields.
	 * @param array  $tags Tags.
	 * @return array|\WP_Error|null
	 */
	public function subscribe_to_form( int $form_id, string $email, string $first_name, array $fields = array(), array $tags = array() ) {
		$validate = $this->validate_email( $email );
		if ( is_wp_error( $validate ) ) {
			return $validate;
		}

		$subscriber = parent::create_subscriber( $email, $first_name, 'active', $fields );
		if ( is_wp_error( $subscriber ) ) {
			return $subscriber;
		}

		$subscriber_id = isset( $subscriber['subscriber']['id'] ) ? (int) $subscriber['subscriber']['id'] : 0;
		if ( $subscriber_id <= 0 ) {
			return new \WP_Error( self::ERROR_API_ERROR, esc_html__( 'Unable to determine subscriber ID.', 'glue-link' ) );
		}

		$result = parent::add_subscriber_to_form( $form_id, $subscriber_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! empty( $tags ) ) {
			foreach ( wp_parse_id_list( $tags ) as $tag_id ) {
				$tag_result = parent::tag_subscriber( (int) $tag_id, $subscriber_id );
				if ( is_wp_error( $tag_result ) ) {
					return $tag_result;
				}
			}
		}

		return $result;
	}

	/**
	 * Validate email.
	 *
	 * @param string $email Email.
	 * @return true|\WP_Error
	 */
	private function validate_email( string $email ) {
		if ( empty( $email ) ) {
			return new \WP_Error( self::ERROR_NO_EMAIL, esc_html__( 'Email address is required.', 'glue-link' ) );
		}

		if ( ! is_email( $email ) ) {
			return new \WP_Error( self::ERROR_NO_EMAIL, sprintf( esc_html__( 'Invalid email address format: %s', 'glue-link' ), $email ) );
		}

		return true;
	}
}
