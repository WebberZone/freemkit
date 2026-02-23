<?php
/**
 * Admin class.
 *
 * @link  https://webberzone.com
 * @since 1.0.0
 *
 * @package WebberZone\FreemKit\Admin
 */

namespace WebberZone\FreemKit\Admin;

use WebberZone\FreemKit\Database;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class to handle all admin functionality.
 *
 * @since 1.0.0
 */
class Admin {

	/**
	 * Settings API object.
	 *
	 * @since 1.0.0
	 * @var Settings
	 */
	public $settings;

	/**
	 * Subscribers list table object.
	 *
	 * @since 1.0.0
	 * @var Subscribers_List
	 */
	public $subscribers_list;

	/**
	 * Settings wizard object.
	 *
	 * @since 1.0.0
	 * @var Settings_Wizard|null
	 */
	public ?Settings_Wizard $settings_wizard = null;

	/**
	 * Admin notices API object.
	 *
	 * @since 1.0.0
	 * @var Admin_Notices_API|null
	 */
	public static ?Admin_Notices_API $notices_api = null;

	/**
	 * Admin banner API object.
	 *
	 * @since 1.0.0
	 * @var Admin_Banner|null
	 */
	public ?Admin_Banner $admin_banner = null;

	/**
	 * Database instance.
	 *
	 * @since 1.0.0
	 * @var Database
	 */
	protected $database;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Database $database Database instance.
	 */
	public function __construct( Database $database ) {
		$this->database     = $database;
		self::$notices_api  = new Admin_Notices_API();
		$this->admin_banner = new Admin_Banner(
			array(
				'capability' => 'manage_options',
				'prefix'     => 'freemkit',
				'strings'    => array(
					'region_label' => __( 'FreemKit admin navigation', 'freemkit' ),
					'nav_label'    => __( 'FreemKit sections', 'freemkit' ),
					'eyebrow'      => __( 'WebberZone', 'freemkit' ),
					'title'        => __( 'FreemKit', 'freemkit' ),
					'text'         => __( 'Manage integration settings and subscribers.', 'freemkit' ),
				),
				'sections'   => array(
					'settings'    => array(
						'label'      => __( 'Settings', 'freemkit' ),
						'url'        => admin_url( 'options-general.php?page=freemkit_options_page' ),
						'type'       => 'primary',
						'page_slugs' => array( 'freemkit_options_page' ),
						'screen_ids' => array( 'settings_page_freemkit_options_page' ),
					),
					'wizard'      => array(
						'label'      => __( 'Setup Wizard', 'freemkit' ),
						'url'        => admin_url( 'options-general.php?page=freemkit_setup_wizard' ),
						'type'       => 'secondary',
						'page_slugs' => array( 'freemkit_setup_wizard' ),
						'screen_ids' => array( 'settings_page_freemkit_setup_wizard' ),
					),
					'subscribers' => array(
						'label'      => __( 'Subscribers', 'freemkit' ),
						'url'        => admin_url( 'users.php?page=freemkit_subscribers' ),
						'type'       => 'secondary',
						'page_slugs' => array( 'freemkit_subscribers' ),
						'screen_ids' => array( 'users_page_freemkit_subscribers' ),
					),
				),
			)
		);

		// Initialize settings.
		$this->settings = new Settings();

		// Initialize setup wizard.
		$this->settings_wizard = new Settings_Wizard();

		// Initialize subscribers list.
		$this->subscribers_list = new Subscribers_List( $database );

		// Add admin menu.
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
	}

	/**
	 * Add admin menu items.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function admin_menu(): void {
		// No menu items needed as we'll link from settings page.
	}

	/**
	 * Add an admin notice.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message      Notice message.
	 * @param string $notice_class Notice class.
	 * @return void
	 */
	public static function add_notice( $message, $notice_class = 'notice-info' ): void {
		if ( ! self::$notices_api instanceof Admin_Notices_API ) {
			self::$notices_api = new Admin_Notices_API();
		}

		$type = 'info';
		if ( false !== strpos( (string) $notice_class, 'warning' ) ) {
			$type = 'warning';
		} elseif ( false !== strpos( (string) $notice_class, 'success' ) ) {
			$type = 'success';
		} elseif ( false !== strpos( (string) $notice_class, 'error' ) ) {
			$type = 'error';
		}

		self::$notices_api->register_notice(
			array(
				'id'          => 'freemkit_' . md5( (string) $message . '|' . microtime( true ) ),
				'message'     => '<p>' . wp_kses_post( (string) $message ) . '</p>',
				'type'        => $type,
				'dismissible' => true,
				'screens'     => array(),
				'capability'  => 'manage_options',
			)
		);
	}
}
