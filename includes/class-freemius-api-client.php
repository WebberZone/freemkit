<?php
/**
 * Freemius API Client.
 *
 * @package WebberZone\FreemKit
 */

namespace WebberZone\FreemKit;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches users and licenses from the Freemius REST API for a single product.
 *
 * @since 1.0.0
 */
class Freemius_API_Client {

	/**
	 * Freemius API base URL.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const API_BASE = 'https://api.freemius.com/v1/products/';

	/**
	 * Product ID.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected string $plugin_id;

	/**
	 * Public key (from the product's Settings page in the Freemius dashboard).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected string $public_key;

	/**
	 * Secret key (from the product's Settings page in the Freemius dashboard).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected string $secret_key;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $plugin_id  Freemius product ID.
	 * @param string $public_key Public key from the Freemius dashboard.
	 * @param string $secret_key Secret key from the Freemius dashboard.
	 */
	public function __construct( string $plugin_id, string $public_key, string $secret_key ) {
		$this->plugin_id  = $plugin_id;
		$this->public_key = $public_key;
		$this->secret_key = $secret_key;
	}

	/**
	 * Generate FS signature authorization headers.
	 *
	 * @since 1.0.0
	 *
	 * @param string $resource_path Resource path WITHOUT query string (e.g. '/v1/plugins/123/users.json').
	 * @param string $method        HTTP method.
	 * @param string $body          Request body (for POST/PUT).
	 * @return array Headers keyed by name.
	 */
	private function generate_fs_auth( string $resource_path, string $method = 'GET', string $body = '' ): array {
		$method       = strtoupper( $method );
		$content_md5  = '';
		$content_type = '';
		$date         = gmdate( 'r' );

		if ( in_array( $method, array( 'POST', 'PUT' ), true ) ) {
			$content_type = 'application/json';
			if ( '' !== $body ) {
				$content_md5 = md5( $body );
			}
		}

		$string_to_sign = implode(
			"\n",
			array(
				$method,
				$content_md5,
				$content_type,
				$date,
				$resource_path,
			)
		);

		$auth_type = ( $this->secret_key !== $this->public_key ) ? 'FS' : 'FSP';
		$signature = rtrim(
			strtr(
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				base64_encode( hash_hmac( 'sha256', $string_to_sign, $this->secret_key ) ),
				'+/',
				'-_'
			),
			'='
		);

		$headers = array(
			'Date'          => $date,
			'Authorization' => $auth_type . ' ' . $this->plugin_id . ':' . $this->public_key . ':' . $signature,
		);

		if ( '' !== $content_md5 ) {
			$headers['Content-MD5'] = $content_md5;
		}

		return $headers;
	}

	/**
	 * Make an authenticated GET request to the Freemius API.
	 *
	 * @since 1.0.0
	 *
	 * @param string $endpoint Path relative to the product base (e.g. 'users.json').
	 * @param array  $params   Query parameters.
	 * @return array|\WP_Error Decoded response array or WP_Error on failure.
	 */
	private function api_get( string $endpoint, array $params = array() ) {
		$resource_path     = '/v1/plugins/' . rawurlencode( $this->plugin_id ) . '/' . $endpoint;
		$url               = add_query_arg( $params, 'https://api.freemius.com' . $resource_path );
		$headers           = $this->generate_fs_auth( $resource_path, 'GET' );
		$headers['Accept'] = 'application/json';

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = (string) wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$api_message = '';
			if ( is_array( $data ) && isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
				$api_message = sanitize_text_field( $data['error']['message'] );
			}

			return new \WP_Error(
				'freemius_api_error',
				$api_message ? $api_message : sprintf(
					/* translators: %d: HTTP status code. */
					esc_html__( 'Freemius API returned HTTP %d.', 'freemkit' ),
					$status_code
				),
				array( 'status_code' => $status_code )
			);
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Fetch a page of users from the Freemius API.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $offset Pagination offset.
	 * @param int    $count  Users per page (max 50).
	 * @param string $filter Optional financial-status filter: 'paid', 'paying', 'never_paid', 'beta', or 'all'.
	 * @return array|\WP_Error Array with 'users' (raw user arrays) and 'has_more' (bool), or WP_Error.
	 */
	public function get_users( int $offset = 0, int $count = 50, string $filter = '' ) {
		$count  = min( 50, max( 1, $count ) );
		$params = array(
			'count'  => $count,
			'offset' => $offset,
		);
		if ( '' !== $filter ) {
			$params['filter'] = $filter;
		}
		$result = $this->api_get( 'users.json', $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$users = isset( $result['users'] ) && is_array( $result['users'] ) ? $result['users'] : array();

		return array(
			'users'    => $users,
			'has_more' => count( $users ) === $count,
		);
	}

	/**
	 * Fetch a page of active licenses from the Freemius API, with embedded user data.
	 *
	 * @since 1.0.0
	 *
	 * @param int $offset Pagination offset.
	 * @param int $count  Licenses per page (max 50).
	 * @return array|\WP_Error Array with 'licenses' (raw license arrays) and 'has_more' (bool), or WP_Error.
	 */
	public function get_licenses( int $offset = 0, int $count = 50 ) {
		$count  = min( 50, max( 1, $count ) );
		$params = array(
			'filter'   => 'active',
			'enriched' => 'true',
			'count'    => $count,
			'offset'   => $offset,
		);
		$result = $this->api_get( 'licenses.json', $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$licenses = isset( $result['licenses'] ) && is_array( $result['licenses'] ) ? $result['licenses'] : array();

		return array(
			'licenses' => $licenses,
			'has_more' => count( $licenses ) === $count,
		);
	}

	/**
	 * Determine the user type from a Freemius user object.
	 *
	 * Checks is_paying (enriched endpoints) then gross lifetime spend (users endpoint).
	 *
	 * @since 1.0.0
	 *
	 * @param array $user Raw user data from the Freemius API.
	 * @return string 'paid', 'trial', or 'free'.
	 */
	public static function get_user_type( array $user ): string {
		if ( ! empty( $user['is_paying'] ) ) {
			return 'paid';
		}
		if ( ! empty( $user['is_trial'] ) ) {
			return 'trial';
		}
		if ( isset( $user['gross'] ) && (float) $user['gross'] > 0 ) {
			return 'paid';
		}
		return 'free';
	}
}
