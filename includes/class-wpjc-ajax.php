<?php

/**
 * AJAX-specific functionality for the plugin.
 *
 * @link       https://wpjuggler.com
 * @since      1.0.0
 *
 * @package    WP_Juggler_Client
 * @subpackage WP_Juggler_Client/includes
 */

// Prevent direct access.
if (! defined('WPJC_PATH')) exit;

class WPJC_AJAX
{

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $wp_juggler_client    The ID of this plugin.
	 */
	private $wp_juggler_client;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	private $plugin_name;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @var      string    $wp_juggler_client       The name of this plugin.
	 * @var      string    $version    The version of this plugin.
	 */
	public function __construct($wp_juggler_client, $version)
	{
		$this->wp_juggler_client = $wp_juggler_client;
		$this->version = $version;
		$this->plugin_name = 'wpjc';
	}

	public function ajax_get_dashboard()
	{
		if (!current_user_can('manage_options')) {
			wp_send_json_error(new WP_Error('Unauthorized', 'Access to API is unauthorized.'), 401);
			return;
		}

		$args = array(
			'post_type' => 'wpjugglersites',
			'post_status' => 'publish',
			'numberposts' => -1
		);

		$wpjuggler_sites = get_posts($args);
		$data = array();

		foreach ($wpjuggler_sites as $site) {
			$data[] = array(
				'title' => get_the_title($site->ID),
				'wp_juggler_automatic_login' => get_post_meta($site->ID, 'wp_juggler_automatic_login', true),
				'wp_juggler_client_site_url' => get_post_meta($site->ID, 'wp_juggler_client_site_url', true)
			);
		}

		wp_send_json_success($data, 200);
	}

	public function ajax_get_settings()
	{
		if (!current_user_can('manage_options')) {
			wp_send_json_error(new WP_Error('Unauthorized', 'Access to API is unauthorized.'), 401);
			return;
		}

		$wpjc_api_key = get_option('wpjc_api_key');
		$wpjc_server_url = get_site_option('wpjc_server_url');

		$data = array(
			'wpjc_api_key' => $wpjc_api_key ? esc_attr($wpjc_api_key) : '',
			'wpjc_server_url' => $wpjc_server_url ? esc_attr($wpjc_server_url) : ''
		);

		wp_send_json_success($data, 200);
	}

	public function ajax_save_settings()
	{
		if (!current_user_can('manage_options')) {
			wp_send_json_error(new WP_Error('Unauthorized', 'Access to API is unauthorized.'), 401);
			return;
		}

		$wpjc_api_key = (isset($_POST['wpjc_api_key'])) ? sanitize_text_field($_POST['wpjc_api_key']) : false;
		$wpjc_server_url = (isset($_POST['wpjc_server_url'])) ? sanitize_text_field($_POST['wpjc_server_url']) : false;

		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], $this->plugin_name . '-settings')) {
			wp_send_json_error(new WP_Error('Unauthorized', 'Nonce is not valid'), 401);
			exit;
		}

		if ($wpjc_api_key) {
			update_option('wpjc_api_key',  $wpjc_api_key);
		} else {
			delete_option('wpjc_api_key');
		}

		if ($wpjc_server_url) {
			update_site_option('wpjc_server_url',  $wpjc_server_url);
		} else {
			delete_site_option('wpjc_server_url');
		}

		$response = WPJC_Server_Api::activate_site();

		if( $response && !is_wp_error($response)){
			$data = array();
			wp_send_json_success($data, 200);
		} else {
			wp_send_json_error( $response, 400 );
		}
	}
}
