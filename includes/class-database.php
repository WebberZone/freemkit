<?php
/**
 * Database management class.
 *
 * @package WebberZone\FreemKit
 */

namespace WebberZone\FreemKit;

use WebberZone\FreemKit\Subscriber;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class to handle database operations.
 *
 * @since 1.0.0
 */
class Database {

	/**
	 * Table name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public $table_name;

	/**
	 * Database version.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public $db_version = '1.0.0';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		global $wpdb;

		$this->table_name = $wpdb->prefix . 'freemkit_subscribers';
	}

	/**
	 * Create the database table.
	 *
	 * @since 1.0.0
	 *
	 * @return bool|\WP_Error True if table created successfully, \WP_Error on failure.
	 */
	public function create_table() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(100) NOT NULL,
			first_name varchar(50) DEFAULT '',
			last_name varchar(50) DEFAULT '',
			fields longtext DEFAULT NULL,
			tags longtext DEFAULT NULL,
			forms longtext DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			created datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			modified datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			KEY status (status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$result = dbDelta( $sql );

		if ( ! empty( $wpdb->last_error ) ) {
			return new \WP_Error(
				'database_creation_error',
				sprintf(
					/* translators: 1: Database error */
					__( 'Error creating database table: %s', 'freemkit' ),
					$wpdb->last_error
				)
			);
		}

		add_option( 'freemkit_db_version', $this->db_version );

		return true;
	}

	/**
	 * Check if database needs to be updated.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if update is required, false otherwise.
	 */
	public function needs_update() {
		$current_version = get_option( 'freemkit_db_version', '0' );
		return version_compare( $current_version, $this->db_version, '<' );
	}

	/**
	 * Get table name.
	 *
	 * @since 1.0.0
	 *
	 * @return string Table name.
	 */
	public function get_table_name() {
		return $this->table_name;
	}

	/**
	 * Clear subscriber cache.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $identifier Subscriber ID or email.
	 */
	public function clear_subscriber_cache( $identifier ) {
		if ( is_int( $identifier ) ) {
			wp_cache_delete( 'freemkit_subscriber_' . $identifier, 'freemkit' );
		} elseif ( is_string( $identifier ) ) {
			wp_cache_delete( 'freemkit_subscriber_email_' . md5( $identifier ), 'freemkit' );
		}
	}

	/**
	 * Get subscriber by ID.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Subscriber ID.
	 * @return Subscriber|\WP_Error Subscriber object or \WP_Error on failure.
	 */
	public function get_subscriber( $id ) {
		global $wpdb;

		$cache_key  = 'freemkit_subscriber_' . $id;
		$subscriber = wp_cache_get( $cache_key, 'freemkit' );

		if ( false === $subscriber ) {
			$table = $this->get_table_name();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$subscriber = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$id
				)
			);

			if ( null === $subscriber ) {
				return new \WP_Error(
					'subscriber_not_found',
					__( 'Subscriber not found.', 'freemkit' )
				);
			}

			wp_cache_set( $cache_key, $subscriber, 'freemkit' );
		}

		return new Subscriber( (array) $subscriber );
	}

	/**
	 * Get subscriber by email.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email Subscriber email.
	 * @return Subscriber|\WP_Error Subscriber object or \WP_Error on failure.
	 */
	public function get_subscriber_by_email( $email ) {
		global $wpdb;

		$cache_key  = 'freemkit_subscriber_email_' . md5( $email );
		$subscriber = wp_cache_get( $cache_key, 'freemkit' );

		if ( false === $subscriber ) {
			$table = $this->get_table_name();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$subscriber = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE email = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$email
				)
			);

			if ( ! $subscriber ) {
				return new \WP_Error(
					'subscriber_not_found',
					__( 'Subscriber not found.', 'freemkit' )
				);
			}

			wp_cache_set( $cache_key, $subscriber, 'freemkit' );
		}

		return new Subscriber( (array) $subscriber );
	}

	/**
	 * Add a new subscriber.
	 *
	 * @since 1.0.0
	 *
	 * @param Subscriber $subscriber Subscriber object.
	 * @return int|\WP_Error Subscriber ID on success, \WP_Error on failure.
	 */
	public function add_subscriber( $subscriber ) {
		global $wpdb;

		// Validate required fields early.
		if ( empty( $subscriber->email ) ) {
			return new \WP_Error(
				'missing_email',
				__( 'Email is required.', 'freemkit' )
			);
		}

		// Sanitize email once.
		$sanitized_email = sanitize_email( $subscriber->email );

		// Use prepared statement for better security.
		$existing = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id FROM {$this->get_table_name()} WHERE email = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$sanitized_email
			)
		);

		if ( $existing ) {
			return new \WP_Error(
				'subscriber_exists',
				__( 'Subscriber already exists.', 'freemkit' )
			);
		}

		// Extract method for common data preparation.
		$data = $this->prepare_subscriber_data( $subscriber );

		$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->get_table_name(),
			$data['data'],
			$data['format']
		);

		if ( false === $result ) {
			return new \WP_Error(
				'db_insert_error',
				__( 'Could not add subscriber.', 'freemkit' )
			);
		}

		$subscriber_id = (int) $wpdb->insert_id;
		$this->clear_subscriber_cache( $sanitized_email );

		/**
		 * Fires after a subscriber is added.
		 *
		 * @since 1.0.0
		 *
		 * @param int        $subscriber_id The ID of the subscriber.
		 * @param Subscriber $subscriber    The subscriber object.
		 */
		do_action( 'freemkit_after_add_subscriber', $subscriber_id, $subscriber );

		return $subscriber_id;
	}

	/**
	 * Update an existing subscriber.
	 *
	 * @since 1.0.0
	 *
	 * @param Subscriber $subscriber Subscriber object.
	 * @return int|\WP_Error Subscriber ID on success, \WP_Error on failure.
	 */
	public function update_subscriber( $subscriber ) {
		global $wpdb;

		if ( empty( $subscriber->id ) ) {
			return $this->add_subscriber( $subscriber );
		}

		if ( empty( $subscriber->email ) ) {
			return new \WP_Error(
				'missing_email',
				__( 'Email is required.', 'freemkit' )
			);
		}

		// Get existing subscriber with single query.
		$existing = $this->get_subscriber( $subscriber->id );
		if ( is_wp_error( $existing ) ) {
			return $this->add_subscriber( $subscriber );
		}

		// Check email uniqueness with exception for current subscriber.
		$sanitized_email = sanitize_email( $subscriber->email );
		$email_exists    = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id FROM {$this->get_table_name()} WHERE email = %s AND id != %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$sanitized_email,
				$subscriber->id
			)
		);

		if ( $email_exists ) {
			return new \WP_Error(
				'email_exists',
				__( 'Email is already taken by another subscriber.', 'freemkit' )
			);
		}

		// Merge and prepare data.
		$subscriber = $this->merge_subscriber_data( $existing, $subscriber );
		$data       = $this->prepare_subscriber_data( $subscriber, false );

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->get_table_name(),
			$data['data'],
			array( 'id' => $subscriber->id ),
			$data['format'],
			array( '%d' )
		);

		if ( false === $result && ! empty( $wpdb->last_error ) ) {
			return new \WP_Error(
				'db_update_error',
				__( 'Could not update subscriber.', 'freemkit' )
			);
		}

		// Clear both old and new email caches.
		$this->clear_subscriber_cache( $subscriber->id );
		$this->clear_subscriber_cache( $sanitized_email );
		if ( $existing->email !== $sanitized_email ) {
			$this->clear_subscriber_cache( $existing->email );
		}

		/**
		 * Fires after a subscriber is updated.
		 *
		 * @since 1.0.0
		 *
		 * @param int        $subscriber_id The ID of the subscriber.
		 * @param Subscriber $subscriber    The updated subscriber object.
		 * @param Subscriber $existing      The original subscriber object.
		 */
		do_action( 'freemkit_after_update_subscriber', $subscriber->id, $subscriber, $existing );

		return $subscriber->id;
	}

	/**
	 * Prepare subscriber data for database operations.
	 *
	 * @since 1.0.0
	 *
	 * @param Subscriber $subscriber Subscriber object.
	 * @param bool       $is_new     Whether this is a new subscriber.
	 * @return array Array with 'data' and 'format' keys.
	 */
	public function prepare_subscriber_data( $subscriber, $is_new = true ) {
		$data = array(
			'email'      => sanitize_email( $subscriber->email ),
			'first_name' => sanitize_text_field( $subscriber->first_name ),
			'last_name'  => sanitize_text_field( $subscriber->last_name ),
			'fields'     => $this->prepare_array_field( $subscriber->fields ),
			'tags'       => $this->prepare_array_field( $subscriber->tags ),
			'forms'      => $this->prepare_array_field( $subscriber->forms ),
			'status'     => ! empty( $subscriber->status ) ? $subscriber->status : 'active',
		);

		if ( $is_new ) {
			$data['created'] = ! empty( $subscriber->created )
				? $subscriber->created
				: current_time( 'mysql', true );
			$format          = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );
		} else {
			$format = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' );
		}

		return array(
			'data'   => $data,
			'format' => $format,
		);
	}

	/**
	 * Prepare array fields for database storage.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $field Field value.
	 * @return string Comma-separated unique values.
	 */
	public function prepare_array_field( $field ) {
		if ( ! is_array( $field ) ) {
			return (string) $field;
		}
		return implode( ',', array_unique( array_filter( $field ) ) );
	}

	/**
	 * Merge existing and new subscriber data.
	 *
	 * @since 1.0.0
	 *
	 * @param Subscriber $existing_subscriber Existing subscriber.
	 * @param Subscriber $new_subscriber      New subscriber data.
	 * @return Subscriber
	 */
	public function merge_subscriber_data( $existing_subscriber, $new_subscriber ) {
		$fields_to_merge = array( 'fields', 'tags', 'forms' );

		foreach ( $fields_to_merge as $field ) {
			$new_subscriber->$field = array_unique(
				array_merge(
					wp_parse_list( $existing_subscriber->$field ),
					wp_parse_list( $new_subscriber->$field )
				)
			);
		}

		return $new_subscriber;
	}

	/**
	 * Delete subscriber.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Subscriber ID.
	 * @return bool|\WP_Error True on success, \WP_Error on failure.
	 */
	public function delete_subscriber( $id ) {
		global $wpdb;

		$subscriber = $this->get_subscriber( $id );
		if ( is_wp_error( $subscriber ) ) {
			return $subscriber;
		}

		$table = $this->get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$table,
			array( 'id' => $id ),
			array( '%d' )
		);

		if ( false === $result ) {
			return new \WP_Error(
				'db_delete_error',
				__( 'Could not delete subscriber.', 'freemkit' )
			);
		}

		$this->clear_subscriber_cache( $id );
		$this->clear_subscriber_cache( $subscriber->email );

		/**
		 * Fires after a subscriber is deleted.
		 *
		 * @since 1.0.0
		 *
		 * @param int       $id         Subscriber ID.
		 * @param Subscriber $subscriber Subscriber object.
		 */
		do_action( 'freemkit_delete_subscriber', $id, $subscriber );

		return true;
	}

	/**
	 * Get subscribers.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args {
	 *     Optional. Arguments to retrieve subscribers.
	 *
	 *     @type string       $search   Search term.
	 *     @type string|array $status   Single status or array of statuses.
	 *     @type int         $per_page  Number of subscribers per page.
	 *     @type int         $page      Page number.
	 *     @type string      $orderby   Column to order by.
	 *     @type string      $order     Order direction.
	 * }
	 * @return Subscriber[] Array of Subscriber objects.
	 */
	public function get_subscribers( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'search'   => '',
			'status'   => '',
			'per_page' => 10,
			'page'     => 1,
			'orderby'  => 'id',
			'order'    => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		// Build WHERE clause.
		$where  = array();
		$values = array();

		if ( ! empty( $args['search'] ) ) {
			$search_like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]     = '(email LIKE %s OR first_name LIKE %s OR last_name LIKE %s)';
			$values[]    = $search_like;
			$values[]    = $search_like;
			$values[]    = $search_like;
		}

		if ( ! empty( $args['status'] ) ) {
			$statuses = wp_parse_list( $args['status'] );
			if ( ! empty( $statuses ) ) {
				$placeholders = array_fill( 0, count( $statuses ), '%s' );
				$where[]      = 'status IN (' . implode( ', ', $placeholders ) . ')';
				$values       = array_merge( $values, $statuses );
			}
		}

		// Default WHERE clause if no conditions.
		if ( empty( $where ) ) {
			$where_clause = '';
		} else {
			$where_clause = 'WHERE ' . implode( ' AND ', $where );
		}

		// Calculate offset.
		$offset = ( $args['page'] - 1 ) * $args['per_page'];

		$table = $this->get_table_name();

		// Build query.
		$sql = "SELECT * FROM {$table} {$where_clause}";

		// Add ORDER BY clause.
		$orderby = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] );
		if ( ! empty( $orderby ) ) {
			$sql .= " ORDER BY {$orderby}";
		}

		// Add LIMIT and OFFSET.
		$sql .= ' LIMIT %d OFFSET %d';

		// Merge LIMIT and OFFSET values.
		$values = array_merge( $values, array( $args['per_page'], $offset ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );

		// Convert results to Subscriber objects.
		$items = array();
		foreach ( $results as $result ) {
			$items[] = new Subscriber( (array) $result );
		}

		return $items;
	}

	/**
	 * Get subscriber counts by status.
	 *
	 * @since 1.0.0
	 *
	 * @return array|\WP_Error Array of counts by status or \WP_Error on failure.
	 */
	public function get_subscriber_counts() {
		global $wpdb;

		$cache_key = 'freemkit_subscriber_counts';
		$counts    = wp_cache_get( $cache_key, 'freemkit' );

		if ( false === $counts ) {
			$table = $this->get_table_name();

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
			$results = $wpdb->get_results( "SELECT status, COUNT(*) as count FROM {$table} GROUP BY status" );

			if ( null === $results ) {
				return new \WP_Error(
					'db_query_error',
					sprintf(
						/* translators: %s: Database error */
						__( 'Could not get subscriber counts: %s', 'freemkit' ),
						$wpdb->last_error
					)
				);
			}

			$counts = array();
			foreach ( $results as $row ) {
				$counts[ $row->status ] = (int) $row->count;
			}

			wp_cache_set( $cache_key, $counts, 'freemkit', HOUR_IN_SECONDS );
		}

		return $counts;
	}

	/**
	 * Delete multiple subscribers.
	 *
	 * @since 1.0.0
	 *
	 * @param array $ids Array of subscriber IDs.
	 * @return bool|\WP_Error True on success, \WP_Error on failure.
	 */
	public function delete_subscribers( $ids ) {
		global $wpdb;

		if ( empty( $ids ) ) {
			return new \WP_Error(
				'invalid_ids',
				__( 'No subscriber IDs provided.', 'freemkit' )
			);
		}

		// Parse and validate IDs.
		$ids = wp_parse_id_list( $ids );

		if ( empty( $ids ) ) {
			return new \WP_Error(
				'invalid_ids',
				__( 'No valid subscriber IDs provided.', 'freemkit' )
			);
		}

		// Delete subscribers.
		$table  = $this->get_table_name();
		$ids    = implode( ',', $ids );
		$result = $wpdb->query( "DELETE FROM {$table} WHERE id IN ({$ids})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false === $result ) {
			return new \WP_Error(
				'db_delete_error',
				sprintf(
					/* translators: %s: Database error */
					__( 'Could not delete subscribers: %s', 'freemkit' ),
					$wpdb->last_error
				)
			);
		}

		return true;
	}

	/**
	 * Get subscriber count based on filters.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args {
	 *     Optional. Arguments to filter subscribers.
	 *
	 *     @type string       $search  Search term.
	 *     @type string|array $status  Single status or array of statuses.
	 * }
	 * @return int Total number of subscribers matching the criteria.
	 */
	public function get_subscriber_count( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'search' => '',
			'status' => '',
		);

		$args = wp_parse_args( $args, $defaults );

		// Build WHERE clause.
		$where  = array();
		$values = array();

		if ( ! empty( $args['search'] ) ) {
			$search_like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]     = '(email LIKE %s OR first_name LIKE %s OR last_name LIKE %s)';
			$values[]    = $search_like;
			$values[]    = $search_like;
			$values[]    = $search_like;
		}

		if ( ! empty( $args['status'] ) ) {
			$statuses = wp_parse_list( $args['status'] );
			if ( ! empty( $statuses ) ) {
				$placeholders = array_fill( 0, count( $statuses ), '%s' );
				$where[]      = 'status IN (' . implode( ', ', $placeholders ) . ')';
				$values       = array_merge( $values, $statuses );
			}
		}

		if ( ! empty( $where ) ) {
			$where_clause = implode( ' AND ', $where );
			$where_clause = $wpdb->prepare( $where_clause, $values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$where_clause = '1=1';
		}

		$table = $this->get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}" );
	}
}
