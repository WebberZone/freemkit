<?php
/**
 * Top 10 Display statistics page.
 *
 * @package   FreemKit
 * @subpackage  FreemKit_Statistics
 */

namespace WebberZone\FreemKit\Admin;

use WebberZone\FreemKit\Database;
use WebberZone\FreemKit\Options_API;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * FreemKit_Statistics class.
 */
class Subscribers_List {

	/**
	 * WP_List_Table object.
	 *
	 * @var \WebberZone\FreemKit\Admin\Subscribers_List_Table
	 */
	public $subscribers_table;

	/**
	 * Parent Menu ID.
	 *
	 * @since 1.0.0
	 *
	 * @var string Parent Menu ID.
	 */
	public $parent_id;

	/**
	 * Database object.
	 *
	 * @since 1.0.0
	 *
	 * @var \WebberZone\FreemKit\Database
	 */
	public $database;

	/**
	 * Class constructor.
	 *
	 * @param Database $database Database instance.
	 * @return void
	 */
	public function __construct( Database $database ) {
		$this->database = $database;
		add_filter( 'set-screen-option', array( __CLASS__, 'set_screen' ), 10, 3 );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	/**
	 * Handle form and delete actions before admin output starts.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_actions() {
		$page = isset( $_REQUEST['page'] ) ? sanitize_key( (string) wp_unslash( $_REQUEST['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'freemkit_subscribers' !== $page ) {
			return;
		}

		$action        = isset( $_REQUEST['action'] ) ? sanitize_key( (string) wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$subscriber_id = isset( $_REQUEST['id'] ) ? absint( wp_unslash( $_REQUEST['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( in_array( $action, array( 'add', 'edit' ), true ) ) {
			$form = new Subscriber_Form( $this->database, $subscriber_id );
			$form->process_form();
		}

		$this->process_single_delete();
	}

	/**
	 * Admin Menu.
	 *
	 * @since 3.0.0
	 */
	public function admin_menu() {
		$this->parent_id = add_users_page(
			__( 'FreemKit - Subscribers', 'freemkit' ),
			__( 'Subscribers', 'freemkit' ),
			'manage_options',
			'freemkit_subscribers',
			array( $this, 'render_page' )
		);

		add_action( "load-{$this->parent_id}", array( $this, 'screen_option' ) );
	}

	/**
	 * Set screen options.
	 *
	 * @param  string $status Status of screen.
	 * @param  string $option Option name.
	 * @param  string $value  Option value.
	 * @return string Value.
	 */
	public static function set_screen( $status, $option, $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		return $value;
	}

	/**
	 * Plugin settings page.
	 */
	public function render_page() {

		$action        = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$subscriber_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Show admin notices from redirects.
		$this->display_admin_notice();

		if ( in_array( $action, array( 'add', 'edit' ), true ) ) {
			$form = new Subscriber_Form( $this->database, $subscriber_id );
			$form->render_form();
			return;
		}

		$page = '';
		if ( isset( $_REQUEST['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$page = sanitize_text_field( wp_unslash( $_REQUEST['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$add_new_url = admin_url( 'users.php?page=freemkit_subscribers&action=add' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'FreemKit - Subscribers', 'freemkit' ); ?></h1>
			<a href="<?php echo esc_url( $add_new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'freemkit' ); ?></a>
			<?php do_action( 'freemkit_subscribers_page_header' ); ?>

			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-2">
					<div id="post-body-content">
						<div class="meta-box-sortables ui-sortable">
							<form method="get">
								<input type="hidden" name="page" value="<?php echo esc_attr( $page ); ?>" />
								<?php
								$this->subscribers_table->prepare_items();
								$this->subscribers_table->search_box( __( 'Search Subscribers', 'freemkit' ), 'freemkit' );
								$this->subscribers_table->display();
								?>
							</form>
						</div>
					</div>
					<div id="postbox-container-1" class="postbox-container">
						<div id="side-sortables" class="meta-box-sortables ui-sortable">
						<?php include_once __DIR__ . '/sidebar.php'; ?>
						</div><!-- /side-sortables -->
					</div><!-- /postbox-container-1 -->
				</div><!-- /post-body -->
				<br class="clear" />
			</div><!-- /poststuff -->
		</div>
		<?php
	}

	/**
	 * Process single subscriber delete action.
	 *
	 * @since 1.0.0
	 */
	protected function process_single_delete() {
		if ( ! isset( $_GET['action'] ) || 'delete' !== $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $id ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'delete_subscriber_' . $id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'freemkit' ) );
		}

		// Fetch email before deleting for Kit unsubscribe.
		$subscriber = $this->database->get_subscriber( $id );
		$result     = $this->database->delete_subscriber( $id );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_message( 'error', $result->get_error_message() );
			return;
		}

		$message = __( 'Subscriber deleted.', 'freemkit' );

		// Unsubscribe from Kit if enabled.
		if ( ! is_wp_error( $subscriber ) && Options_API::get_option( 'kit_unsubscribe_on_delete', 0 ) ) {
			$kit_api = new \WebberZone\FreemKit\Kit\Kit_API();
			if ( $kit_api->has_access_and_refresh_token() ) {
				$kit_result = $kit_api->unsubscribe_subscriber( $subscriber->email );
				if ( is_wp_error( $kit_result ) ) {
					/* translators: %s: Error message from Kit API */
					$message .= ' ' . sprintf( __( 'Kit unsubscribe failed: %s', 'freemkit' ), $kit_result->get_error_message() );
				} else {
					$message .= ' ' . __( 'Unsubscribed from Kit.', 'freemkit' );
				}
			}
		}

		$this->redirect_with_message( 'success', $message );
	}

	/**
	 * Display admin notice from query args.
	 *
	 * @since 1.0.0
	 */
	protected function display_admin_notice() {
		if ( ! isset( $_GET['freemkit_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$message  = sanitize_text_field( wp_unslash( $_GET['freemkit_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg_type = isset( $_GET['msg_type'] ) ? sanitize_key( $_GET['msg_type'] ) : 'info'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$class    = 'success' === $msg_type ? 'notice-success' : 'notice-error';

		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			esc_attr( $class ),
			esc_html( $message )
		);
	}

	/**
	 * Redirect with a message.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type    Message type ('success' or 'error').
	 * @param string $message Message text.
	 */
	protected function redirect_with_message( string $type, string $message ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'freemkit_subscribers',
					'freemkit_msg' => rawurlencode( $message ),
					'msg_type'     => $type,
				),
				admin_url( 'users.php' )
			)
		);
		exit;
	}

	/**
	 * Screen options
	 */
	public function screen_option() {
		$option = 'per_page';
		$args   = array(
			'label'   => __( 'Subscribers', 'freemkit' ),
			'default' => 50,
			'option'  => 'subscribers_per_page',
		);
		add_screen_option( $option, $args );
		$this->subscribers_table = new Subscribers_List_Table( $this->database );
	}
}
