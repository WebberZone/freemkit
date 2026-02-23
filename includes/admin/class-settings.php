<?php
/**
 * Register Settings.
 *
 * @since 1.0.0
 *
 * @package WebberZone\Glue_Link\Admin
 */

namespace WebberZone\Glue_Link\Admin;

use WebberZone\Glue_Link\Admin\Settings\Settings_API;
use WebberZone\Glue_Link\Options_API;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class to register the settings.
 *
 * @since 1.0.0
 */
class Settings {

	/**
	 * Settings API.
	 *
	 * @since 1.0.0
	 *
	 * @var Settings_API Settings API.
	 */
	public Settings_API $settings_api;

	/**
	 * Prefix which is used for creating the unique filters and actions.
	 *
	 * @since 1.0.0
	 *
	 * @var string Prefix.
	 */
	public static $prefix;

	/**
	 * Settings Key.
	 *
	 * @since 1.0.0
	 *
	 * @var string Settings Key.
	 */
	public $settings_key;

	/**
	 * The slug name to refer to this menu by (should be unique for this menu).
	 *
	 * @since 1.0.0
	 *
	 * @var string Menu slug.
	 */
	public $menu_slug;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->settings_key = 'glue_link_settings';
		self::$prefix       = 'glue_link';
		$this->menu_slug    = 'glue_link_options_page';
		new Kit_OAuth( $this->menu_slug );

		$this->register_hooks();
	}

	/**
	 * Register the hooks.
	 *
	 * @since 1.0.0
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'initialise_settings' ) );
		add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 11, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( GLUE_LINK_PLUGIN_FILE ), array( $this, 'plugin_actions_links' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ), 99 );

		// Add filters for settings page customization.
		add_filter( self::$prefix . '_after_setting_output', array( $this, 'add_connection_test_button' ), 10, 2 );
		add_filter( self::$prefix . '_settings_form_buttons', array( $this, 'add_cache_clear_button' ), 10 );
		add_action( self::$prefix . '_settings_form_buttons', array( $this, 'render_wizard_button' ), 20 );
		add_filter( self::$prefix . '_settings_sanitize', array( $this, 'change_settings_on_save' ), 99 );

		// Add AJAX handlers for Kit resources and connection testing.
		add_action( 'wp_ajax_' . self::$prefix . '_test_kit_connection', array( $this, 'ajax_test_kit_connection' ) );
		add_action( 'wp_ajax_' . self::$prefix . '_refresh_lists', array( $this, 'ajax_refresh_lists' ) );
		add_action( 'wp_ajax_' . self::$prefix . '_kit_search', array( $this, 'handle_kit_search' ) );
	}

	/**
	 * Initialise the settings API.
	 *
	 * @since 1.0.0
	 */
	public function initialise_settings() {
		$props = array(
			'default_tab'       => 'kit',
			'help_sidebar'      => $this->get_help_sidebar(),
			'help_tabs'         => $this->get_help_tabs(),
			'admin_footer_text' => $this->get_admin_footer_text(),
			'menus'             => $this->get_menus(),
		);

		$args = array(
			'props'               => $props,
			'translation_strings' => $this->get_translation_strings(),
			'settings_sections'   => $this->get_settings_sections(),
			'registered_settings' => $this->get_registered_settings(),
		);

		$this->settings_api = new Settings_API( $this->settings_key, self::$prefix, $args );
	}

	/**
	 * Array containing the settings' sections.
	 *
	 * @since 1.0.0
	 *
	 * @return array Settings array
	 */
	public static function get_settings_sections(): array {
		$settings_sections = array(
			'kit'         => __( 'Kit', 'glue-link' ),
			'freemius'    => __( 'Freemius', 'glue-link' ),
			'subscribers' => __( 'Subscribers', 'glue-link' ),
		);

		/**
		 * Filter the array containing the settings' sections.
		 *
		 * @since 1.0.0
		 *
		 * @param array $settings_sections Settings array
		 */
		$settings_sections = apply_filters( 'glue_link_settings_sections', $settings_sections );

		return $settings_sections;
	}

	/**
	 * Array containing the settings' translation strings.
	 *
	 * @since 1.0.0
	 *
	 * @return array Settings array
	 */
	public function get_translation_strings(): array {
		$strings = array(
			'page_header'          => esc_html__( 'Glue for Freemius and Kit Settings', 'glue-link' ),
			'reset_message'        => esc_html__( 'Settings have been reset to their default values. Reload this page to view the updated settings.', 'glue-link' ),
			'success_message'      => esc_html__( 'Settings updated.', 'glue-link' ),
			'save_changes'         => esc_html__( 'Save Changes', 'glue-link' ),
			'reset_settings'       => esc_html__( 'Reset all settings', 'glue-link' ),
			'reset_button_confirm' => esc_html__( 'Do you really want to reset all these settings to their default values?', 'glue-link' ),
			'checkbox_modified'    => esc_html__( 'Modified from default setting', 'glue-link' ),
		);

		/**
		 * Filter the array containing the settings' sections.
		 *
		 * @since 1.0.0
		 *
		 * @param array $strings Translation strings.
		 */
		return apply_filters( self::$prefix . '_translation_strings', $strings );
	}

	/**
	 * Get the admin menus.
	 *
	 * @return array Admin menus.
	 */
	public function get_menus(): array {
		$menus = array();

		// Settings menu.
		$menus[] = array(
			'settings_page' => true,
			'type'          => 'options',
			'page_title'    => esc_html__( 'Glue for Freemius and Kit Settings', 'glue-link' ),
			'menu_title'    => esc_html__( 'WZ Glue', 'glue-link' ),
			'menu_slug'     => $this->menu_slug,
		);

		return $menus;
	}

	/**
	 * Retrieve the array of plugin settings
	 *
	 * @since 1.0.0
	 *
	 * @return array Settings array
	 */
	public static function get_registered_settings(): array {
		static $running = false;
		if ( $running ) {
			return array();
		}
		$running = true;

		$settings = array();
		$sections = self::get_settings_sections();

		foreach ( $sections as $section => $value ) {
			$method_name = 'settings_' . $section;
			if ( method_exists( __CLASS__, $method_name ) ) {
				$settings[ $section ] = self::$method_name();
			}
		}

		/**
		 * Filters the settings array
		 *
		 * @since 1.0.0
		 *
		 * @param array $glue_link_setings Settings array
		 */
		$settings = apply_filters( self::$prefix . '_registered_settings', $settings );
		$running  = false;

		return $settings;
	}

	/**
	 * Retrieve the array of Freemius settings
	 *
	 * @since 1.0.0
	 *
	 * @return array Freemius settings array
	 */
	public static function settings_freemius(): array {
		$settings = array(
			'freemius'              => array(
				'id'   => 'freemius',
				'name' => __( 'Freemius', 'glue-link' ),
				'desc' => __( 'Configure your Freemius plugins in this tab by entering required identifiers and keys. Plugin name, ID, public and secret keys are mandatory. Form and tags are optional and default to settings in the Kit tab if left blank.', 'glue-link' ),
				'type' => 'header',
			),
			'webhook_endpoint_type' => array(
				'id'      => 'webhook_endpoint_type',
				'name'    => __( 'Webhook Endpoint Type', 'glue-link' ),
				'desc'    => __( 'Select the method for registering the webhook endpoint. REST API is recommended for better security and standardization. For Query Variable, use: yourdomain.com/?glue_webhook', 'glue-link' ),
				'type'    => 'select',
				'options' => array(
					'rest'  => __( 'REST API', 'glue-link' ),
					'query' => __( 'Query Variable', 'glue-link' ),
				),
				'default' => 'rest',
			),
			'webhook_url'           => array(
				'id'   => 'webhook_url',
				'name' => __( 'Webhook URL', 'glue-link' ),
				'desc' => self::get_webhook_url(),
				'type' => 'header',
			),
			'plugins'               => array(
				'id'                => 'plugins',
				'name'              => __( 'Freemius Plugins', 'glue-link' ),
				'desc'              => '',
				'type'              => 'repeater',
				'live_update_field' => 'name',
				'default'           => array(),
				'section'           => 'freemius',
				'fields'            => array(
					array(
						'id'      => 'name',
						'name'    => __( 'Plugin Name', 'glue-link' ),
						'desc'    => __( 'Enter the name of your plugin', 'glue-link' ),
						'type'    => 'text',
						'default' => '',
						'size'    => 'large',
					),
					array(
						'id'      => 'id',
						'name'    => __( 'Plugin ID', 'glue-link' ),
						'desc'    => __( 'Enter your Freemius plugin ID', 'glue-link' ),
						'type'    => 'text',
						'default' => '',
						'size'    => 'large',
					),
					array(
						'id'      => 'public_key',
						'name'    => __( 'Public Key', 'glue-link' ),
						'desc'    => __( 'Enter your Freemius public key', 'glue-link' ),
						'type'    => 'text',
						'default' => '',
						'size'    => 'large',
					),
					array(
						'id'      => 'secret_key',
						'name'    => __( 'Secret Key', 'glue-link' ),
						'desc'    => __( 'Enter your Freemius secret key. Once saved, this will be securely stored and masked.', 'glue-link' ),
						'type'    => 'sensitive',
						'default' => '',
						'size'    => 'large',
					),
					array(
						'id'               => 'free_form_ids',
						'name'             => __( 'Free Form', 'glue-link' ),
						'desc'             => __( 'Choose the form(s) for free subscribers. Begin typing to search.', 'glue-link' ),
						'type'             => 'text',
						'default'          => '',
						'size'             => 'large',
						'field_class'      => 'ts_autocomplete',
						'field_attributes' => self::get_kit_search_field_attributes( 'forms' ),
					),
					array(
						'id'               => 'free_event_types',
						'name'             => __( 'Free Trigger Events', 'glue-link' ),
						'desc'             => __( 'Choose Freemius webhook event(s) that should add users to the Free form/tag mapping.', 'glue-link' ),
						'type'             => 'text',
						'default'          => 'install.installed,install.activated',
						'size'             => 'large',
						'field_class'      => 'ts_autocomplete',
						'field_attributes' => self::get_kit_search_field_attributes( 'freemius_events', array( 'create' => true ) ),
					),
					array(
						'id'               => 'free_tag_ids',
						'name'             => __( 'Free Tag', 'glue-link' ),
						'desc'             => __( 'Optionally, choose the tag(s) for free subscribers. Begin typing to search.', 'glue-link' ),
						'type'             => 'text',
						'default'          => '',
						'size'             => 'large',
						'field_class'      => 'ts_autocomplete',
						'field_attributes' => self::get_kit_search_field_attributes( 'tags' ),
					),
					array(
						'id'               => 'paid_form_ids',
						'name'             => __( 'Paid Form', 'glue-link' ),
						'desc'             => __( 'Choose the form(s) for paid subscribers. Begin typing to search.', 'glue-link' ),
						'type'             => 'text',
						'default'          => '',
						'size'             => 'large',
						'field_class'      => 'ts_autocomplete',
						'field_attributes' => self::get_kit_search_field_attributes( 'forms' ),
					),
					array(
						'id'               => 'paid_event_types',
						'name'             => __( 'Paid Trigger Events', 'glue-link' ),
						'desc'             => __( 'Choose Freemius webhook event(s) that should add users to the Paid form/tag mapping.', 'glue-link' ),
						'type'             => 'text',
						'default'          => 'license.created,subscription.created,payment.created',
						'size'             => 'large',
						'field_class'      => 'ts_autocomplete',
						'field_attributes' => self::get_kit_search_field_attributes( 'freemius_events', array( 'create' => true ) ),
					),
					array(
						'id'               => 'paid_tag_ids',
						'name'             => __( 'Paid Tag', 'glue-link' ),
						'desc'             => __( 'Choose the tag(s) for paid subscribers. Begin typing to search.', 'glue-link' ),
						'type'             => 'text',
						'default'          => '',
						'size'             => 'large',
						'field_class'      => 'ts_autocomplete',
						'field_attributes' => self::get_kit_search_field_attributes( 'tags' ),
					),
				),
			),
		);

		/**
		 * Filters the General settings array.
		 *
		 * @since 1.0.0
		 *
		 * @param array $settings General settings array.
		 */
		return apply_filters( self::$prefix . '_settings_general', $settings );
	}

	/**
	 * Retrieve the array of Kit settings
	 *
	 * @since 1.0.0
	 *
	 * @return array Kit settings array
	 */
	public static function settings_kit(): array {
		$settings = array(
			'kit'              => array(
				'id'   => 'kit',
				'name' => __( 'Kit', 'glue-link' ),
				'desc' => __( 'Connect to Kit using OAuth (API v4)', 'glue-link' ),
				'type' => 'header',
			),
			'kit_oauth_status' => array(
				'id'   => 'kit_oauth_status',
				'name' => __( 'Connection', 'glue-link' ),
				'desc' => Kit_OAuth::get_status_html( 'glue_link_options_page' ),
				'type' => 'header',
			),
			'kit_form_id'      => array(
				'id'               => 'kit_form_id',
				'name'             => __( 'Global Form ID', 'glue-link' ),
				'desc'             => __( 'Select the Kit form to add subscribers to. Start typing to search. This is used if the form ID is not set for a specific plugin.', 'glue-link' ),
				'type'             => 'text',
				'default'          => '',
				'size'             => 'large',
				'field_class'      => 'ts_autocomplete',
				'field_attributes' => self::get_kit_search_field_attributes( 'forms' ),
			),
			'kit_tag_id'       => array(
				'id'               => 'kit_tag_id',
				'name'             => __( 'Tag ID', 'glue-link' ),
				'desc'             => __( 'Select the Kit tag to apply (optional). Start typing to search. This is used if the tag ID is not set for a specific plugin.', 'glue-link' ),
				'type'             => 'text',
				'default'          => '',
				'size'             => 'large',
				'field_class'      => 'ts_autocomplete',
				'field_attributes' => self::get_kit_search_field_attributes( 'tags' ),
			),
		);

		/**
		 * Filters the Kit settings array
		 *
		 * @since 1.0.0
		 *
		 * @param array $settings Kit settings array
		 */
		return apply_filters( 'glue_link_settings_kit', $settings );
	}

	/**
	 * Retrieve the array of Subscribers settings
	 *
	 * @since 1.0.0
	 *
	 * @return array Subscribers settings array
	 */
	public static function settings_subscribers(): array {
		$settings = array(
			'subscribers'     => array(
				'id'   => 'subscribers',
				'name' => __( 'Subscribers', 'glue-link' ),
				'desc' => __( 'Configure your subscribers settings in this tab.', 'glue-link' ),
				'type' => 'header',
			),
			'last_name_field' => array(
				'id'               => 'last_name_field',
				'name'             => __( 'Last Name field', 'glue-link' ),
				'desc'             => __( 'Select the field name for mapping the last name in Kit. Note: Kit lacks a default last name field; a custom field must be created in your account first.', 'glue-link' ),
				'type'             => 'text',
				'default'          => '',
				'field_class'      => 'ts_autocomplete',
				'field_attributes' => self::get_kit_search_field_attributes( 'custom_fields', array( 'maxItems' => 1 ) ),
			),
			'custom_fields'   => array(
				'id'                => 'custom_fields',
				'name'              => __( 'Custom Fields', 'glue-link' ),
				'desc'              => '',
				'type'              => 'repeater',
				'live_update_field' => 'local_name',
				'default'           => array(),
				'fields'            => array(
					array(
						'id'      => 'local_name',
						'name'    => __( 'Field Local Name', 'glue-link' ),
						'desc'    => __( 'Enter the name of your field that will be used locally in the database on this site.', 'glue-link' ),
						'type'    => 'text',
						'default' => '',
					),
					array(
						'id'               => 'remote_name',
						'name'             => __( 'Field name on Kit', 'glue-link' ),
						'desc'             => __( 'Enter the name of your custom field that is used on the Kit.', 'glue-link' ),
						'type'             => 'text',
						'default'          => '',
						'field_class'      => 'ts_autocomplete',
						'field_attributes' => self::get_kit_search_field_attributes( 'custom_fields', array( 'maxItems' => 1 ) ),
					),
				),
			),
		);

		/**
		 * Filters the Subscribers settings array
		 *
		 * @since 1.0.0
		 *
		 * @param array $settings Subscribers settings array
		 */
		return apply_filters( 'glue_link_settings_subscribers', $settings );
	}

	/**
	 * Get common field attributes for Kit search fields
	 *
	 * @since 1.0.0
	 *
	 * @param string $endpoint   The endpoint to search ('forms', 'tags', 'custom_fields', 'freemius_events').
	 * @param array  $ts_config  Optional TypeScript configuration.
	 * @return array Field attributes array
	 */
	private static function get_kit_search_field_attributes( string $endpoint, array $ts_config = array() ): array {
		$attributes = array(
			'data-wp-prefix'   => 'GlueLink',
			'data-wp-action'   => self::$prefix . '_kit_search',
			'data-wp-nonce'    => wp_create_nonce( self::$prefix . '_kit_search' ),
			'data-wp-endpoint' => $endpoint,
		);

		if ( ! empty( $ts_config ) ) {
			$attributes['data-ts-config'] = wp_json_encode( $ts_config );
		}

		return $attributes;
	}

	/**
	 * Adding WordPress plugin action links.
	 *
	 * @since 1.0.0
	 *
	 * @param array $links Array of links.
	 * @return array Updated array of links.
	 */
	public function plugin_actions_links( array $links ): array {

		return array_merge(
			array(
				'settings' => '<a href="' . admin_url( 'admin.php?page=' . $this->menu_slug ) . '">' . esc_html__( 'Settings', 'glue-link' ) . '</a>',
				'wizard'   => '<a href="' . admin_url( 'options-general.php?page=glue_link_setup_wizard' ) . '">' . esc_html__( 'Setup Wizard', 'glue-link' ) . '</a>',
			),
			$links
		);
	}

	/**
	 * Add meta links on Plugins page.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $links Array of Links.
	 * @param string $file Current file.
	 * @return array Updated array of links.
	 */
	public function plugin_row_meta( array $links, string $file ): array {

		if ( false !== strpos( $file, 'glue-link.php' ) ) {
			$new_links = array(
				'support' => '<a href = "https://webberzone.com/support/">' . esc_html__( 'Support', 'glue-link' ) . '</a>',
			);

			$links = array_merge( $links, $new_links );
		}
		return $links;
	}

	/**
	 * Get the help sidebar content to display on the plugin settings page.
	 *
	 * @since 1.0.0
	 */
	public function get_help_sidebar() {
		$help_sidebar =
			/* translators: 1: Plugin support site link. */
			'<p>' . sprintf( __( 'For more information or how to get support visit the <a href="%s">support site</a>.', 'glue-link' ), esc_url( 'https://webberzone.com/support/' ) ) . '</p>';

		/**
		 * Filter to modify the help sidebar content.
		 *
		 * @since 1.0.0
		 *
		 * @param string $help_sidebar Help sidebar content.
		 */
		return apply_filters( self::$prefix . '_settings_help', $help_sidebar );
	}

	/**
	 * Get the help tabs to display on the plugin settings page.
	 *
	 * @since 1.0.0
	 */
	public function get_help_tabs() {
		$help_tabs = array(
			array(
				'id'      => 'glue_link-settings-general-help',
				'title'   => esc_html__( 'Freemius Plugins', 'glue-link' ),
				'content' =>
				'<p><strong>' . esc_html__( 'This tab allows you to add or remove plugins that you have added on Freemius', 'glue-link' ) . '</strong></p>' .
					'<p>' . esc_html__( 'You must click the Save Changes button at the bottom of the screen for new settings to take effect.', 'glue-link' ) . '</p>',
			),
			array(
				'id'      => 'glue_link-settings-kit-help',
				'title'   => esc_html__( 'Kit', 'glue-link' ),
				'content' =>
				'<p><strong>' . esc_html__( 'This tab provides the settings for configuring the integration with Kit. OAuth (API v4) is recommended; API key/secret can be used as fallback.', 'glue-link' ) . '</strong></p>' .
					'<p>' . esc_html__( 'You must click the Save Changes button at the bottom of the screen for new settings to take effect.', 'glue-link' ) . '</p>',
			),
		);

		/**
		 * Filter to add more help tabs.
		 *
		 * @since 1.0.0
		 *
		 * @param array $help_tabs Associative array of help tabs.
		 */
		return apply_filters( self::$prefix . '_settings_help', $help_tabs );
	}

	/**
	 * Add footer text on the plugin page.
	 *
	 * @since 1.0.0
	 */
	public static function get_admin_footer_text() {
		return sprintf(
			/* translators: 1: Opening achor tag with Plugin page link, 2: Closing anchor tag. */
			__( 'Thank you for using %1$sGlue for Freemius and Kit%2$s!', 'glue-link' ),
			'<a href="https://webberzone.com/plugins/glue-link/" target="_blank">',
			'</a>'
		);
	}

	/**
	 * Enqueue scripts and styles for the admin settings page.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function admin_enqueue_scripts( $hook ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check for current admin page.
		$page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : '';

		if ( false === strpos( $hook, $this->menu_slug ) && $page !== $this->menu_slug ) {
			return;
		}

		$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

		// Kit-specific scripts.
		$this->enqueue_admin_script(
			'kit-validate',
			"/js/kit-validate{$suffix}.js",
			array( 'jquery' )
		);

		// Settings scripts.
		$this->enqueue_admin_script(
			'admin',
			"/js/admin{$suffix}.js",
			array( 'jquery' )
		);

			wp_localize_script(
				'glue-link-admin',
				'GlueLinkAdmin',
				array(
					'prefix'        => self::$prefix,
					'thumb_default' => plugins_url( 'images/default.png', __FILE__ ),
					'ajax_url'      => admin_url( 'admin-ajax.php' ),
					'nonce'         => wp_create_nonce( self::$prefix . '_admin_nonce' ),
					'webhook_urls'  => self::get_webhook_urls(),
					'strings'       => array(
						'cache_cleared'        => esc_html__( 'Cache cleared successfully!', 'glue-link' ),
						'cache_error'          => esc_html__( 'Error clearing cache: ', 'glue-link' ),
						'api_validation_error' => esc_html__( 'Error validating API credentials.', 'glue-link' ),
						'copy_success'         => esc_html__( 'Webhook URL copied.', 'glue-link' ),
						'copy_failed'          => esc_html__( 'Copy failed. Select and copy manually.', 'glue-link' ),
					),
				)
			);

		// Tom Select variables.
			wp_localize_script(
				'wz-' . self::$prefix . '-tom-select-init',
				'GlueLinkTomSelectSettings',
				array(
					'prefix'          => 'GlueLink',
					'nonce'           => wp_create_nonce( self::$prefix . '_kit_search' ),
					'action'          => self::$prefix . '_kit_search',
					'endpoint'        => '',
					'forms'           => self::get_localized_kit_data( 'forms' ),
					'tags'            => self::get_localized_kit_data( 'tags' ),
					'custom_fields'   => self::get_localized_kit_data( 'custom_fields' ),
					'freemius_events' => self::get_localized_kit_data( 'freemius_events' ),
					'strings'         => array(
						/* translators: %s: search term */
						'no_results' => esc_html__( 'No results found for %s', 'glue-link' ),
					),
				)
			);
	}

	/**
	 * Helper function to enqueue admin scripts.
	 *
	 * @since 1.0.0
	 *
	 * @param string $handle Script handle without the 'glue-link-' prefix.
	 * @param string $path   Path to the script relative to the admin directory.
	 * @param array  $deps   Array of script dependencies.
	 */
	private function enqueue_admin_script( string $handle, string $path, array $deps = array() ) {
		$script_file = __DIR__ . $path;
		$version     = file_exists( $script_file ) ? (string) filemtime( $script_file ) : GLUE_LINK_VERSION;

		wp_enqueue_script(
			'glue-link-' . $handle,
			plugins_url( $path, __FILE__ ),
			$deps,
			$version,
			true
		);
	}

	/**
	 * Modify settings when they are being saved.
	 *
	 * @since 1.0.0
	 *
	 * @param  array $settings Settings array.
	 * @return array Sanitized settings array.
	 */
	public function change_settings_on_save( array $settings ): array {
		return $settings;
	}

	/**
	 * Handle AJAX search for ConvertKit resources
	 *
	 * @since 1.0.0
	 */
	public function handle_kit_search() {
		if ( ! isset( $_REQUEST['endpoint'] ) || ! isset( $_REQUEST['nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_send_json_error(
				(object) array(
					'message' => __( 'Invalid request parameters', 'glue-link' ),
					'items'   => array(),
				)
			);
		}

		// Tom Select endpoint.
		$endpoint = sanitize_text_field( wp_unslash( $_REQUEST['endpoint'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$nonce    = self::$prefix . '_kit_search';
		$query    = isset( $_REQUEST['q'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		? sanitize_text_field( wp_unslash( $_REQUEST['q'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		: ( isset( $_REQUEST['query'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_text_field( wp_unslash( $_REQUEST['query'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '' );

		check_ajax_referer( $nonce, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Insufficient permissions', 'glue-link' ),
					'items'   => array(),
				)
			);
		}

		try {
			$items = array();

			switch ( $endpoint ) {
				case 'forms':
					$data = $this->get_kit_forms( $query );
					break;
				case 'tags':
					$data = $this->get_kit_tags( $query );
					break;
				case 'custom_fields':
					$data = $this->get_kit_custom_fields( $query );
					break;
				case 'freemius_events':
					$data = self::get_freemius_events( $query );
					break;
				default:
					$data = array();
					break;
			}

			if ( is_wp_error( $data ) ) {
				wp_send_json_error(
					array(
						'message' => $data->get_error_message(),
						'items'   => array(),
					)
				);
			}

			foreach ( $data as $entry ) {
				$items[] = array(
					'id'   => $entry['id'],
					'name' => $entry['name'],
				);
			}

			wp_send_json_success(
				array(
					'message' => '',
					'items'   => $items,
				)
			);

		} catch ( \Exception $e ) {
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
					'items'   => array(),
				)
			);
		}
	}

	/**
	 * AJAX endpoint to refresh ConvertKit lists
	 *
	 * @since 1.0.0
	 */
	public function ajax_refresh_lists() {
		check_ajax_referer( self::$prefix . '_admin_nonce', 'nonce' );

		foreach ( array( 'forms', 'tags', 'sequences', 'custom_fields' ) as $transient ) {
			delete_transient( 'glue_link_kit_' . $transient );
		}

		wp_send_json_success();
	}

	/**
	 * AJAX handler to validate ConvertKit API credentials.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function ajax_validate_api() {
		check_ajax_referer( self::$prefix . '_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( (object) array( 'message' => esc_html__( 'You do not have permission to perform this action.', 'glue-link' ) ) );
		}

		$api_key = isset( $_POST['kit_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['kit_api_key'] ) ) : '';

		if ( empty( $api_key ) ) {
			wp_send_json_error( (object) array( 'message' => esc_html__( 'API key is empty.', 'glue-link' ) ) );
		}

		$api    = new \WebberZone\Glue_Link\Kit_API();
		$result = $api->validate_api_credentials();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( (object) array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( (object) array( 'message' => esc_html__( 'API key is valid.', 'glue-link' ) ) );
	}

	/**
	 * AJAX handler to validate ConvertKit API secret.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function ajax_validate_api_secret() {
		check_ajax_referer( self::$prefix . '_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( (object) array( 'message' => esc_html__( 'You do not have permission to perform this action.', 'glue-link' ) ) );
		}

		$secret_input = isset( $_POST['kit_api_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['kit_api_secret'] ) ) : '';

		// First try to use it as a raw value (when validating before saving).
		if ( ! empty( $secret_input ) && strpos( $secret_input, '*' ) === false ) {
			$api_secret = $secret_input;
		} else {
			// If it contains asterisks, it's likely from the database, so try to decrypt.
			$api_secret = Options_API::decrypt_api_key( $secret_input );
		}

		if ( empty( $api_secret ) ) {
			wp_send_json_error( (object) array( 'message' => esc_html__( 'API secret is empty.', 'glue-link' ) ) );
		}

		if ( strpos( $api_secret, '*' ) !== false ) {
			$api_secret = Options_API::decrypt_api_key( Options_API::get_option( 'kit_api_secret' ) );
		}

		$api    = new \WebberZone\Glue_Link\Kit_API();
		$result = $api->get_account();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( (object) array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( (object) array( 'message' => esc_html__( 'API secret is valid!', 'glue-link' ) ) );
	}

	/**
	 * AJAX handler to test Kit connection.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function ajax_test_kit_connection() {
		check_ajax_referer( self::$prefix . '_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( (object) array( 'message' => esc_html__( 'You do not have permission to perform this action.', 'glue-link' ) ) );
		}

		$api    = new \WebberZone\Glue_Link\Kit_API();
		$result = $api->get_account();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( (object) array( 'message' => $result->get_error_message() ) );
		}

		$account_name = isset( $result['account']['name'] ) ? sanitize_text_field( (string) $result['account']['name'] ) : '';
		$message      = $account_name
			? sprintf(
				/* translators: %s: Kit account name. */
				esc_html__( 'Connection successful. Account: %s', 'glue-link' ),
				$account_name
			)
			: esc_html__( 'Connection successful.', 'glue-link' );

		wp_send_json_success( (object) array( 'message' => $message ) );
	}

	/**
	 * Get Kit data, optionally filtered by search term.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type   Type of data to get ('forms' or 'tags').
	 * @param string $search Optional search term.
	 * @return array|\WP_Error Array of items or WP_Error on failure.
	 */
	public static function get_kit_data( string $type, string $search = '' ) {
		$transient_key = self::$prefix . "_kit_{$type}";
		$items         = get_transient( $transient_key );

		if ( false === $items ) {
			$api = new \WebberZone\Glue_Link\Kit_API();
			$has = $api->validate_api_credentials();

			if ( is_wp_error( $has ) ) {
				return $has;
			}

			switch ( $type ) {
				case 'forms':
					$response = $api->get_forms();
					break;
				case 'tags':
					$response = $api->get_tags();
					break;
				case 'custom_fields':
					$response = $api->get_custom_fields();
					break;
				default:
					$response = new \WP_Error( 'invalid_type', __( 'Invalid type specified.', 'glue-link' ) );
					break;
			}

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			// Extract items from the appropriate key in response.
			if ( isset( $response[ $type ] ) && is_array( $response[ $type ] ) ) {
				$items = $response[ $type ];
			} elseif ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
				$items = $response['data'];
			} else {
				$items = array();
			}

			$items = self::normalize_kit_items( $items, $type );

			if ( ! empty( $items ) ) {
				set_transient( $transient_key, $items, DAY_IN_SECONDS );
			}
		}

		if ( ! empty( $search ) ) {
			$search = trim( strtolower( $search ) );
			$items  = array_filter(
				$items,
				function ( $item ) use ( $search ) {
					$name = trim( strtolower( (string) $item['name'] ) );
					$id   = trim( strtolower( (string) $item['id'] ) );
					return false !== strpos( $name, $search ) || false !== strpos( $id, $search );
				}
			);
		}

		return array_values( $items );
	}

	/**
	 * Return Kit data for script localization.
	 *
	 * @param string $type Resource type.
	 * @return array
	 */
	public static function get_localized_kit_data( string $type ): array {
		if ( 'freemius_events' === $type ) {
			return self::get_freemius_events();
		}

		$data = self::get_kit_data( $type );
		return is_wp_error( $data ) ? array() : $data;
	}

	/**
	 * Return Freemius event choices for selectors.
	 *
	 * @param string $search Optional search text.
	 * @return array<int,array<string,string>>
	 */
	public static function get_freemius_events( string $search = '' ): array {
		$events = array(
			'install.installed',
			'install.activated',
			'install.premium.activated',
			'install.connected',
			'install.disconnected',
			'install.trial.started',
			'install.trial.extended',
			'install.trial.cancelled',
			'install.trial.expired',
			'install.updated',
			'license.created',
			'license.activated',
			'license.updated',
			'license.extended',
			'license.shortened',
			'license.expired',
			'license.cancelled',
			'license.deactivated',
			'license.deleted',
			'license.ownership.changed',
			'license.quota.changed',
			'subscription.created',
			'subscription.cancelled',
			'subscription.renewal.retry',
			'subscription.renewal.failed',
			'subscription.renewal.failed.last',
			'payment.created',
			'payment.refund',
			'payment.dispute.created',
			'payment.dispute.closed',
			'payment.dispute.lost',
			'payment.dispute.won',
			'cart.completed',
			'plan.lifetime.purchase',
		);

		$items = array_map(
			static function ( string $event ): array {
				return array(
					'id'   => $event,
					'name' => $event,
				);
			},
			$events
		);

		if ( '' !== $search ) {
			$query = strtolower( trim( $search ) );
			$items = array_values(
				array_filter(
					$items,
					static function ( array $item ) use ( $query ): bool {
						$needle = strtolower( $item['id'] . ' ' . $item['name'] );
						return false !== strpos( $needle, $query );
					}
				)
			);
		}

		return $items;
	}

	/**
	 * Normalize Kit API resource items into id/name pairs.
	 *
	 * @param array  $items Raw items.
	 * @param string $type  Resource type.
	 * @return array
	 */
	private static function normalize_kit_items( array $items, string $type ): array {
		$normalized = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$id = isset( $item['id'] ) ? (string) $item['id'] : ( isset( $item['key'] ) ? (string) $item['key'] : '' );
			if ( '' === $id ) {
				continue;
			}

			$name = '';
			if ( isset( $item['name'] ) ) {
				$name = (string) $item['name'];
			} elseif ( isset( $item['label'] ) ) {
				$name = (string) $item['label'];
			} elseif ( 'custom_fields' === $type && isset( $item['key'] ) ) {
				$name = (string) $item['key'];
			}

			if ( '' === $name ) {
				$name = $id;
			}

			$normalized[] = array(
				'id'   => $id,
				'name' => $name,
			);
		}

		return $normalized;
	}

	/**
	 * Get Kit forms, optionally filtered by search term.
	 *
	 * @since 1.0.0
	 *
	 * @param string $search Optional search term.
	 * @return array|\WP_Error Array of forms.
	 */
	private function get_kit_forms( $search = '' ) {
		return self::get_kit_data( 'forms', $search );
	}

	/**
	 * Get Kit tags, optionally filtered by search term.
	 *
	 * @since 1.0.0
	 *
	 * @param string $search Optional search term.
	 * @return array|\WP_Error Array of tags.
	 */
	private function get_kit_tags( $search = '' ) {
		return self::get_kit_data( 'tags', $search );
	}

	/**
	 * Get Kit custom fields, optionally filtered by search term.
	 *
	 * @since 1.0.0
	 *
	 * @param string $search Optional search term.
	 * @return array|\WP_Error Array of custom fields.
	 */
	private function get_kit_custom_fields( $search = '' ) {
		return self::get_kit_data( 'custom_fields', $search );
	}

	/**
	 * Add API validation button after API key settings.
	 *
	 * @since 1.0.0
	 *
	 * @param string $desc Description HTML.
	 * @param array  $args Field arguments.
	 * @return string Modified description HTML.
	 */
	public function add_api_validation_button( string $desc, array $args ): string {
		// Only add button after API key field.
		if ( 'kit_api_key' === $args['id'] ) {
			$desc .= ' <button type="button" class="button button-secondary validate-api-key">' . esc_html__( 'Validate API Key', 'glue-link' ) . '</button>';
			$desc .= '<span class="api-validation-status" style="margin-left: 10px;"></span>';
		}
		if ( 'kit_api_secret' === $args['id'] ) {
			$desc .= ' <button type="button" class="button button-secondary validate-api-secret">' . esc_html__( 'Validate API Secret', 'glue-link' ) . '</button>';
			$desc .= '<span class="api-validation-status" style="margin-left: 10px;"></span>';
		}
		return $desc;
	}

	/**
	 * Add a "Test Connection" button after the OAuth connection output.
	 *
	 * @since 1.0.0
	 *
	 * @param string $html Field output HTML.
	 * @param array  $args Field arguments.
	 * @return string
	 */
	public function add_connection_test_button( string $html, array $args ): string {
		if ( ! isset( $args['id'] ) || 'kit_oauth_status' !== $args['id'] ) {
			return $html;
		}

		$html .= '<p><button type="button" class="button button-secondary test-kit-connection">' . esc_html__( 'Test Connection', 'glue-link' ) . '</button>';
		$html .= '<span class="kit-connection-status" style="margin-left: 10px;"></span></p>';

		return $html;
	}

	/**
	 * Get settings defaults.
	 *
	 * @since 1.0.0
	 *
	 * @return array Default settings.
	 */
	public static function settings_defaults() {
		static $running = false;
		if ( $running ) {
			return array();
		}
		$running = true;

		$defaults = array();

		// Get all registered settings.
		$settings = self::get_registered_settings();

		// Loop through each section.
		foreach ( $settings as $section => $section_settings ) {
			// Loop through each setting in the section.
			foreach ( $section_settings as $setting ) {
				if ( isset( $setting['id'] ) ) {
					// When checkbox is set to true, set this to 1.
					if ( 'checkbox' === $setting['type'] && ! empty( $setting['options'] ) ) {
						$defaults[ $setting['id'] ] = 1;
					} elseif ( in_array( $setting['type'], array( 'textarea', 'css', 'html', 'text', 'url', 'csv', 'color', 'numbercsv', 'postids', 'posttypes', 'number', 'wysiwyg', 'file', 'password' ), true ) && isset( $setting['default'] ) ) {
						$defaults[ $setting['id'] ] = $setting['default'];
					} elseif ( in_array( $setting['type'], array( 'multicheck', 'radio', 'select', 'radiodesc', 'thumbsizes', 'repeater' ), true ) && isset( $setting['default'] ) ) {
						$defaults[ $setting['id'] ] = $setting['default'];
					} else {
						$defaults[ $setting['id'] ] = '';
					}
				}
			}
		}

		/**
		 * Filter the default settings array.
		 *
		 * @since 1.0.0
		 *
		 * @param array $defaults Default settings.
		 */
		$defaults = apply_filters( self::$prefix . '_settings_defaults', $defaults );
		$running  = false;

		return $defaults;
	}

	/**
	 * Add subscribers link to settings page header.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function add_subscribers_link() {
		$url = add_query_arg(
			array(
				'page' => 'glue_link_subscribers',
			),
			admin_url( 'users.php' )
		);
		?>

		<a href="<?php echo esc_url( $url ); ?>" class="page-title-action"><?php esc_html_e( 'View Subscribers', 'glue-link' ); ?></a>
		<?php
	}

	/**
	 * Add clear cache button to the settings page.
	 *
	 * @since 1.0.0
	 */
	public static function add_cache_clear_button() {
		printf(
			'<button type="button" name="wp_ajax_glue_link_refresh_cache" id="wp_ajax_glue_link_refresh_cache" class="button button-secondary glue_link_cache_clear" aria-label="%1$s">%1$s</button>',
			esc_html__( 'Clear cache', 'glue-link' )
		);
	}

	/**
	 * Add Setup Wizard button on the settings page.
	 *
	 * @return void
	 */
	public function render_wizard_button(): void {
		printf(
			'<br /><a aria-label="%1$s" class="button button-secondary" href="%2$s" title="%1$s" style="margin-top: 10px;">%3$s</a>',
			esc_attr__( 'Start Setup Wizard', 'glue-link' ),
			esc_url(
				wp_nonce_url(
					add_query_arg(
						array(
							'page'          => 'glue_link_setup_wizard',
							'wizard_action' => 'restart',
						),
						admin_url( 'options-general.php' )
					),
					'glue_link_restart_wizard'
				)
			),
			esc_html__( 'Start Setup Wizard', 'glue-link' )
		);
	}

	/**
	 * Return webhook URLs for both endpoint types.
	 *
	 * @return array<string,string>
	 */
	public static function get_webhook_urls(): array {
		return array(
			'rest'  => home_url( '/wp-json/glue-link/v1/webhook' ),
			'query' => add_query_arg( 'glue_webhook', '1', home_url() ),
		);
	}

	/**
	 * Get the webhook URL.
	 *
	 * @since 1.0.0
	 *
	 * @return string The webhook URL.
	 */
	private static function get_webhook_url(): string {
		$urls      = self::get_webhook_urls();
		$rest_url  = $urls['rest'];
		$query_url = $urls['query'];

		// Avoid recursive defaults resolution while settings are being registered.
		$settings      = get_option( Options_API::SETTINGS_OPTION, array() );
		$endpoint_type = isset( $settings['webhook_endpoint_type'] ) ? (string) $settings['webhook_endpoint_type'] : 'rest';
		$webhook_url   = 'query' === $endpoint_type ? $query_url : $rest_url;

		$string  = '<div class="webhook-url-container" data-rest-url="' . esc_attr( $rest_url ) . '" data-query-url="' . esc_attr( $query_url ) . '">';
		$string .= '<p>' . esc_html__( 'Copy the following URL to your Freemius dashboard:', 'glue-link' ) . '</p>';
		$string .= '<p><input type="text" class="regular-text glue-link-webhook-url-input" readonly value="' . esc_attr( $webhook_url ) . '" /></p>';
		$string .= '<p><button type="button" class="button button-secondary glue-link-webhook-copy">' . esc_html__( 'Copy URL', 'glue-link' ) . '</button></p>';
		$string .= '<p class="description glue-link-webhook-copy-status" aria-live="polite"></p>';
		$string .= '<p><code class="glue-link-webhook-url-code" title="' . esc_attr__( 'Click to copy URL', 'glue-link' ) . '" style="cursor:pointer;">' . esc_html( $webhook_url ) . '</code></p>';
		$string .= '<p class="description">' . esc_html__( 'This URL updates automatically based on your selected endpoint type.', 'glue-link' ) . '</p>';
		$string .= '</div>';

		return $string;
	}
}
