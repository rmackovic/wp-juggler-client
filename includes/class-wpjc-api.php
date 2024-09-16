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

require_once WPJC_PATH . 'includes/api-classes/class-wpjc-core-checksum.php';
require_once WPJC_PATH . 'includes/api-classes/class-wpjc-plugin-checksum.php';
require_once WPJC_PATH . 'includes/api-classes/class-wpjc-health.php';

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

	public $core_checksum;
	public $plugin_checksum;

	private $plugin_plugin_updater;

	private $bg_process;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @var      string    $wp_juggler_server       The name of this plugin.
	 * @var      string    $version    The version of this plugin.
	 */
	public function __construct($wp_juggler_client, $version, $plugin_plugin_updater)
	{
		$this->wp_juggler_client = $wp_juggler_client;
		$this->version = $version;
		$this->plugin_name = 'wpjc';
		$this->plugin_plugin_updater = $plugin_plugin_updater;
		$this->core_checksum = new WPJCCoreChecksum();
		$this->plugin_checksum = new WPJCPluginChecksum();

		$this->bg_process = new WPJC_Background_Process($this);
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

		$args = array(
			'role'    => 'administrator',
			'orderby' => 'ID',
			'order'   => 'ASC',
			'number'  => 1
		);

		$users = get_users($args);
		
		$our_user = !empty($users) ? $users[0] : false;
		if ($our_user) wp_set_current_user($our_user->ID);

		register_rest_route('juggler/v1', '/activatePlugin/', array(
			'methods' => 'POST',
			'callback' => array($this, 'api_activate_plugin'),
			'args' => array(),
			'permission_callback' => array($this, 'api_validate_api_key')
		));

		register_rest_route('juggler/v1', '/deactivatePlugin/', array(
			'methods' => 'POST',
			'callback' => array($this, 'api_deactivate_plugin'),
			'args' => array(),
			'permission_callback' => array($this, 'api_validate_api_key')
		));

		register_rest_route('juggler/v1', '/updatePlugin/', array(
			'methods' => 'POST',
			'callback' => array($this, 'api_update_plugin'),
			'args' => array(),
			'permission_callback' => array($this, 'api_validate_api_key')
		));

		register_rest_route('juggler/v1', '/confirmClientApi/', array(
			'methods' => 'POST',
			'callback' => array($this, 'confirm_client_api'),
			'args' => array(),
			'permission_callback' => array($this, 'api_validate_api_key')
		));

		register_rest_route('juggler/v1', '/initiateTask/', array(
			'methods' => 'POST',
			'callback' => array($this, 'initiate_Task'),
			'args' => array(),
			'permission_callback' => array($this, 'api_validate_api_key')
		));
	}

	public function api_activate_plugin(WP_REST_Request $request)
	{
		$parameters = json_decode($request->get_body(), true);

		if (array_key_exists('pluginSlug', $parameters)) {

			$network_wide = false;

			if (array_key_exists('networkWide', $parameters)) {
				$network_wide = filter_var($parameters['networkWide'], FILTER_VALIDATE_BOOLEAN);
			}

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
				wp_send_json_error(new WP_Error('Missing plugin', 'Plugin is not installed'), 400);
				return;
			}

			$status = $this->get_status($plugin_file);
			if (in_array($status, ['active', 'active-network'], true)) {
				wp_send_json_error(new WP_Error('Plugin acivated', 'Deactivate plugin before activating it again'), 400);
				return;
			}

			try {

				$result = activate_plugin($plugin_file, '', $network_wide);

				if (is_wp_error($result)) {
					wp_send_json_error($result, 500);
					return;
				}
			} catch (Exception $ex) {
				wp_send_json_error(new WP_Error('activation_failed', __('Failed to activate the plugin.'), array('status' => 500)), 500);
				return;
			}

			$data = array();
			wp_send_json_success($data, 200);
		} else {
			wp_send_json_error(new WP_Error('Missing param', 'Plugin slug is missing'), 400);
		}
	}

	public function api_deactivate_plugin(WP_REST_Request $request)
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
				wp_send_json_error(new WP_Error('Missing plugin', 'Plugin is not installed'), 400);
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
					wp_send_json_error(new WP_Error('deactivation_failed', __('Failed to deactivate the plugin.'), array('status' => 500)), 500);
					return;
				}
			}
			$data = array();
			wp_send_json_success($data, 200);
		} else {
			wp_send_json_error(new WP_Error('Missing param', 'Plugin slug is missing'), 400);
		}
	}

	public function api_update_plugin(WP_REST_Request $request)
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
				wp_send_json_error(new WP_Error('Missing plugin', 'Plugin is not installed'), 400);
				return;
			}

			$status = $this->get_status($plugin_file);

			$network_wide = ($status == 'active-network');
			$active = false;

			if (in_array($status, ['active', 'active-network'], true)) {
				$active = true;
			}

			require_once(ABSPATH . 'wp-admin/includes/class-wp-upgrader.php');
			require_once(ABSPATH . 'wp-admin/includes/plugin-install.php');

			$api = plugins_api('plugin_information', array('slug' => $plugin_slug));

			if (is_wp_error($api)) {
				wp_send_json_error($api, 500);
				return;
			}

			$upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());

			try {

				$upgrader->upgrade($plugin_path);

				if ($active) {
					$result = activate_plugin($plugin_file, $network_wide);

					if (is_wp_error($result)) {
						wp_send_json_error($result, 500);
						return;
					}
				}

				WPJC_Plugin_Updater::clear_wpjs_plugin_cache();
				wp_update_plugins();
			} catch (Exception $ex) {
				wp_send_json_error(new WP_Error('upgrade_failed', __('Failed to upgrade the plugin.'), array('status' => 500)), 500);
				return;
			}

			$data = array();
			wp_send_json_success($data, 200);
		} else {
			wp_send_json_error(new WP_Error('Missing param', 'Plugin slug is missing'), 400);
		}
	}

	public function confirm_client_api(WP_REST_Request $request)
	{

		//wp_send_json_error(new WP_Error('Missing param', 'Plugin slug is missing'), 400);

		global $wp_version;

		$data = array(
			'multisite' => is_multisite(),
			'wp_version' => $wp_version
		);
		wp_send_json_success($data, 200);
	}

	private function is_plugin_from_wp_org($plugin_slug)
	{
		include_once(ABSPATH . 'wp-admin/includes/plugin-install.php');

		$info = plugins_api('plugin_information', array(
			'slug'   => $plugin_slug,
			'fields' => array(
				'homepage' => true,
				'banners'  => false,
			)
		));

		if (is_wp_error($info)) {
			return false;
		}

		return true;
	}

	public function api_load_tgmpa()
	{
		if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/wp-json/juggler/v1/initiateTask') !== false) {
			add_filter('tgmpa_load', '__return_true');

			require_once ABSPATH . 'wp-admin/includes/template.php';

		}
	}

	private function is_tgmpa_plugin_bundled($plugin_slug) {
		// Check if the TGM Plugin Activation class exists
		if (class_exists('TGM_Plugin_Activation')) {
			// Get TGMPA instance
			$tgmpa    = $GLOBALS['tgmpa'];
			
			// Loop through registered plugins
			foreach ($tgmpa->plugins as $plugin) {
				if ($plugin['slug'] === $plugin_slug && isset($plugin['source_type']) && $plugin['source_type'] === 'bundled') {
					return true;
				}
			}
		}
		
		return false;
	}

	public function initiate_task(WP_REST_Request $request)
	{

		$parameters = json_decode($request->get_body(), true);

		if (!array_key_exists('taskId', $parameters) || !array_key_exists('taskType', $parameters)) {
			wp_send_json_error(new WP_Error('Missing param', 'Either taskId or taskType are missing'), 400);
			return;
		}

		$task_id = sanitize_text_field($parameters['taskId']);
		$task_type = sanitize_text_field($parameters['taskType']);

		if ($task_type == 'checkCoreChecksum') {
			$data = $this->core_checksum->get_core_checksum();
		}

		if ($task_type == 'checkPluginChecksum') {
			$data = $this->plugin_checksum->get_plugin_checksum();
		}

		if ($task_type == 'checkHealth') {
			$health_check_site_status = new WPJC_Health();

			require_once ABSPATH . 'wp-admin/includes/admin.php';

			if (! class_exists('WP_Debug_Data')) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-debug-data.php';
			}

			WP_Debug_Data::check_for_updates();
			$data = $health_check_site_status->wpjc_health_info();

			$info = WP_Debug_Data::debug_data();
			$data['debug'] = $info;
			$data['core_checksum'] = $this->core_checksum->get_core_checksum();
		}

		if ($task_type == 'checkNotices') {

			global $wp_filter;
			$dashboard_notices = array();

			if (! function_exists('get_current_screen')) {
				require_once ABSPATH . 'wp-admin/includes/screen.php';
			}

			if (isset($wp_filter['admin_notices'])) {
				foreach ($wp_filter['admin_notices']->callbacks as $priority => $callbacks) {
					foreach ($callbacks as $callback) {
						if (is_callable($callback['function'])) {
							ob_start();
							call_user_func($callback['function']);
							$output = ob_get_clean();
							if (!empty($output)) {
								$dashboard_notices[] = [
									'NoticeHTML' => $output
								];
							}
						}
					}
				}
			}

			$data = $dashboard_notices;
		}

		if ($task_type == 'checkPlugins') {

			if (! function_exists('get_plugins')) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			if (! function_exists('wp_update_plugins')) {
				require_once ABSPATH . 'wp-includes/update.php';
			}

			$installed_plugins = get_plugins();

			WPJC_Plugin_Updater::clear_wpjs_plugin_cache();

			wp_update_plugins();

			$update_plugins = get_site_transient('update_plugins');

			$data_checksum = $this->plugin_checksum->get_plugin_checksum();

			foreach ($installed_plugins as $plugin_path => $plugin_info) {

				$slug = $this->get_plugin_name($plugin_path);

				$data[$plugin_path] = $plugin_info;

				$data[$plugin_path]['File'] = $plugin_path;
				$data[$plugin_path]['Slug'] = $slug;

				if (in_array($slug, $data_checksum['failures_list'], true)) {
					$data[$plugin_path]['Checksum'] = false;
				} else {
					$data[$plugin_path]['Checksum'] = true;
				}

				$data[$plugin_path]['Wporg'] = $this->is_plugin_from_wp_org($slug);
				$data[$plugin_path]['Multisite'] = is_multisite();
				$data[$plugin_path]['Active'] = is_plugin_active($plugin_path);
				$data[$plugin_path]['NetworkActive'] = $this->get_status($plugin_path) == 'active-network' ? true : false;
				$data[$plugin_path]['Update'] = false;
				$data[$plugin_path]['UpdateVersion'] = '';

				$remote = $this->plugin_plugin_updater->request();

				if (!$remote || !property_exists($remote, $slug)) {
					$data[$plugin_path]['WpJuggler'] = false;
				} else {
					$data[$plugin_path]['WpJuggler'] = true;
				}

				$is_tgmpa = $this->is_tgmpa_plugin_bundled($slug);
				$data[$plugin_path]['Tgmpa'] = $is_tgmpa;

				if (isset($update_plugins->response[$plugin_path])) {
					$data[$plugin_path]['Update'] = true;
					$data[$plugin_path]['UpdateVersion'] = $update_plugins->response[$plugin_path]->new_version;
				}
			}

			$plugins_data = $data;

			//Themes data

			if (! function_exists('wp_get_themes')) {
				require_once ABSPATH . 'wp-admin/includes/theme.php';
			}

			if (! function_exists('wp_get_theme')) {
				require_once ABSPATH . 'wp-includes/theme.php';
			}

			if (! function_exists('wp_update_themes')) {
				require_once ABSPATH . 'wp-includes/update.php';
			}

			if (! function_exists('get_theme_updates')) {
				require_once ABSPATH . 'wp-admin/includes/update.php';
			}

			wp_update_themes();

			$themes = wp_get_themes();
			$active_theme = wp_get_theme();
			$active_theme_slug = $active_theme->get_stylesheet();

			$updates = get_theme_updates();

			$themes_info = array_map(function ($theme) {
				return array(
					'Name' => $theme->get('Name'),
					'Version' => $theme->get('Version'),
					'Author' => $theme->get('Author'),
					'IsChildTheme' => $theme->parent() ? true : false,
					'ThemeObject' => $theme,
					'Update' => false,
					'UpdateVersion' => '',
					'Active' => false
				);
			}, array_filter($themes, function ($theme) use ($active_theme_slug) {
				return $theme->get_stylesheet() !== $active_theme_slug;
			}));

			foreach ($themes_info as $theme_slug => $theme) {
				if (isset($updates[$theme_slug])) {
					$themes_info[$theme_slug]['Update'] = true;
					$themes_info[$theme_slug]['UpdateVersion'] = $updates[$theme_slug]->update['new_version'];
				}
			}

			$active_theme_info = array(
				'Name' => $active_theme->get('Name'),
				'Version' => $active_theme->get('Version'),
				'Author' => $active_theme->get('Author'),
				'IsChildTheme' => $active_theme->parent() ? true : false,
				'ThemeObject' => $active_theme,
				'Update' => false,
				'UpdateVersion' => '',
				'Active' => true
			);

			if (isset($updates[$active_theme_slug])) {
				$active_theme_info['Update'] = true;
				$active_theme_info['UpdateVersion'] = $updates[$active_theme_slug]->update['new_version'];
			}

			$themes_data = $themes_info;
			$themes_data[$active_theme_slug] = $active_theme_info;

			$data = array(
				'plugins_data' => $plugins_data,
				'themes_data' => $themes_data,
			);
		}

		if ($task_type == 'checkThemes') {

			if (! function_exists('wp_get_themes')) {
				require_once ABSPATH . 'wp-admin/includes/theme.php';
			}

			if (! function_exists('wp_get_theme')) {
				require_once ABSPATH . 'wp-includes/theme.php';
			}

			if (! function_exists('wp_update_themes')) {
				require_once ABSPATH . 'wp-includes/update.php';
			}

			if (! function_exists('get_theme_updates')) {
				require_once ABSPATH . 'wp-admin/includes/update.php';
			}

			wp_update_themes();

			$themes = wp_get_themes();
			$active_theme = wp_get_theme();
			$active_theme_slug = $active_theme->get_stylesheet();

			$updates = get_theme_updates();

			$themes_info = array_map(function ($theme) {
				return array(
					'Name' => $theme->get('Name'),
					'Version' => $theme->get('Version'),
					'Author' => $theme->get('Author'),
					'IsChildTheme' => $theme->parent() ? true : false,
					'ThemeObject' => $theme,
					'Update' => false,
					'UpdateVersion' => ''
				);
			}, array_filter($themes, function ($theme) use ($active_theme_slug) {
				return $theme->get_stylesheet() !== $active_theme_slug;
			}));

			foreach ($themes_info as $theme_slug => $theme) {
				if (isset($updates[$theme_slug])) {
					$themes_info[$theme_slug]['Update'] = true;
					$themes_info[$theme_slug]['UpdateVersion'] = $updates[$theme_slug]->update['new_version'];
				}
			}

			$active_theme_info = array(
				'Name' => $active_theme->get('Name'),
				'Version' => $active_theme->get('Version'),
				'Author' => $active_theme->get('Author'),
				'IsChildTheme' => $active_theme->parent() ? true : false,
				'ThemeObject' => $active_theme,
				'Update' => false,
				'UpdateVersion' => ''
			);

			if (isset($updates[$active_theme_slug])) {
				$active_theme_info['Update'] = true;
				$active_theme_info['UpdateVersion'] = $updates[$active_theme_slug]->update['new_version'];
			}


			$data = array(
				'inactive' => $themes_info,
				'active' => $active_theme_info
			);
		}

		wp_send_json_success($data, 200);
	}

	private function get_plugin_name($basename)
	{
		if (false === strpos($basename, '/')) {
			$name = basename($basename, '.php');
		} else {
			$name = dirname($basename);
		}

		return $name;
	}

	protected function get_status($file)
	{
		if (is_plugin_active_for_network($file)) {
			return 'active-network';
		}

		if (is_plugin_active($file)) {
			return 'active';
		}

		return 'inactive';
	}

	private function check_active($file, $network_wide)
	{
		$required = $network_wide ? 'active-network' : 'active';

		return $required === $this->get_status($file);
	}
}
