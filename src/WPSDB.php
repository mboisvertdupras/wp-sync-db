<?php
declare(strict_types=1);

namespace WPSDB;

use DateInterval;
use DatePeriod;
use Exception;

class WPSDB extends WPSDB_Base
{
  protected $fp;

  protected string $absolute_root_file_path;

  /** @var array<string, mixed> */
  protected array $form_defaults;

  protected array $accepted_fields;

  /** @var array<string, mixed> */
  protected array $default_profile;

  protected ?int $maximum_chunk_size = null;

  protected string $current_chunk = '';

  /** @var array<string, mixed>|null */
  protected ?array $connection_details = null;

  protected ?string $remote_url = null;

  protected ?string $remote_key = null;

  /** @var array<string, mixed>|null */
  protected ?array $form_data = null;

  protected int $max_insert_string_len;

  protected int $row_tracker = 0;

  protected int $rows_per_segment = 100;

  protected ?string $create_alter_table_query = null;

  protected ?string $alter_table_name = null;

  protected ?string $session_salt = null;

  /** @var array<string, mixed>|null */
  protected ?array $primary_keys = null;

  protected array $checkbox_options;

  public function __construct($plugin_file_path)
  {
    parent::__construct($plugin_file_path);

    if (! function_exists('get_plugin_data')) {
      require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $plugin_data = get_plugin_data(plugin_dir_path(__DIR__) . 'wp-sync-db.php', true, false);
    $this->plugin_version = $plugin_data['Version'];

    $this->max_insert_string_len = 50000; // 50000 is the default as defined by phphmyadmin

    $default_settings = [
      'key'  => $this->generate_key(),
      'allow_pull' => false,
      'allow_push' => false,
      'profiles'  => [],
      'verify_ssl'  => false,
      'blacklist_plugins' => [],
    ];

    if (empty($this->settings['max_request'])) {
      $this->settings['max_request'] = min(1024 * 1024, $this->get_bottleneck('max'));
      update_option('wpsdb_settings', $this->settings);
    }

    // if no settings exist then this is a fresh install, set up some default settings
    if (empty($this->settings)) {
      $this->settings = $default_settings;
      update_option('wpsdb_settings', $this->settings);
    }
    // When we add a new setting, an existing customer's db won't have this
    // new setting, so we need to add it. Otherwise, they'll see
    // array index errors in debug mode
    else {
      $update_settings = false;

      foreach ($default_settings as $key => $value) {
        if (!isset($this->settings[$key])) {
          $this->settings[$key] = $value;
          $update_settings = true;
        }
      }

      if ($update_settings) {
        update_option('wpsdb_settings', $this->settings);
      }
    }

    /**
     * Filter the plugin action links displayed on the Plugins page.
     *
     * @since 1.0
     * @param array<string, string> $actions An array of plugin action links.
     * @return array<string, string> Modified array of plugin action links.
     */
    add_filter('plugin_action_links_' . $this->plugin_basename, $this->plugin_action_links(...));

    /**
     * Filter the plugin action links displayed on the Network Plugins page (multisite).
     *
     * @since 1.0
     * @param array<string, string> $actions An array of plugin action links.
     * @return array<string, string> Modified array of plugin action links.
     */
    add_filter('network_admin_plugin_action_links_' . $this->plugin_basename, $this->plugin_action_links(...));

    // internal AJAX handlers

    /**
     * Handle AJAX request to verify connection to a remote site.
     *
     * @since 1.0
     */
    add_action('wp_ajax_wpsdb_verify_connection_to_remote_site', $this->ajax_verify_connection_to_remote_site(...));

    /**
     * Handle AJAX request to reset the API key.
     *
     * @since 1.0
     */
    add_action('wp_ajax_wpsdb_reset_api_key', $this->ajax_reset_api_key(...));

    /**
     * Handle AJAX request to delete a migration profile.
     *
     * @since 1.0
     */
    add_action('wp_ajax_wpsdb_delete_migration_profile', $this->ajax_delete_migration_profile(...));

    /**
     * Handle AJAX request to save a setting.
     *
     * @since 1.0
     */
    add_action('wp_ajax_wpsdb_save_setting', $this->ajax_save_setting(...));

    /**
     * Handle AJAX request to save a migration profile.
     *
     * @since 1.0
     */
    add_action('wp_ajax_wpsdb_save_profile', $this->ajax_save_profile(...));

    /**
     * Handle AJAX request to initiate a migration.
     *
     * @since 1.0
     */
    add_action('wp_ajax_wpsdb_initiate_migration', $this->ajax_initiate_migration(...));

    /**
     * Handle AJAX request to migrate a table.
     *
     * @since 1.0
     */
    add_action('wp_ajax_wpsdb_migrate_table', $this->ajax_migrate_table(...));

    /**
     * Handle AJAX request to finalize a migration.
     *
     * @since 1.0
     */
    add_action('wp_ajax_wpsdb_finalize_migration', $this->ajax_finalize_migration(...));

    /**
     * Handle AJAX request to clear the migration log.
     *
     * @since 1.0
     */
    add_action('wp_ajax_wpsdb_clear_log', $this->ajax_clear_log(...));

    /**
     * Handle AJAX request to retrieve the migration log.
     *
     * @since 1.0
     */
    add_action('wp_ajax_wpsdb_get_log', $this->ajax_get_log(...));

    /**
     * Handle AJAX request to fire migration complete action.
     *
     * @since 1.0
     */
    add_action('wp_ajax_wpsdb_fire_migration_complete', $this->fire_migration_complete(...));

    /**
     * Handle AJAX request to update max request size.
     *
     * @since 1.0
     */
    add_action('wp_ajax_wpsdb_update_max_request_size', $this->ajax_update_max_request_size(...));

    /**
     * Handle AJAX request to check plugin compatibility.
     *
     * @since 1.0
     */
    add_action('wp_ajax_wpsdb_plugin_compatibility', $this->ajax_plugin_compatibility(...));

    /**
     * Handle AJAX request to update blacklisted plugins.
     *
     * @since 1.0
     */
    add_action('wp_ajax_wpsdb_blacklist_plugins', $this->ajax_blacklist_plugins(...));

    /**
     * Handle AJAX request to cancel a migration.
     *
     * @since 1.0
     */
    add_action('wp_ajax_wpsdb_cancel_migration', $this->ajax_cancel_migration(...));

    // external AJAX handlers

    /**
     * Handle external AJAX request to verify connection from remote site.
     *
     * @since 1.0
     */
    add_action('wp_ajax_nopriv_wpsdb_verify_connection_to_remote_site', $this->respond_to_verify_connection_to_remote_site(...));

    /**
     * Handle external AJAX request to initiate migration on remote site.
     *
     * @since 1.0
     */
    add_action('wp_ajax_nopriv_wpsdb_remote_initiate_migration', $this->respond_to_remote_initiate_migration(...));

    /**
     * Handle external AJAX request to process a migration chunk.
     *
     * @since 1.0
     */
    add_action('wp_ajax_nopriv_wpsdb_process_chunk', $this->ajax_process_chunk(...));

    /**
     * Handle external AJAX request to process a pull request.
     *
     * @since 1.0
     */
    add_action('wp_ajax_nopriv_wpsdb_process_pull_request', $this->respond_to_process_pull_request(...));

    /**
     * Handle external AJAX request to fire migration complete action.
     *
     * @since 1.0
     */
    add_action('wp_ajax_nopriv_wpsdb_fire_migration_complete', $this->fire_migration_complete(...));

    /**
     * Handle external AJAX request to backup a remote table.
     *
     * @since 1.0
     */
    add_action('wp_ajax_nopriv_wpsdb_backup_remote_table', $this->respond_to_backup_remote_table(...));

    /**
     * Handle external AJAX request to finalize migration on remote site.
     *
     * @since 1.0
     */
    add_action('wp_ajax_nopriv_wpsdb_remote_finalize_migration', $this->respond_to_remote_finalize_migration(...));

    /**
     * Handle external AJAX request to process push migration cancellation.
     *
     * @since 1.0
     */
    add_action('wp_ajax_nopriv_wpsdb_process_push_migration_cancellation', $this->respond_to_process_push_migration_cancellation(...));

    /**
     * Clear update transients when the user clicks the "Check Again" button from the update screen.
     *
     * @since 1.0
     * @param WP_Screen $current_screen Current WordPress admin screen object.
     */
    add_action('current_screen', $this->check_again_clear_transients(...));

    $absolute_path = rtrim(ABSPATH, '\\/');
    $site_url = rtrim(site_url('', 'http'), '\\/');
    $home_url = rtrim(home_url('', 'http'), '\\/');
    if ($site_url != $home_url) {
      $difference = str_replace($home_url, '', $site_url);
      if (str_contains($absolute_path, $difference)) {
        $absolute_path = rtrim(substr($absolute_path, 0, -strlen($difference)), '\\/');
      }
    }
    $this->absolute_root_file_path = $absolute_path;

    $this->accepted_fields = [
      'action',
      'save_computer',
      'gzip_file',
      'connection_info',
      'replace_old',
      'replace_new',
      'table_migrate_option',
      'select_tables',
      'replace_guids',
      'exclude_spam',
      'save_migration_profile',
      'save_migration_profile_option',
      'create_new_profile',
      'create_backup',
      'remove_backup',
      'keep_active_plugins',
      'select_post_types',
      'backup_option',
      'select_backup',
      'exclude_transients',
      'exclude_post_types'
    ];

    $this->default_profile = [
      'action' => 'savefile',
      'save_computer' => '1',
      'gzip_file' => '1',
      'table_migrate_option' => 'migrate_only_with_prefix',
      'replace_guids' => '1',
      'default_profile' => true,
      'name' => '',
      'select_tables' => [],
      'select_post_types' => [],
      'backup_option' => 'backup_only_with_prefix',
      'exclude_transients' => '1',
    ];

    $this->checkbox_options = [
      'save_computer' => '0',
      'gzip_file' => '0',
      'replace_guids' => '0',
      'exclude_spam' => '0',
      'keep_active_plugins' => '0',
      'create_backup' => '0',
      'exclude_post_types' => '0'
    ];

    if (is_multisite()) {
      /**
       * Add WP Sync DB menu to the network admin dashboard (multisite).
       *
       * @since 1.0
       */
      add_action('network_admin_menu', $this->network_admin_menu(...));
    } else {
      /**
       * Add WP Sync DB menu to the admin dashboard.
       *
       * @since 1.0
       */
      add_action('admin_menu', $this->admin_menu(...));
    }

    /**
     * Filter the admin body CSS classes.
     *
     * @since 1.0
     * @param string $classes Space-separated list of CSS classes.
     * @return string Modified space-separated list of CSS classes.
     */
    add_filter('admin_body_class', $this->admin_body_class(...));

    // this is how many DB rows are processed at a time, allow devs to change this value
    $this->rows_per_segment = apply_filters('wpsdb_rows_per_segment', $this->rows_per_segment);

    if (is_multisite()) {
      /**
       * Add WP Sync DB menu to the network admin dashboard (multisite).
       *
       * @since 1.0
       */
      add_action('network_admin_menu', $this->network_admin_menu(...));
      $this->plugin_base = 'settings.php?page=wp-sync-db';
    } else {
      /**
       * Add WP Sync DB menu to the admin dashboard.
       *
       * @since 1.0
       */
      add_action('admin_menu', $this->admin_menu(...));
      $this->plugin_base = 'tools.php?page=wp-sync-db';
    }
  }

  public function ajax_blacklist_plugins(): never
  {
    $this->settings['blacklist_plugins'] = $_POST['blacklist_plugins'];
    update_option('wpsdb_settings', $this->settings);
    exit;
  }

  public function ajax_plugin_compatibility(): void
  {
    $mu_dir = (defined('WPMU_PLUGIN_DIR') && defined('WPMU_PLUGIN_URL')) ? WPMU_PLUGIN_DIR : trailingslashit(WP_CONTENT_DIR) . 'mu-plugins';
    $source = trailingslashit($this->plugin_dir_path) . 'compatibility/wp-sync-db-compatibility.php';
    $dest = trailingslashit($mu_dir) . 'wp-sync-db-compatibility.php';
    if ('1' === trim((string) $_POST['install'])) { // install MU plugin
      if (!wp_mkdir_p($mu_dir)) {
        _e(sprintf('The following directory could not be created: %s', $mu_dir), 'wp-sync-db');
        exit;
      }
      if (!copy($source, $dest)) {
        _e(sprintf('Could not copy the compatibility plugin from %1$s to %2$s', $source, $destination), 'wp-sync-db');
        exit;
      }
    } else { // uninstall MU plugin
      if (file_exists($dest) && !unlink($dest)) {
        _e(sprintf('Could not remove the compatibility plugin from %s', $dest), 'wp-sync-db');
        exit;
      }
    }
    exit;
  }

  public function check_again_clear_transients($current_screen): void
  {
    if (!isset($current_screen->id) || !str_contains($current_screen->id, 'update-core') || !isset($_GET['force-check'])) return;
    delete_site_transient('wpsdb_upgrade_data');
    delete_site_transient('update_plugins');
  }

  public function get_alter_table_name()
  {
    if (!is_null($this->alter_table_name)) {
      return $this->alter_table_name;
    }
    global $wpdb;
    $this->alter_table_name = apply_filters('wpsdb_alter_table_name', $wpdb->prefix . 'wpsdb_alter_statements');
    return $this->alter_table_name;
  }

  public function get_create_alter_table_query()
  {
    if (!is_null($this->create_alter_table_query)) {
      return $this->create_alter_table_query;
    }
    $alter_table_name = $this->get_alter_table_name();
    $this->create_alter_table_query = sprintf("DROP TABLE IF EXISTS `%s`;\n", $alter_table_name);
    $this->create_alter_table_query .= sprintf("CREATE TABLE `%s` ( `query` longtext NOT NULL );\n", $alter_table_name);
    $this->create_alter_table_query = apply_filters('wpsdb_create_alter_table_query', $this->create_alter_table_query);
    return $this->create_alter_table_query;
  }

  public function get_short_uploads_dir()
  {
    $short_path = str_replace($this->absolute_root_file_path, '', $this->get_upload_info('path'));
    return trailingslashit(substr(str_replace('\\', '/', $short_path), 1));
  }

  public function get_upload_info($type = 'path')
  {
    // Let developers define their own path to for export files
    // Note: We require a very specific data set here, it should be similiar to the following
    // [
    //			'path' 	=> '/path/to/custom/uploads/directory', <- note missing end trailing slash
    //			'url'	=> 'http://yourwebsite.com/custom/uploads/directory' <- note missing end trailing slash
    // );
    $upload_info = apply_filters('wpsdb_upload_info', []);
    if (!empty($upload_info)) {
      return $upload_info[$type];
    }

    $upload_dir = wp_upload_dir();

    $upload_info['path'] = $upload_dir['basedir'];
    $upload_info['url'] = $upload_dir['baseurl'];

    $upload_dir_name = apply_filters('wpsdb_upload_dir_name', 'wp-sync-db');

    if (!file_exists($upload_dir['basedir'] . DIRECTORY_SEPARATOR . $upload_dir_name)) {
      $url = wp_nonce_url($this->plugin_base, 'wp-sync-db-nonce');

      if (false === @mkdir($upload_dir['basedir'] . DIRECTORY_SEPARATOR . $upload_dir_name, 0755)) {
        return $upload_info[$type];
      }

      $filename = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . $upload_dir_name . DIRECTORY_SEPARATOR . 'index.php';
      if (false === @file_put_contents($filename, "<?php\r\n// Silence is golden\r\n?>")) {
        return $upload_info[$type];
      }
    }

    $upload_info['path'] .= DIRECTORY_SEPARATOR . $upload_dir_name;
    $upload_info['url'] .= '/' . $upload_dir_name;

    return $upload_info[$type];
  }

  public function ajax_update_max_request_size()
  {
    $this->check_ajax_referer('update-max-request-size');
    $this->settings['max_request'] = (int) $_POST['max_request_size'] * 1024;
    update_option('wpsdb_settings', $this->settings);
    return $this->end_ajax();
  }

  public function is_json($string, $strict = false)
  {
    $json = @json_decode((string) $string, true);
    if (true === $strict && !is_array($json)) return false;
    return !(null === $json || false === $json);
  }

  public function get_sql_dump_info($migration_type, $info_type): string
  {
    if (empty($this->session_salt)) {
      $this->session_salt = strtolower(wp_generate_password(5, false, false));
    }
    $datetime = date('YmdHis');
    $ds = ('path' == $info_type ? DIRECTORY_SEPARATOR : '/');
    return sprintf('%s%s%s-%s-%s-%s.sql', $this->get_upload_info($info_type), $ds, sanitize_title_with_dashes(DB_NAME), $migration_type, $datetime, $this->session_salt);
  }

  /**
   * @return mixed[]
   */
  public function parse_migration_form_data($data): array
  {
    parse_str((string) $data, $form_data);
    $this->accepted_fields = apply_filters('wpsdb_accepted_profile_fields', $this->accepted_fields);
    $form_data = array_intersect_key($form_data, array_flip($this->accepted_fields));
    unset($form_data['replace_old'][0]);
    unset($form_data['replace_new'][0]);
    return $form_data;
  }

  public function plugin_action_links($links)
  {
    $link = sprintf('<a href="%s">%s</a>', network_admin_url($this->plugin_base), __('Settings', 'wp-sync-db'));
    array_unshift($links, $link);
    return $links;
  }

  public function ajax_clear_log()
  {
    $this->check_ajax_referer('clear-log');
    delete_option('wpsdb_error_log');
    return $this->end_ajax();
  }

  public function ajax_get_log()
  {
    $this->check_ajax_referer('get-log');
    ob_start();
    $this->output_diagnostic_info();
    $this->output_log_file();
    $return = ob_get_clean();
    return $this->end_ajax($return);
  }

  public function output_log_file(): void
  {
    $log = get_option('wpsdb_error_log');
    if ($log) {
      echo $log;
    }
  }

  public function output_diagnostic_info(): void
  {
    global $table_prefix;
    global $wpdb;

    echo 'site_url(): ';
    echo site_url();
    echo "\r\n";

    echo 'home_url(): ';
    echo home_url();
    echo "\r\n";

    echo 'Table Prefix: ';
    echo $table_prefix;
    echo "\r\n";

    echo 'WordPress: ';
    if (is_multisite()) echo 'WPMU';
    else echo 'WP';
    echo bloginfo('version');
    echo "\r\n";

    echo 'Web Server: ';
    echo $_SERVER['SERVER_SOFTWARE'];
    echo "\r\n";

    echo 'PHP: ';
    if (function_exists('phpversion')) echo esc_html(phpversion());
    echo "\r\n";

    echo 'MySQL: ';
    echo esc_html(empty($wpdb->use_mysqli) ? mysql_get_server_info() : mysqli_get_server_info($wpdb->dbh));
    echo "\r\n";

    _e('ext/mysqli', 'wp-app-store');
    echo ': ';
    echo empty($wpdb->use_mysqli) ? 'no' : 'yes';
    echo "\r\n";

    _e('WP Memory Limit', 'wp-app-store');
    echo ': ';
    echo WP_MEMORY_LIMIT;
    echo "\r\n";

    echo 'WPSDB Bottleneck: ';
    echo size_format($this->get_bottleneck());
    echo "\r\n";

    if (function_exists('ini_get') && $suhosin_limit = ini_get('suhosin.post.max_value_length')) {
      echo 'Suhosin Post Max Value Length: ';
      echo is_numeric($suhosin_limit) ? size_format($suhosin_limit) : $suhosin_limit;
      echo "\r\n";
    }

    if (function_exists('ini_get') && $suhosin_limit = ini_get('suhosin.request.max_value_length')) {
      echo 'Suhosin Request Max Value Length: ';
      echo is_numeric($suhosin_limit) ? size_format($suhosin_limit) : $suhosin_limit;
      echo "\r\n";
    }

    echo 'Debug Mode: ';
    if (defined('WP_DEBUG') && WP_DEBUG) {
      echo 'Yes';
    } else {
      echo 'No';
    }
    echo "\r\n";

    echo 'WP Max Upload Size: ';
    echo size_format(wp_max_upload_size());
    echo "\r\n";

    echo 'PHP Post Max Size: ';
    echo size_format($this->get_post_max_size());
    echo "\r\n";

    echo 'PHP Time Limit: ';
    if (function_exists('ini_get')) echo ini_get('max_execution_time');
    echo "\r\n";

    echo 'PHP Error Log: ';
    if (function_exists('ini_get')) echo ini_get('error_log');
    echo "\r\n";

    echo 'fsockopen: ';
    if (function_exists('fsockopen')) {
      echo 'Enabled';
    } else {
      echo 'Disabled';
    }
    echo "\r\n";

    echo 'OpenSSL: ';
    if ($this->open_ssl_enabled()) {
      echo OPENSSL_VERSION_TEXT;
    } else {
      echo 'Disabled';
    }
    echo "\r\n";

    echo 'cURL: ';
    if (function_exists('curl_init')) {
      echo 'Enabled';
    } else {
      echo 'Disabled';
    }
    echo "\r\n";
    echo "\r\n";

    echo "Active Plugins:\r\n";

    $active_plugins = (array) get_option('active_plugins', []);

    if (is_multisite()) {
      $network_active_plugins = wp_get_active_network_plugins();
      $active_plugins = array_map($this->remove_wp_plugin_dir(...), $network_active_plugins);
    }

    foreach ($active_plugins as $active_plugin) {
      $plugin_data = @get_plugin_data(WP_PLUGIN_DIR . '/' . $active_plugin);
      if (empty($plugin_data['Name'])) continue;
      printf("%s (v%s) by %s\r\n", $plugin_data['Name'], $plugin_data['Version'], $plugin_data['AuthorName']);
    }

    echo "\r\n";
  }

  public function remove_wp_plugin_dir($name): string
  {
    $plugin = str_replace(WP_PLUGIN_DIR, '', $name);
    return substr($plugin, 1);
  }

  public function fire_migration_complete()
  {
    $filtered_post = $this->filter_post_elements($_POST, ['action', 'url']);
    if (!$this->verify_signature($filtered_post, $this->settings['key'])) {
      $error_msg = $this->invalid_content_verification_error . ' (#138)';
      $this->log_error($error_msg, $filtered_post);
      return $this->end_ajax($error_msg);
    }

    do_action('wpsdb_migration_complete', 'pull', $_POST['url']);
    return $this->end_ajax();
  }

  public function get_alter_queries(): string
  {
    global $wpdb;
    $alter_table_name = $this->get_alter_table_name();
    $sql = '';
    $alter_queries = $wpdb->get_results(sprintf('SELECT * FROM `%s`', $alter_table_name), ARRAY_A);
    if (!empty($alter_queries)) {
      foreach ($alter_queries as $alter_query) {
        $sql .= $alter_query['query'];
      }
    }
    return $sql;
  }

  // After table migration, delete old tables and rename new tables removing the temporarily prefix
  public function ajax_finalize_migration()
  {
    $this->check_ajax_referer('finalize-migration');
    global $wpdb;
    $return = '';
    if ('pull' == $_POST['intent']) {
      $return = $this->finalize_migration();
    } else {
      do_action('wpsdb_migration_complete', 'push', $_POST['url']);
      $data = $_POST;
      if (isset($data['nonce'])) {
        unset($data['nonce']);
      }
      $data['action'] = 'wpsdb_remote_finalize_migration';
      $data['intent'] = 'pull';
      $data['prefix'] = $wpdb->prefix;
      $data['type'] = 'push';
      $data['location'] = home_url();
      $data['temp_prefix'] = $this->temp_prefix;
      $data['sig'] = $this->create_signature($data, $data['key']);
      $ajax_url = trailingslashit($_POST['url']) . 'wp-admin/admin-ajax.php';
      $response = $this->remote_post($ajax_url, $data, __FUNCTION__);
      ob_start();
      echo $response;
      $this->display_errors();
      $return = ob_get_clean();
    }
    return $this->end_ajax($return);
  }

  public function respond_to_remote_finalize_migration()
  {
    $filtered_post = $this->filter_post_elements($_POST, ['action', 'intent', 'url', 'key', 'form_data', 'prefix', 'type', 'location', 'tables', 'temp_prefix']);
    if (!$this->verify_signature($filtered_post, $this->settings['key'])) {
      $error_msg = $this->invalid_content_verification_error . ' (#123)';
      $this->log_error($error_msg, $filtered_post);
      return $this->end_ajax($error_msg);
    }
    $return = $this->finalize_migration();
    return $this->end_ajax($return);
  }

  public function finalize_migration()
  {
    global $wpdb;

    $tables = explode(',', (string) $_POST['tables']);
    $temp_tables = [];
    foreach ($tables as $table) {
      $temp_prefix = stripslashes((string) $_POST['temp_prefix']);
      $temp_tables[] = $temp_prefix . $table;
    }

    $sql = "SET FOREIGN_KEY_CHECKS=0;\n";

    $preserved_options = ['wpsdb_settings', 'wpsdb_error_log'];

    $this->form_data = $this->parse_migration_form_data($_POST['form_data']);
    if (isset($this->form_data['keep_active_plugins'])) {
      $preserved_options[] = 'active_plugins';
    }

    $preserved_options = apply_filters('wpsdb_preserved_options', $preserved_options);

    foreach ($temp_tables as $temp_table) {
      $sql .= 'DROP TABLE IF EXISTS ' . $this->backquote(substr($temp_table, strlen($temp_prefix))) . ';';
      $sql .= "\n";
      $sql .= 'RENAME TABLE ' . $this->backquote($temp_table)  . ' TO ' . $this->backquote(substr($temp_table, strlen($temp_prefix))) . ';';
      $sql .= "\n";
    }

    $preserved_options_data = $wpdb->get_results(sprintf("SELECT * FROM %soptions WHERE `option_name` IN ('%s')", $wpdb->prefix, implode("','", $preserved_options)), ARRAY_A);

    foreach ($preserved_options_data as $preserved_option_data) {
      $sql .= $wpdb->prepare("DELETE FROM `{$_POST['prefix']}options` WHERE `option_name` = %s;\n", $preserved_option_data['option_name']);
      $sql .= $wpdb->prepare("INSERT INTO `{$_POST['prefix']}options` ( `option_id`, `option_name`, `option_value`, `autoload` ) VALUES ( NULL , %s, %s, %s );\n", $preserved_option_data['option_name'], $preserved_option_data['option_value'], $preserved_option_data['autoload']);
    }

    $alter_table_name = $this->get_alter_table_name();
    $sql .= $this->get_alter_queries();
    $sql .= "DROP TABLE IF EXISTS " . $this->backquote($alter_table_name) . ";\n";

    $process_chunk_result = $this->process_chunk($sql);
    if (true !== $process_chunk_result) {
      return $this->end_ajax($process_chunk_result);
    }

    $type = (isset($_POST['type']) ? 'push' : 'pull');
    $location = ($_POST['location'] ?? $_POST['url']);

    if (!isset($_POST['location'])) {
      $data = [];
      $data['action'] = 'wpsdb_fire_migration_complete';
      $data['url'] = home_url();
      $data['sig'] = $this->create_signature($data, $_POST['key']);
      $ajax_url = trailingslashit($_POST['url']) . 'wp-admin/admin-ajax.php';
      $response = $this->remote_post($ajax_url, $data, __FUNCTION__);
      ob_start();
      echo $response;
      $this->display_errors();
      $maybe_errors = trim(ob_get_clean());
      if (false === ('' === $maybe_errors || '0' === $maybe_errors)) {
        return $this->end_ajax($maybe_errors);
      }
    }

    // flush rewrite rules to prevent 404s and other oddities
    flush_rewrite_rules(true); // true = hard refresh, recreates the .htaccess file

    do_action('wpsdb_migration_complete', $type, $location);
  }

  public function ajax_process_chunk()
  {
    $filtered_post = $this->filter_post_elements($_POST, ['action', 'table', 'chunk_gzipped']);
    $gzip = (isset($_POST['chunk_gzipped']) && $_POST['chunk_gzipped']);

    $tmp_file_name = 'chunk.txt';
    if ($gzip) {
      $tmp_file_name .= '.gz';
    }

    $tmp_file_path = wp_tempnam($tmp_file_name);
    if (!isset($_FILES['chunk']['tmp_name']) || !move_uploaded_file($_FILES['chunk']['tmp_name'], $tmp_file_path)) {
      return $this->end_ajax(__('Could not upload the SQL to the server. (#135)', 'wp-sync-db'));
    }

    if (false === ($chunk = file_get_contents($tmp_file_path))) {
      return $this->end_ajax(__('Could not read the SQL file we uploaded to the server. (#136)', 'wp-sync-db'));
    }

    @unlink($tmp_file_path);

    $filtered_post['chunk'] = $chunk;

    if (!$this->verify_signature($filtered_post, $this->settings['key'])) {
      $error_msg = $this->invalid_content_verification_error . ' (#130)';
      $this->log_error($error_msg, $filtered_post);
      return $this->end_ajax($error_msg);
    }

    if (true != $this->settings['allow_push']) {
      return $this->end_ajax(__('The connection succeeded but the remote site is configured to reject push connections. You can change this in the "settings" tab on the remote site. (#133)', 'wp-sync-db'));
    }

    if ($gzip) {
      $filtered_post['chunk'] = gzuncompress($filtered_post['chunk']);
    }

    $process_chunk_result = $this->process_chunk($filtered_post['chunk']);
    return $this->end_ajax($process_chunk_result);
  }

  public function process_chunk($chunk)
  {
    // prepare db
    global $wpdb;
    $this->set_time_limit();

    $queries = array_filter(explode(";\n", (string) $chunk));
    array_unshift($queries, "SET sql_mode='NO_AUTO_VALUE_ON_ZERO';");

    ob_start();
    $wpdb->show_errors();
    if (empty($wpdb->charset)) {
      $charset = (defined('DB_CHARSET') ? DB_CHARSET : 'utf8');
      $wpdb->charset = $charset;
      $wpdb->set_charset($wpdb->dbh, $wpdb->charset);
    }
    foreach ($queries as $query) {
      if (false === $wpdb->query($query)) {
        $return = ob_get_clean();
        return $this->end_ajax($return);
      }
    }
    return true;
  }

  public function ajax_migrate_table()
  {
    $this->check_ajax_referer('migrate-table');
    global $wpdb;

    $this->form_data = $this->parse_migration_form_data($_POST['form_data']);

    $result = '';
    // checks if we're performing a backup, if so, continue with the backup and exit immediately after
    if ('backup' == $_POST['stage'] && 'savefile' != $_POST['intent']) {
      // if performing a push we need to backup the REMOTE machine's DB
      if ('push' == $_POST['intent']) {
        $data = $_POST;
        if (isset($data['nonce'])) {
          unset($data['nonce']);
        }
        $data['action'] = 'wpsdb_backup_remote_table';
        $data['intent'] = 'pull';
        $ajax_url = trailingslashit($_POST['url']) . 'wp-admin/admin-ajax.php';
        $data['primary_keys'] = stripslashes((string) $data['primary_keys']);
        $data['sig'] = $this->create_signature($data, $data['key']);
        $response = $this->remote_post($ajax_url, $data, __FUNCTION__);
        ob_start();
        $this->display_errors();
        $return = ob_get_clean();
        $return .= $response;
      } else {
        $return = $this->handle_table_backup();
      }
      return $this->end_ajax($return);
    }

    // Pull and push need to be handled differently for obvious reasons, trigger different code depending on the migration intent (push or pull)
    if ('push' == $_POST['intent'] || 'savefile' == $_POST['intent']) {
      $this->maximum_chunk_size = $this->get_bottleneck();
      if (isset($_POST['bottleneck'])) {
        $this->maximum_chunk_size = (int) $_POST['bottleneck'];
      }
      if ('push' == $_POST['intent']) {
        $this->remote_key = $_POST['key'];
        $this->remote_url = $_POST['url'];
      }
      $sql_dump_file_name = $this->get_upload_info('path') . DIRECTORY_SEPARATOR;
      $sql_dump_file_name .= $this->format_dump_name($_POST['dump_filename']);

      if ('savefile' == $_POST['intent']) {
        $this->fp = $this->open($sql_dump_file_name);
      }
      $result = $this->export_table($_POST['table']);
      if ('savefile' == $_POST['intent']) {
        $this->close($this->fp);
      }
      ob_start();
      $this->display_errors();
      $maybe_errors = trim(ob_get_clean());
      if (false === ('' === $maybe_errors || '0' === $maybe_errors)) {
        return $this->end_ajax($maybe_errors);
      }
      return $result;
    }
    $data = $_POST;
    if (isset($data['nonce'])) {
      unset($data['nonce']);
    }
    $data['action'] = 'wpsdb_process_pull_request';
    $data['pull_limit'] = $this->get_sensible_pull_limit();
    if (is_multisite()) {
      $data['path_current_site'] = $this->get_path_current_site();
      $data['domain_current_site'] = $this->get_domain_current_site();
    }
    $data['prefix'] = $wpdb->prefix;
    if (isset($data['sig'])) {
      unset($data['sig']);
    }
    $ajax_url = trailingslashit($data['url']) . 'wp-admin/admin-ajax.php';
    $data['primary_keys'] = stripslashes((string) $data['primary_keys']);
    $data['sig'] = $this->create_signature($data, $data['key']);
    $response = $this->remote_post($ajax_url, $data, __FUNCTION__);
    ob_start();
    $this->display_errors();
    $maybe_errors = trim(ob_get_clean());
    if (false === ('' === $maybe_errors || '0' === $maybe_errors)) {
      return $this->end_ajax($maybe_errors);
    }
    if (!str_contains((string) $response, ';')) {
      return $this->end_ajax($response);
    }
    // returned data is just a big string like this query;query;query;33
    // need to split this up into a chunk and row_tracker
    $row_information = trim(substr(strrchr((string) $response, "\n"), 1));
    $row_information = explode(',', $row_information);
    $chunk = substr((string) $response, 0, strrpos((string) $response, ";\n") + 1);
    if ('' !== $chunk && '0' !== $chunk) {
      $process_chunk_result = $this->process_chunk($chunk);
      if (true !== $process_chunk_result) {
        return $this->end_ajax($process_chunk_result);
      }
    }
    return $this->end_ajax(json_encode(
      [
        'current_row'     => $row_information[0],
        'primary_keys'    => $row_information[1]
      ]
    ));
  }

  public function respond_to_backup_remote_table()
  {
    $filtered_post = $this->filter_post_elements($_POST, ['action', 'intent', 'url', 'key', 'table', 'form_data', 'stage', 'bottleneck', 'prefix', 'current_row', 'dump_filename', 'last_table', 'gzip', 'primary_keys', 'path_current_site', 'domain_current_site']);
    $filtered_post['primary_keys'] = stripslashes((string) $filtered_post['primary_keys']);
    if (!$this->verify_signature($filtered_post, $this->settings['key'])) {
      $error_msg = $this->invalid_content_verification_error . ' (#137)';
      $this->log_error($error_msg, $filtered_post);
      return $this->end_ajax($error_msg);
    }

    $this->form_data = $this->parse_migration_form_data($_POST['form_data']);
    return $this->handle_table_backup();
  }

  public function handle_table_backup()
  {
    if (isset($this->form_data['gzip_file'])) {
      unset($this->form_data['gzip_file']);
    }
    $this->maximum_chunk_size = $this->get_bottleneck();
    $sql_dump_file_name = $this->get_upload_info('path') . DIRECTORY_SEPARATOR;
    $sql_dump_file_name .= $this->format_dump_name($_POST['dump_filename']);
    $file_created = file_exists($sql_dump_file_name);
    $this->fp = $this->open($sql_dump_file_name);
    if (false == $file_created) {
      $this->db_backup_header();
    }
    $result = $this->export_table($_POST['table']);
    if (isset($this->fp)) {
      $this->close($this->fp);
    }
    ob_start();
    $this->display_errors();
    $maybe_errors = trim(ob_get_clean());
    if (false === ('' === $maybe_errors || '0' === $maybe_errors)) {
      return $this->end_ajax($maybe_errors);
    }

    return $result;
  }

  public function respond_to_process_pull_request()
  {
    $filtered_post = $this->filter_post_elements($_POST, ['action', 'intent', 'url', 'key', 'table', 'form_data', 'stage', 'bottleneck', 'prefix', 'current_row', 'dump_filename', 'pull_limit', 'last_table', 'gzip', 'primary_keys', 'path_current_site', 'domain_current_site']);

    // verification will fail unless we strip slashes on primary_keys and form_data
    $filtered_post['primary_keys'] = stripslashes((string) $filtered_post['primary_keys']);
    $filtered_post['form_data'] = stripslashes((string) $filtered_post['form_data']);
    if (isset($filtered_post['path_current_site'])) {
      $filtered_post['path_current_site'] = stripslashes($filtered_post['path_current_site']);
    }

    if (!$this->verify_signature($filtered_post, $this->settings['key'])) {
      $error_msg = $this->invalid_content_verification_error . ' (#124)';
      $this->log_error($error_msg, $filtered_post);
      return $this->end_ajax($error_msg);
    }

    if (true != $this->settings['allow_pull']) {
      return $this->end_ajax(__('The connection succeeded but the remote site is configured to reject pull connections. You can change this in the "settings" tab on the remote site. (#132)', 'wp-sync-db'));
    }

    $this->maximum_chunk_size = $_POST['pull_limit'];
    $this->export_table($_POST['table']);
    ob_start();
    $this->display_errors();
    $return = ob_get_clean();
    return $this->end_ajax($return);
  }

  // Occurs right before the first table is migrated / backed up during the migration process
  // Does a quick check to make sure the verification string is valid and also opens / creates files for writing to (if required)
  public function ajax_initiate_migration()
  {
    $this->check_ajax_referer('initiate-migration');
    $this->form_data = $this->parse_migration_form_data($_POST['form_data']);
    if ('savefile' == $_POST['intent']) {

      $return = [
        'code' => 200,
        'message' => 'OK',
        'body'  => json_encode(['error' => 0]),
      ];

      $return['dump_filename'] = basename((string) $this->get_sql_dump_info('migrate', 'path'));
      $return['dump_url'] = $this->get_sql_dump_info('migrate', 'url');
      $dump_filename_no_extension = substr($return['dump_filename'], 0, -4);

      $create_alter_table_query = $this->get_create_alter_table_query();
      // sets up our table to store 'ALTER' queries
      $process_chunk_result = $this->process_chunk($create_alter_table_query);
      if (true !== $process_chunk_result) {
        return $this->end_ajax($process_chunk_result);
      }

      if ($this->gzip() && isset($this->form_data['gzip_file'])) {
        $return['dump_filename'] .= '.gz';
        $return['dump_url'] .= '.gz';
      }
      $this->fp = $this->open($this->get_upload_info('path') . DIRECTORY_SEPARATOR . $return['dump_filename']);
      $this->db_backup_header();
      $this->close($this->fp);

      $return['dump_filename'] = $dump_filename_no_extension;
    } else { // does one last check that our verification string is valid

      $data = [
        'action'  => 'wpsdb_remote_initiate_migration',
        'intent' => $_POST['intent'],
        'form_data' => $_POST['form_data'],
      ];

      $data['sig'] = $this->create_signature($data, $_POST['key']);
      $ajax_url = trailingslashit($_POST['url']) . 'wp-admin/admin-ajax.php';
      $response = $this->remote_post($ajax_url, $data, __FUNCTION__);

      if (false === $response) {
        $return = ['wpsdb_error' => 1, 'body' => $this->error];
        return $this->end_ajax(json_encode($return));
      }

      $return = @unserialize(trim((string) $response));

      if (false === $return) {
        $error_msg = __('Failed attempting to unserialize the response from the remote server. Please contact support.', 'wp-sync-db');
        $return = ['wpsdb_error' => 1, 'body' => $error_msg];
        $this->log_error($error_msg, $response);
        return $this->end_ajax(json_encode($return));
      }

      if (isset($return['error']) && 1 == $return['error']) {
        $return = ['wpsdb_error' => 1, 'body' => $return['message']];
        return $this->end_ajax(json_encode($return));
      }

      if ('pull' == $_POST['intent']) {
        // sets up our table to store 'ALTER' queries
        $create_alter_table_query = $this->get_create_alter_table_query();
        $process_chunk_result = $this->process_chunk($create_alter_table_query);
        if (true !== $process_chunk_result) {
          return $this->end_ajax($process_chunk_result);
        }
      }

      if (!empty($this->form_data['create_backup']) && 'pull' == $_POST['intent']) {
        $return['dump_filename'] = basename((string) $this->get_sql_dump_info('backup', 'path'));
        $return['dump_filename'] = substr($return['dump_filename'], 0, -4);
        $return['dump_url'] = $this->get_sql_dump_info('backup', 'url');
      }
    }

    $return['dump_filename'] = (empty($return['dump_filename'])) ? '' : $return['dump_filename'];
    $return['dump_url'] = (empty($return['dump_url'])) ? '' : $return['dump_url'];
    return $this->end_ajax(json_encode($return));
  }

  // End point for the above remote_post call, ensures that the verification string is valid before continuing with the migration
  public function respond_to_remote_initiate_migration()
  {
    $return = [];
    $filtered_post = $this->filter_post_elements($_POST, ['action', 'intent', 'form_data']);
    if ($this->verify_signature($filtered_post, $this->settings['key'])) {
      if (isset($this->settings['allow_' . $_POST['intent']]) && (true === $this->settings['allow_' . $_POST['intent']] || 1 === $this->settings['allow_' . $_POST['intent']])) {
        $return['error'] = 0;
      } else {
        $return['error'] = 1;
        if ('pull' == $_POST['intent']) {
          $intent = __('pull', 'wp-sync-db');
        } else {
          $intent = __('push', 'wp-sync-db');
        }
        $return['message'] = sprintf(__('The connection succeeded but the remote site is configured to reject %s connections. You can change this in the "settings" tab on the remote site. (#110)', 'wp-sync-db'), $intent);
      }
    } else {
      $return['error'] = 1;
      $error_msg = $this->invalid_content_verification_error . ' (#111)';
      $this->log_error($error_msg, $filtered_post);
      $return['message'] = $error_msg;
    }

    $this->form_data = $this->parse_migration_form_data($_POST['form_data']);
    if (!empty($this->form_data['create_backup']) && 'push' == $_POST['intent']) {
      $return['dump_filename'] = basename((string) $this->get_sql_dump_info('backup', 'path'));
      $return['dump_filename'] = substr($return['dump_filename'], 0, -4);
      $return['dump_url'] = $this->get_sql_dump_info('backup', 'url');
    }

    if ('push' == $_POST['intent']) {
      // sets up our table to store 'ALTER' queries
      $create_alter_table_query = $this->get_create_alter_table_query();
      $process_chunk_result = $this->process_chunk($create_alter_table_query);
      if (true !== $process_chunk_result) {
        return $this->end_ajax($process_chunk_result);
      }
    }
    return $this->end_ajax(serialize($return));
  }

  public function ajax_save_profile()
  {
    $this->check_ajax_referer('save-profile');
    $profile = $this->parse_migration_form_data($_POST['profile']);
    $profile = wp_parse_args($profile, $this->checkbox_options);
    if (isset($profile['save_migration_profile_option']) && 'new' == $profile['save_migration_profile_option']) {
      $profile['name'] = $profile['create_new_profile'];
      $this->settings['profiles'][] = $profile;
    } else {
      $key = $profile['save_migration_profile_option'];
      $name = $this->settings['profiles'][$key]['name'];
      $this->settings['profiles'][$key] = $profile;
      $this->settings['profiles'][$key]['name'] = $name;
    }
    update_option('wpsdb_settings', $this->settings);
    $key = array_key_last($this->settings['profiles']);
    return $this->end_ajax($key);
  }

  public function ajax_save_setting()
  {
    $this->check_ajax_referer('save-setting');
    $this->settings[$_POST['setting']] = ('false' == $_POST['checked'] ? false : true);
    update_option('wpsdb_settings', $this->settings);
    return $this->end_ajax();
  }

  public function ajax_delete_migration_profile()
  {
    $this->check_ajax_referer('delete-migration-profile');
    $key = absint($_POST['profile_id']);
    --$key;
    $return = '';
    if (isset($this->settings['profiles'][$key])) {
      unset($this->settings['profiles'][$key]);
      update_option('wpsdb_settings', $this->settings);
    } else {
      $return = '-1';
    }
    return $this->end_ajax($return);
  }

  public function ajax_reset_api_key()
  {
    $this->check_ajax_referer('reset-api-key');
    $this->settings['key'] = $this->generate_key();
    update_option('wpsdb_settings', $this->settings);
    return $this->end_ajax(sprintf("%s\n%s", site_url('', 'https'), $this->settings['key']));
  }

  // AJAX endpoint for when the user pastes into the connection info box (or when they click "connect")
  // Responsible for contacting the remote website and retrieving info and testing the verification string
  public function ajax_verify_connection_to_remote_site()
  {
    $this->check_ajax_referer('verify-connection-to-remote-site');
    $data = [
      'action'  => 'wpsdb_verify_connection_to_remote_site',
      'intent' => $_POST['intent']
    ];

    $data['sig'] = $this->create_signature($data, $_POST['key']);
    $ajax_url = trailingslashit($_POST['url']) . 'wp-admin/admin-ajax.php';
    $timeout = apply_filters('wpsdb_prepare_remote_connection_timeout', 10);
    $response = $this->remote_post($ajax_url, $data, __FUNCTION__, compact('timeout'), true);
    $url_bits = parse_url((string) $this->attempting_to_connect_to);
    $return = $response;

    $alt_action = '';

    if (false === $response) {
      $return = ['wpsdb_error' => 1, 'body' => $this->error];
      return $this->end_ajax(json_encode($return));
    }

    $response = unserialize(trim((string) $response));

    if (false === $response) {
      $error_msg = __('Failed attempting to unserialize the response from the remote server. Please contact support.', 'wp-sync-db');
      $return = ['wpsdb_error' => 1, 'body' => $error_msg];
      $this->log_error($error_msg);
      return $this->end_ajax(json_encode($return));
    }

    if (isset($response['error']) && 1 == $response['error']) {
      $return = ['wpsdb_error' => 1, 'body' => $response['message']];
      $this->log_error($response['message'], $response);
      return $this->end_ajax(json_encode($return));
    }

    if (isset($_POST['convert_post_type_selection']) && '1' == $_POST['convert_post_type_selection']) {
      $profile = (int) $_POST['profile'];
      unset($this->settings['profiles'][$profile]['post_type_migrate_option']);
      $this->settings['profiles'][$profile]['exclude_post_types'] = '1';
      $this->settings['profiles'][$profile]['select_post_types'] = array_values(array_diff($response['post_types'], $this->settings['profiles'][$profile]['select_post_types']));
      $response['select_post_types'] = $this->settings['profiles'][$profile]['select_post_types'];
      update_option('wpsdb_settings', $this->settings);
    }

    $response['scheme'] = $url_bits['scheme'];
    $return = json_encode($response);
    return $this->end_ajax($return);
  }

  // End point for the above remote_post call, returns table information, absolute file path, table prefix, etc
  public function respond_to_verify_connection_to_remote_site()
  {
    global $wpdb;

    $return = [];

    $filtered_post = $this->filter_post_elements($_POST, ['action', 'intent']);
    if (!$this->verify_signature($filtered_post, $this->settings['key'])) {
      $return['error'] = 1;
      $return['message'] = $this->invalid_content_verification_error . ' (#120) <a href="#" class="try-again js-action-link">' . __('Try again?', 'wp-sync-db') . '</a>';
      $this->log_error($this->invalid_content_verification_error . ' (#120)', $filtered_post);
      return $this->end_ajax(serialize($return));
    }

    if (!isset($this->settings['allow_' . $_POST['intent']]) || true != $this->settings['allow_' . $_POST['intent']]) {
      $return['error'] = 1;
      if ('pull' == $_POST['intent']) {
        $intent = __('pull', 'wp-sync-db');
      } else {
        $intent = __('push', 'wp-sync-db');
      }
      $return['message'] = sprintf(__('The connection succeeded but the remote site is configured to reject %s connections. You can change this in the "settings" tab on the remote site. (#122) <a href="#" class="try-again js-action-link">Try again?</a>', 'wp-sync-db'), $intent);
      return $this->end_ajax(serialize($return));
    }

    $return['tables'] = $this->get_tables();
    $return['prefixed_tables'] = $this->get_tables('prefix');
    $return['table_sizes'] = $this->get_table_sizes();
    $return['table_rows'] = $this->get_table_row_count();
    $return['table_sizes_hr'] = array_map($this->format_table_sizes(...), $this->get_table_sizes());
    $return['path'] = $this->absolute_root_file_path;
    $return['url'] = home_url();
    $return['prefix'] = $wpdb->prefix;
    $return['bottleneck'] = $this->get_bottleneck();
    $return['error'] = 0;
    $return['plugin_version'] = $this->plugin_version;
    $return['domain'] = $this->get_domain_current_site();
    $return['path_current_site'] = $this->get_path_current_site();
    $return['uploads_dir'] = $this->get_short_uploads_dir();
    $return['gzip'] = ($this->gzip() ? '1' : '0');
    $return['post_types'] = $this->get_post_types();
    $return['write_permissions'] = (is_writeable($this->get_upload_info('path')) ? '1' : '0');
    $return['upload_dir_long'] = $this->get_upload_info('path');
    $return['temp_prefix'] = $this->temp_prefix;
    $return = apply_filters('wpsdb_establish_remote_connection_data', $return);
    return $this->end_ajax(serialize($return));
  }

  public function format_table_sizes($size)
  {
    $size *= 1024;
    return size_format($size);
  }

  // Generates our secret key
  public function generate_key(): string
  {
    $keyset = 'abcdefghijklmnopqrstuvqxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789+/';
    $key = '';
    for ($i = 0; $i < 32; $i++) {
      $key .= substr($keyset, random_int(0, strlen($keyset) - 1), 1);
    }
    return $key;
  }

  public function get_post_types()
  {
    global $wpdb;

    if (is_multisite()) {
      $tables = $this->get_tables();
      $sql = sprintf('SELECT `post_type` FROM `%sposts` ', $wpdb->prefix);
      foreach ($tables as $table) {
        if (0 == preg_match('/' . $wpdb->prefix . '[0-9]+_posts/', (string) $table)) continue;
        $blog_id = str_replace([$wpdb->prefix, '_posts'], ['', ''], $table);
        $sql .= 'UNION SELECT `post_type` FROM `' . $wpdb->prefix . $blog_id . "_posts` ";
      }
      $sql .= ";";
      $post_types = $wpdb->get_results($sql, ARRAY_A);
    } else {
      $post_types = $wpdb->get_results(
        "SELECT DISTINCT `post_type`
				FROM `{$wpdb->prefix}posts`
				WHERE 1;",
        ARRAY_A
      );
    }

    $return = ['revision'];
    foreach ($post_types as $post_type) {
      $return[] = $post_type['post_type'];
    }
    return apply_filters('wpsdb_post_types', array_unique($return));
  }

  // Retrieves the specified profile, if -1, returns the default profile
  public function get_profile($profile_id)
  {
    --$profile_id;
    if ('-1' == $profile_id || !isset($this->settings['profiles'][$profile_id])) {
      return $this->default_profile;
    }
    return $this->settings['profiles'][$profile_id];
  }

  /**
   * @return int[]
   */
  public function get_table_row_count(): array
  {
    global $wpdb;
    $results = $wpdb->get_results(
      $wpdb->prepare(
        'SELECT TABLE_NAME, TABLE_ROWS FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s',
        DB_NAME
      ),
      ARRAY_A
    );
    $return = [];
    foreach ($results as $result) {
      $return[$result['TABLE_NAME']] = (0 == $result['TABLE_ROWS'] ? 1 : (int) $result['TABLE_ROWS']);
    }
    return $return;
  }

  public function get_table_sizes($scope = 'regular')
  {
    global $wpdb;
    $results = $wpdb->get_results(
      $wpdb->prepare(
        'SELECT TABLE_NAME AS "table",
							ROUND((data_length + index_length)/1024,0) AS "size"
							FROM information_schema.TABLES
							WHERE information_schema.TABLES.table_schema="%s"
							AND information_schema.TABLES.table_type="%s"',
        DB_NAME,
        "BASE TABLE"
      ),
      ARRAY_A
    );

    $return = [];

    foreach ($results as $result) {
      $return[$result['table']] = $result['size'];
    }

    return apply_filters('wpsdb_table_sizes', $return, $scope);
  }

  public function get_post_max_size(): int
  {
    $val = trim(ini_get('post_max_size'));
    $last = strtolower($val[strlen($val) - 1]);
    $val = (int) $val;
    $val *= match ($last) {
      'g' => 1024 * 1024 * 1024,
      'm' => 1024 * 1024,
      'k' => 1024,
      default => 1,
    };
    return $val;
  }

  public function get_sensible_pull_limit()
  {
    return apply_filters('wpsdb_sensible_pull_limit', min(26214400, $this->settings['max_request']));
  }

  public function get_bottleneck($type = 'regular')
  {
    $suhosin_limit = false;
    $suhosin_request_limit = false;
    $suhosin_post_limit = false;

    if (function_exists('ini_get')) {
      $suhosin_request_limit = $this->return_bytes(ini_get('suhosin.request.max_value_length'));
      $suhosin_post_limit = $this->return_bytes(ini_get('suhosin.post.max_value_length'));
    }

    if ($suhosin_request_limit && $suhosin_post_limit) {
      $suhosin_limit = min($suhosin_request_limit, $suhosin_post_limit);
    }

    // we have to account for HTTP headers and other bloating, here we minus 1kb for bloat
    $post_max_upper_size = apply_filters('wpsdb_post_max_upper_size', 26214400);
    $calculated_bottleneck = min(($this->get_post_max_size() - 1024), $post_max_upper_size);

    if ($suhosin_limit) {
      $calculated_bottleneck = min($calculated_bottleneck, $suhosin_limit - 1024);
    }

    if ('max' != $type) {
      $calculated_bottleneck = min($calculated_bottleneck, $this->settings['max_request']);
    }

    return apply_filters('wpsdb_bottleneck', $calculated_bottleneck);
  }

  public function format_dump_name($dump_name): string
  {
    $extension = '.sql';
    $dump_name = sanitize_file_name($dump_name);
    if ($this->gzip() && isset($this->form_data['gzip_file'])) {
      $extension .= '.gz';
    }
    return $dump_name . $extension;
  }

  public function options_page(): void
  {
?>

    <div class="wrap wpsdb">

      <div id="icon-tools" class="icon32"><br /></div>
      <h2>Migrate DB</h2>

      <h2 class="nav-tab-wrapper">
        <a href="#" class="nav-tab nav-tab-active js-action-link migrate" data-div-name="migrate-tab"><?php _e('Migrate', 'wp-sync-db'); ?></a>
        <a href="#" class="nav-tab js-action-link settings" data-div-name="settings-tab"><?php _e('Settings', 'wp-sync-db'); ?></a>
        <a href="#" class="nav-tab js-action-link help" data-div-name="help-tab"><?php _e('Help', 'wp-sync-db'); ?></a>
      </h2>

      <?php
      do_action('wpsdb_notices');

      $hide_warning = apply_filters('wpsdb_hide_safe_mode_warning', false);
      if (function_exists('ini_get') && ini_get('safe_mode') && !$hide_warning) { ?>
        <div class="updated warning inline-message">
          <?php
          _e("<strong>PHP Safe Mode Enabled</strong> &mdash; We do not officially support running this plugin in safe mode because <code>set_time_limit()</code> has no effect. Therefore we can't extend the run time of the script and ensure it doesn't time out before the migration completes. We haven't disabled the plugin however, so you're free to cross your fingers and hope for the best. However, if you have trouble, we can't help you until you turn off safe mode.", 'wp-sync-db');
          if (function_exists('ini_get')) {
            printf(__('Your current PHP run time limit is set to %s seconds.', 'wp-sync-db'), ini_get('max_execution_time'));
          } ?>
        </div>
      <?php
      }
      ?>

      <div class="updated warning ie-warning inline-message" style="display: none;">
        <?php _e("<strong>Internet Explorer Not Supported</strong> &mdash; Less than 2% of our customers use IE, so we've decided not to spend time supporting it. We ask that you use Firefox or a Webkit-based browser like Chrome or Safari instead. If this is a problem for you, please let us know.", 'wp-sync-db'); ?>
      </div>

      <?php
      $hide_warning = apply_filters('wpsdb_hide_set_time_limit_warning', false);
      if (false == $this->set_time_limit_available() && !$hide_warning && (!function_exists('ini_get') || !ini_get('safe_mode'))) {
      ?>
        <div class="updated warning inline-message">
          <?php
          _e("<strong>PHP Function Disabled</strong> &mdash; The <code>set_time_limit()</code> function is currently disabled on your server. We use this function to ensure that the migration doesn't time out. We haven't disabled the plugin however, so you're free to cross your fingers and hope for the best. You may want to contact your web host to enable this function.", 'wp-sync-db');
          if (function_exists('ini_get')) {
            printf(__('Your current PHP run time limit is set to %s seconds.', 'wp-sync-db'), ini_get('max_execution_time'));
          } ?>
        </div>
      <?php
      }
      ?>

      <div id="wpsdb-main">

        <?php
        // select profile if more than > 1 profile saved
        if (!empty($this->settings['profiles']) && !isset($_GET['wpsdb-profile'])) {
          $this->template('profile');
        } else {
          $this->template('migrate');
        }

        $this->template('settings');

        $this->template('help');
        ?>

      </div> <!-- end #wpsdb-main -->

    </div> <!-- end .wrap -->
  <?php
  }

  public function apply_replaces($subject, $is_serialized = false): string|array|null
  {
    $search = $this->form_data['replace_old'];
    $replace = $this->form_data['replace_new'];
    $new = str_ireplace($search, $replace, $subject, $count);

    /*
		 * Automatically replace URLs for subdomain based multisite installations
		 * e.g. //site1.example.com -> //site1.example.local for site with domain example.com
		 * NB: only handles the current network site, does not work for additional networks / mapped domains
		 */
    $subdomain_replace_enabled = apply_filters('wpsdb_subdomain_replace', true); // allow developers to turn off this functionality
    if ($subdomain_replace_enabled && is_multisite() && defined('SUBDOMAIN_INSTALL') && SUBDOMAIN_INSTALL & !empty($_POST['domain_current_site'])) {
      $pattern = '|//(.*?)\\.' . preg_quote((string) $this->get_domain_current_site(), '|') . '|';
      $replacement = '//$1.' . trim((string) $_POST['domain_current_site']);
      $new = preg_replace($pattern, $replacement, $new);
    }

    return $new;
  }

  public function process_sql_constraint($create_query, $table, &$alter_table_query)
  {
    if (preg_match('@CONSTRAINT|FOREIGN[\s]+KEY@', (string) $create_query)) {

      $sql_constraints_query = '';

      $nl_nix = "\n";
      $nl_win = "\r\n";
      $nl_mac = "\r";
      if (str_contains((string) $create_query, $nl_win)) {
        $crlf = $nl_win;
      } elseif (str_contains((string) $create_query, $nl_mac)) {
        $crlf = $nl_mac;
      } elseif (str_contains((string) $create_query, $nl_nix)) {
        $crlf = $nl_nix;
      }

      // Split the query into lines, so we can easily handle it.
      // We know lines are separated by $crlf (done few lines above).
      $sql_lines = explode($crlf, (string) $create_query);
      $sql_count = count($sql_lines);

      // lets find first line with constraints
      for ($i = 0; $i < $sql_count; $i++) {
        if (preg_match(
          '@^[\s]*(CONSTRAINT|FOREIGN[\s]+KEY)@',
          $sql_lines[$i]
        )) {
          break;
        }
      }

      // If we really found a constraint
      if ($i != $sql_count) {

        // remove, from the end of create statement
        $sql_lines[$i - 1] = preg_replace(
          '@,$@',
          '',
          $sql_lines[$i - 1]
        );

        // let's do the work
        $sql_constraints_query .= 'ALTER TABLE '
          . $this->backquote($table)
          . $crlf;

        $first = true;
        for ($j = $i; $j < $sql_count; $j++) {
          if (preg_match(
            '@CONSTRAINT|FOREIGN[\s]+KEY@',
            (string) $sql_lines[$j]
          )) {
            if (!str_contains((string) $sql_lines[$j], 'CONSTRAINT')) {
              $tmp_str = preg_replace(
                '/(FOREIGN[\s]+KEY)/',
                'ADD \1',
                (string) $sql_lines[$j]
              );
              $sql_constraints_query .= $tmp_str;
            } else {
              $tmp_str = preg_replace(
                '/(CONSTRAINT)/',
                'ADD \1',
                (string) $sql_lines[$j]
              );
              $sql_constraints_query .= $tmp_str;
              preg_match(
                '/(CONSTRAINT)([\s])([\S]*)([\s])/',
                (string) $sql_lines[$j],
                $matches
              );
            }
            $first = false;
          } else {
            break;
          }
        }
        $sql_constraints_query .= ";\n";

        $create_query = implode(
          $crlf,
          array_slice($sql_lines, 0, $i)
        )
          . $crlf
          . implode(
            $crlf,
            array_slice($sql_lines, $j, $sql_count - 1)
          );
        unset($sql_lines);

        $alter_table_query = $sql_constraints_query;
        return $create_query;
      }
    }
    return $create_query;
  }

  /**
   * Taken partially from phpMyAdmin and partially from
   * Alain Wolf, Zurich - Switzerland
   * Website: http://restkultur.ch/personal/wolf/scripts/db_backup/
   * Modified by Scott Merrill (http://www.skippy.net/)
   * to use the WordPress $wpdb object
   *
   * @return void
   */
  public function export_table(string $table)
  {
    global $wpdb;
    $this->set_time_limit();

    if (empty($this->form_data)) {
      $this->form_data = $this->parse_migration_form_data($_POST['form_data']);
    }

    $temp_prefix = $this->temp_prefix;

    $table_structure = $wpdb->get_results("DESCRIBE " . $this->backquote($table));
    if (!$table_structure) {
      $this->error = __('Failed to retrieve table structure, please ensure your database is online. (#125)', 'wp-sync-db');
      return false;
    }

    $current_row = -1;
    if (!empty($_POST['current_row'])) {
      $temp_current_row = trim((string) $_POST['current_row']);
      if ('' !== $temp_current_row && '0' !== $temp_current_row) {
        $current_row = (int) $temp_current_row;
      }
    }

    if (-1 == $current_row) {
      // Add SQL statement to drop existing table
      if ('savefile' == $this->form_data['action'] || 'backup' == $_POST['stage']) {
        $this->stow("\n\n");
        $this->stow("#\n");
        $this->stow("# " . sprintf(__('Delete any existing table %s', 'wp-sync-db'), $this->backquote($table)) . "\n");
        $this->stow("#\n");
        $this->stow("\n");
        $this->stow("DROP TABLE IF EXISTS " . $this->backquote($table) . ";\n");
      } else {
        $this->stow("DROP TABLE IF EXISTS " . $this->backquote($temp_prefix . $table) . ";\n");
      }

      // Table structure
      // Comment in SQL-file
      if ('savefile' == $this->form_data['action'] || 'backup' == $_POST['stage']) {
        $this->stow("\n\n");
        $this->stow("#\n");
        $this->stow("# " . sprintf(__('Table structure of table %s', 'wp-sync-db'), $this->backquote($table)) . "\n");
        $this->stow("#\n");
        $this->stow("\n");
      }

      $create_table = $wpdb->get_results("SHOW CREATE TABLE " . $this->backquote($table), ARRAY_N);
      if (false === $create_table) {
        $this->error = __('Failed to generate the create table query, please ensure your database is online. (#126)', 'wp-sync-db');
        return false;
      }

      if ('savefile' != $this->form_data['action'] && 'backup' != $_POST['stage']) {
        $create_table[0][1] = str_replace('CREATE TABLE `', 'CREATE TABLE `' . $temp_prefix, $create_table[0][1]);
      }

      $create_table[0][1] = str_replace('TYPE=', 'ENGINE=', $create_table[0][1]);

      $alter_table_query = '';
      $create_table[0][1] = $this->process_sql_constraint($create_table[0][1], $table, $alter_table_query);

      $create_table[0][1] = apply_filters('wpsdb_create_table_query', $create_table[0][1], $table);

      $this->stow($create_table[0][1] . ";\n");

      if (!empty($alter_table_query)) {
        $alter_table_name = $this->get_alter_table_name();
        $insert = sprintf("INSERT INTO %s ( `query` ) VALUES ( '%s' );\n", $this->backquote($alter_table_name), esc_sql($alter_table_query));
        if ('savefile' == $this->form_data['action'] || 'backup' == $_POST['stage']) {
          $process_chunk_result = $this->process_chunk($insert);
          if (true !== $process_chunk_result) {
            return $this->end_ajax($process_chunk_result);
          }
        } else {
          $this->stow($insert);
        }
      }

      // Comment in SQL-file
      if ('savefile' == $this->form_data['action'] || 'backup' == $_POST['stage']) {
        $this->stow("\n\n");
        $this->stow("#\n");
        $this->stow('# ' . sprintf(__('Data contents of table %s', 'wp-sync-db'), $this->backquote($table)) . "\n");
        $this->stow("#\n");
      }
    }

    // $defs = mysql defaults, looks up the default for that paricular column, used later on to prevent empty inserts values for that column
    // $ints = holds a list of the possible integar types so as to not wrap them in quotation marks later in the insert statements
    $defs = [];
    $ints = [];
    foreach ($table_structure as $struct) {
      if ((str_starts_with((string) $struct->Type, 'tinyint')) ||
        (str_starts_with(strtolower((string) $struct->Type), 'smallint')) ||
        (str_starts_with(strtolower((string) $struct->Type), 'mediumint')) ||
        (str_starts_with(strtolower((string) $struct->Type), 'int')) ||
        (str_starts_with(strtolower((string) $struct->Type), 'bigint'))
      ) {
        $defs[strtolower((string) $struct->Field)] = $struct->Default ?? 'NULL';
        $ints[strtolower((string) $struct->Field)] = "1";
      }
    }

    // Batch by $row_inc

    $row_inc = $this->rows_per_segment;
    $row_start = 0;
    if (-1 != $current_row) {
      $row_start = $current_row;
    }

    $this->row_tracker = $row_start;

    // \x08\\x09, not required
    $search = ["\x00", "\x0a", "\x0d", "\x1a"];
    $replace = ['\0', '\n', '\r', '\Z'];

    $query_size = 0;

    $table_name = $table;

    if ('savefile' != $this->form_data['action'] && 'backup' != $_POST['stage']) {
      $table_name = $temp_prefix . $table;
    }

    $this->primary_keys = [];
    $use_primary_keys = true;
    foreach ($table_structure as $col) {
      $field_set[] = $this->backquote($col->Field);
      if ('PRI' == $col->Key && true === $use_primary_keys) {
        if (!str_contains((string) $col->Type, 'int')) {
          $use_primary_keys = false;
          $this->primary_keys = [];
          continue;
        }
        $this->primary_keys[$col->Field] = 0;
      }
    }

    $first_select = true;
    if (!empty($_POST['primary_keys'])) {
      $_POST['primary_keys'] = trim((string) $_POST['primary_keys']);
      if (isset($_POST['primary_keys']) && ('' !== $_POST['primary_keys'] && '0' !== $_POST['primary_keys']) && is_serialized($_POST['primary_keys'])) {
        $this->primary_keys = unserialize(stripslashes($_POST['primary_keys']));
        $first_select = false;
      }
    }

    $fields = implode(', ', $field_set);
    $insert_buffer = "INSERT INTO " . $this->backquote($table_name) . " ( " . $fields . ") VALUES\n";
    $insert_query_template = "INSERT INTO " . $this->backquote($table_name) . " ( " . $fields . ") VALUES\n";

    do {
      $join = [];
      $where = 'WHERE 1=1';
      $order_by = '';
      // We need ORDER BY here because with LIMIT, sometimes it will return
      // the same results from the previous query and we'll have duplicate insert statements
      if ('backup' != $_POST['stage'] && isset($this->form_data['exclude_spam'])) {
        if ($this->table_is('comments', $table)) {
          $where .= ' AND comment_approved != "spam"';
        } elseif ($this->table_is('commentmeta', $table)) {
          extract($this->get_ms_compat_table_names(['commentmeta', 'comments'], $table));
          $join[] = sprintf('INNER JOIN %1$s ON %1$s.comment_ID = %2$s.comment_id', $this->backquote($comments_table), $this->backquote($commentmeta_table));
          $where .= sprintf(' AND %1$s.comment_approved != \'spam\'', $this->backquote($comments_table));
        }
      }

      if ('backup' != $_POST['stage'] && isset($this->form_data['exclude_post_types']) && !empty($this->form_data['select_post_types'])) {
        $post_types = "'" . implode("', '", $this->form_data['select_post_types']) . "'";
        if ($this->table_is('posts', $table)) {
          $where .= ' AND `post_type` NOT IN ( ' . $post_types . ' )';
        } elseif ($this->table_is('postmeta', $table)) {
          extract($this->get_ms_compat_table_names(['postmeta', 'posts'], $table));
          $join[] = sprintf('INNER JOIN %1$s ON %1$s.ID = %2$s.post_id', $this->backquote($posts_table), $this->backquote($postmeta_table));
          $where .= sprintf(' AND %1$s.post_type NOT IN ( ' . $post_types . ' )', $this->backquote($posts_table));
        } elseif ($this->table_is('comments', $table)) {
          extract($this->get_ms_compat_table_names(['comments', 'posts'], $table));
          $join[] = sprintf('INNER JOIN %1$s ON %1$s.ID = %2$s.comment_post_ID', $this->backquote($posts_table), $this->backquote($comments_table));
          $where .= sprintf(' AND %1$s.post_type NOT IN ( ' . $post_types . ' )', $this->backquote($posts_table));
        } elseif ($this->table_is('commentmeta', $table)) {
          extract($this->get_ms_compat_table_names(['commentmeta', 'posts', 'comments'], $table));
          $join[] = sprintf('INNER JOIN %1$s ON %1$s.comment_ID = %2$s.comment_id', $this->backquote($comments_table), $this->backquote($commentmeta_table));
          $join[] = sprintf('INNER JOIN %2$s ON %2$s.ID = %1$s.comment_post_ID', $this->backquote($comments_table), $this->backquote($posts_table));
          $where .= sprintf(' AND %1$s.post_type NOT IN ( ' . $post_types . ' )', $this->backquote($posts_table));
        }
      }

      if ('backup' != $_POST['stage'] && true === apply_filters('wpsdb_exclude_transients', true) && isset($this->form_data['exclude_transients']) && '1' === $this->form_data['exclude_transients'] && ($this->table_is('options', $table) || (isset($wpdb->sitemeta) && $wpdb->sitemeta == $table))) {
        $col_name = 'option_name';

        if (isset($wpdb->sitemeta) && $wpdb->sitemeta == $table) {
          $col_name = 'meta_key';
        }

        $where .= sprintf(" AND `%s` NOT LIKE '\\_transient\\_%%' AND `%s` NOT LIKE '\\_site\\_transient\\_%%'", $col_name, $col_name);
      }

      $limit = sprintf('LIMIT %s, %s', $row_start, $row_inc);

      if (!empty($this->primary_keys)) {
        $primary_keys_keys = array_keys($this->primary_keys);
        $primary_keys_keys = array_map($this->backquote(...), $primary_keys_keys);

        $order_by = 'ORDER BY ' . implode(',', $primary_keys_keys);
        $limit = 'LIMIT ' . $row_inc;
        if (false == $first_select) {
          $where .= ' AND ';

          $temp_primary_keys = $this->primary_keys;
          $primary_key_count = count($temp_primary_keys);

          // build a list of clauses, iteratively reducing the number of fields compared in the compound key
          // e.g. (a = 1 AND b = 2 AND c > 3) OR (a = 1 AND b > 2) OR (a > 1)
          $clauses = [];
          for ($j = 0; $j < $primary_key_count; $j++) {
            // build a subclause for each field in the compound index
            $subclauses = [];
            $i = 0;
            foreach ($temp_primary_keys as $primary_key => $value) {
              // only the last field in the key should be different in this subclause
              $operator = (count($temp_primary_keys) - 1 == $i ? '>' : '=');
              $subclauses[] = sprintf('%s %s %s', $this->backquote($primary_key), $operator, $wpdb->prepare('%s', $value));
              ++$i;
            }

            // remove last field from array to reduce fields in next clause
            array_pop($temp_primary_keys);

            // join subclauses into a single clause
            // NB: AND needs to be wrapped in () as it has higher precedence than OR
            $clauses[] = '( ' . implode(' AND ', $subclauses) . ' )';
          }
          // join clauses into a single clause
          // NB: OR needs to be wrapped in () as it has lower precedence than AND
          $where .= '( ' . implode(' OR ', $clauses) . ' )';
        }
        $first_select = false;
      }

      $join = implode(' ', array_unique($join));
      $join = apply_filters('wpsdb_rows_join', $join, $table);
      $where = apply_filters('wpsdb_rows_where', $where, $table);
      $order_by = apply_filters('wpsdb_rows_order_by', $order_by, $table);
      $limit = apply_filters('wpsdb_rows_limit', $limit, $table);

      $sql = "SELECT " . $this->backquote($table) . ".* FROM " . $this->backquote($table) . sprintf(' %s %s %s %s', $join, $where, $order_by, $limit);
      $sql = apply_filters('wpsdb_rows_sql', $sql, $table);

      $table_data = $wpdb->get_results($sql);

      if ($table_data) {
        foreach ($table_data as $row) {
          $values = [];
          foreach ($row as $key => $value) {
            if (isset($ints[strtolower((string) $key)]) && $ints[strtolower((string) $key)]) {
              // make sure there are no blank spots in the insert syntax,
              // yet try to avoid quotation marks around integers
              $value = (null === $value || '' === $value) ? $defs[strtolower((string) $key)] : $value;
              $values[] = ('' === $value) ? "''" : $value;
            } else {
              if (null === $value) {
                $values[] = 'NULL';
              } else {

                if (is_multisite() && 'path' == $key && 'backup' != $_POST['stage'] && ($wpdb->site == $table || $wpdb->blogs == $table)) {
                  $old_path_current_site = $this->get_path_current_site();
                  if (!empty($_POST['path_current_site'])) {
                    $new_path_current_site = stripslashes((string) $_POST['path_current_site']);
                  } else {
                    $new_path_current_site = $this->get_path_from_url($this->form_data['replace_new'][1]);
                  }

                  if ($old_path_current_site != $new_path_current_site) {
                    $pos = strpos($value, (string) $old_path_current_site);
                    $value = substr_replace($value, $new_path_current_site, $pos, strlen((string) $old_path_current_site));
                  }
                }

                if (is_multisite() && 'domain' == $key && 'backup' != $_POST['stage'] && ($wpdb->site == $table || $wpdb->blogs == $table)) {
                  if (!empty($_POST['domain_current_site'])) {
                    $main_domain_replace = $_POST['domain_current_site'];
                  } else {
                    $url = parse_url((string) $this->form_data['replace_new'][1]);
                    $main_domain_replace = $url['host'];
                  }

                  $main_domain_find = sprintf("/%s/", $this->get_domain_current_site());
                  $domain_replaces[$main_domain_find] = $main_domain_replace;
                  $domain_replaces = apply_filters('wpsdb_domain_replaces', $domain_replaces);

                  $value = preg_replace(array_keys($domain_replaces), array_values($domain_replaces), $value);
                }

                if ('guid' != $key || (isset($this->form_data['replace_guids']) && $this->table_is('posts', $table))) {
                  if ('backup' != $_POST['stage']) {
                    $value = $this->recursive_unserialize_replace($value);
                  }
                }

                $values[] = "'" . str_replace($search, $replace, $this->sql_addslashes($value)) . "'";
              }
            }
          }

          $insert_line = '(' . implode(', ', $values) . '),';
          $insert_line .= "\n";

          if ((strlen((string) $this->current_chunk) + strlen($insert_line) + strlen($insert_buffer) + 10) > $this->maximum_chunk_size) {
            if ($insert_buffer == $insert_query_template) {
              $insert_buffer .= $insert_line;

              ++$this->row_tracker;

              if (!empty($this->primary_keys)) {
                foreach ($this->primary_keys as $primary_key => $value) {
                  $this->primary_keys[$primary_key] = $row->$primary_key;
                }
              }
            }
            $insert_buffer = rtrim($insert_buffer, "\n,");
            $insert_buffer .= " ;\n";
            $this->stow($insert_buffer);
            $insert_buffer = $insert_query_template;
            $query_size = 0;
            return $this->transfer_chunk();
          }

          if (($query_size + strlen($insert_line)) > $this->max_insert_string_len && $insert_buffer != $insert_query_template) {
            $insert_buffer = rtrim($insert_buffer, "\n,");
            $insert_buffer .= " ;\n";
            $this->stow($insert_buffer);
            $insert_buffer = $insert_query_template;
            $query_size = 0;
          }

          $insert_buffer .= $insert_line;
          $query_size += strlen($insert_line);

          ++$this->row_tracker;

          if (!empty($this->primary_keys)) {
            foreach ($this->primary_keys as $primary_key => $value) {
              $this->primary_keys[$primary_key] = $row->$primary_key;
            }
          }
        }
        $row_start += $row_inc;

        if ($insert_buffer != $insert_query_template) {
          $insert_buffer = rtrim($insert_buffer, "\n,");
          $insert_buffer .= " ;\n";
          $this->stow($insert_buffer);
          $insert_buffer = $insert_query_template;
          $query_size = 0;
        }
      }
    } while (count($table_data) > 0);

    // Create footer/closing comment in SQL-file
    if ('savefile' == $this->form_data['action'] || 'backup' == $_POST['stage']) {
      $this->stow("\n");
      $this->stow("#\n");
      $this->stow("# " . sprintf(__('End of data contents of table %s', 'wp-sync-db'), $this->backquote($table)) . "\n");
      $this->stow("# --------------------------------------------------------\n");
      $this->stow("\n");

      if ('1' == $_POST['last_table']) {
        $this->stow("#\n");
        $this->stow("# Add constraints back in\n");
        $this->stow("#\n\n");
        $this->stow($this->get_alter_queries());
        $alter_table_name = $this->get_alter_table_name();
        if ('savefile' == $this->form_data['action']) {
          $wpdb->query("DROP TABLE IF EXISTS " . $this->backquote($alter_table_name) . ";");
        }
      }
    }

    $this->row_tracker = -1;
    return $this->transfer_chunk();
  } // end backup_table()

  public function table_is(string $desired_table, $given_table): bool
  {
    global $wpdb;
    return ($wpdb->{$desired_table} == $given_table || preg_match('/' . $wpdb->prefix . '[0-9]+_' . $desired_table . '/', (string) $given_table));
  }

  /**
   * return multisite-compatible names for requested tables, based on queried table name
   *
   * @param array  $tables          list of table names required
   * @param string $queried_table   name of table from which to derive the blog ID
   *
   * @return array                  list of table names altered for multisite compatibility
   */
  public function get_ms_compat_table_names($tables, $queried_table): array
  {
    global $wpdb;

    // default table prefix
    $prefix = $wpdb->prefix;

    // if multisite, extract blog ID from queried table name and add to prefix
    // won't match for primary blog because it uses standard table names, i.e. blog_id will never be 1
    if (is_multisite() && preg_match('/^' . preg_quote((string) $wpdb->prefix, '/') . '([0-9]+)_/', $queried_table, $matches)) {
      $blog_id = $matches[1];
      $prefix .= $blog_id . '_';
    }

    // build table names
    $ms_compat_table_names = [];
    foreach ($tables as $table) {
      $ms_compat_table_names[$table . '_table'] = $prefix . $table;
    }
    return $ms_compat_table_names;
  }

  /**
   * Take a serialized array and unserialize it replacing elements as needed and
   * unserialising any subordinate arrays and performing the replace on those too.
   *
   * Mostly from https://github.com/interconnectit/Search-Replace-DB
   *
   * @param array  $data               Used to pass any subordinate arrays back to in.
   * @param bool   $serialized         Does the array passed via $data need serialising.
   * @param bool   $parent_serialized  Passes whether the original data passed in was serialized
   *
   * @return array    The original array with all elements replaced as needed.
   */
  public function recursive_unserialize_replace($data, $serialized = false, $parent_serialized = false)
  {

    $is_json = false;
    // some unseriliased data cannot be re-serialized eg. SimpleXMLElements
    try {

      if (is_string($data) && ($unserialized = @unserialize($data)) !== false) {
        // PHP currently has a bug that doesn't allow you to clone the DateInterval / DatePeriod classes.
        // We skip them here as they probably won't need data to be replaced anyway
        if (is_object($unserialized)) {
          if ($unserialized instanceof DateInterval || $unserialized instanceof DatePeriod) return $data;
        }
        $data = $this->recursive_unserialize_replace($unserialized, true, true);
      } elseif (is_array($data)) {
        $_tmp = [];
        foreach ($data as $key => $value) {
          $_tmp[$key] = $this->recursive_unserialize_replace($value, false, $parent_serialized);
        }

        $data = $_tmp;
        unset($_tmp);
      }
      // Submitted by Tina Matter
      elseif (is_object($data)) {
        $_tmp = clone $data;
        foreach ($data as $key => $value) {
          $_tmp->$key = $this->recursive_unserialize_replace($value, false, $parent_serialized);
        }

        $data = $_tmp;
        unset($_tmp);
      } elseif ($this->is_json($data, true)) {
        $_tmp = [];
        $data = json_decode($data, true);
        foreach ($data as $key => $value) {
          $_tmp[$key] = $this->recursive_unserialize_replace($value, false, $parent_serialized);
        }

        $data = $_tmp;
        unset($_tmp);
        $is_json = true;
      } elseif (is_string($data)) {
        $data = $this->apply_replaces($data, $parent_serialized);
      }

      if ($serialized)
        return serialize($data);

      if ($is_json)
        return json_encode($data);
    } catch (Exception) {
    }

    return $data;
  }

  public function db_backup_header(): void
  {
    $charset = (defined('DB_CHARSET') ? DB_CHARSET : 'utf8');
    $this->stow("# " . __('WordPress MySQL database migration', 'wp-sync-db') . "\n", false);
    $this->stow("#\n", false);
    $this->stow("# " . sprintf(__('Generated: %s', 'wp-sync-db'), date("l j. F Y H:i T")) . "\n", false);
    $this->stow("# " . sprintf(__('Hostname: %s', 'wp-sync-db'), DB_HOST) . "\n", false);
    $this->stow("# " . sprintf(__('Database: %s', 'wp-sync-db'), $this->backquote(DB_NAME)) . "\n", false);
    $this->stow("# --------------------------------------------------------\n\n", false);
    $this->stow("/*!40101 SET NAMES {$charset} */;\n\n", false);
    $this->stow("SET sql_mode='NO_AUTO_VALUE_ON_ZERO';\n\n", false);
  }

  public function gzip(): bool
  {
    return function_exists('gzopen');
  }

  public function open($filename = '', $mode = 'a')
  {
    if ('' == $filename) return false;
    if ($this->gzip() && isset($this->form_data['gzip_file']))
      return gzopen($filename, $mode);
    return fopen($filename, $mode);
  }

  public function close($fp): void
  {
    if ($this->gzip() && isset($this->form_data['gzip_file'])) gzclose($fp);
    else fclose($fp);
    unset($this->fp);
  }

  public function stow(string $query_line, $replace = true)
  {
    $this->current_chunk .= $query_line;
    if ('savefile' == $this->form_data['action'] || 'backup' == $_POST['stage']) {
      if ($this->gzip() && isset($this->form_data['gzip_file'])) {
        if (!@gzwrite($this->fp, $query_line)) {
          $this->error = __('Failed to write the gzipped SQL data to the file. (#127)', 'wp-sync-db');
          return false;
        }
      } else {
        if (false === @fwrite($this->fp, $query_line)) {
          $this->error = __('Failed to write the SQL data to the file. (#128)', 'wp-sync-db');
          return false;
        }
      }
    } else if ('pull' == $_POST['intent']) {
      echo $query_line;
    }
  }

  // Called in the $this->stow function once our chunk buffer is full, will transfer the SQL to the remote server for importing
  public function transfer_chunk()
  {
    if ('savefile' == $_POST['intent'] || 'backup' == $_POST['stage']) {
      $this->close($this->fp);
      return $this->end_ajax(json_encode(
        [
          'current_row'   => $this->row_tracker,
          'primary_keys'  => serialize($this->primary_keys)
        ]
      ));
    }

    if ('pull' == $_POST['intent']) {
      return $this->end_ajax($this->row_tracker . ',' . serialize($this->primary_keys));
    }

    $chunk_gzipped = '0';
    if (isset($_POST['gzip']) && '1' == $_POST['gzip'] && $this->gzip()) {
      $this->current_chunk = gzcompress((string) $this->current_chunk);
      $chunk_gzipped = '1';
    }

    $data = [
      'action'  => 'wpsdb_process_chunk',
      'table' => $_POST['table'],
      'chunk_gzipped'  => $chunk_gzipped,
      'chunk'  =>  $this->current_chunk // NEEDS TO BE the last element in this array because of adding it back into the array in ajax_process_chunk()
    ];

    $data['sig'] = $this->create_signature($data, $_POST['key']);

    $ajax_url = trailingslashit($this->remote_url) . 'wp-admin/admin-ajax.php';
    $response = $this->remote_post($ajax_url, $data, __FUNCTION__);
    ob_start();
    $this->display_errors();
    $response = ob_get_clean();
    $response .= trim($response);
    if ('' !== $response && '0' !== $response) {
      return $this->end_ajax($response);
    }
    return $this->end_ajax(json_encode(
      [
        'current_row'     => $this->row_tracker,
        'primary_keys'    => serialize($this->primary_keys)
      ]
    ));
  }

  /**
   * Add backquotes to tables and db-names in
   * SQL queries. Taken from phpMyAdmin.
   */
  public function backquote(?string $a_name): array|string|null
  {
    if (null !== $a_name && '' !== $a_name && '0' !== $a_name && '*' != $a_name) {
      if (is_array($a_name)) {
        $result = [];
        reset($a_name);
        foreach ($a_name as $key => $val) {
            $result[$key] = '`' . $val . '`';
        }
        return $result;
      }
      return '`' . $a_name . '`';
    }
    return $a_name;
  }

  /**
   * Better addslashes for SQL queries.
   * Taken from phpMyAdmin.
   */
  public function sql_addslashes($a_string = '', $is_like = false): string|array
  {
    if ($is_like) $a_string = str_replace('\\', '\\\\\\\\', $a_string);
    else $a_string = str_replace('\\', '\\\\', $a_string);
    return str_replace("'", '\\\'', $a_string);
  }

  public function network_admin_menu(): void
  {
    $hook_suffix = add_submenu_page('settings.php', 'Migrate DB', 'Migrate DB', 'manage_network_options', 'wp-sync-db', $this->options_page(...));
    $this->after_admin_menu($hook_suffix);
  }

  public function admin_menu(): void
  {
    $hook_suffix = add_management_page('Migrate DB', 'Migrate DB', 'export', 'wp-sync-db', $this->options_page(...));
    $this->after_admin_menu($hook_suffix);
  }

  public function after_admin_menu(string $hook_suffix): void
  {
    /**
     * Output CSS in the admin head for the plugin page.
     *
     * @since 1.0
     */
    add_action('admin_head-' . $hook_suffix, $this->admin_head_connection_info(...));

    /**
     * Load assets and handle post requests on the plugin page load.
     *
     * @since 1.0
     */
    add_action('load-' . $hook_suffix, $this->load_assets(...));
  }

  public function admin_body_class($classes): string
  {
    if (!$classes) {
      $classes = [];
    } else {
      $classes = explode(' ', $classes);
    }

    // Recommended way to target WP 3.8+
    // http://make.wordpress.org/ui/2013/11/19/targeting-the-new-dashboard-design-in-a-post-mp6-world/
    if (version_compare($GLOBALS['wp_version'], '3.8-alpha', '>')) {
      if (!in_array('mp6', $classes)) {
        $classes[] = 'mp6';
      }
    }

    return implode(' ', $classes);
  }

  public function load_assets(): void
  {
    if (!empty($_GET['download'])) {
      $this->download_file();
    }

    $plugins_url = trailingslashit(plugins_url('', $this->plugin_file_path));

    $version = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? time() : $this->plugin_version;

    $src = $plugins_url . 'asset/css/styles.css';
    wp_enqueue_style('wp-sync-db-styles', $src, [], $version);

    $src = $plugins_url . 'asset/js/common.js';
    wp_enqueue_script('wp-sync-db-common', $src, NULL, $version, true);

    $src = $plugins_url . 'asset/js/hook.js';
    wp_enqueue_script('wp-sync-db-hook', $src, NULL, $version, true);

    do_action('wpsdb_load_assets');

    $src = $plugins_url . 'asset/js/script.js';
    wp_enqueue_script('wp-sync-db-script', $src, ['jquery'], $version, true);

    wp_localize_script('wp-sync-db-script', 'wpsdb_i10n', [
      'max_request_size_problem'        => __("A problem occurred when trying to change the maximum request size, please try again.", 'wp-sync-db'),
      'establishing_remote_connection'    => __("Establishing connection to remote server, please wait", 'wp-sync-db'),
      'connection_local_server_problem'    => __("A problem occurred when attempting to connect to the local server, please check the details and try again.", 'wp-sync-db'),
      'clear_log_problem'            => __("An error occurred when trying to clear the debug log. Please contact support. (#132)", 'wp-sync-db'),
      'update_log_problem'          => __("An error occurred when trying to update the debug log. Please contact support. (#133)", 'wp-sync-db'),
      'migrate_db_save'            => __("Migrate DB & Save", 'wp-sync-db'),
      'migrate_db'              => __("Migrate DB", 'wp-sync-db'),
      'please_select_one_table'        => __("Please select at least one table to migrate.", 'wp-sync-db'),
      'enter_name_for_profile'        => __("Please enter a name for your migration profile.", 'wp-sync-db'),
      'save_profile_problem'          => __("An error occurred when attempting to save the migration profile. Please see the Help tab for details on how to request support. (#118)", 'wp-sync-db'),
      'exporting_complete'          => __("Exporting complete", 'wp-sync-db'),
      'exporting_please_wait'          => __("Exporting, please wait...", 'wp-sync-db'),
      'please_wait'              => __("please wait...", 'wp-sync-db'),
      'complete'                => __("complete", 'wp-sync-db'),
      'migration_failed'            => __("Migration failed", 'wp-sync-db'),
      'backing_up'              => __("Backing up", 'wp-sync-db'),
      'migrating'                => __("Migrating", 'wp-sync-db'),
      'status'                => __("Status", 'wp-sync-db'),
      'response'                => __("Response", 'wp-sync-db'),
      'table_process_problem'          => __("A problem occurred when attempting to process the following table (#113)", 'wp-sync-db'),
      'table_process_problem_empty_response'  => __("A problem occurred when processing the following table. We were expecting a response in JSON format but instead received an empty response.", 'wp-sync-db'),
      'completed_with_some_errors'      => __("Migration completed with some errors", 'wp-sync-db'),
      'completed_dump_located_at'        => __("Migration complete, your backup is located at:", 'wp-sync-db'),
      'finalize_tables_problem'        => __("A problem occurred when finalizing the backup. (#132)", 'wp-sync-db'),
      'saved'                  => __("Saved", 'wp-sync-db'),
      'reset_api_key'              => __("Any sites setup to use the current API key will no longer be able to connect. You will need to update those sites with the newly generated API key. Do you wish to continue?", 'wp-sync-db'),
      'reset_api_key_problem'          => __("An error occurred when trying to generate the API key. Please see the Help tab for details on how to request support. (#105)", 'wp-sync-db'),
      'remove_profile'            => __("You are removing the following migration profile. This cannot be undone. Do you wish to continue?", 'wp-sync-db'),
      'remove_profile_problem'        => __("An error occurred when trying to delete the profile. Please see the Help tab for details on how to request support. (#106)", 'wp-sync-db'),
      'remove_profile_not_found'        => __("The selected migration profile could not be deleted because it was not found.\nPlease refresh this page to see an accurate list of the currently available migration profiles.", 'wp-sync-db'),
      'change_connection_info'        => __("If you change the connection details, you will lose any replaces and table selections you have made below. Do you wish to continue?", 'wp-sync-db'),
      'enter_connection_info'          => __("Please enter the connection information above to continue.", 'wp-sync-db'),
      'save_settings_problem'          => __("An error occurred when trying to save the settings. Please try again. If the problem persists, please see the Help tab for details on how to request support. (#108)", 'wp-sync-db'),
      'connection_info_missing'        => __("The connection information appears to be missing, please enter it to continue.", 'wp-sync-db'),
      'connection_info_incorrect'        => __("The connection information appears to be incorrect, it should consist of two lines. The first being the remote server's URL and the second being the secret key.", 'wp-sync-db'),
      'connection_info_url_invalid'      => __("The URL on the first line appears to be invalid, please check it and try again.", 'wp-sync-db'),
      'connection_info_key_invalid'      => __("The secret key on the second line appears to be invalid. It should be a 32 character string that consists of letters, numbers and special characters only.", 'wp-sync-db'),
      'connection_info_local_url'        => __("It appears you've entered the URL for this website, you need to provide the URL of the remote website instead.", 'wp-sync-db'),
      'connection_info_local_key'        => __("It appears you've entered the secret key for this website, you need to provide the secret key for the remote website instead.", 'wp-sync-db'),
      'time_elapsed'              => __("Time Elapsed:", 'wp-sync-db'),
      'pause'                  => __("Pause", 'wp-sync-db'),
      'migration_paused'            => __("Migration Paused", 'wp-sync-db'),
      'resume'                => __("Resume", 'wp-sync-db'),
      'completing_current_request'      => __("Completing current request", 'wp-sync-db'),
      'cancelling_migration'          => __("Cancelling migration", 'wp-sync-db'),
      'paused'                => __("Paused", 'wp-sync-db'),
      'removing_local_sql'          => __("Removing the local MySQL export file", 'wp-sync-db'),
      'removing_local_backup'          => __("Removing the local backup MySQL export file", 'wp-sync-db'),
      'removing_local_temp_tables'      => __("Removing the local temporary tables", 'wp-sync-db'),
      'removing_remote_sql'          => __("Removing the remote backup MySQL export file", 'wp-sync-db'),
      'removing_remote_temp_tables'      => __("Removing the remote temporary tables", 'wp-sync-db'),
      'migration_cancellation_failed'      => __("Migration cancellation failed", 'wp-sync-db'),
      'manually_remove_temp_files'      => __("A problem occurred while cancelling the migration, you may have to manually delete some temporary files / tables.", 'wp-sync-db'),
      'migration_cancelled'          => __("Migration cancelled", 'wp-sync-db'),
    ]);

    wp_enqueue_script('jquery');
    wp_enqueue_script('jquery-ui-core');
    wp_enqueue_script('jquery-ui-slider');
    wp_enqueue_script('jquery-ui-sortable');
  }

  public function download_file(): void
  {
    // dont need to check for user permissions as our 'add_management_page' already takes care of this
    $this->set_time_limit();

    $dump_name = $this->format_dump_name($_GET['download']);
    if (isset($_GET['gzip'])) {
      $dump_name .= '.gz';
    }
    $diskfile = $this->get_upload_info('path') . DIRECTORY_SEPARATOR . $dump_name;
    $filename = basename($diskfile);
    $last_dash = strrpos($filename, '-');
    $salt = substr($filename, $last_dash, 6);
    $filename_no_salt = str_replace($salt, '', $filename);

    if (file_exists($diskfile)) {
      header('Content-Description: File Transfer');
      header('Content-Type: application/octet-stream');
      header('Content-Length: ' . filesize($diskfile));
      header('Content-Disposition: attachment; filename=' . $filename_no_salt);
      $success = readfile($diskfile);
      unlink($diskfile);
      exit;
    }
    wp_die(__('Could not find the file to download:', 'wp-sync-db') . '<br />' . $diskfile);
  }

  public function admin_head_connection_info(): void
  {
    global $table_prefix;

    $nonces = [
      'update_max_request_size'       => wp_create_nonce('update-max-request-size'),
      'verify_connection_to_remote_site'  => wp_create_nonce('verify-connection-to-remote-site'),
      'clear_log'              => wp_create_nonce('clear-log'),
      'get_log'              => wp_create_nonce('get-log'),
      'save_profile'            => wp_create_nonce('save-profile'),
      'initiate_migration'        => wp_create_nonce('initiate-migration'),
      'migrate_table'            => wp_create_nonce('migrate-table'),
      'finalize_migration'        => wp_create_nonce('finalize-migration'),
      'reset_api_key'            => wp_create_nonce('reset-api-key'),
      'delete_migration_profile'      => wp_create_nonce('delete-migration-profile'),
      'save_setting'            => wp_create_nonce('save-setting'),
    ];

    $nonces = apply_filters('wpsdb_nonces', $nonces);

  ?>
    <script type='text/javascript'>
      var wpsdb_connection_info = <?php echo json_encode([site_url('', 'https'), $this->settings['key']]); ?>;
      var wpsdb_this_url = '<?php echo addslashes(home_url()) ?>';
      var wpsdb_this_path = '<?php echo addslashes((string) $this->absolute_root_file_path); ?>';
      var wpsdb_this_domain = '<?php echo $this->get_domain_current_site(); ?>';
      var wpsdb_this_tables = <?php echo json_encode($this->get_tables()); ?>;
      var wpsdb_this_prefixed_tables = <?php echo json_encode($this->get_tables('prefix')); ?>;
      var wpsdb_this_table_sizes = <?php echo json_encode($this->get_table_sizes()); ?>;
      var wpsdb_this_table_rows = <?php echo json_encode($this->get_table_row_count()); ?>;
      var wpsdb_this_upload_url = '<?php echo addslashes(trailingslashit($this->get_upload_info('url'))); ?>';
      var wpsdb_this_upload_dir_long = '<?php echo addslashes(trailingslashit($this->get_upload_info('path'))); ?>';
      var wpsdb_this_website_name = '<?php echo sanitize_title_with_dashes(DB_NAME); ?>';
      var wpsdb_this_download_url = '<?php echo network_admin_url($this->plugin_base . '&download='); ?>';
      var wpsdb_this_prefix = '<?php echo $table_prefix; ?>';
      var wpsdb_is_multisite = <?php echo (is_multisite() ? 'true' : 'false'); ?>;
      var wpsdb_openssl_available = <?php echo ($this->open_ssl_enabled() ? 'true' : 'false'); ?>;
      var wpsdb_plugin_version = '<?php echo $this->plugin_version; ?>';
      var wpsdb_max_request = '<?php echo $this->settings['max_request'] ?>';
      var wpsdb_bottleneck = '<?php echo $this->get_bottleneck('max'); ?>';
      var wpsdb_this_uploads_dir = '<?php echo addslashes((string) $this->get_short_uploads_dir()); ?>';
      var wpsdb_write_permission = <?php echo (is_writeable($this->get_upload_info('path')) ? 'true' : 'false'); ?>;
      var wpsdb_nonces = <?php echo json_encode($nonces); ?>;
      var wpsdb_profile = '<?php echo ($_GET['wpsdb-profile'] ?? '-1'); ?>';
      <?php do_action('wpsdb_js_variables'); ?>
    </script>
<?php
  }

  public function maybe_update_profile(array $profile, $profile_id): array
  {
    $profile_changed = false;

    if (isset($profile['exclude_revisions'])) {
      unset($profile['exclude_revisions']);
      $profile['select_post_types'] = ['revision'];
      $profile_changed = true;
    }

    if (isset($profile['post_type_migrate_option']) && 'migrate_select_post_types' == $profile['post_type_migrate_option'] && 'pull' != $profile['action']) {
      unset($profile['post_type_migrate_option']);
      $profile['exclude_post_types'] = '1';
      $all_post_types = $this->get_post_types();
      $profile['select_post_types'] = array_diff($all_post_types, $profile['select_post_types']);
      $profile_changed = true;
    }

    if ($profile_changed) {
      $this->settings['profiles'][$profile_id] = $profile;
      update_option('wpsdb_settings', $this->settings);
    }
    return $profile;
  }

  public function get_path_from_url($url)
  {
    $parts = parse_url((string) $url);
    return (isset($parts['path']) && ('' !== $parts['path'] && '0' !== $parts['path'])) ? trailingslashit($parts['path']) : '/';
  }

  public function get_path_current_site()
  {
    if (!is_multisite()) return '';
    $current_site = get_current_site();
    return $current_site->path;
  }

  public function get_domain_current_site()
  {
    if (!is_multisite()) return '';
    $current_site = get_current_site();
    return $current_site->domain;
  }

  public function return_bytes($val): int|false
  {
    if (is_numeric($val)) return (int) $val;
    if (empty($val)) return false;
    $val = trim((string) $val);
    $last = strtolower($val[strlen($val) - 1]);
    $num = (int) $val;
    return match ($last) {
      'g' => $num * 1024 * 1024 * 1024,
      'm' => $num * 1024 * 1024,
      'k' => $num * 1024,
      default => false,
    };
  }

  public function maybe_checked($option): void
  {
    echo (isset($option) && '1' == $option) ? ' checked="checked"' : '';
  }

  public function ajax_cancel_migration(): void
  {
    $this->form_data = $this->parse_migration_form_data($_POST['form_data']);

    match ($_POST['intent']) {
      'savefile' => $this->delete_export_file($_POST['dump_filename'], false),
      'push' => (function () {
        $data = $_POST;
        $data['action'] = 'wpsdb_process_push_migration_cancellation';
        $data['temp_prefix'] = $this->temp_prefix;
        $ajax_url = trailingslashit($data['url']) . 'wp-admin/admin-ajax.php';
        $data['sig'] = $this->create_signature($data, $data['key']);

        $response = $this->remote_post($ajax_url, $data, __FUNCTION__);
        $this->display_errors();

        echo trim((string) $response);
      })(),
      'pull' => match ($_POST['stage']) {
        'backup' => $this->delete_export_file($_POST['dump_filename'], true),
        default => $this->delete_temporary_tables($_POST['temp_prefix']),
      },
      default => null,
    };

    exit;
  }

  public function respond_to_process_push_migration_cancellation(): void
  {
    $filtered_post = $this->filter_post_elements($_POST, ['action', 'intent', 'url', 'key', 'form_data', 'dump_filename', 'temp_prefix', 'stage']);
    if (!$this->verify_signature($filtered_post, $this->settings['key'])) {
      echo $this->invalid_content_verification_error;
      exit;
    }

    $this->form_data = $this->parse_migration_form_data($filtered_post['form_data']);

    if ('backup' == $filtered_post['stage']) {
      $this->delete_export_file($filtered_post['dump_filename'], true);
    } else {
      $this->delete_temporary_tables($filtered_post['temp_prefix']);
    }
    exit;
  }

  public function delete_export_file($filename, $is_backup): void
  {
    $dump_file = $this->format_dump_name($filename);
    if (true == $is_backup) {
      $dump_file = preg_replace('/.gz$/', '', (string) $dump_file);
    }
    $dump_file = $this->get_upload_info('path') . DIRECTORY_SEPARATOR . $dump_file;

    if (false == file_exists($dump_file)) {
      _e('MySQL export file not found.', 'wp-sync-db');
      exit;
    }

    if (false === @unlink($dump_file)) {
      e('Could not delete the MySQL export file.', 'wp-sync-db');
      exit;
    }
  }

  public function delete_temporary_tables($prefix): void
  {
    $tables = $this->get_tables();
    $delete_queries = '';
    foreach ($tables as $table) {
      if (!str_starts_with((string) $table, (string) $prefix)) continue;
      $delete_queries .= sprintf("DROP TABLE %s;\n", $this->backquote($table));
    }
    $this->process_chunk($delete_queries);
  }

  public function empty_current_chunk(): void
  {
    $this->current_chunk = '';
  }
}
