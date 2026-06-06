<?php
/**
 * Kit API wrapper class.
 *
 * @package WebberZone\FreemKit
 * @since 1.0.0
 */

namespace WebberZone\FreemKit\Kit;

/**
 * Class Kit_API
 *
 * Wraps Kit's official ConvertKit_API_V4 library.
 */
class Kit_API extends \ConvertKit_API_V4 {

	/**
	 * Error codes.
	 */
	protected const ERROR_NO_CONNECTION = 'invalid_connection';
	protected const ERROR_NO_EMAIL      = 'invalid_email';
	protected const ERROR_API_ERROR     = 'api_error';

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
		$client_id             = defined( 'FREEMKIT_KIT_OAUTH_CLIENT_ID' ) ? (string) FREEMKIT_KIT_OAUTH_CLIENT_ID : '';
		$redirect_uri          = defined( 'FREEMKIT_KIT_OAUTH_REDIRECT_URI' ) ? (string) FREEMKIT_KIT_OAUTH_REDIRECT_URI : '';
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

		return new \WP_Error( self::ERROR_NO_CONNECTION, esc_html__( 'Connect to Kit using OAuth to continue.', 'freemkit' ) );
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
			do_action( 'freemkit_api_get_access_token_error', $result, $this->client_id );
			return $result;
		}

		do_action( 'freemkit_api_get_access_token', $result, $this->client_id );
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
			do_action( 'freemkit_api_refresh_token_error', $result, $this->client_id );
			return $result;
		}

		do_action( 'freemkit_api_refresh_token', $result, $this->client_id, $previous_access_token, $previous_refresh_token );
		return $result;
	}

	/**
	 * Get current account.
	 *
	 * @return array|\WP_Error|null
	 */
	public function get_account() {
		if ( ! $this->has_access_and_refresh_token() ) {
			return new \WP_Error( self::ERROR_NO_CONNECTION, esc_html__( 'Connect to Kit using OAuth to continue.', 'freemkit' ) );
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

		// create_subscriber acts as upsert: creates new or updates first_name for existing.
		// Custom fields are only set for new subscribers via this endpoint.
		$subscriber = parent::create_subscriber( $email, $first_name, 'active', $fields );
		if ( is_wp_error( $subscriber ) ) {
			return $subscriber;
		}

		$subscriber_id = isset( $subscriber['subscriber']['id'] ) ? (int) $subscriber['subscriber']['id'] : 0;
		if ( $subscriber_id <= 0 ) {
			return new \WP_Error( self::ERROR_API_ERROR, esc_html__( 'Unable to determine subscriber ID.', 'freemkit' ) );
		}

		// Explicitly update custom fields for existing subscribers, since create_subscriber
		// only updates first_name on upsert and silently ignores fields for existing records.
		if ( ! empty( $fields ) ) {
			$update_result = parent::update_subscriber( $subscriber_id, $first_name, '', $fields );
			if ( is_wp_error( $update_result ) ) {
				return $update_result;
			}
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
	 * Bulk subscribe multiple subscribers to Kit forms and tags.
	 *
	 * Uses Kit's v4 bulk endpoints to minimise API calls.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array<string, mixed>> $tasks Array of tasks. Each task must contain:
	 *                                                email (string), first_name (string),
	 *                                                form_ids (int[]), tag_ids (int[]).
	 * @return array<string, array<string, mixed>>|\WP_Error
	 *         Array mapping email to result arrays on success, WP_Error on total failure.
	 */
	public function bulk_subscribe_to_kit( array $tasks ) {
		// Build subscribers array for bulk create.
		$subscribers    = array();
		$email_to_task  = array();
		$invalid_emails = array();

		foreach ( $tasks as $task ) {
			$email = isset( $task['email'] ) ? sanitize_email( (string) $task['email'] ) : '';
			if ( empty( $email ) || ! is_email( $email ) ) {
				$invalid_emails[] = isset( $task['email'] ) ? (string) $task['email'] : '';
				continue;
			}

			$first_name = isset( $task['first_name'] ) ? sanitize_text_field( (string) $task['first_name'] ) : '';

			$subscribers[] = array(
				'email_address' => $email,
				'first_name'    => $first_name,
				'state'         => 'active',
			);

			$email_to_task[ strtolower( $email ) ] = $task;
		}

		if ( empty( $subscribers ) ) {
			return new \WP_Error( self::ERROR_NO_EMAIL, esc_html__( 'No valid subscribers to process.', 'freemkit' ) );
		}

		// 1. Bulk create subscribers.
		$create_result = parent::create_subscribers( $subscribers );
		if ( is_wp_error( $create_result ) ) {
			return $create_result;
		}

		// Map email to subscriber ID from response.
		$email_to_id = array();
		$failures    = array();

		if ( ! empty( $create_result['subscribers'] ) && is_array( $create_result['subscribers'] ) ) {
			foreach ( $create_result['subscribers'] as $sub ) {
				if ( ! is_array( $sub ) || empty( $sub['email_address'] ) ) {
					continue;
				}
				$email                 = strtolower( (string) $sub['email_address'] );
				$email_to_id[ $email ] = isset( $sub['id'] ) ? (int) $sub['id'] : 0;
			}
		}

		if ( ! empty( $create_result['failures'] ) && is_array( $create_result['failures'] ) ) {
			foreach ( $create_result['failures'] as $failure ) {
				if ( ! is_array( $failure ) || ! is_array( $failure['subscriber'] ?? null ) ) {
					continue;
				}
				$email = strtolower( (string) ( $failure['subscriber']['email_address'] ?? '' ) );
				if ( $email ) {
					$failures[ $email ] = isset( $failure['errors'] ) && is_array( $failure['errors'] )
						? implode( ', ', $failure['errors'] )
						: esc_html__( 'Unknown error during bulk creation.', 'freemkit' );
				}
			}
		}

		// 2. Build additions for bulk forms.
		$additions = array();
		foreach ( $email_to_task as $email => $task ) {
			if ( isset( $failures[ $email ] ) || ! isset( $email_to_id[ $email ] ) ) {
				continue;
			}

			$sid = $email_to_id[ $email ];
			if ( $sid <= 0 ) {
				continue;
			}

			$form_ids = isset( $task['form_ids'] ) && is_array( $task['form_ids'] ) ? $task['form_ids'] : array();
			foreach ( $form_ids as $form_id ) {
				$form_id = (int) $form_id;
				if ( $form_id > 0 ) {
					$additions[] = array(
						'form_id'       => $form_id,
						'subscriber_id' => $sid,
					);
				}
			}
		}

		if ( ! empty( $additions ) ) {
			$form_result = parent::add_subscribers_to_forms( $additions );
			if ( is_wp_error( $form_result ) ) {
				$form_error = $form_result->get_error_message();
				foreach ( $email_to_task as $email => $task ) {
					if ( ! isset( $failures[ $email ] ) && isset( $email_to_id[ $email ] ) && $email_to_id[ $email ] > 0 ) {
						$failures[ $email ] = $form_error;
					}
				}
			} elseif ( ! empty( $form_result['failures'] ) && is_array( $form_result['failures'] ) ) {
				foreach ( $form_result['failures'] as $failure ) {
					if ( ! is_array( $failure ) || ! is_array( $failure['subscription'] ?? null ) ) {
						continue;
					}
					$sid = (int) ( $failure['subscription']['subscriber_id'] ?? 0 );
					if ( $sid > 0 ) {
						$email = array_search( $sid, $email_to_id, true );
						if ( false !== $email && ! isset( $failures[ $email ] ) ) {
							$failures[ $email ] = isset( $failure['errors'] ) && is_array( $failure['errors'] )
								? implode( ', ', $failure['errors'] )
								: esc_html__( 'Failed to add to form.', 'freemkit' );
						}
					}
				}
			}
		}

		// 3. Build taggings for bulk tags.
		$taggings = array();
		foreach ( $email_to_task as $email => $task ) {
			if ( isset( $failures[ $email ] ) || ! isset( $email_to_id[ $email ] ) ) {
				continue;
			}

			$sid = $email_to_id[ $email ];
			if ( $sid <= 0 ) {
				continue;
			}

			$tag_ids = isset( $task['tag_ids'] ) && is_array( $task['tag_ids'] ) ? $task['tag_ids'] : array();
			foreach ( $tag_ids as $tag_id ) {
				$tag_id = (int) $tag_id;
				if ( $tag_id > 0 ) {
					$taggings[] = array(
						'tag_id'        => $tag_id,
						'subscriber_id' => $sid,
					);
				}
			}
		}

		if ( ! empty( $taggings ) ) {
			$tag_result = $this->post( 'bulk/tags/subscribers', array( 'taggings' => $taggings ) );
			if ( is_wp_error( $tag_result ) ) {
				$tag_error = $tag_result->get_error_message();
				foreach ( $email_to_task as $email => $task ) {
					if ( ! isset( $failures[ $email ] ) && isset( $email_to_id[ $email ] ) && $email_to_id[ $email ] > 0 ) {
						$failures[ $email ] = $tag_error;
					}
				}
			} elseif ( ! empty( $tag_result['failures'] ) && is_array( $tag_result['failures'] ) ) {
				foreach ( $tag_result['failures'] as $failure ) {
					if ( ! is_array( $failure ) || ! is_array( $failure['tagging'] ?? null ) ) {
						continue;
					}
					$sid = (int) ( $failure['tagging']['subscriber_id'] ?? 0 );
					if ( $sid > 0 ) {
						$email = array_search( $sid, $email_to_id, true );
						if ( false !== $email && ! isset( $failures[ $email ] ) ) {
							$failures[ $email ] = isset( $failure['errors'] ) && is_array( $failure['errors'] )
								? implode( ', ', $failure['errors'] )
								: esc_html__( 'Failed to apply tag.', 'freemkit' );
						}
					}
				}
			}
		}

		// 4. Build final results per email.
		$results = array();
		foreach ( $email_to_task as $email => $task ) {
			$original_email = isset( $task['email'] ) ? sanitize_email( (string) $task['email'] ) : '';
			if ( isset( $failures[ $email ] ) ) {
				$results[ $original_email ] = array(
					'status' => 'error',
					'error'  => $failures[ $email ],
				);
			} elseif ( isset( $email_to_id[ $email ] ) && $email_to_id[ $email ] > 0 ) {
				$results[ $original_email ] = array(
					'status'        => 'success',
					'subscriber_id' => $email_to_id[ $email ],
				);
			} else {
				$results[ $original_email ] = array(
					'status' => 'error',
					'error'  => esc_html__( 'Subscriber not found in Kit response.', 'freemkit' ),
				);
			}
		}

		// Track invalid emails separately.
		foreach ( $invalid_emails as $email ) {
			$results[ $email ] = array(
				'status' => 'error',
				'error'  => esc_html__( 'Invalid email address.', 'freemkit' ),
			);
		}

		return $results;
	}

	/**
	 * Update a subscriber's first name in Kit by email.
	 *
	 * Looks up the Kit subscriber ID by email, then calls update_subscriber.
	 * Returns a WP_Error if the subscriber is not found in Kit.
	 *
	 * @param string $email      Email address.
	 * @param string $first_name New first name.
	 * @param array  $fields     Optional custom fields to update.
	 * @return array|\WP_Error|null
	 */
	public function update_subscriber_name( string $email, string $first_name, array $fields = array() ) {
		$validate = $this->validate_email( $email );
		if ( is_wp_error( $validate ) ) {
			return $validate;
		}

		$subscriber_id = parent::get_subscriber_id( $email );
		if ( is_wp_error( $subscriber_id ) ) {
			return $subscriber_id;
		}

		if ( false === $subscriber_id ) {
			return new \WP_Error( self::ERROR_API_ERROR, esc_html__( 'Subscriber not found in Kit.', 'freemkit' ) );
		}

		return parent::update_subscriber( $subscriber_id, $first_name, '', $fields );
	}

	/**
	 * Unsubscribe a subscriber from Kit by email.
	 *
	 * @param string $email Email address.
	 * @return array|\WP_Error|null
	 */
	public function unsubscribe_subscriber( string $email ) {
		$validate = $this->validate_email( $email );
		if ( is_wp_error( $validate ) ) {
			return $validate;
		}

		return parent::unsubscribe_by_email( $email );
	}

	/**
	 * Resolve a custom field value to its API key.
	 *
	 * Stored settings may contain the numeric field ID (legacy) or the string key.
	 * Kit's update_subscriber endpoint requires the string key (e.g. 'last_name').
	 * If the value is numeric, fetches custom fields from Kit and returns the matching key.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Stored field value — either a numeric ID or a string key.
	 * @return string Resolved key, or the original value if no match found.
	 */
	public function resolve_custom_field_key( string $value ): string {
		if ( ! ctype_digit( $value ) ) {
			return $value;
		}

		$response = parent::get_custom_fields();
		if ( is_wp_error( $response ) || empty( $response['custom_fields'] ) ) {
			return $value;
		}

		foreach ( $response['custom_fields'] as $field ) {
			if ( isset( $field['id'] ) && (string) $field['id'] === $value && ! empty( $field['key'] ) ) {
				return (string) $field['key'];
			}
		}

		return $value;
	}

	/**
	 * Validate email.
	 *
	 * @param string $email Email.
	 * @return true|\WP_Error
	 */
	public function validate_email( string $email ) {
		if ( empty( $email ) ) {
			return new \WP_Error( self::ERROR_NO_EMAIL, esc_html__( 'Email address is required.', 'freemkit' ) );
		}

		if ( ! is_email( $email ) ) {
			/* translators: %s: The invalid email address provided. */
			return new \WP_Error( self::ERROR_NO_EMAIL, sprintf( esc_html__( 'Invalid email address format: %s', 'freemkit' ), $email ) );
		}

		return true;
	}
}
