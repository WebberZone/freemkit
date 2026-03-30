<?php
/**
 * Subscriber Event class file
 *
 * @package WebberZone\FreemKit
 * @since 1.0.0
 */

namespace WebberZone\FreemKit;

/**
 * Class representing a subscriber event (per-plugin webhook interaction).
 *
 * @since 1.0.0
 */
class Subscriber_Event {

	/**
	 * Event ID.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public int $id = 0;

	/**
	 * Subscriber ID.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public int $subscriber_id = 0;

	/**
	 * Freemius plugin ID.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public string $plugin_id = '';

	/**
	 * Plugin slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public string $plugin_slug = '';

	/**
	 * Webhook event type (e.g. install.installed, license.created).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public string $event_type = '';

	/**
	 * User type: free or paid.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public string $user_type = '';

	/**
	 * Comma-separated Kit form IDs.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public string $form_ids = '';

	/**
	 * Comma-separated Kit tag IDs.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public string $tag_ids = '';

	/**
	 * Freemius user ID.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public int $freemius_user_id = 0;

	/**
	 * Created timestamp.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public string $created = '';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param array|object $data Event data.
	 */
	public function __construct( $data = array() ) {
		if ( ! empty( $data ) ) {
			$this->init_by_data( (array) $data );
		}
	}

	/**
	 * Initialize event data from array.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Event data.
	 */
	public function init_by_data( array $data ): void {
		foreach ( get_object_vars( $this ) as $key => $value ) {
			if ( ! isset( $data[ $key ] ) ) {
				continue;
			}

			switch ( $key ) {
				case 'id':
				case 'subscriber_id':
				case 'freemius_user_id':
					$this->$key = (int) $data[ $key ];
					break;
				case 'plugin_id':
				case 'plugin_slug':
				case 'event_type':
				case 'user_type':
				case 'form_ids':
				case 'tag_ids':
					$this->$key = sanitize_text_field( $data[ $key ] );
					break;
				case 'created':
					if ( ! empty( $data[ $key ] ) ) {
						$this->created = $data[ $key ];
					}
					break;
			}
		}
	}

	/**
	 * Convert event to array.
	 *
	 * @since 1.0.0
	 *
	 * @return array Event data.
	 */
	public function to_array(): array {
		return get_object_vars( $this );
	}
}
