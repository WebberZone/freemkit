<?php
/**
 * Sync Admin class.
 *
 * @package WebberZone\FreemKit\Admin
 */

namespace WebberZone\FreemKit\Admin;

use WebberZone\FreemKit\Database;
use WebberZone\FreemKit\Freemius_API_Client;
use WebberZone\FreemKit\Kit\Kit_API;
use WebberZone\FreemKit\Options_API;
use WebberZone\FreemKit\Subscriber;
use WebberZone\FreemKit\Subscriber_Event;
use WebberZone\FreemKit\Util\Hook_Registry;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Sync Admin class — registers the Sync admin page and handles the two-phase AJAX wizard.
 *
 * @since 1.0.0
 */
class Sync_Admin {

	/**
	 * Admin page hook suffix.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public string $page_id = '';

	/**
	 * Database instance.
	 *
	 * @since 1.0.0
	 * @var Database
	 */
	protected Database $database;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Database $database Database instance.
	 */
	public function __construct( Database $database ) {
		$this->database = $database;

		Hook_Registry::add_action( 'admin_menu', array( $this, 'register_sync_page' ) );
		Hook_Registry::add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		Hook_Registry::add_action( 'wp_ajax_freemkit_sync_list_users', array( $this, 'ajax_list_users' ) );
		Hook_Registry::add_action( 'wp_ajax_freemkit_sync_process_batch', array( $this, 'ajax_process_batch' ) );
	}

	/**
	 * Register the Sync submenu page.
	 *
	 * @since 1.0.0
	 */
	public function register_sync_page(): void {
		$this->page_id = add_submenu_page(
			'options-general.php',
			__( 'FreemKit Sync', 'freemkit' ),
			__( 'FreemKit Sync', 'freemkit' ),
			'manage_options',
			'freemkit_sync',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue scripts for the sync page.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_scripts( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->page_id ) {
			return;
		}

		$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		// Tom Select — registered globally by Settings_API on every admin page; enqueue here.
		wp_enqueue_style( 'wz-freemkit-tom-select' );
		wp_enqueue_script( 'wz-freemkit-tom-select' );
		wp_enqueue_script( 'wz-freemkit-tom-select-init' );

		wp_localize_script(
			'wz-freemkit-tom-select-init',
			'freemkitTomSelectSettings',
			array(
				'prefix'   => 'freemkit',
				'nonce'    => wp_create_nonce( 'freemkit_kit_search' ),
				'action'   => 'freemkit_kit_search',
				'endpoint' => '',
				'forms'    => Settings::get_localized_kit_data( 'forms' ),
				'tags'     => Settings::get_localized_kit_data( 'tags' ),
				'strings'  => array(
					/* translators: %s: search term */
					'no_results' => esc_html__( 'No results found for %s', 'freemkit' ),
				),
			)
		);

		wp_enqueue_script(
			'freemkit-sync-admin',
			plugins_url( 'includes/admin/js/sync-admin' . $min . '.js', FREEMKIT_PLUGIN_FILE ),
			array( 'jquery', 'wz-freemkit-tom-select-init' ),
			FREEMKIT_VERSION,
			true
		);

		wp_localize_script(
			'freemkit-sync-admin',
			'FreemKitSync',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'freemkit_sync' ),
				'strings'  => array(
					'fetching'       => __( 'Fetching users…', 'freemkit' ),
					'fetching_more'  => __( 'Fetching next page…', 'freemkit' ),
					'processing'     => __( 'Processing', 'freemkit' ),
					'done'           => __( 'Sync complete.', 'freemkit' ),
					'cancelled'      => __( 'Sync cancelled.', 'freemkit' ),
					'no_users'       => __( 'No users found matching the selected criteria.', 'freemkit' ),
					'fetch_error'    => __( 'Failed to fetch users. Check your settings and try again.', 'freemkit' ),
					'process_error'  => __( 'Error processing user.', 'freemkit' ),
					'request_failed' => __( 'Request failed. Check your connection and try again.', 'freemkit' ),
					'summary'        => __( 'Processed: {processed} • Synced: {synced} • Updated: {updated} • Skipped: {skipped} • Errors: {errors}', 'freemkit' ),
				),
			)
		);
	}

	/**
	 * Render the Sync admin page.
	 *
	 * @since 1.0.0
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'freemkit' ) );
		}

		$plugin_configs = $this->get_plugin_configs();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'FreemKit Sync', 'freemkit' ); ?></h1>
			<p><?php esc_html_e( 'Manually sync users from Freemius or your local subscriber database. Use this to backfill historical users or re-push existing subscribers after a form/tag change.', 'freemkit' ); ?></p>

			<?php if ( empty( $plugin_configs ) ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php
						printf(
							/* translators: %s: Settings page URL. */
							wp_kses_post( __( 'No plugins configured. Add at least one plugin in <a href="%s">FreemKit Settings</a> before running a sync.', 'freemkit' ) ),
							esc_url( admin_url( 'options-general.php?page=freemkit_options_page' ) )
						);
						?>
					</p>
				</div>
			<?php else : ?>

			<form id="freemkit-sync-form" method="post">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Source', 'freemkit' ); ?></th>
						<td>
							<label>
								<input type="radio" name="sync_source" value="freemius" checked />
								<?php esc_html_e( 'Freemius API (import historical users)', 'freemkit' ); ?>
							</label>
							<br />
							<label>
								<input type="radio" name="sync_source" value="local" />
								<?php esc_html_e( 'Local database (re-sync captured subscribers)', 'freemkit' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Freemius imports users who never triggered a webhook. Local re-pushes already-captured subscribers (e.g. after Kit was offline).', 'freemkit' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Destination', 'freemkit' ); ?></th>
						<td>
							<label>
								<input type="radio" name="sync_destination" value="both" />
								<?php esc_html_e( 'Local database + Kit (save locally and subscribe to email list)', 'freemkit' ); ?>
							</label>
							<br />
							<label>
								<input type="radio" name="sync_destination" value="local" checked />
								<?php esc_html_e( 'Local database only (no Kit push)', 'freemkit' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Subscribers are always saved to the local database. Choose "Local database only" to import without immediately pushing to Kit — useful for a staged migration.', 'freemkit' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="freemkit-plugin-id"><?php esc_html_e( 'Plugin', 'freemkit' ); ?></label>
						</th>
						<td>
							<select name="plugin_id" id="freemkit-plugin-id">
								<option value=""><?php esc_html_e( '— All Plugins —', 'freemkit' ); ?></option>
								<?php foreach ( $plugin_configs as $id => $config ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>">
										<?php echo esc_html( ! empty( $config['name'] ) ? $config['name'] : $id ); ?>
										(<?php echo esc_html( $id ); ?>)
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Leave blank to sync all configured plugins. For Freemius source, each plugin is paginated in sequence.', 'freemkit' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'User Types', 'freemkit' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="sync_user_types[]" value="free" />
								<?php esc_html_e( 'Free', 'freemkit' ); ?>
							</label>
							&nbsp;&nbsp;
							<label>
								<input type="checkbox" name="sync_user_types[]" value="paid" checked />
								<?php esc_html_e( 'Paid', 'freemkit' ); ?>
							</label>
							&nbsp;&nbsp;
							<label>
								<input type="checkbox" name="sync_user_types[]" value="trial" />
								<?php esc_html_e( 'Trial (uses free forms/tags)', 'freemkit' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'For Freemius source, filters by current licence status. For local source, filters by recorded event type.', 'freemkit' ); ?>
							</p>
						</td>
					</tr>
					<tbody id="freemkit-kit-fields">
						<tr>
							<th scope="row">
								<label for="freemkit-override-form-ids"><?php esc_html_e( 'Override Kit Form IDs', 'freemkit' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									name="override_form_ids"
									id="freemkit-override-form-ids"
									class="ts_autocomplete"
									data-wp-prefix="freemkit"
									data-wp-action="freemkit_kit_search"
									data-wp-nonce="<?php echo esc_attr( wp_create_nonce( 'freemkit_kit_search' ) ); ?>"
									data-wp-endpoint="forms"
									value=""
								/>
								<p class="description">
									<?php esc_html_e( 'Kit forms to push all synced users to, overriding per-plugin and global settings. Leave blank to use configured settings.', 'freemkit' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="freemkit-override-tag-ids"><?php esc_html_e( 'Override Kit Tag IDs', 'freemkit' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									name="override_tag_ids"
									id="freemkit-override-tag-ids"
									class="ts_autocomplete"
									data-wp-prefix="freemkit"
									data-wp-action="freemkit_kit_search"
									data-wp-nonce="<?php echo esc_attr( wp_create_nonce( 'freemkit_kit_search' ) ); ?>"
									data-wp-endpoint="tags"
									value=""
								/>
								<p class="description">
									<?php esc_html_e( 'Kit tags to apply to all synced users, overriding per-plugin and global settings. Leave blank to use configured settings.', 'freemkit' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( __( 'Start Sync', 'freemkit' ), 'primary', 'freemkit_sync_submit' ); ?>
				<button type="button" id="freemkit-sync-cancel" class="button" style="display:none; margin-left:6px;"><?php esc_html_e( 'Cancel', 'freemkit' ); ?></button>
			</form>

			<div id="freemkit-sync-progress" style="display:none; margin-top:20px;">
				<div style="background:#e0e0e0; border-radius:4px; height:22px; overflow:hidden;">
					<div id="freemkit-progress-bar-inner" style="background:#2271b1; height:100%; width:0%; transition:width 0.2s;"></div>
				</div>
				<p id="freemkit-progress-text" style="margin:6px 0 0;"></p>
			</div>

			<div id="freemkit-sync-results" style="display:none; margin-top:20px;">
				<h2><?php esc_html_e( 'Sync Results', 'freemkit' ); ?></h2>
				<p id="freemkit-sync-summary" style="display:none; font-weight:600;"></p>
				<table class="widefat striped" id="freemkit-results-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Action', 'freemkit' ); ?></th>
							<th><?php esc_html_e( 'Email', 'freemkit' ); ?></th>
							<th><?php esc_html_e( 'Name', 'freemkit' ); ?></th>
							<th><?php esc_html_e( 'User Type', 'freemkit' ); ?></th>
							<th><?php esc_html_e( 'Plugin', 'freemkit' ); ?></th>
							<th><?php esc_html_e( 'Destination', 'freemkit' ); ?></th>
							<th><?php esc_html_e( 'Notes', 'freemkit' ); ?></th>
						</tr>
					</thead>
					<tbody id="freemkit-results-tbody"></tbody>
				</table>
			</div>

			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * AJAX: return a page of users ready to process.
	 *
	 * POST params: nonce, source, plugin_id, plugin_index, user_types[], offset, count
	 *
	 * @since 1.0.0
	 */
	public function ajax_list_users(): void {
		check_ajax_referer( 'freemkit_sync', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'freemkit' ) ) );
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput
		$source       = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : 'local';
		$plugin_id    = isset( $_POST['plugin_id'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin_id'] ) ) : '';
		$plugin_index = isset( $_POST['plugin_index'] ) ? (int) $_POST['plugin_index'] : 0;
		$raw_types    = isset( $_POST['user_types'] ) && is_array( $_POST['user_types'] ) ? wp_unslash( $_POST['user_types'] ) : array();
		$offset       = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0;
		$count        = isset( $_POST['count'] ) ? min( 50, max( 1, (int) $_POST['count'] ) ) : 50;
		// phpcs:enable

		$allowed_types = array( 'free', 'paid', 'trial' );
		$user_types    = array_values( array_intersect( array_map( 'sanitize_key', (array) $raw_types ), $allowed_types ) );

		if ( 'freemius' === $source ) {
			$this->list_freemius_users( $plugin_id, $user_types, $offset, $count, $plugin_index );
		} else {
			$this->list_local_users( $plugin_id, $user_types, $offset, $count );
		}
	}

	/**
	 * Fetch a page of Freemius users for the list step.
	 *
	 * Supports a single plugin (plugin_id set) or all plugins in sequence (plugin_id empty,
	 * advancing via plugin_index once a plugin's pages are exhausted).
	 *
	 * @since 1.0.0
	 *
	 * @param string   $plugin_id    Freemius product ID, or empty for all.
	 * @param string[] $user_types   User type filter.
	 * @param int      $offset       Pagination offset within the current plugin.
	 * @param int      $count        Page size (max 50).
	 * @param int      $plugin_index Index into plugin list (only used when plugin_id is empty).
	 */
	private function list_freemius_users( string $plugin_id, array $user_types, int $offset, int $count, int $plugin_index ): void {
		$plugin_configs = $this->get_plugin_configs();

		$paid_only = array( 'paid' ) === $user_types;
		$free_only = array( 'free' ) === $user_types;

		if ( ! empty( $plugin_id ) ) {
			if ( ! isset( $plugin_configs[ $plugin_id ] ) ) {
				wp_send_json_error( array( 'message' => __( 'Plugin ID not found in settings.', 'freemkit' ) ) );
			}

			$config = $plugin_configs[ $plugin_id ];
			$client = new Freemius_API_Client( $plugin_id, $config['public_key'], $config['secret_key'] );

			if ( $paid_only ) {
				$result = $client->get_users( $offset, $count, 'paid' );
			} elseif ( $free_only ) {
				$result = $client->get_users( $offset, $count, 'never_paid' );
			} else {
				$result = $client->get_users( $offset, $count );
			}

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			$task_type = $paid_only ? 'paid' : null;
			$tasks     = $this->build_freemius_tasks( $result['users'], $plugin_id, $config, $task_type );
			$raw_count = count( $result['users'] );

			wp_send_json_success(
				array(
					'tasks'             => $tasks,
					'total'             => 0,
					'offset'            => $offset + $raw_count,
					'next_plugin_index' => 0,
					'has_more'          => $result['has_more'],
				)
			);
		}

		// All plugins: iterate by index, advancing to the next plugin when pages are exhausted.
		if ( empty( $plugin_configs ) ) {
			wp_send_json_error( array( 'message' => __( 'No plugins configured.', 'freemkit' ) ) );
		}

		$plugin_ids = array_keys( $plugin_configs );

		if ( ! isset( $plugin_ids[ $plugin_index ] ) ) {
			wp_send_json_success(
				array(
					'tasks'             => array(),
					'total'             => 0,
					'offset'            => 0,
					'next_plugin_index' => $plugin_index,
					'has_more'          => false,
				)
			);
		}

		$current_id = $plugin_ids[ $plugin_index ];
		$config     = $plugin_configs[ $current_id ];
		$client     = new Freemius_API_Client( $current_id, $config['public_key'], $config['secret_key'] );

		if ( $paid_only ) {
			$result = $client->get_users( $offset, $count, 'paid' );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: 1: plugin ID 2: error message */
							__( 'Error fetching paid users for plugin %1$s: %2$s', 'freemkit' ),
							$current_id,
							$result->get_error_message()
						),
					)
				);
			}
			$tasks     = $this->build_freemius_tasks( $result['users'], $current_id, $config, 'paid' );
			$raw_count = count( $result['users'] );
		} elseif ( $free_only ) {
			$result = $client->get_users( $offset, $count, 'never_paid' );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: 1: plugin ID 2: error message */
							__( 'Error fetching free users for plugin %1$s: %2$s', 'freemkit' ),
							$current_id,
							$result->get_error_message()
						),
					)
				);
			}
			$tasks     = $this->build_freemius_tasks( $result['users'], $current_id, $config );
			$raw_count = count( $result['users'] );
		} else {
			$result = $client->get_users( $offset, $count );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: 1: plugin ID 2: error message */
							__( 'Error fetching users for plugin %1$s: %2$s', 'freemkit' ),
							$current_id,
							$result->get_error_message()
						),
					)
				);
			}
			$tasks     = $this->build_freemius_tasks( $result['users'], $current_id, $config );
			$raw_count = count( $result['users'] );
		}

		$page_has_more = $result['has_more'];

		if ( $page_has_more ) {
			// More pages remain for this plugin.
			$next_offset       = $offset + $raw_count;
			$next_plugin_index = $plugin_index;
			$has_more          = true;
		} elseif ( isset( $plugin_ids[ $plugin_index + 1 ] ) ) {
			// This plugin exhausted; move to the next one.
			$next_offset       = 0;
			$next_plugin_index = $plugin_index + 1;
			$has_more          = true;
		} else {
			// Last plugin and last page.
			$next_offset       = 0;
			$next_plugin_index = $plugin_index;
			$has_more          = false;
		}

		wp_send_json_success(
			array(
				'tasks'             => $tasks,
				'total'             => 0,
				'offset'            => $next_offset,
				'next_plugin_index' => $next_plugin_index,
				'has_more'          => $has_more,
			)
		);
	}

	/**
	 * Convert raw Freemius user data into task objects.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $raw_users        Raw user arrays from the Freemius API.
	 * @param string $plugin_id        Freemius product ID.
	 * @param array  $config           Plugin config row.
	 * @param string $default_user_type Optional default user type when the API response lacks payment flags.
	 * @return array[]
	 */
	private function build_freemius_tasks( array $raw_users, string $plugin_id, array $config, string $default_user_type = '' ): array {
		$tasks = array();

		foreach ( $raw_users as $user ) {
			if ( ! is_array( $user ) ) {
				continue;
			}

			$user_type = Freemius_API_Client::get_user_type( $user );
			if ( '' !== $default_user_type && 'free' === $user_type ) {
				$user_type = $default_user_type;
			}

			$email = isset( $user['email'] ) ? sanitize_email( (string) $user['email'] ) : '';
			if ( empty( $email ) ) {
				continue;
			}

			$first = isset( $user['first'] ) ? sanitize_text_field( (string) $user['first'] ) : '';
			$last  = isset( $user['last'] ) ? sanitize_text_field( (string) $user['last'] ) : '';

			if ( 0 === strcasecmp( $first, 'Admin' ) ) {
				$first = '';
			}
			if ( 0 === strcasecmp( $last, 'Admin' ) ) {
				$last = '';
			}

			$tasks[] = array(
				'source'           => 'freemius',
				'email'            => $email,
				'first_name'       => $first,
				'last_name'        => $last,
				'user_type'        => $user_type,
				'plugin_id'        => $plugin_id,
				'plugin_name'      => $config['name'],
				'plugin_slug'      => $config['slug'],
				'freemius_user_id' => isset( $user['id'] ) ? (int) $user['id'] : 0,
				'freemius_created' => isset( $user['created'] ) ? sanitize_text_field( (string) $user['created'] ) : '',
				'marketing'        => ! empty( $user['is_marketing_allowed'] ) ? 1 : 0,
				'is_verified'      => ! empty( $user['is_verified'] ) ? 1 : 0,
				'email_status'     => isset( $user['email_status'] ) ? sanitize_text_field( (string) $user['email_status'] ) : '',
				'meta'             => array(),
			);
		}

		return $tasks;
	}

	/**
	 * Fetch a page of local DB subscribers for the list step.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $plugin_id  Filter by plugin ID (empty = all).
	 * @param string[] $user_types User type filter.
	 * @param int      $offset     Pagination offset.
	 * @param int      $count      Page size.
	 */
	private function list_local_users( string $plugin_id, array $user_types, int $offset, int $count ): void {
		$result = $this->query_local_subscribers( $plugin_id, $user_types, $offset, $count );
		$rows   = $result['rows'];
		$total  = $result['total'];

		$tasks = array();
		foreach ( $rows as $row ) {
			$tasks[] = array(
				'source'           => 'local',
				'email'            => sanitize_email( (string) $row->email ),
				'first_name'       => sanitize_text_field( (string) $row->first_name ),
				'last_name'        => sanitize_text_field( (string) $row->last_name ),
				'user_type'        => sanitize_key( (string) $row->user_type ),
				'plugin_id'        => sanitize_text_field( (string) $row->plugin_id ),
				'plugin_slug'      => sanitize_text_field( (string) $row->plugin_slug ),
				'freemius_user_id' => (int) $row->freemius_user_id,
				'marketing'        => (int) $row->marketing,
			);
		}

		wp_send_json_success(
			array(
				'tasks'             => $tasks,
				'total'             => $total,
				'offset'            => $offset + count( $rows ),
				'next_plugin_index' => 0,
				'has_more'          => count( $rows ) === $count,
			)
		);
	}

	/**
	 * AJAX: process a batch of users and return result rows.
	 *
	 * @since 1.0.0
	 */
	public function ajax_process_batch(): void {
		check_ajax_referer( 'freemkit_sync', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'freemkit' ) ) );
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw_tasks = isset( $_POST['tasks'] ) && is_array( $_POST['tasks'] ) ? wp_unslash( $_POST['tasks'] ) : array();
		// phpcs:enable

		$destination = isset( $raw_tasks[0]['destination'] ) ? sanitize_key( $raw_tasks[0]['destination'] ) : 'both';

		if ( 'both' === $destination && count( $raw_tasks ) > 1 ) {
			$results = $this->process_bulk_tasks( $raw_tasks );
		} else {
			$results = array();
			foreach ( $raw_tasks as $raw_task ) {
				if ( ! is_array( $raw_task ) ) {
					continue;
				}
				$results[] = $this->process_single_task( $raw_task );
			}
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * Process a single sync task and return a result row.
	 *
	 * @since 1.0.0
	 *
	 * @param array $raw_task Raw task data.
	 * @return array Result row.
	 */
	private function process_single_task( array $raw_task ): array {
		$task_meta = isset( $raw_task['meta'] ) && is_array( $raw_task['meta'] ) ? array_map( 'sanitize_text_field', $raw_task['meta'] ) : array();
		$task      = array_map( 'sanitize_text_field', $raw_task );

		$email       = sanitize_email( $task['email'] ?? '' );
		$first       = sanitize_text_field( $task['first_name'] ?? '' );
		$last        = sanitize_text_field( $task['last_name'] ?? '' );
		$user_type   = sanitize_key( $task['user_type'] ?? 'free' );
		$plugin_id   = sanitize_text_field( $task['plugin_id'] ?? '' );
		$plugin_name = sanitize_text_field( $task['plugin_name'] ?? $plugin_id );
		$slug        = sanitize_text_field( $task['plugin_slug'] ?? '' );
		$fs_uid      = (int) ( $task['freemius_user_id'] ?? 0 );
		$source      = sanitize_key( $task['source'] ?? 'local' );
		$destination = sanitize_key( $task['destination'] ?? 'both' );

		$override_form_ids  = wp_parse_list( $task['override_form_ids'] ?? '' );
		$override_tag_ids   = wp_parse_list( $task['override_tag_ids'] ?? '' );
		$allowed_user_types = array( 'free', 'paid', 'trial' );
		$user_types_raw     = isset( $raw_task['user_types'] ) && is_array( $raw_task['user_types'] )
			? $raw_task['user_types']
			: array();
		$user_types_filter  = array_values( array_intersect( array_map( 'sanitize_key', $user_types_raw ), $allowed_user_types ) );

		if ( ! empty( $user_types_filter ) && ! in_array( $user_type, $user_types_filter, true ) ) {
			return array(
				'action'      => 'skipped',
				'email'       => $email,
				'first_name'  => $first,
				'last_name'   => $last,
				'user_type'   => $user_type,
				'plugin_name' => $plugin_name,
				'destination' => $destination,
			);
		}

		if ( empty( $email ) ) {
			return array(
				'action'      => 'error',
				'email'       => '',
				'first_name'  => $first,
				'last_name'   => $last,
				'user_type'   => $user_type,
				'plugin_name' => $plugin_name,
				'destination' => $destination,
				'forms'       => '',
				'error'       => __( 'Invalid or empty email address.', 'freemkit' ),
			);
		}

		// Skip opted-out subscribers.
		$respect_optout = (bool) Options_API::get_option( 'respect_marketing_optout' );
		if ( $respect_optout ) {
			$existing = $this->database->get_subscriber_by_email( $email );
			if ( ! is_wp_error( $existing ) && empty( $existing->marketing ) ) {
				return array(
					'action'      => 'skipped',
					'email'       => $email,
					'first_name'  => $first,
					'last_name'   => $last,
					'user_type'   => $user_type,
					'destination' => $destination,
					'forms'       => '',
					'error'       => __( 'Skipped: subscriber has opted out of marketing.', 'freemkit' ),
				);
			}
		}

		/**
		 * Filter the subscriber data before syncing.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $subscriber_data { email, first_name, last_name, user_type }.
		 * @param string $plugin_id       Freemius plugin ID.
		 * @param string $source          'freemius' or 'local'.
		 */
		$subscriber_data = apply_filters(
			'freemkit_sync_subscriber_data',
			array(
				'email'      => $email,
				'first_name' => $first,
				'last_name'  => $last,
				'user_type'  => $user_type,
			),
			$plugin_id,
			$source
		);

		$email     = sanitize_email( $subscriber_data['email'] );
		$first     = sanitize_text_field( $subscriber_data['first_name'] );
		$last      = sanitize_text_field( $subscriber_data['last_name'] );
		$user_type = sanitize_key( $subscriber_data['user_type'] );

		// Check if subscriber already exists to set action label later.
		$was_existing = ! is_wp_error( $this->database->get_subscriber_by_email( $email ) );

		$freemius_created = sanitize_text_field( $task['freemius_created'] ?? '' );
		$marketing        = isset( $task['marketing'] ) ? (int) $task['marketing'] : 1;
		$is_verified      = isset( $task['is_verified'] ) ? (int) $task['is_verified'] : 0;
		$email_status     = isset( $task['email_status'] ) ? sanitize_text_field( $task['email_status'] ) : '';
		$meta             = $task_meta;

		// -----------------------------------------------------------------------
		// Local-only destination: save to DB, skip Kit entirely.
		// -----------------------------------------------------------------------
		if ( 'local' === $destination ) {
			$subscriber = new Subscriber(
				array(
					'email'            => $email,
					'first_name'       => $first,
					'last_name'        => $last,
					'marketing'        => $marketing,
					'freemius_user_id' => $fs_uid,
					'freemius_created' => $freemius_created,
					'is_verified'      => $is_verified,
					'email_status'     => $email_status,
					'meta'             => $meta,
				)
			);

			$db_result = $this->database->upsert_subscriber_by_email( $subscriber );
			if ( is_wp_error( $db_result ) ) {
				return array(
					'action'      => 'error',
					'email'       => $email,
					'first_name'  => $first,
					'last_name'   => $last,
					'user_type'   => $user_type,
					'destination' => $destination,
					'forms'       => '',
					'error'       => $db_result->get_error_message(),
				);
			}

			$event = new Subscriber_Event(
				array(
					'subscriber_id'    => $db_result,
					'plugin_id'        => $plugin_id,
					'plugin_slug'      => $slug,
					'event_type'       => 'sync.local_import',
					'user_type'        => $user_type,
					'form_ids'         => '',
					'tag_ids'          => '',
					'freemius_user_id' => $fs_uid,
				)
			);

			$this->database->add_subscriber_event( $event );
			$action = $was_existing ? 'updated' : 'synced';

			do_action( 'freemkit_sync_user_complete', $email, $user_type, $plugin_id, $source, $action );

			return array(
				'action'      => $action,
				'email'       => $email,
				'first_name'  => $first,
				'last_name'   => $last,
				'user_type'   => $user_type,
				'plugin_name' => $plugin_name,
				'destination' => $destination,
				'forms'       => '',
				'error'       => '',
			);
		}

		// -----------------------------------------------------------------------
		// Kit or Both destination: resolve forms/tags and push to Kit.
		// -----------------------------------------------------------------------
		$plugin_configs = $this->get_plugin_configs();
		$plugin_config  = isset( $plugin_configs[ $plugin_id ] ) ? $plugin_configs[ $plugin_id ] : array();

		$global_form_id = Options_API::get_option( 'kit_form_id' );
		$global_tag_id  = Options_API::get_option( 'kit_tag_id' );

		// Trial falls back to free forms/tags.
		$type_key = ( 'paid' === $user_type ) ? 'paid' : 'free';

		if ( ! empty( $override_form_ids ) ) {
			$active_form_ids = $override_form_ids;
		} elseif ( ! empty( $plugin_config[ $type_key . '_form_ids' ] ) ) {
			$active_form_ids = wp_parse_list( $plugin_config[ $type_key . '_form_ids' ] );
		} else {
			$active_form_ids = wp_parse_list( $global_form_id );
		}

		if ( ! empty( $override_tag_ids ) ) {
			$active_tag_ids = $override_tag_ids;
		} elseif ( ! empty( $plugin_config[ $type_key . '_tag_ids' ] ) ) {
			$active_tag_ids = wp_parse_list( $plugin_config[ $type_key . '_tag_ids' ] );
		} else {
			$active_tag_ids = wp_parse_list( $global_tag_id );
		}

		if ( empty( $active_form_ids ) ) {
			return array(
				'action'      => 'error',
				'email'       => $email,
				'first_name'  => $first,
				'last_name'   => $last,
				'user_type'   => $user_type,
				'plugin_name' => $plugin_name,
				'destination' => $destination,
				'forms'       => '',
				'error'       => __( 'No Kit forms configured for this user type.', 'freemkit' ),
			);
		}

		// marketing=0: write to local DB to record the subscriber, but skip Kit.
		if ( 0 === $marketing ) {
			$subscriber = new Subscriber(
				array(
					'email'            => $email,
					'first_name'       => $first,
					'last_name'        => $last,
					'marketing'        => 0,
					'freemius_user_id' => $fs_uid,
					'freemius_created' => $freemius_created,
					'is_verified'      => $is_verified,
					'email_status'     => $email_status,
					'meta'             => $meta,
				)
			);
			$this->database->upsert_subscriber_by_email( $subscriber );
			return array(
				'action'      => 'skipped',
				'email'       => $email,
				'first_name'  => $first,
				'last_name'   => $last,
				'user_type'   => $user_type,
				'plugin_name' => $plugin_name,
				'destination' => $destination,
				'forms'       => '',
				'error'       => __( 'Skipped: subscriber has opted out of marketing.', 'freemkit' ),
			);
		}

		$api        = new Kit_API();
		$api_result = null;
		foreach ( $active_form_ids as $form_id ) {
			if ( empty( $form_id ) ) {
				continue;
			}
			$api_result = $api->subscribe_to_form( (int) $form_id, $email, $first, array(), $active_tag_ids );
			if ( is_wp_error( $api_result ) ) {
				break;
			}
		}

		if ( is_wp_error( $api_result ) ) {
			return array(
				'action'      => 'error',
				'email'       => $email,
				'first_name'  => $first,
				'last_name'   => $last,
				'user_type'   => $user_type,
				'plugin_name' => $plugin_name,
				'destination' => $destination,
				'forms'       => implode( ', ', $active_form_ids ),
				'error'       => $api_result->get_error_message(),
			);
		}

		$subscriber = new Subscriber(
			array(
				'email'            => $email,
				'first_name'       => $first,
				'last_name'        => $last,
				'marketing'        => $marketing,
				'freemius_user_id' => $fs_uid,
				'freemius_created' => $freemius_created,
				'is_verified'      => $is_verified,
				'email_status'     => $email_status,
				'meta'             => $meta,
			)
		);

		$db_result = $this->database->upsert_subscriber_by_email( $subscriber );
		if ( is_wp_error( $db_result ) ) {
			return array(
				'action'      => 'error',
				'email'       => $email,
				'first_name'  => $first,
				'last_name'   => $last,
				'user_type'   => $user_type,
				'plugin_name' => $plugin_name,
				'destination' => $destination,
				'forms'       => implode( ', ', $active_form_ids ),
				'error'       => $db_result->get_error_message(),
			);
		}

		$event_type = ( 'local' === $source ) ? 'sync.resynced' : 'sync.imported';
		$event      = new Subscriber_Event(
			array(
				'subscriber_id'    => $db_result,
				'plugin_id'        => $plugin_id,
				'plugin_slug'      => $slug,
				'event_type'       => $event_type,
				'user_type'        => $user_type,
				'form_ids'         => implode( ',', $active_form_ids ),
				'tag_ids'          => implode( ',', $active_tag_ids ),
				'freemius_user_id' => $fs_uid,
			)
		);

		$this->database->add_subscriber_event( $event );
		$action = $was_existing ? 'updated' : 'synced';

		/**
		 * Fires after a subscriber is successfully synced.
		 *
		 * @since 1.0.0
		 *
		 * @param string $email       Subscriber email.
		 * @param string $user_type   User type (free|paid|trial).
		 * @param string $plugin_id   Freemius plugin ID.
		 * @param string $source      'freemius' or 'local'.
		 * @param string $action      'synced' or 'updated'.
		 */
		do_action( 'freemkit_sync_user_complete', $email, $user_type, $plugin_id, $source, $action );

		return array(
			'action'      => $action,
			'email'       => $email,
			'first_name'  => $first,
			'last_name'   => $last,
			'user_type'   => $user_type,
			'plugin_name' => $plugin_name,
			'destination' => $destination,
			'forms'       => implode( ', ', $active_form_ids ),
			'error'       => '',
		);
	}

	/**
	 * Process a batch of sync tasks using Kit's bulk API.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array<string, mixed>> $raw_tasks Raw task data.
	 * @return array<int, array<string, mixed>> Result rows.
	 */
	private function process_bulk_tasks( array $raw_tasks ): array {
		$results   = array();
		$kit_tasks = array();
		$task_meta = array();

		$plugin_configs = $this->get_plugin_configs();
		$global_form_id = Options_API::get_option( 'kit_form_id' );
		$global_tag_id  = Options_API::get_option( 'kit_tag_id' );
		$respect_optout = (bool) Options_API::get_option( 'respect_marketing_optout' );
		$allowed_types  = array( 'free', 'paid', 'trial' );

		foreach ( $raw_tasks as $raw_task ) {
			$task_meta_data = isset( $raw_task['meta'] ) && is_array( $raw_task['meta'] ) ? array_map( 'sanitize_text_field', $raw_task['meta'] ) : array();
			$task           = array_map( 'sanitize_text_field', $raw_task );

			$email       = sanitize_email( $task['email'] ?? '' );
			$first       = sanitize_text_field( $task['first_name'] ?? '' );
			$last        = sanitize_text_field( $task['last_name'] ?? '' );
			$user_type   = sanitize_key( $task['user_type'] ?? 'free' );
			$plugin_id   = sanitize_text_field( $task['plugin_id'] ?? '' );
			$plugin_name = sanitize_text_field( $task['plugin_name'] ?? $plugin_id );
			$slug        = sanitize_text_field( $task['plugin_slug'] ?? '' );
			$fs_uid      = (int) ( $task['freemius_user_id'] ?? 0 );
			$source      = sanitize_key( $task['source'] ?? 'local' );
			$destination = sanitize_key( $task['destination'] ?? 'both' );
			$marketing   = isset( $task['marketing'] ) ? (int) $task['marketing'] : 1;

			$override_form_ids = wp_parse_list( $task['override_form_ids'] ?? '' );
			$override_tag_ids  = wp_parse_list( $task['override_tag_ids'] ?? '' );
			$user_types_raw    = isset( $raw_task['user_types'] ) && is_array( $raw_task['user_types'] )
				? $raw_task['user_types']
				: array();
			$user_types_filter = array_values( array_intersect( array_map( 'sanitize_key', $user_types_raw ), $allowed_types ) );

			// User type filter.
			if ( ! empty( $user_types_filter ) && ! in_array( $user_type, $user_types_filter, true ) ) {
				$results[] = array(
					'action'      => 'skipped',
					'email'       => $email,
					'first_name'  => $first,
					'last_name'   => $last,
					'user_type'   => $user_type,
					'plugin_name' => $plugin_name,
					'destination' => $destination,
					'forms'       => '',
					'error'       => '',
				);
				continue;
			}

			// Email check.
			if ( empty( $email ) ) {
				$results[] = array(
					'action'      => 'error',
					'email'       => '',
					'first_name'  => $first,
					'last_name'   => $last,
					'user_type'   => $user_type,
					'plugin_name' => $plugin_name,
					'destination' => $destination,
					'forms'       => '',
					'error'       => __( 'Invalid or empty email address.', 'freemkit' ),
				);
				continue;
			}

			// Marketing opt-out check (DB + task flag).
			if ( $respect_optout ) {
				$existing = $this->database->get_subscriber_by_email( $email );
				if ( ! is_wp_error( $existing ) && empty( $existing->marketing ) ) {
					$results[] = array(
						'action'      => 'skipped',
						'email'       => $email,
						'first_name'  => $first,
						'last_name'   => $last,
						'user_type'   => $user_type,
						'plugin_name' => $plugin_name,
						'destination' => $destination,
						'forms'       => '',
						'error'       => __( 'Skipped: subscriber has opted out of marketing.', 'freemkit' ),
					);
					continue;
				}
			}

			// marketing=0: write to local DB to record the subscriber, but skip Kit.
			if ( 0 === $marketing ) {
				$subscriber = new Subscriber(
					array(
						'email'            => $email,
						'first_name'       => $first,
						'last_name'        => $last,
						'marketing'        => 0,
						'freemius_user_id' => $fs_uid,
						'freemius_created' => sanitize_text_field( $task['freemius_created'] ?? '' ),
						'is_verified'      => isset( $task['is_verified'] ) ? (int) $task['is_verified'] : 0,
						'email_status'     => isset( $task['email_status'] ) ? sanitize_text_field( $task['email_status'] ) : '',
						'meta'             => $task_meta_data,
					)
				);
				$this->database->upsert_subscriber_by_email( $subscriber );
				$results[] = array(
					'action'      => 'skipped',
					'email'       => $email,
					'first_name'  => $first,
					'last_name'   => $last,
					'user_type'   => $user_type,
					'plugin_name' => $plugin_name,
					'destination' => $destination,
					'forms'       => '',
					'error'       => __( 'Skipped: subscriber has opted out of marketing.', 'freemkit' ),
				);
				continue;
			}

			/**
			 * Filter the subscriber data before syncing.
			 *
			 * @since 1.0.0
			 *
			 * @param array  $subscriber_data { email, first_name, last_name, user_type }.
			 * @param string $plugin_id       Freemius plugin ID.
			 * @param string $source          'freemius' or 'local'.
			 */
			$subscriber_data = apply_filters(
				'freemkit_sync_subscriber_data',
				array(
					'email'      => $email,
					'first_name' => $first,
					'last_name'  => $last,
					'user_type'  => $user_type,
				),
				$plugin_id,
				$source
			);

			$email     = sanitize_email( $subscriber_data['email'] );
			$first     = sanitize_text_field( $subscriber_data['first_name'] );
			$last      = sanitize_text_field( $subscriber_data['last_name'] );
			$user_type = sanitize_key( $subscriber_data['user_type'] );

			// Resolve forms/tags.
			$plugin_config = isset( $plugin_configs[ $plugin_id ] ) ? $plugin_configs[ $plugin_id ] : array();
			$type_key      = ( 'paid' === $user_type ) ? 'paid' : 'free';

			if ( ! empty( $override_form_ids ) ) {
				$active_form_ids = $override_form_ids;
			} elseif ( ! empty( $plugin_config[ $type_key . '_form_ids' ] ) ) {
				$active_form_ids = wp_parse_list( $plugin_config[ $type_key . '_form_ids' ] );
			} else {
				$active_form_ids = wp_parse_list( $global_form_id );
			}

			if ( ! empty( $override_tag_ids ) ) {
				$active_tag_ids = $override_tag_ids;
			} elseif ( ! empty( $plugin_config[ $type_key . '_tag_ids' ] ) ) {
				$active_tag_ids = wp_parse_list( $plugin_config[ $type_key . '_tag_ids' ] );
			} else {
				$active_tag_ids = wp_parse_list( $global_tag_id );
			}

			if ( empty( $active_form_ids ) ) {
				$results[] = array(
					'action'      => 'error',
					'email'       => $email,
					'first_name'  => $first,
					'last_name'   => $last,
					'user_type'   => $user_type,
					'plugin_name' => $plugin_name,
					'destination' => $destination,
					'forms'       => '',
					'error'       => __( 'No Kit forms configured for this user type.', 'freemkit' ),
				);
				continue;
			}

			// Store for bulk processing.
			$freemius_created = sanitize_text_field( $task['freemius_created'] ?? '' );
			$is_verified      = isset( $task['is_verified'] ) ? (int) $task['is_verified'] : 0;
			$email_status     = isset( $task['email_status'] ) ? sanitize_text_field( $task['email_status'] ) : '';
			$meta             = $task_meta_data;

			$kit_tasks[] = array(
				'email'      => $email,
				'first_name' => $first,
				'form_ids'   => array_values( array_filter( array_map( 'intval', $active_form_ids ) ) ),
				'tag_ids'    => array_values( array_filter( array_map( 'intval', $active_tag_ids ) ) ),
			);

			$task_meta[ $email ] = array(
				'email'            => $email,
				'first_name'       => $first,
				'last_name'        => $last,
				'user_type'        => $user_type,
				'plugin_id'        => $plugin_id,
				'plugin_name'      => $plugin_name,
				'plugin_slug'      => $slug,
				'freemius_user_id' => $fs_uid,
				'source'           => $source,
				'destination'      => $destination,
				'marketing'        => $marketing,
				'freemius_created' => $freemius_created,
				'is_verified'      => $is_verified,
				'email_status'     => $email_status,
				'meta'             => $meta,
				'form_ids'         => $active_form_ids,
				'tag_ids'          => $active_tag_ids,
			);
		}

		if ( empty( $kit_tasks ) ) {
			return $results;
		}

		// Bulk subscribe.
		$api          = new Kit_API();
		$bulk_results = $api->bulk_subscribe_to_kit( $kit_tasks );
		if ( is_wp_error( $bulk_results ) ) {
			$error_msg = $bulk_results->get_error_message();
			foreach ( $task_meta as $email => $meta ) {
				$results[] = array(
					'action'      => 'error',
					'email'       => $email,
					'first_name'  => $meta['first_name'],
					'last_name'   => $meta['last_name'],
					'user_type'   => $meta['user_type'],
					'plugin_name' => $meta['plugin_name'],
					'destination' => $meta['destination'],
					'forms'       => implode( ', ', $meta['form_ids'] ),
					'error'       => $error_msg,
				);
			}
			return $results;
		}

		// Process each result.
		foreach ( $task_meta as $email => $meta ) {
			if ( ! isset( $bulk_results[ $email ] ) ) {
				$results[] = array(
					'action'      => 'error',
					'email'       => $email,
					'first_name'  => $meta['first_name'],
					'last_name'   => $meta['last_name'],
					'user_type'   => $meta['user_type'],
					'plugin_name' => $meta['plugin_name'],
					'destination' => $meta['destination'],
					'forms'       => implode( ', ', $meta['form_ids'] ),
					'error'       => __( 'No response for this subscriber from Kit.', 'freemkit' ),
				);
				continue;
			}

			$bulk_result = $bulk_results[ $email ];
			if ( 'error' === $bulk_result['status'] ) {
				$results[] = array(
					'action'      => 'error',
					'email'       => $email,
					'first_name'  => $meta['first_name'],
					'last_name'   => $meta['last_name'],
					'user_type'   => $meta['user_type'],
					'plugin_name' => $meta['plugin_name'],
					'destination' => $meta['destination'],
					'forms'       => implode( ', ', $meta['form_ids'] ),
					'error'       => $bulk_result['error'] ?? __( 'Unknown Kit error.', 'freemkit' ),
				);
				continue;
			}

			// Success: save to DB.
			$was_existing = ! is_wp_error( $this->database->get_subscriber_by_email( $email ) );

			$subscriber = new Subscriber(
				array(
					'email'            => $email,
					'first_name'       => $meta['first_name'],
					'last_name'        => $meta['last_name'],
					'marketing'        => $meta['marketing'],
					'freemius_user_id' => $meta['freemius_user_id'],
					'freemius_created' => $meta['freemius_created'],
					'is_verified'      => $meta['is_verified'],
					'email_status'     => $meta['email_status'],
					'meta'             => $meta['meta'],
				)
			);

			$db_result = $this->database->upsert_subscriber_by_email( $subscriber );
			if ( is_wp_error( $db_result ) ) {
				$results[] = array(
					'action'      => 'error',
					'email'       => $email,
					'first_name'  => $meta['first_name'],
					'last_name'   => $meta['last_name'],
					'user_type'   => $meta['user_type'],
					'plugin_name' => $meta['plugin_name'],
					'destination' => $meta['destination'],
					'forms'       => implode( ', ', $meta['form_ids'] ),
					'error'       => $db_result->get_error_message(),
				);
				continue;
			}

			$event_type = ( 'local' === $meta['source'] ) ? 'sync.resynced' : 'sync.imported';
			$event      = new Subscriber_Event(
				array(
					'subscriber_id'    => $db_result,
					'plugin_id'        => $meta['plugin_id'],
					'plugin_slug'      => $meta['plugin_slug'],
					'event_type'       => $event_type,
					'user_type'        => $meta['user_type'],
					'form_ids'         => implode( ',', $meta['form_ids'] ),
					'tag_ids'          => implode( ',', $meta['tag_ids'] ),
					'freemius_user_id' => $meta['freemius_user_id'],
				)
			);

			$this->database->add_subscriber_event( $event );
			$action = $was_existing ? 'updated' : 'synced';

			/**
			 * Fires after a subscriber is successfully synced.
			 *
			 * @since 1.0.0
			 *
			 * @param string $email       Subscriber email.
			 * @param string $user_type   User type (free|paid|trial).
			 * @param string $plugin_id   Freemius plugin ID.
			 * @param string $source      'freemius' or 'local'.
			 * @param string $action      'synced' or 'updated'.
			 */
			do_action( 'freemkit_sync_user_complete', $email, $meta['user_type'], $meta['plugin_id'], $meta['source'], $action );

			$results[] = array(
				'action'      => $action,
				'email'       => $email,
				'first_name'  => $meta['first_name'],
				'last_name'   => $meta['last_name'],
				'user_type'   => $meta['user_type'],
				'plugin_name' => $meta['plugin_name'],
				'destination' => $meta['destination'],
				'forms'       => implode( ', ', $meta['form_ids'] ),
				'error'       => '',
			);
		}

		return $results;
	}

	/**
	 * Query local subscribers with their most-recent event metadata.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $plugin_id  Filter by plugin ID, or empty for all.
	 * @param string[] $user_types Filter by user type array.
	 * @param int      $offset     Pagination offset.
	 * @param int      $count      Page size.
	 * @return array{ rows: object[], total: int }
	 */
	private function query_local_subscribers( string $plugin_id, array $user_types, int $offset, int $count ): array {
		global $wpdb;

		$subs_table   = $this->database->get_table_name();
		$events_table = $this->database->get_events_table_name();

		$join_values  = array();
		$where_values = array();
		$where_parts  = array( '1=1' );

		if ( ! empty( $plugin_id ) ) {
			$join_sql       = "INNER JOIN (
				SELECT subscriber_id, MAX(id) AS max_id
				FROM {$events_table}
				WHERE plugin_id = %s
				GROUP BY subscriber_id
			) latest ON latest.subscriber_id = s.id
			INNER JOIN {$events_table} le ON le.id = latest.max_id";
			$join_values[]  = $plugin_id;
			$user_type_expr = 'le.user_type';
			$extra_selects  = 'le.user_type AS user_type, le.plugin_id AS plugin_id, le.plugin_slug AS plugin_slug, le.freemius_user_id AS freemius_user_id';
		} else {
			$join_sql       = "LEFT JOIN (
				SELECT subscriber_id, MAX(id) AS max_id
				FROM {$events_table}
				GROUP BY subscriber_id
			) latest ON latest.subscriber_id = s.id
			LEFT JOIN {$events_table} le ON le.id = latest.max_id";
			$user_type_expr = "COALESCE(le.user_type, 'free')";
			$extra_selects  = "COALESCE(le.user_type, 'free') AS user_type, COALESCE(le.plugin_id, '') AS plugin_id, COALESCE(le.plugin_slug, '') AS plugin_slug, COALESCE(le.freemius_user_id, 0) AS freemius_user_id";
		}

		if ( ! empty( $user_types ) ) {
			$placeholders  = implode( ', ', array_fill( 0, count( $user_types ), '%s' ) );
			$where_parts[] = "{$user_type_expr} IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$where_values  = array_merge( $where_values, $user_types );
		}

		$where_clause     = implode( ' AND ', $where_parts );
		$all_count_values = array_merge( $join_values, $where_values );
		$all_row_values   = array_merge( $join_values, $where_values, array( $count, $offset ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count_sql = "SELECT COUNT(*) FROM {$subs_table} s {$join_sql} WHERE {$where_clause}";
		if ( ! empty( $all_count_values ) ) {
			$count_sql = $wpdb->prepare( $count_sql, $all_count_values );
		}
		$total = (int) $wpdb->get_var( $count_sql );

		$rows_sql = "SELECT s.id, s.email, s.first_name, s.last_name, s.marketing, {$extra_selects}
		             FROM {$subs_table} s {$join_sql}
		             WHERE {$where_clause}
		             ORDER BY s.id ASC
		             LIMIT %d OFFSET %d";
		$rows_sql = $wpdb->prepare( $rows_sql, $all_row_values );
		$rows     = $wpdb->get_results( $rows_sql );
		// phpcs:enable

		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Build plugin configurations from settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, string>>
	 */
	private function get_plugin_configs(): array {
		$settings       = get_option( Options_API::SETTINGS_OPTION, array() );
		$plugin_configs = array();

		if ( ! is_array( $settings ) || empty( $settings['plugins'] ) || ! is_array( $settings['plugins'] ) ) {
			return $plugin_configs;
		}

		foreach ( $settings['plugins'] as $plugin ) {
			if ( ! is_array( $plugin ) ) {
				continue;
			}

			$plugin_fields = isset( $plugin['fields'] ) && is_array( $plugin['fields'] ) ? $plugin['fields'] : $plugin;

			if ( empty( $plugin_fields['id'] ) ) {
				continue;
			}

			$plugin_id = sanitize_text_field( (string) $plugin_fields['id'] );

			$public_key = (string) ( $plugin_fields['public_key'] ?? '' );

			$secret_raw = (string) ( $plugin_fields['secret_key'] ?? '' );
			$secret_key = Settings\Settings_API::decrypt_api_key( $secret_raw );
			if ( '' === $secret_key ) {
				$secret_key = trim( $secret_raw );
			}

			$plugin_configs[ $plugin_id ] = array(
				'name'          => sanitize_text_field( (string) ( $plugin_fields['name'] ?? '' ) ),
				'slug'          => sanitize_title( (string) ( $plugin_fields['name'] ?? '' ) ),
				'public_key'    => $public_key,
				'secret_key'    => $secret_key,
				'free_form_ids' => (string) ( $plugin_fields['free_form_ids'] ?? '' ),
				'free_tag_ids'  => (string) ( $plugin_fields['free_tag_ids'] ?? '' ),
				'paid_form_ids' => (string) ( $plugin_fields['paid_form_ids'] ?? '' ),
				'paid_tag_ids'  => (string) ( $plugin_fields['paid_tag_ids'] ?? '' ),
			);
		}

		return $plugin_configs;
	}
}
