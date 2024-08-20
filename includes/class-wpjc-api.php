<?php

use Tmeister\Firebase\JWT\JWT;
use Tmeister\Firebase\JWT\Key;

/**
 * API-specific functionality for the plugin.
 *
 * @link       https://wpjuggler.com
 * @since      1.0.0
 *
 * @package    WP_Juggler_Server
 * @subpackage WP_Juggler_Server/includes
 */

// Prevent direct access.
if (! defined('WPJC_PATH')) exit;

class WPJC_Api
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
	 * @var      string    $wp_juggler_server       The name of this plugin.
	 * @var      string    $version    The version of this plugin.
	 */
	public function __construct($wp_juggler_client, $version)
	{
		$this->wp_juggler_client = $wp_juggler_client;
		$this->version = $version;
		$this->plugin_name = 'wpjc';
	}

	public function api_validate_api_key()
	{
		$auth_header = !empty($_SERVER['HTTP_AUTHORIZATION']) ? sanitize_text_field($_SERVER['HTTP_AUTHORIZATION']) : false;
		/* Double check for different auth header string (server dependent) */
		if (!$auth_header) {
			$auth_header = !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) ? sanitize_text_field($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) : false;
		}

		if (!$auth_header) {
			return false;
		}

		/**
		 * Check if the auth header is not bearer, if so, return the user
		 */
		if (strpos($auth_header, 'Bearer') !== 0) {
			return false;
		}

		[$token] = sscanf($auth_header, 'Bearer %s');

		$api_key = get_option('wpjc_api_key') ? esc_attr(get_option('wpjc_api_key')) : '';

		if ($api_key && $api_key == $token) {
			return true;
		} else {
			return false;
		}
	}

	public function api_register_routes()
	{
		register_rest_route('juggler/v1', '/getPlugins/', array(
			'methods' => 'POST',
			'callback' => array($this, 'get_plugins'),
			'args' => array(),
			'permission_callback' => array($this, 'api_validate_api_key')
		));

		register_rest_route('juggler/v1', '/activatePlugin/', array(
			'methods' => 'POST',
			'callback' => array($this, 'activate_plugin'),
			'args' => array(),
			'permission_callback' => array($this, 'api_validate_api_key')
		));

		register_rest_route('juggler/v1', '/deactivatePlugin/', array(
			'methods' => 'POST',
			'callback' => array($this, 'deactivate_plugin'),
			'args' => array(),
			'permission_callback' => array($this, 'api_validate_api_key')
		));

		register_rest_route('juggler/v1', '/siteHealth/', array(
			'methods' => 'POST',
			'callback' => array($this, 'site_health'),
			'args' => array(),
			'permission_callback' => array($this, 'api_validate_api_key')
		));

		register_rest_route('juggler/v1', '/confirmClientApi/', array(
			'methods' => 'POST',
			'callback' => array($this, 'confirm_client_api'),
			'args' => array(),
			'permission_callback' => array($this, 'api_validate_api_key')
		));
	}

	public function test_connection(WP_REST_Request $request)
	{

		$parameters = json_decode($request->get_body(), true);

		if (array_key_exists('pluginSlug', $parameters) && array_key_exists('pluginVersion', $parameters)) {

			$plugin_slug = sanitize_text_field($parameters['pluginSlug']);
			$plugin_version = sanitize_text_field($parameters['pluginVersion']);

			include_once(ABSPATH . 'wp-admin/includes/plugin.php');
			include_once(ABSPATH . 'wp-admin/includes/file.php');
			include_once(ABSPATH . 'wp-admin/includes/misc.php');
			include_once(ABSPATH . 'wp-admin/includes/class-wp-upgrader.php');
			include_once(ABSPATH . 'wp-admin/includes/plugin-install.php');

			$installed_plugins = get_plugins();

			$data = array();

			foreach ($installed_plugins as $plugin_path => $plugin_info) {
				$data[$plugin_path] = array(
					'Name' => $plugin_info['Name'],
					'Version' => $plugin_info['Version'],
				);
				/* if (strpos($plugin_path, $plugin_slug . '/') === 0) {
					$plugin_file = $plugin_path;
					break;
				} */
			}

			wp_send_json_success($data, 200);
		} else {
			wp_send_json_error(new WP_Error('Missing param', 'Either domain or title or uid missing'), 400);
		}
	}

	public function activate_plugin(WP_REST_Request $request)
	{
		$parameters = json_decode($request->get_body(), true);

		if (array_key_exists('pluginSlug', $parameters)) {

			if (! function_exists('get_plugins')) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$plugin_slug = sanitize_text_field($parameters['pluginSlug']);

			$installed_plugins = get_plugins();
			$plugin_file = '';

			foreach ($installed_plugins as $plugin_path => $plugin_info) {
				if (strpos($plugin_path, $plugin_slug . '/') === 0) {
					$plugin_file = $plugin_path;
					break;
				}
			}

			if (!$plugin_file) {
				return;
			}

			if (!is_plugin_active($plugin_file)) {
				try {
					$result = activate_plugin($plugin_file);
					if (is_wp_error($result)) {
						wp_send_json_error($result, 500);
						return;
					}
				} catch (Exception $ex) {
					wp_send_json_error( new WP_Error('activation_failed', __('Failed to activate the plugin.'), array('status' => 500)), 500 );
					return;
				}
			}
			$data = array();
			wp_send_json_success($data, 200);
		} else {
			wp_send_json_error(new WP_Error('Missing param', 'Plugin slug is missing'), 400);
		}
	}

	public function deactivate_plugin(WP_REST_Request $request)
	{
		$parameters = json_decode($request->get_body(), true);

		if (array_key_exists('pluginSlug', $parameters)) {

			if (! function_exists('get_plugins')) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$plugin_slug = sanitize_text_field($parameters['pluginSlug']);

			$installed_plugins = get_plugins();
			$plugin_file = '';

			foreach ($installed_plugins as $plugin_path => $plugin_info) {
				if (strpos($plugin_path, $plugin_slug . '/') === 0) {
					$plugin_file = $plugin_path;
					break;
				}
			}

			if (!$plugin_file) {
				return;
			}

			if (is_plugin_active($plugin_file)) {
				try {
					$result = deactivate_plugins($plugin_file);
					if (is_wp_error($result)) {
						wp_send_json_error($result, 500);
						return;
					}
				} catch (Exception $ex) {
					wp_send_json_error( new WP_Error('deactivation_failed', __('Failed to deactivate the plugin.'), array('status' => 500)), 500 );
					return;
				}
			}
			$data = array();
			wp_send_json_success($data, 200);
		} else {
			wp_send_json_error(new WP_Error('Missing param', 'Plugin slug is missing'), 400);
		}
	}

	public function get_plugins(WP_REST_Request $request)
	{

		if (! function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed_plugins = get_plugins();

		$data = array();

		foreach ($installed_plugins as $plugin_path => $plugin_info) {
			$data[$plugin_path] = array(
				'Name' => $plugin_info['Name'],
				'Version' => $plugin_info['Version'],
				'Active' => is_plugin_active($plugin_path)
			);
		}

		wp_send_json_success($data, 200);
	}

	public function site_health(WP_REST_Request $request)
	{

		$health_check_site_status = new WPJC_Health();

		require_once ABSPATH . 'wp-admin/includes/admin.php';

		if ( ! class_exists( 'WP_Debug_Data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-debug-data.php';
		}

		WP_Debug_Data::check_for_updates();

		$info = WP_Debug_Data::debug_data();

		$data = $health_check_site_status->wpjc_health_info();
		$data['debug'] = $info;

		wp_send_json_success($data, 200);
	}

	public function confirm_client_api(WP_REST_Request $request)
	{

		//wp_send_json_error(new WP_Error('Missing param', 'Plugin slug is missing'), 400);
		
		$data = array();
		wp_send_json_success($data, 200);

	}
}




if ( ! class_exists( 'WP_Site_Health' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
}



class WPJC_Health extends WP_Site_Health{

	public function wpjc_perform_test( $callback ) {
		return apply_filters( 'site_status_test_result', call_user_func( $callback ) );
	}

	public function wpjc_health_info(){

		require_once ABSPATH . 'wp-admin/includes/admin.php';

		$health_check_js_variables = array(
			'nonce'       => array(
				'site_status'        => wp_create_nonce( 'health-check-site-status' ),
				'site_status_result' => wp_create_nonce( 'health-check-site-status-result' ),
			),
			'site_status' => array(
				'direct' => array(),
				'async'  => array(),
				'issues' => array(
					'good'        => 0,
					'recommended' => 0,
					'critical'    => 0,
				),
			),
		);

		$issue_counts = get_transient( 'health-check-site-status-result' );

		if ( false !== $issue_counts ) {
			$issue_counts = json_decode( $issue_counts );

			$health_check_js_variables['site_status']['issues'] = $issue_counts;
		}

		$tests = WPJC_Health::get_tests();

			foreach ( $tests['direct'] as $test ) {
				if ( is_string( $test['test'] ) ) {
					$test_function = sprintf(
						'get_test_%s',
						$test['test']
					);

					if ( method_exists( $this, $test_function ) && is_callable( array( $this, $test_function ) ) ) {
						$health_check_js_variables['site_status']['direct'][] = $this->wpjc_perform_test( array( $this, $test_function ) );
						continue;
					}
				}

				if ( is_callable( $test['test'] ) ) {
					$health_check_js_variables['site_status']['direct'][] = $this->wpjc_perform_test( $test['test'] );
				}
			}

			foreach ( $tests['async'] as $test ) {
				if ( is_string( $test['test'] ) ) {
					$health_check_js_variables['site_status']['async'][] = array(
						'test'      => $test['test'],
						'has_rest'  => ( isset( $test['has_rest'] ) ? $test['has_rest'] : false ),
						'completed' => false,
						'headers'   => isset( $test['headers'] ) ? $test['headers'] : array(),
					);
				}
			}

		return $health_check_js_variables;
	}

}


