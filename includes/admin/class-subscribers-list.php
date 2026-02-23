<?php
/**
 * Top 10 Display statistics page.
 *
 * @package   FreemKit
 * @subpackage  FreemKit_Statistics
 */

namespace WebberZone\FreemKit\Admin;

use WebberZone\FreemKit\Database;

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
	 * Plugin settings page
	 */
	public function render_page() {

		$page = '';
		if ( isset( $_REQUEST['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$page = sanitize_text_field( wp_unslash( $_REQUEST['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'FreemKit - Subscribers', 'freemkit' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'options-general.php?page=freemkit_options_page' ) ); ?>" class="page-title-action"><?php esc_html_e( 'View Settings', 'freemkit' ); ?></a>
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
