<?php
/**
 * Audit Log class.
 *
 * @package WebberZone\FreemKit
 */

namespace WebberZone\FreemKit;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Plugin-wide audit log stored as a non-autoloaded WP option.
 *
 * Entries are capped at {@see DEFAULT_MAX_ENTRIES} using a rolling window so
 * the option never grows unbounded. Logging can be disabled entirely via the
 * `enable_audit_log` plugin setting.
 *
 * @since 1.0.0
 */
class Audit_Log {

	/**
	 * Option name storing log entries.
	 */
	public const OPTION = 'freemkit_audit_log';

	/**
	 * Default maximum number of entries to keep.
	 */
	public const DEFAULT_MAX_ENTRIES = 200;

	/**
	 * Add an audit log entry.
	 *
	 * Silently returns when audit logging is disabled via settings.
	 *
	 * @since 1.0.0
	 *
	 * @param string $event   Event key (snake_case).
	 * @param array  $context Optional key/value context. Email addresses are masked.
	 * @param string $level   Log level: 'info', 'warning', or 'error'.
	 * @return void
	 */
	public static function add( string $event, array $context = array(), string $level = 'info' ): void {
		if ( ! Options_API::get_option( 'enable_audit_log', 1 ) ) {
			return;
		}

		$entries   = self::read();
		$entries[] = array(
			'time'    => time(),
			'event'   => sanitize_key( $event ),
			'level'   => sanitize_key( $level ),
			'context' => self::sanitize_context( $context ),
		);

		$max = (int) apply_filters( 'freemkit_audit_log_max_entries', self::DEFAULT_MAX_ENTRIES );
		if ( $max < 1 ) {
			$max = self::DEFAULT_MAX_ENTRIES;
		}
		if ( count( $entries ) > $max ) {
			$entries = array_slice( $entries, -1 * $max );
		}

		self::write( $entries );
	}

	/**
	 * Return all log entries, newest first.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		return array_reverse( self::read() );
	}

	/**
	 * Clear all entries.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function clear(): void {
		self::write( array() );
	}

	/**
	 * Read current entries from the option.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function read(): array {
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Persist entries as a non-autoloaded option.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int,array<string,mixed>> $entries Entries to persist.
	 * @return void
	 */
	private static function write( array $entries ): void {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $entries, '', false );
			return;
		}

		update_option( self::OPTION, $entries, false );
	}

	/**
	 * Sanitize context values for safe storage. Email addresses are masked.
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Raw context values.
	 * @return array Sanitized context.
	 */
	private static function sanitize_context( array $context ): array {
		$clean = array();
		foreach ( $context as $key => $value ) {
			$sanitized_key = sanitize_key( (string) $key );
			if ( is_array( $value ) ) {
				$clean[ $sanitized_key ] = wp_json_encode( $value );
				continue;
			}
			$string_value = sanitize_text_field( (string) $value );
			// Mask email addresses to avoid storing PII in plain text.
			$string_value            = self::mask_email( $string_value );
			$clean[ $sanitized_key ] = $string_value;
		}

		return $clean;
	}

	/**
	 * Mask an email address so only the domain and first character are visible.
	 *
	 * E.g. john.doe@example.com → j***@example.com
	 *
	 * @since 1.0.0
	 *
	 * @param string $value String that may contain an email address.
	 * @return string Value with email addresses masked.
	 */
	private static function mask_email( string $value ): string {
		return (string) preg_replace_callback(
			'/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/',
			static function ( array $matches ): string {
				$parts = explode( '@', $matches[0], 2 );
				return substr( $parts[0], 0, 1 ) . '***@' . $parts[1];
			},
			$value
		);
	}
}
