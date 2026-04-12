<?php
declare(strict_types=1);

namespace WPSDB;

class WPSDB_Base
{
  /** @var array<string, mixed> */
  protected array $settings;
  protected string $plugin_dir_path;

  protected string $plugin_folder_name;

  protected string $plugin_basename;

  protected string $plugin_base;

  protected string $plugin_version;

  protected string $template_dir;

  protected string $plugin_title;

  protected int $transient_timeout;

  protected int $transient_retry_timeout;

  protected string $multipart_boundary = 'bWH4JVmYCnf6GfXacrcc';

  protected ?string $attempting_to_connect_to = null;

  protected ?string $error = null;

  protected string $temp_prefix = '_mig_';

  protected string $invalid_content_verification_error;

  protected bool $doing_cli_migration = false;

  public function __construct(protected string $plugin_file_path)
  {
    $this->settings = get_option('wpsdb_settings');
    // @phpstan-ignore-next-line - get_option returns mixed, we need to validate
    if (!is_array($this->settings)) {
      $this->settings = [];
    }

    $this->invalid_content_verification_error = '';

    $this->transient_timeout = 60 * 60 * 12;
    $this->transient_retry_timeout = 60 * 60 * 2;
    $this->plugin_dir_path = plugin_dir_path($this->plugin_file_path);
    $this->plugin_folder_name = basename($this->plugin_dir_path);
    $this->plugin_basename = plugin_basename($this->plugin_file_path);
    $this->template_dir = $this->plugin_dir_path . 'template' . DIRECTORY_SEPARATOR;
    $this->plugin_title = ucwords(str_ireplace('-', ' ', basename((string) $this->plugin_file_path)));
    $this->plugin_title = str_ireplace(['db', 'wp', '.php'], ['DB', 'WP', ''], $this->plugin_title);

    if (is_multisite()) {
      $this->plugin_base = 'settings.php?page=wp-sync-db';
    } else {
      $this->plugin_base = 'tools.php?page=wp-sync-db';
    }

    // allow devs to change the temporary prefix applied to the tables
    $this->temp_prefix = apply_filters('wpsdb_temporary_prefix', $this->temp_prefix);

    /**
     * Fires after WordPress has finished loading but before any headers are sent.
     *
     * Used to initialize plugin translations during the WordPress initialization process.
     *
     * @since 1.0
     * @param callable $function_to_add The callback method to invoke.
     * @param int      $priority        The execution priority (default: 10).
     * @param int      $accepted_args   Number of arguments the callback accepts.
     */
    add_action('init', $this->set_translations(...));
  }

  public function template(string $template): void
  {
    include $this->template_dir . $template . '.php';
  }

  public function open_ssl_enabled(): bool
  {
    if (defined('OPENSSL_VERSION_TEXT')) {
      return true;
    }
    return false;
  }

  public function set_time_limit(): void
  {
    if (!function_exists('ini_get') || !ini_get('safe_mode')) {
      @set_time_limit(0);
    }
  }

  public function remote_post(string $url, array $data, string $scope, array $args = [], bool $expecting_serial = false): mixed
  {
    $this->set_time_limit();

    if (function_exists('fsockopen') && str_starts_with((string) $url, 'https://') && 'ajax_verify_connection_to_remote_site' == $scope) {
      $url_parts = parse_url((string) $url);
      $host = $url_parts['host'];
      if ($pf = @fsockopen($host, 443, $err, $err_string, 1)) {
        // worked
        fclose($pf);
      } else {
        // failed
        $url = substr_replace($url, 'http', 0, 5);
      }
    }

    $sslverify = (1 == $this->settings['verify_ssl']);

    $default_remote_post_timeout = apply_filters('wpsdb_default_remote_post_timeout', 60 * 20);

    $args = wp_parse_args($args, [
      'timeout'  => $default_remote_post_timeout,
      'blocking'  => true,
      'sslverify'  => $sslverify,
    ]);

    $args['method'] = 'POST';
    if (! isset($args['body'])) {
      $args['body'] = $this->array_to_multipart($data);
    }

    $args['headers']['Content-Type'] = 'multipart/form-data; boundary=' . $this->multipart_boundary;
    $args['headers']['Referer'] = network_admin_url('admin-ajax.php');

    $this->attempting_to_connect_to = $url;

    $response = wp_remote_post($url, $args);

    if (! is_wp_error($response)) {
      $response['body'] = trim((string) $response['body'], "\xef\xbb\xbf");
    }
    if (is_wp_error($response)) {
        if (str_starts_with((string) $url, 'https://') && 'ajax_verify_connection_to_remote_site' == $scope) {
          return $this->retry_remote_post($url, $data, $scope, $args, $expecting_serial);
        }
        if (isset($response->errors['http_request_failed'][0]) && str_contains($response->errors['http_request_failed'][0], 'timed out')) {
          $this->error = sprintf(__('The connection to the remote server has timed out, no changes have been committed. (#134 - scope: %s)', 'wp-sync-db'), $scope);
        } else if (isset($response->errors['http_request_failed'][0]) && (str_contains($response->errors['http_request_failed'][0], 'Could not resolve host') || str_contains($response->errors['http_request_failed'][0], "couldn't connect to host"))) {
          $this->error = sprintf(__('We could not find: %s. Are you sure this is the correct URL?', 'wp-sync-db'), $_POST['url']);
          $url_bits = parse_url((string) $_POST['url']);
          if (str_contains((string) $_POST['url'], 'dev.') || str_contains((string) $_POST['url'], '.dev') || ! str_contains($url_bits['host'], '.')) {
            $this->error .= '<br />';
            if ('pull' == $_POST['intent']) {
              $this->error .= __('It appears that you might be trying to pull from a local environment. This will not work if <u>this</u> website happens to be located on a remote server, it would be impossible for this server to contact your local environment.', 'wp-sync-db');
            } else {
              $this->error .= __('It appears that you might be trying to push to a local environment. This will not work if <u>this</u> website happens to be located on a remote server, it would be impossible for this server to contact your local environment.', 'wp-sync-db');
            }
          }
        } else {
          $this->error = sprintf(__('The connection failed, an unexpected error occurred, please contact support. (#121 - scope: %s)', 'wp-sync-db'), $scope);
        }

        $this->log_error($this->error, false);
        return false;
    }
    if ((int) $response['response']['code'] < 200 || (int) $response['response']['code'] > 399) {
        if (str_starts_with((string) $url, 'https://') && 'ajax_verify_connection_to_remote_site' == $scope) {
          return $this->retry_remote_post($url, $data, $scope, $args, $expecting_serial);
        }
        if ('401' == $response['response']['code']) {
          $this->error = __('The remote site is protected with Basic Authentication. Please enter the username and password above to continue. (401 Unauthorized)', 'wp-sync-db');
          $this->log_error($this->error, $response);
          return false;
        }
        $this->error = sprintf(__('Unable to connect to the remote server, please check the connection details - %1$s %2$s (#129 - scope: %3$s)', 'wp-sync-db'), $response['response']['code'], $response['response']['message'], $scope);
        $this->log_error($this->error, $response);
        return false;
    }
    if ($expecting_serial && false === is_serialized($response['body'])) {
        if (str_starts_with((string) $url, 'https://') && 'ajax_verify_connection_to_remote_site' == $scope) {
          return $this->retry_remote_post($url, $data, $scope, $args, $expecting_serial);
        }

        $this->error = __('There was a problem with the AJAX request, we were expecting a serialized response, instead we received:<br />', 'wp-sync-db') . htmlentities((string) $response['body']);
        $this->log_error($this->error, $response);
        return false;
    }
    if ('0' === $response['body']) {
        if (str_starts_with((string) $url, 'https://') && 'ajax_verify_connection_to_remote_site' == $scope) {
          return $this->retry_remote_post($url, $data, $scope, $args, $expecting_serial);
        }

        $this->error = sprintf(__('WP Sync DB does not seem to be installed or active on the remote site. (#131 - scope: %s)', 'wp-sync-db'), $scope);
        $this->log_error($this->error, $response);
        return false;
    }

    if ($expecting_serial && true === is_serialized($response['body']) && 'ajax_verify_connection_to_remote_site' == $scope) {
        $unserialized_response = unserialize($response['body']);
        if (isset($unserialized_response['error']) && '1' == $unserialized_response['error'] && str_starts_with((string) $url, 'https://')) {
          return $this->retry_remote_post($url, $data, $scope, $args, $expecting_serial);
        }
    }

    return $response['body'];
  }

  public function retry_remote_post(string $url, array $data, string $scope, array $args = [], bool $expecting_serial = false): mixed
  {
    $url = substr_replace($url, 'http', 0, 5);
    if ($response = $this->remote_post($url, $data, $scope, $args, $expecting_serial)) {
      return $response;
    }

    return false;
  }

  public function array_to_multipart(array $data): mixed
  {
    if (empty($data)) {
      return $data;
    }

    $result = '';

    foreach ($data as $key => $value) {
      $result .= '--' . $this->multipart_boundary . "\r\n" .
        sprintf('Content-Disposition: form-data; name="%s"', $key);

      if ('chunk' == $key) {
        if ($data['chunk_gzipped']) {
          $result .= "; filename=\"chunk.txt.gz\"\r\nContent-Type: application/x-gzip";
        } else {
          $result .= "; filename=\"chunk.txt\"\r\nContent-Type: text/plain;";
        }
      } else {
        $result .= "\r\nContent-Type: text/plain; charset=" . get_option('blog_charset');
      }

      $result .= "\r\n\r\n" . $value . "\r\n";
    }

    return $result . ("--" . $this->multipart_boundary . "--\r\n");
  }

  public function file_to_multipart($file): false|string
  {
    $result = '';

    if (false == file_exists($file)) return false;

    $filetype = wp_check_filetype($file);
    $contents = file_get_contents($file);

    $result .= '--' . $this->multipart_boundary . "\r\n" .
      sprintf('Content-Disposition: form-data; name="media[]"; filename="%s"', basename((string) $file));

    $result .= sprintf("\r\nContent-Type: %s", $filetype['type']);

    $result .= "\r\n\r\n" . $contents . "\r\n";

    return $result . ("--" . $this->multipart_boundary . "--\r\n");
  }

  public function log_error(string $wpsdb_error, array|false $additional_error_var = false): void
  {
    $error_header = "********************************************\n******  Log date: " . date('Y/m/d H:i:s') . " ******\n********************************************\n\n";
    $error = $error_header . "WPSDB Error: " . $wpsdb_error . "\n\n";
    if (! empty($this->attempting_to_connect_to)) {
      $error .= "Attempted to connect to: " . $this->attempting_to_connect_to . "\n\n";
    }

    if (false !== $additional_error_var) {
      $error .= print_r($additional_error_var, true) . "\n\n";
    }

    $log = get_option('wpsdb_error_log');
    if ($log) {
      $log = $log . $error;
    } else {
      $log = $error;
    }

    update_option('wpsdb_error_log', $log);
  }

  public function display_errors(): bool
  {
    if (! empty($this->error)) {
      echo $this->error;
      $this->error = '';
      return true;
    }

    return false;
  }

  public function filter_post_elements(array $post_array, array $accepted_elements): array
  {
    if (isset($post_array['form_data'])) {
      $post_array['form_data'] = stripslashes($post_array['form_data']);
    }

    $accepted_elements[] = 'sig';
    return array_intersect_key($post_array, array_flip($accepted_elements));
  }

  public function create_signature(array $data, string $key): string
  {
    if (isset($data['sig'])) {
      unset($data['sig']);
    }

    $flat_data = implode('', $data);
    return base64_encode(hash_hmac('sha1', $flat_data, (string) $key, true));
  }

  public function verify_signature(array $data, string $key): bool
  {
    if (empty($data['sig'])) {
      return false;
    }

    if (isset($data['nonce'])) {
      unset($data['nonce']);
    }

    $temp = $data;
    $computed_signature = $this->create_signature($temp, $key);
    return $computed_signature === $data['sig'];
  }

  /**
   * @return non-empty-array[]
   */
  public function diverse_array(array $vector): array
  {
    $result = [];
    foreach ($vector as $key1 => $value1)
      foreach ($value1 as $key2 => $value2)
        $result[$key2][$key1] = $value2;

    return $result;
  }

  public function set_time_limit_available(): bool
  {
    if (! function_exists('set_time_limit') || ! function_exists('ini_get')) return false;

    $current_max_execution_time = ini_get('max_execution_time');
    $proposed_max_execution_time = (30 == $current_max_execution_time) ? 31 : 30;
    @set_time_limit($proposed_max_execution_time);
    $current_max_execution_time = ini_get('max_execution_time');
    return ($current_max_execution_time == $proposed_max_execution_time);
  }

  public function get_plugin_name(string|false $plugin = false): string|false
  {
    if (!is_admin()) return false;

    $plugin_basename = (false !== $plugin ? $plugin : $this->plugin_basename);

    $plugins = get_plugins();

    if (!isset($plugins[$plugin_basename]['Name'])) {
      return false;
    }

    return $plugins[$plugin_basename]['Name'];
  }

  public function get_class_props(): array
  {
    return get_object_vars($this);
  }

  // Get only the table beginning with our DB prefix or temporary prefix, also skip views
  // Get only the table beginning with our DB prefix or temporary prefix, also skip views
  /**
   * @return string[]
   */
  public function get_tables(string $scope = 'regular'): array
  {
    global $wpdb;
    $prefix = ('temp' == $scope ? $this->temp_prefix : $wpdb->prefix);
    $tables = $wpdb->get_results('SHOW FULL TABLES', ARRAY_N);
    $clean_tables = [];
    foreach ($tables as $table) {
      if (('temp' == $scope || 'prefix' == $scope) && !str_starts_with((string) $table[0], (string) $prefix)) {
          continue;
      }
      if ('VIEW' == $table[1]) {
          continue;
      }

      $clean_tables[] = $table[0];
    }

    return apply_filters('wpsdb_tables', $clean_tables, $scope);
  }

  public function plugins_dir(): string
  {
    $path = untrailingslashit($this->plugin_dir_path);
    return substr($path, 0, strrpos($path, DIRECTORY_SEPARATOR)) . DIRECTORY_SEPARATOR;
  }

  public function get_plugin_file_path(): string
  {
    return $this->plugin_file_path;
  }

  public function set_cli_migration(): void
  {
    $this->doing_cli_migration = true;
  }

  public function end_ajax(string|false $return = false): ?string
  {
    if (defined('DOING_WPSDB_TESTS') || $this->doing_cli_migration) {
      return (false === $return) ? NULL : $return;
    }

    echo (false === $return) ? '' : $return;
    exit;
  }

  public function check_ajax_referer(string $action): void
  {
    if (defined('DOING_WPSDB_TESTS') || $this->doing_cli_migration) return;

    $result = check_ajax_referer($action, 'nonce', false);
    if (false === $result) {
      $return = ['wpsdb_error' => 1, 'body' => sprintf(__('Invalid nonce for: %s', 'wp-sync-db'), $action)];
      $this->end_ajax(json_encode($return));
    }

    $cap = (is_multisite()) ? 'manage_network_options' : 'export';
    $cap = apply_filters('wpsdb_ajax_cap', $cap);
    if (!current_user_can($cap)) {
      $return = ['wpsdb_error' => 1, 'body' => sprintf(__('Access denied for: %s', 'wp-sync-db'), $action)];
      $this->end_ajax(json_encode($return));
    }
  }

  public function set_translations(): void
  {
    $this->invalid_content_verification_error = __('Invalid content verification signature, please verify the connection information on the remote site and try again.', 'wp-sync-db');
  }
}
