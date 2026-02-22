<?php
/**
 * Webhook Handler class
 *
 * @package WebberZone\Glue_Link
 */

namespace WebberZone\Glue_Link;

defined( 'ABSPATH' ) || exit;

/**
 * Webhook Handler class
 *
 * @since 1.0.0
 */
class Webhook_Handler {

	/**
	 * Plugin configurations.
	 *
	 * @var array {
	 *     Array of plugin configurations indexed by plugin ID.
	 *
	 *     @type array $plugin_id {
	 *         Configuration for a specific plugin.
	 *
	 *         @type string $slug         Plugin slug.
	 *         @type string $public_key   Public key for the plugin.
	 *         @type string $secret_key   Secret key for the plugin.
	 *         @type int    $free_form_ids     Form ID for free subscribers.
	 *         @type int    $free_tag_ids      Tag ID for free subscribers.
	 *         @type string $free_event_types  Comma-separated webhook events for free mapping.
	 *         @type int    $paid_form_ids     Form ID for paid subscribers.
	 *         @type int    $paid_tag_ids      Tag ID for paid subscribers.
	 *         @type string $paid_event_types  Comma-separated webhook events for paid mapping.
	 *     }
	 * }
	 */
	private $plugin_configs;

	/**
	 * ConvertKit API instance.
	 *
	 * @var Kit_API
	 */
	private $api;

	/**
	 * Database instance.
	 *
	 * @var Database
	 */
	private $database;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param array    $plugin_configs {
	 *        Plugin configurations array indexed by plugin ID.
	 *
	 *     @type array $plugin_id {
	 *         Configuration for a specific plugin.
	 *
	 *         @type string $slug         Plugin slug.
	 *         @type string $public_key   Public key for the plugin.
	 *         @type string $secret_key   Secret key for the plugin.
	 *         @type int    $free_form_ids     Form ID for free subscribers.
	 *         @type int    $free_tag_ids      Tag ID for free subscribers.
	 *         @type string $free_event_types  Comma-separated webhook events for free mapping.
	 *         @type int    $paid_form_ids     Form ID for paid subscribers.
	 *         @type int    $paid_tag_ids      Tag ID for paid subscribers.
	 *         @type string $paid_event_types  Comma-separated webhook events for paid mapping.
	 *     }
	 * }
	 * @param Kit_API  $api      ConvertKit API instance.
	 * @param Database $database Database instance.
	 */
	public function __construct( array $plugin_configs, Kit_API $api, Database $database ) {
		$this->plugin_configs = $plugin_configs;
		$this->api            = $api;
		$this->database       = $database;

		$this->init();
	}

	/**
	 * Initialize the webhook handler.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function init() {
		$endpoint_type = Options_API::get_option( 'webhook_endpoint_type', 'rest' );

		if ( 'rest' === $endpoint_type ) {
			add_action( 'rest_api_init', array( $this, 'register_webhook_endpoint' ) );
		} else {
			add_action( 'parse_request', array( $this, 'handle_query_var_webhook' ) );
		}
	}

	/**
	 * Register the webhook endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_webhook_endpoint() {
		register_rest_route(
			'glue-link/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => array( $this, 'check_webhook_permissions' ),
			)
		);
	}

	/**
	 * Process webhook data.
	 *
	 * @since 1.0.0
	 *
	 * @param string $input Raw webhook input data.
	 * @return array|\WP_Error Array of processed data or WP_Error on failure.
	 */
	private function process_webhook( string $input ) {
		// Decode the request.
		$fs_event = json_decode( $input );
		if ( empty( $fs_event ) || empty( $fs_event->plugin_id ) ) {
			return new \WP_Error( 'invalid_request', 'Invalid request body or missing plugin ID' );
		}

		$plugin_id = $fs_event->plugin_id;

		// Check if plugin ID exists in config.
		if ( ! isset( $this->plugin_configs[ $plugin_id ] ) ) {
			return new \WP_Error( 'invalid_plugin', 'Plugin ID not found in configuration' );
		}

		// Note: Signature validation is handled in validate_webhook_signature() before this method is called.

		// Process the webhook data.
		// Check if objects property exists.
		if ( ! isset( $fs_event->objects ) || ! isset( $fs_event->objects->user ) ) {
			return new \WP_Error( 'invalid_data', 'Missing user data in request.' );
		}

		$user = $fs_event->objects->user;

		// Validate email.
		if ( empty( $user->email ) || ! filter_var( $user->email, FILTER_VALIDATE_EMAIL ) ) {
			return new \WP_Error( 'invalid_email', 'Invalid or missing email address.' );
		}

		$email      = sanitize_email( $user->email );
		$first_name = isset( $user->first ) ? ( 0 === strcasecmp( $user->first, 'Admin' ) ? '' : sanitize_text_field( $user->first ) ) : '';
		$last_name  = isset( $user->last ) ? ( 0 === strcasecmp( $user->last, 'Admin' ) ? '' : sanitize_text_field( $user->last ) ) : '';

		$fields = array();

		$last_name_field = Options_API::get_option( 'last_name_field' );
		if ( $last_name_field ) {
			$fields[ $last_name_field ] = $last_name;
		}
		$custom_fields = Options_API::get_option( 'custom_fields' );
		if ( $custom_fields ) {
			foreach ( $custom_fields as $custom_field ) {
				// Check if property exists before accessing it.
				$property_name  = $custom_field['local_name'] ?? '';
				$property_value = '';

				if ( ! empty( $property_name ) && isset( $user->{$property_name} ) ) {
					$property_value = sanitize_text_field( $user->{$property_name} );
				}

				$fields[ $custom_field['remote_name'] ] = $property_value;
			}
		}

		// Resolve form/tag IDs with fallback to global settings when plugin-level values are empty.
		$forms         = array();
		$plugin_config = $this->plugin_configs[ $plugin_id ];
		$kit_form_id   = Options_API::get_option( 'kit_form_id' );
		$kit_tag_id    = Options_API::get_option( 'kit_tag_id' );

		$free_form_ids    = $this->resolve_list_config( $plugin_config, 'free_form_ids', $kit_form_id );
		$paid_form_ids    = $this->resolve_list_config( $plugin_config, 'paid_form_ids', $kit_form_id );
		$free_tag_ids     = $this->resolve_list_config( $plugin_config, 'free_tag_ids', $kit_tag_id );
		$paid_tag_ids     = $this->resolve_list_config( $plugin_config, 'paid_tag_ids', $kit_tag_id );
		$free_event_types = $this->resolve_list_config( $plugin_config, 'free_event_types', '', array( 'install.installed', 'install.activated' ) );
		$paid_event_types = $this->resolve_list_config( $plugin_config, 'paid_event_types', '', array( 'license.created', 'subscription.created', 'payment.created' ) );

		// Check if type property exists.
		if ( ! isset( $fs_event->type ) ) {
			return new \WP_Error( 'invalid_event', 'Missing event type in request.' );
		}

		$event_type = (string) $fs_event->type;
		$api_result = null;

		if ( in_array( $event_type, $free_event_types, true ) ) {
			$api_result = $this->subscribe_to_forms( $free_form_ids, $email, $first_name, $fields, $free_tag_ids );
		} elseif ( in_array( $event_type, $paid_event_types, true ) ) {
			$api_result = $this->subscribe_to_forms( $paid_form_ids, $email, $first_name, $fields, $paid_tag_ids );
		} else {
			return new \WP_Error( 'event_not_handled', 'Event type not handled.' );
		}

		if ( isset( $api_result ) && is_wp_error( $api_result ) ) {
			// Log the error using WordPress debug log if enabled.
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( '[Glue Link] Kit API Error: %s', $api_result->get_error_message() ) );
			}
			return new \WP_Error( 'api_error', 'Processed with API errors' );
		}

		// Check if subscriber already exists.
		$existing_sub = $this->database->get_subscriber_by_email( $email );

		// Create a new subscriber object with the data.
		$subscriber = new Subscriber(
			array(
				'email'      => $email,
				'first_name' => $first_name,
				'last_name'  => $last_name,
				'fields'     => $fields,
				'forms'      => array(
					'free' => $free_form_ids,
					'paid' => $paid_form_ids,
				),
				'tags'       => array(
					'free' => $free_tag_ids,
					'paid' => $paid_tag_ids,
				),
			)
		);

		// Update existing subscriber or insert new one.
		if ( ! is_wp_error( $existing_sub ) ) {
			$subscriber->id = $existing_sub->id;
			$db_result      = $this->database->update_subscriber( $subscriber );
		} else {
			$db_result = $this->database->add_subscriber( $subscriber );
		}

		if ( is_wp_error( $db_result ) ) {
			// Log the error using WordPress debug log if enabled.
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( '[Glue Link] Database Error: %s', $db_result->get_error_message() ) );
			}
			return new \WP_Error( 'db_error', 'Processed with database errors' );
		}

		return array(
			'status'  => 'success',
			'message' => 'Webhook processed successfully',
		);
	}

	/**
	 * Resolve a plugin configuration list with optional fallback and defaults.
	 *
	 * @param array              $plugin_config Plugin configuration.
	 * @param string             $key           Setting key.
	 * @param string|array|mixed $fallback      Fallback source value.
	 * @param array              $defaults      Default list when everything else is empty.
	 * @return array
	 */
	private function resolve_list_config( array $plugin_config, string $key, $fallback = '', array $defaults = array() ): array {
		$list = wp_parse_list( $plugin_config[ $key ] ?? '' );
		if ( empty( $list ) ) {
			$list = wp_parse_list( $fallback );
		}
		if ( empty( $list ) && ! empty( $defaults ) ) {
			$list = wp_parse_list( $defaults );
		}

		return $list;
	}

	/**
	 * Subscribe a user to each form in a list with optional tags.
	 *
	 * @param array  $form_ids   Form IDs.
	 * @param string $email      Subscriber email.
	 * @param string $first_name Subscriber first name.
	 * @param array  $fields     Custom fields.
	 * @param array  $tag_ids    Tag IDs.
	 * @return array|\WP_Error|null
	 */
	private function subscribe_to_forms( array $form_ids, string $email, string $first_name, array $fields, array $tag_ids ) {
		$result = null;

		foreach ( $form_ids as $form_id ) {
			if ( empty( $form_id ) ) {
				continue;
			}

			$result = $this->api->subscribe_to_form(
				(int) $form_id,
				$email,
				$first_name,
				$fields,
				$tag_ids
			);
			if ( is_wp_error( $result ) ) {
				break;
			}
		}

		return $result;
	}

	/**
	 * Extract and validate signature from various possible header formats.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request|null $request Optional request object for REST API.
	 * @return string The signature or empty string if not found.
	 */
	private function get_signature( ?\WP_REST_Request $request = null ): string {
		$signature = '';

		// Try to get from REST request if provided.
		if ( null !== $request ) {
			$signature = $request->get_header( 'x-signature' );
		}

		// Check alternative header formats if signature is empty.
		if ( empty( $signature ) && function_exists( 'apache_request_headers' ) ) {
			$headers = apache_request_headers();
			foreach ( $headers as $key => $value ) {
				// Try different case variations.
				if ( strtolower( $key ) === 'x-signature' ) {
					$signature = $value;
					break;
				}
			}
		}

		// If still empty, check $_SERVER for transformed header.
		if ( empty( $signature ) && isset( $_SERVER['HTTP_X_SIGNATURE'] ) ) {
			$signature = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_SIGNATURE'] ) );
		}

		return $signature;
	}

	/**
	 * Handle webhook requests via query variable.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_query_var_webhook() {
		if ( empty( $_SERVER['QUERY_STRING'] ) ) {
			return;
		}

		$query_string = sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) );
		if ( false === strpos( $query_string, 'glue_webhook' ) ) {
			return;
		}

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $request_method ) {
			status_header( 405 );
			die( 'Invalid request method' );
		}

		$input     = file_get_contents( 'php://input' );
		$signature = $this->get_signature();

		// Validate signature before processing.
		$validation = $this->validate_webhook_signature( $input, $signature );
		if ( is_wp_error( $validation ) ) {
			status_header( 400 );
			die( esc_html( $validation->get_error_message() ) );
		}

		// Process the webhook.
		$result = $this->process_webhook( $input );
		if ( is_wp_error( $result ) ) {
			status_header( 400 );
			die( esc_html( $result->get_error_message() ) );
		}

		status_header( 200 );
		die( esc_html( $result['message'] ) );
	}

	/**
	 * Validate webhook signature and configuration without processing side effects.
	 *
	 * @since 1.0.0
	 *
	 * @param string $input Raw webhook input data.
	 * @param string $signature Request signature.
	 * @return true|\WP_Error True if validation passes, WP_Error otherwise.
	 */
	private function validate_webhook_signature( string $input, string $signature ) {
		// Decode the request.
		$fs_event = json_decode( $input );
		if ( empty( $fs_event ) || empty( $fs_event->plugin_id ) ) {
			return new \WP_Error( 'invalid_request', 'Invalid request body or missing plugin ID' );
		}

		$plugin_id = $fs_event->plugin_id;

		// Check if plugin ID exists in config.
		if ( ! isset( $this->plugin_configs[ $plugin_id ] ) ) {
			return new \WP_Error( 'invalid_plugin', 'Plugin ID not found in configuration' );
		}

		// Verify the signature.
		$plugin_config = $this->plugin_configs[ $plugin_id ];
		$hash          = hash_hmac( 'sha256', $input, $plugin_config['secret_key'] );

		if ( ! hash_equals( $hash, $signature ) ) {
			return new \WP_Error( 'invalid_signature', 'Invalid signature' );
		}

		return true;
	}

	/**
	 * Check webhook permissions for REST API.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return true|\WP_Error True if permissions are valid, \WP_Error otherwise.
	 */
	public function check_webhook_permissions( \WP_REST_Request $request ) {
		// Get signature from headers.
		$signature = $this->get_signature( $request );

		// Validate signature only, without processing side effects.
		return $this->validate_webhook_signature( $request->get_body(), $signature );
	}

	/**
	 * Processes incoming webhook requests from ConvertKit via REST API.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_webhook( \WP_REST_Request $request ) {
		// Get signature from headers.
		$signature = $this->get_signature( $request );

		// Validate signature before processing.
		$validation = $this->validate_webhook_signature( $request->get_body(), $signature );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Process the webhook.
		$result = $this->process_webhook( $request->get_body() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			array(
				'status'  => 'success',
				'message' => esc_html( $result['message'] ),
			),
			200
		);
	}
}
