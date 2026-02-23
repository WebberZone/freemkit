<?php
/**
 * Settings Wizard for Glue Link.
 *
 * @package WebberZone\Glue_Link\Admin
 */

namespace WebberZone\Glue_Link\Admin;

use WebberZone\Glue_Link\Admin\Settings\Settings_Wizard_API;
use WebberZone\Glue_Link\Util\Hook_Registry;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Settings Wizard class.
 */
class Settings_Wizard extends Settings_Wizard_API {

	/**
	 * Wizard page slug.
	 */
	private const PAGE_SLUG = 'glue_link_setup_wizard';

	/**
	 * Settings page URL.
	 *
	 * @var string
	 */
	protected string $settings_page_url;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$settings_key = 'glue_link_settings';
		$prefix       = 'glue_link';

		$this->settings_page_url = admin_url( 'options-general.php?page=glue_link_options_page' );

		$args = array(
			'steps'               => $this->get_wizard_steps(),
			'translation_strings' => $this->get_translation_strings(),
			'page_slug'           => self::PAGE_SLUG,
			'menu_args'           => array(
				'parent'     => 'options-general.php',
				'capability' => 'manage_options',
			),
			'hide_when_completed' => true,
			'show_in_menu'        => false,
		);

		parent::__construct( $settings_key, $prefix, $args );

		// Handle OAuth callbacks when originating from wizard screens.
		new Kit_OAuth( $this->page_slug );

		$this->additional_hooks();
	}

	/**
	 * Register plugin-specific hooks.
	 *
	 * @return void
	 */
	protected function additional_hooks(): void {
		Hook_Registry::add_action( 'admin_init', array( $this, 'maybe_redirect_after_activation' ) );
		Hook_Registry::add_action( 'admin_init', array( $this, 'maybe_restart_wizard' ) );
		Hook_Registry::add_action( 'admin_init', array( $this, 'register_wizard_notice' ) );
		Hook_Registry::add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_wizard_support_scripts' ) );
		Hook_Registry::add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_wizard_tom_select_data' ) );
	}

	/**
	 * Return wizard steps.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function get_wizard_steps(): array {
		$all_settings_grouped = Settings::get_registered_settings();
		$all_settings         = array();

		foreach ( $all_settings_grouped as $section_settings ) {
			$all_settings = array_merge( $all_settings, $section_settings );
		}

		if ( isset( $all_settings['kit_oauth_status'] ) ) {
			$all_settings['kit_oauth_status']['desc'] = Kit_OAuth::get_status_html(
				self::PAGE_SLUG,
				array(
					'step' => 2,
				)
			);
		}

		$steps = array(
			'welcome'        => array(
				'title'       => __( 'Welcome', 'glue-link' ),
				'description' => __( 'This wizard helps you complete the essential setup for Glue Link.', 'glue-link' ),
				'settings'    => array(),
			),
			'kit_connection' => array(
				'title'       => __( 'Connect Kit', 'glue-link' ),
				'description' => __( 'Connect your Kit account via OAuth. After authorization, you return directly to the mapping step.', 'glue-link' ),
				'settings'    => $this->build_step_settings(
					array(
						'kit_oauth_status',
					),
					$all_settings
				),
			),
			'kit_mapping'    => array(
				'title'       => __( 'Kit Mapping', 'glue-link' ),
				'description' => __( 'Select default forms/tags and field mappings used for subscriber sync.', 'glue-link' ),
				'settings'    => $this->build_step_settings(
					array(
						'kit_form_id',
						'kit_tag_id',
						'last_name_field',
						'custom_fields',
					),
					$all_settings
				),
			),
			'freemius'       => array(
				'title'       => __( 'Freemius Webhook', 'glue-link' ),
				'description' => __( 'Configure webhook handling and add one or more Freemius plugin mappings.', 'glue-link' ),
				'settings'    => $this->build_step_settings(
					array(
						'webhook_endpoint_type',
						'webhook_url',
						'plugins',
					),
					$all_settings
				),
			),
		);

		/**
		 * Filters wizard steps.
		 *
		 * @param array $steps Wizard steps.
		 */
		return apply_filters( 'glue_link_wizard_steps', $steps );
	}

	/**
	 * Build settings array for a wizard step from setting keys.
	 *
	 * @param array<string>              $keys         Setting keys.
	 * @param array<string,array<mixed>> $all_settings Full settings list.
	 * @return array<string,array<mixed>>
	 */
	protected function build_step_settings( array $keys, array $all_settings ): array {
		$step_settings = array();

		foreach ( $keys as $key ) {
			if ( isset( $all_settings[ $key ] ) ) {
				$step_settings[ $key ] = $all_settings[ $key ];
			}
		}

		return $step_settings;
	}

	/**
	 * Translation strings.
	 *
	 * @return array<string,string>
	 */
	public function get_translation_strings(): array {
		return array(
			'page_title'            => __( 'Glue Link Setup Wizard', 'glue-link' ),
			'menu_title'            => __( 'Setup Wizard', 'glue-link' ),
			'wizard_title'          => __( 'Glue Link Setup Wizard', 'glue-link' ),
			'next_step'             => __( 'Next Step', 'glue-link' ),
			'previous_step'         => __( 'Previous Step', 'glue-link' ),
			'finish_setup'          => __( 'Finish Setup', 'glue-link' ),
			'skip_wizard'           => __( 'Skip Wizard', 'glue-link' ),
			/* translators: %s: Search query. */
			'tom_select_no_results' => __( 'No results found for "%s"', 'glue-link' ),
			'repeater_new_item'     => __( 'New Item', 'glue-link' ),
			'required_label'        => __( 'Required', 'glue-link' ),
			'steps_nav_aria_label'  => __( 'Setup Wizard Steps', 'glue-link' ),
			/* translators: %1$d: Current step number, %2$d: Total number of steps. */
			'step_of'               => __( 'Step %1$d of %2$d', 'glue-link' ),
			'wizard_complete'       => __( 'Setup Complete!', 'glue-link' ),
			'setup_complete'        => __( 'Glue Link is ready. You can continue in the full settings screen at any time.', 'glue-link' ),
			'go_to_settings'        => __( 'Go to Settings', 'glue-link' ),
		);
	}

	/**
	 * Register wizard notice through the notices API.
	 *
	 * @return void
	 */
	public function register_wizard_notice(): void {
		if ( ! Admin::$notices_api instanceof Admin_Notices_API ) {
			return;
		}

		Admin::$notices_api->register_notice(
			array(
				'id'          => 'glue_link_wizard_notice',
				'message'     => sprintf(
					'<p>%s</p><p><a href="%s" class="button button-primary">%s</a></p>',
					esc_html__( 'Welcome to Glue Link. Run the setup wizard to complete the initial configuration.', 'glue-link' ),
					esc_url( $this->get_wizard_url() ),
					esc_html__( 'Run Setup Wizard', 'glue-link' )
				),
				'type'        => 'info',
				'dismissible' => true,
				'capability'  => 'manage_options',
				'conditions'  => array(
					function (): bool {
						// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page check for conditional notice display.
						$page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : '';

						return ! $this->is_wizard_completed()
							&& ! get_option( 'glue_link_wizard_notice_dismissed', false )
							&& ( get_transient( 'glue_link_show_wizard_activation_redirect' ) || get_option( 'glue_link_show_wizard', false ) )
							&& $this->page_slug !== $page;
					},
				),
			)
		);
	}

	/**
	 * Redirect to wizard once after activation.
	 *
	 * @return void
	 */
	public function maybe_redirect_after_activation(): void {
		if ( ! current_user_can( 'manage_options' ) || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		if ( is_network_admin() || ( defined( 'IFRAME_REQUEST' ) && IFRAME_REQUEST ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page check for conditional redirect.
		$page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : '';
		if ( $this->page_slug === $page ) {
			return;
		}

		if ( $this->is_wizard_completed() ) {
			return;
		}

		if ( ! get_transient( 'glue_link_show_wizard_activation_redirect' ) ) {
			return;
		}

		delete_transient( 'glue_link_show_wizard_activation_redirect' );

		wp_safe_redirect( $this->get_wizard_url() );
		exit;
	}

	/**
	 * Restart wizard when requested by user.
	 *
	 * @return void
	 */
	public function maybe_restart_wizard(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page check.
		$page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : '';
		if ( $this->page_slug !== $page ) {
			return;
		}

		$action = isset( $_GET['wizard_action'] ) ? sanitize_key( (string) wp_unslash( $_GET['wizard_action'] ) ) : '';
		if ( 'restart' !== $action ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'glue_link_restart_wizard' ) ) {
			return;
		}

		$this->reset_wizard();
		$this->trigger_wizard();
		update_option( "{$this->prefix}_wizard_current_step", 1 );
		delete_transient( 'glue_link_show_wizard_activation_redirect' );

		wp_safe_redirect( $this->get_wizard_url( array( 'step' => 1 ) ) );
		exit;
	}

	/**
	 * Localize Glue Link Tom Select data on wizard pages.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_wizard_tom_select_data( string $hook ): void {
		if ( false === strpos( $hook, $this->page_slug ) ) {
			return;
		}

		wp_localize_script(
			'wz-' . $this->prefix . '-tom-select-init',
			'GlueLinkTomSelectSettings',
			array(
				'prefix'          => 'GlueLink',
				'nonce'           => wp_create_nonce( $this->prefix . '_kit_search' ),
				'action'          => $this->prefix . '_kit_search',
				'endpoint'        => '',
				'forms'           => Settings::get_localized_kit_data( 'forms' ),
				'tags'            => Settings::get_localized_kit_data( 'tags' ),
				'custom_fields'   => Settings::get_localized_kit_data( 'custom_fields' ),
				'freemius_events' => Settings::get_localized_kit_data( 'freemius_events' ),
				'strings'         => array(
					/* translators: %s: search term */
					'no_results' => esc_html__( 'No results found for %s', 'glue-link' ),
				),
			)
		);
	}

	/**
	 * Enqueue wizard support scripts (e.g. Kit connection test handlers).
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_wizard_support_scripts( string $hook ): void {
		if ( false === strpos( $hook, $this->page_slug ) ) {
			return;
		}

		$suffix     = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
		$admin_path = "/js/admin{$suffix}.js";
		$kit_path   = "/js/kit-validate{$suffix}.js";

		$admin_file    = __DIR__ . $admin_path;
		$kit_file      = __DIR__ . $kit_path;
		$admin_version = file_exists( $admin_file ) ? (string) filemtime( $admin_file ) : GLUE_LINK_VERSION;
		$kit_version   = file_exists( $kit_file ) ? (string) filemtime( $kit_file ) : GLUE_LINK_VERSION;

		wp_enqueue_script(
			'glue-link-admin',
			plugins_url( $admin_path, __FILE__ ),
			array( 'jquery' ),
			$admin_version,
			true
		);

		wp_enqueue_script(
			'glue-link-kit-validate',
			plugins_url( $kit_path, __FILE__ ),
			array( 'jquery' ),
			$kit_version,
			true
		);

		wp_localize_script(
			'glue-link-admin',
			'GlueLinkAdmin',
			array(
				'prefix'        => $this->prefix,
				'thumb_default' => plugins_url( 'images/default.png', __FILE__ ),
				'ajax_url'      => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( $this->prefix . '_admin_nonce' ),
				'webhook_urls'  => Settings::get_webhook_urls(),
				'strings'       => array(
					'cache_cleared'        => esc_html__( 'Cache cleared successfully!', 'glue-link' ),
					'cache_error'          => esc_html__( 'Error clearing cache: ', 'glue-link' ),
					'api_validation_error' => esc_html__( 'Error validating API credentials.', 'glue-link' ),
					'copy_success'         => esc_html__( 'Webhook URL copied.', 'glue-link' ),
					'copy_failed'          => esc_html__( 'Copy failed. Select and copy manually.', 'glue-link' ),
				),
			)
		);
	}

	/**
	 * Completion redirect URL.
	 *
	 * @return string
	 */
	protected function get_completion_redirect_url() {
		return $this->settings_page_url;
	}

	/**
	 * Completion buttons.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_completion_buttons() {
		$buttons   = parent::get_completion_buttons();
		$buttons[] = array(
			'url'     => wp_nonce_url(
				$this->get_wizard_url(
					array(
						'wizard_action' => 'restart',
					)
				),
				'glue_link_restart_wizard'
			),
			'text'    => __( 'Run Wizard Again', 'glue-link' ),
			'primary' => false,
		);

		return $buttons;
	}

	/**
	 * Build wizard URL.
	 *
	 * @param array<string,mixed> $args Optional query args.
	 * @return string
	 */
	private function get_wizard_url( array $args = array() ): string {
		$default = array(
			'page' => $this->page_slug,
		);

		return add_query_arg( array_merge( $default, $args ), admin_url( 'options-general.php' ) );
	}
}
