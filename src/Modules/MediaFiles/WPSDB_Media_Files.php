<?php
declare(strict_types=1);

namespace WPSDB\Modules\MediaFiles;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WP_Error;
use WPSDB\WPSDB_Base;

class WPSDB_Media_Files extends WPSDB_Base
{
  /** @var array<string, int> */
  protected array $files_to_migrate = [];

  protected bool $responding_to_get_remote_media_listing = false;

  public function __construct(string $plugin_file_path)
  {
    parent::__construct($plugin_file_path);

    /**
     * Fires after the advanced options section in the migration form.
     *
     * @since 1.0
     * @param WPSDB_Media_Files $this The WPSDB_Media_Files instance.
     */
    add_action('wpsdb_after_advanced_options', $this->migration_form_controls(...));

    /**
     * Fires when loading plugin assets.
     *
     * @since 1.0
     * @param WPSDB_Media_Files $this The WPSDB_Media_Files instance.
     */
    add_action('wpsdb_load_assets', $this->load_assets(...));

    /**
     * Filters the accepted profile fields.
     *
     * @since 1.0
     * @param string[] $profile_fields Array of accepted profile field names.
     * @return string[] Modified array of profile fields.
     */
    add_filter('wpsdb_accepted_profile_fields', $this->accepted_profile_fields(...));

    /**
     * Filters the data used to establish a remote connection.
     *
     * @since 1.0
     * @param array<string, mixed> $data Array of connection data.
     * @return array<string, mixed> Modified array of connection data.
     */
    add_filter('wpsdb_establish_remote_connection_data', $this->establish_remote_connection_data(...));

    /**
     * Filters the nonces used for AJAX requests.
     *
     * @since 1.0
     * @param array<string, string> $nonces Array of nonce names and values.
     * @return array<string, string> Modified array of nonces.
     */
    add_filter('wpsdb_nonces', $this->add_nonces(...));

    /**
     * Filters the CLI migration finalization outcome.
     *
     * @since 1.0
     * @param bool|WP_Error $outcome The migration outcome.
     * @param array<string, mixed> $profile The migration profile.
     * @param array<string, mixed> $verify_connection_response Connection verification response.
     * @param array<int, array<string, mixed>> $initiate_migration_response Migration initiation response.
     * @return bool|WP_Error The filtered migration outcome.
     */
    add_filter('wpsdb_cli_finalize_migration', $this->cli_migration(...), 10, 4);

    /**
     * Fires when handling AJAX request to determine media to migrate.
     *
     * @since 1.0
     * @param WPSDB_Media_Files $this The WPSDB_Media_Files instance.
     */
    add_action('wp_ajax_wpsdbmf_determine_media_to_migrate', $this->ajax_determine_media_to_migrate(...));

    /**
     * Fires when handling AJAX request to migrate media files.
     *
     * @since 1.0
     * @param WPSDB_Media_Files $this The WPSDB_Media_Files instance.
     */
    add_action('wp_ajax_wpsdbmf_migrate_media', $this->ajax_migrate_media(...));

    /**
     * Fires when handling unauthenticated AJAX request to get remote media listing.
     *
     * @since 1.0
     * @param WPSDB_Media_Files $this The WPSDB_Media_Files instance.
     */
    add_action('wp_ajax_nopriv_wpsdbmf_get_remote_media_listing', $this->respond_to_get_remote_media_listing(...));

    /**
     * Fires when handling unauthenticated AJAX request for push operations.
     *
     * @since 1.0
     * @param WPSDB_Media_Files $this The WPSDB_Media_Files instance.
     */
    add_action('wp_ajax_nopriv_wpsdbmf_push_request', $this->respond_to_push_request(...));

    /**
     * Fires when handling unauthenticated AJAX request to remove local attachments.
     *
     * @since 1.0
     * @param WPSDB_Media_Files $this The WPSDB_Media_Files instance.
     */
    add_action('wp_ajax_nopriv_wpsdbmf_remove_local_attachments', $this->respond_to_remove_local_attachments(...));
  }

  public function get_local_attachments(): array
  {
    global $wpdb;
    $prefix = $wpdb->prefix;
    $temp_prefix = stripslashes((string) $_POST['temp_prefix']);

    /*
		* We determine which media files need migrating BEFORE the database migration is finalized.
		* Because of this we need to scan the *_post & *_postmeta that are prefixed using the temporary prefix.
		* Though this should only happen when we're responding to a get_remote_media_listing() call AND it's a push OR
		* we're scanning local files AND it's a pull.
		*/

    if (
      (true == $this->responding_to_get_remote_media_listing && $_POST['intent'] == 'push') ||
      (false == $this->responding_to_get_remote_media_listing && $_POST['intent'] == 'pull')
    ) {

      $local_tables = array_flip($this->get_tables());

      $posts_table_name = sprintf('%s%sposts', $temp_prefix, $prefix);
      $postmeta_table_name = sprintf('%s%spostmeta', $temp_prefix, $prefix);

      if (isset($local_tables[$posts_table_name]) && isset($local_tables[$postmeta_table_name])) {
        $prefix = $temp_prefix . $prefix;
      }
    }

    $local_attachments = $wpdb->get_results(
      "SELECT `{$prefix}posts`.`post_modified_gmt` AS 'date', pm1.`meta_value` AS 'file', pm2.`meta_value` AS 'metadata'
			FROM `{$prefix}posts`
			INNER JOIN `{$prefix}postmeta` pm1 ON `{$prefix}posts`.`ID` = pm1.`post_id` AND pm1.`meta_key` = '_wp_attached_file'
			LEFT OUTER JOIN `{$prefix}postmeta` pm2 ON `{$prefix}posts`.`ID` = pm2.`post_id` AND pm2.`meta_key` = '_wp_attachment_metadata'
			WHERE `{$prefix}posts`.`post_type` = 'attachment'",
      ARRAY_A
    );

    if (is_multisite()) {
      $blogs = $this->get_blogs();
      $prefix = $wpdb->prefix;
      foreach ($blogs as $blog) {
        $posts_table_name = sprintf('%s%s%s_posts', $temp_prefix, $prefix, $blog);
        $postmeta_table_name = sprintf('%s%s%s_postmeta', $temp_prefix, $prefix, $blog);
        if (isset($local_tables[$posts_table_name]) && isset($local_tables[$postmeta_table_name])) {
          $prefix = $temp_prefix . $prefix;
        }

        $attachments = $wpdb->get_results(
          "SELECT `{$prefix}{$blog}_posts`.`post_modified_gmt` AS 'date', pm1.`meta_value` AS 'file', pm2.`meta_value` AS 'metadata', {$blog} AS 'blog_id'
					FROM `{$prefix}{$blog}_posts`
					INNER JOIN `{$prefix}{$blog}_postmeta` pm1 ON `{$prefix}{$blog}_posts`.`ID` = pm1.`post_id` AND pm1.`meta_key` = '_wp_attached_file'
					LEFT OUTER JOIN `{$prefix}{$blog}_postmeta` pm2 ON `{$prefix}{$blog}_posts`.`ID` = pm2.`post_id` AND pm2.`meta_key` = '_wp_attachment_metadata'
					WHERE `{$prefix}{$blog}_posts`.`post_type` = 'attachment'",
          ARRAY_A
        );

        $local_attachments = array_merge($attachments, $local_attachments);
      }
    }

    $local_attachments = array_map($this->process_attachment_data(...), $local_attachments);

    return array_filter($local_attachments);
  }

  /**
   * @return mixed[]
   */
  /**
   * @param array<int, array<string, mixed>> $attachments
   * @return string[]
   */
  public function get_flat_attachments(array $attachments): array
  {
    $flat_attachments = [];
    foreach ($attachments as $attachment) {
      $flat_attachments[] = $attachment['file'];
      if (isset($attachment['sizes'])) {
        $flat_attachments = array_merge($flat_attachments, $attachment['sizes']);
      }
    }

    return $flat_attachments;
  }

  public function process_attachment_data(array $attachment): array
  {
    if (isset($attachment['blog_id'])) { // used for multisite
      if (defined('UPLOADBLOGSDIR')) {
        $upload_dir = sprintf('%s/files/', $attachment['blog_id']);
      } else {
        $upload_dir = sprintf('sites/%s/', $attachment['blog_id']);
      }

      $attachment['file'] = $upload_dir . $attachment['file'];
    }

    $upload_dir = str_replace(basename((string) $attachment['file']), '', $attachment['file']);
    if (! empty($attachment['metadata'])) {
      $attachment['metadata'] = @unserialize($attachment['metadata']);
      if (! empty($attachment['metadata']['sizes']) && is_array($attachment['metadata']['sizes'])) {
        foreach ($attachment['metadata']['sizes'] as $size) {
          if (empty($size['file'])) continue;

          $attachment['sizes'][] = $upload_dir . $size['file'];
        }
      }
    }

    unset($attachment['metadata']);
    return $attachment;
  }

  public function uploads_dir(): string
  {
    if (defined('UPLOADBLOGSDIR')) {
      $upload_dir = trailingslashit(ABSPATH) . UPLOADBLOGSDIR;
    } else {
      $upload_dir = wp_upload_dir();
      $upload_dir = $upload_dir['basedir'];
    }

    return trailingslashit($upload_dir);
  }

  /**
   * @return mixed[]
   */
  public function get_local_media(): array
  {
    $upload_dir = untrailingslashit($this->uploads_dir());
    if (! file_exists($upload_dir)) return [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($upload_dir), RecursiveIteratorIterator::SELF_FIRST);
    $local_media = [];

    foreach ($files as $name => $object) {
      $name = str_replace([$upload_dir . DIRECTORY_SEPARATOR, '\\'], ['', '/'], $name);
      $local_media[$name] = $object->getSize();
    }

    return $local_media;
  }

  public function ajax_migrate_media(): mixed
  {
    $this->check_ajax_referer('migrate-media');
    $this->set_time_limit();

    if ($_POST['intent'] == 'pull') {
      return $this->process_pull_request();
    }
    return $this->process_push_request();
  }

  public function process_pull_request(): mixed
  {
    $files_to_download = $_POST['file_chunk'];
    $remote_uploads_url = trailingslashit($_POST['remote_uploads_url']);
    $parsed = parse_url((string) $_POST['url']);
    if (isset($parsed['user']) && ($parsed['user'] !== '' && $parsed['user'] !== '0')) {
      $credentials = sprintf('%s:%s@', $parsed['user'], $parsed['pass']);
      $remote_uploads_url = str_replace('://', '://' . $credentials, $remote_uploads_url);
    }

    $upload_dir = $this->uploads_dir();

    $errors = [];
    foreach ($files_to_download as $file_to_download) {
      $temp_file_path = $this->download_url($remote_uploads_url . $file_to_download);

      if (is_wp_error($temp_file_path)) {
        $download_error = $temp_file_path->get_error_message();
        $errors[] = __(sprintf('Could not download file: %1$s - %2$s', $remote_uploads_url . $file_to_download, $download_error), 'wp-sync-db-media-files');
        continue;
      }

      $date = str_replace(basename((string) $file_to_download), '', $file_to_download);
      $new_path = $upload_dir . $date . basename((string) $file_to_download);

      $move_result = @rename($temp_file_path, $new_path);

      if (false === $move_result) {
        $folder = dirname($new_path);
        if (@file_exists($folder)) {
          $errors[] =  __(sprintf('Error attempting to move downloaded file. Temp path: %1$s - New Path: %2$s', $temp_file_path, $new_path), 'wp-sync-db-media-files') . ' (#103mf)';
        } else {
          if (false === @mkdir($folder, 0755, true)) {
            $errors[] =  __(sprintf('Error attempting to create required directory: %s', $folder), 'wp-sync-db-media-files') . ' (#104mf)';
          } else {
            $move_result = @rename($temp_file_path, $new_path);
            if (false === $move_result) {
              $errors[] =  __(sprintf('Error attempting to move downloaded file. Temp path: %1$s - New Path: %2$s', $temp_file_path, $new_path), 'wp-sync-db-media-files') . ' (#105mf)';
            }
          }
        }
      }
    }

    if ($errors !== []) {
      $return = [
        'wpsdb_error'  => 1,
        'body'      => implode('<br />', $errors) . '<br />'
      ];
      return $this->end_ajax(json_encode($return));
    }

    // not required, just here because we have to return something otherwise the AJAX fails
    $return['success'] = 1;
    return $this->end_ajax(json_encode($return));
  }

  public function process_push_request(): mixed
  {
    $files_to_migrate = $_POST['file_chunk'];

    $upload_dir = $this->uploads_dir();

    $body = '';
    foreach ($files_to_migrate as $file_to_migrate) {
      $body .= $this->file_to_multipart($upload_dir . $file_to_migrate);
    }

    $post_args = [
      'action'  => 'wpsdbmf_push_request',
      'files'    => serialize($files_to_migrate)
    ];

    $post_args['sig'] = $this->create_signature($post_args, $_POST['key']);

    $body .= $this->array_to_multipart($post_args);

    $args['body'] = $body;
    $ajax_url = trailingslashit($_POST['url']) . 'wp-admin/admin-ajax.php';
    $response = $this->remote_post($ajax_url, [], __FUNCTION__, $args);
    $response = $this->verify_remote_post_response($response);
    return $this->end_ajax(json_encode($response));
  }

  public function respond_to_push_request(): mixed
  {
    $filtered_post = $this->filter_post_elements($_POST, ['action', 'files']);
    $filtered_post['files'] = stripslashes((string) $filtered_post['files']);
    if (! $this->verify_signature($filtered_post, $this->settings['key'])) {
      $return = [
        'wpsdb_error'   => 1,
        'body'      => $this->invalid_content_verification_error . ' (#101mf)',
      ];
      return $this->end_ajax(serialize($return));
    }

    if (! isset($_FILES['media'])) {
      $return = [
        'wpsdb_error'   => 1,
        'body'      => __('$_FILES is empty, the upload appears to have failed', 'wp-sync-db-media-files') . ' (#106mf)',
      ];
      return $this->end_ajax(serialize($return));
    }

    $upload_dir = $this->uploads_dir();

    $files = $this->diverse_array($_FILES['media']);
    $file_paths = unserialize($filtered_post['files']);
    $i = 0;
    $errors = [];
    foreach ($files as &$file) {
      $destination = $upload_dir . $file_paths[$i];
      $folder = dirname($destination);

      if (false === @file_exists($folder) && false === @mkdir($folder, 0755, true)) {
        $errors[] = __(sprintf('Error attempting to create required directory: %s', $folder), 'wp-sync-db-media-files') . ' (#108mf)';
        ++$i;
        continue;
      }

      if (false === @move_uploaded_file($file['tmp_name'], $destination)) {
        $errors[] = __(sprintf('A problem occurred when attempting to move the temp file "%1$s" to "%2$s"', $file['tmp_name'], $destination), 'wp-sync-db-media-files') . ' (#107mf)';
      }

      ++$i;
    }

    $return = ['success' => 1];
    if ($errors !== []) {
      $return = [
        'wpsdb_error'   => 1,
        'body'      => implode('<br />', $errors) . '<br />'
      ];
    }

    return $this->end_ajax(serialize($return));
  }

  public function ajax_determine_media_to_migrate(): mixed
  {
    $this->check_ajax_referer('determine-media-to-migrate');
    $this->set_time_limit();

    $local_attachments = $this->get_local_attachments();
    $local_media = $this->get_local_media();

    $data = [];
    $data['action'] = 'wpsdbmf_get_remote_media_listing';
    $data['temp_prefix'] = $this->temp_prefix;
    $data['intent'] = $_POST['intent'];
    $data['sig'] = $this->create_signature($data, $_POST['key']);
    $ajax_url = trailingslashit($_POST['url']) . 'wp-admin/admin-ajax.php';
    $response = $this->remote_post($ajax_url, $data, __FUNCTION__);
    $response = $this->verify_remote_post_response($response);

    $remote_attachments = $response['remote_attachments'];
    $remote_media = $response['remote_media'];

    $this->files_to_migrate = [];

    if ($_POST['intent'] == 'pull') {
      $this->media_diff($local_attachments, $remote_attachments, $local_media, $remote_media);
    } else {
      $this->media_diff($remote_attachments, $local_attachments, $remote_media, $local_media);
    }

    $return['files_to_migrate'] = $this->files_to_migrate;
    $return['total_size'] = array_sum($this->files_to_migrate);
    $return['remote_uploads_url'] = $response['remote_uploads_url'];

    // remove local/remote media if it doesn't exist on the local/remote site
    if ($_POST['remove_local_media'] == '1') {
      if ($_POST['intent'] == 'pull') {
        $this->remove_local_attachments($remote_attachments);
      } else {
        $data = [];
        $data['action'] = 'wpsdbmf_remove_local_attachments';
        $data['remote_attachments'] = serialize($local_attachments);
        $data['sig'] = $this->create_signature($data, $_POST['key']);
        $ajax_url = trailingslashit($_POST['url']) . 'wp-admin/admin-ajax.php';
        $response = $this->remote_post($ajax_url, $data, __FUNCTION__);
        // the response is ignored here (for now) as this is not a critical task
      }
    }
    return $this->end_ajax(json_encode($return));
  }

  public function respond_to_remove_local_attachments(): string
  {
    $filtered_post = $this->filter_post_elements($_POST, ['action', 'remote_attachments']);
    $filtered_post['remote_attachments'] = stripslashes((string) $filtered_post['remote_attachments']);
    if (! $this->verify_signature($filtered_post, $this->settings['key'])) {
      $return = [
        'wpsdb_error'   => 1,
        'body'      => $this->invalid_content_verification_error . ' (#109mf)',
      ];
      return $this->end_ajax(serialize($return));
    }

    $remote_attachments = @unserialize($filtered_post['remote_attachments']);
    if (false === $remote_attachments) {
      $return = [
        'wpsdb_error'   => 1,
        'body'      => __('Error attempting to unserialize the remote attachment data', 'wp-sync-db-media-files') . ' (#110mf)',
      ];
      return $this->end_ajax(serialize($return));
    }

    $this->remove_local_attachments($remote_attachments);

    $return = [
      'success'   => 1,
    ];
    return serialize(json_encode($return));
  }

  /**
   * @param array<int, array<string, mixed>> $remote_attachments
   */
  public function remove_local_attachments(array $remote_attachments): void
  {
    $flat_remote_attachments = array_flip($this->get_flat_attachments($remote_attachments));
    $local_media = $this->get_local_media();
    // remove local media if it doesn't exist on the remote site
    $temp_local_media = array_keys($local_media);
    $allowed_mime_types = array_flip(get_allowed_mime_types());
    $upload_dir = $this->uploads_dir();
    foreach ($temp_local_media as $temp_local_medium) {
      // don't remove folders
      if (false === is_file($upload_dir . $temp_local_medium)) continue;

      $filetype = wp_check_filetype($temp_local_medium);
      // don't remove files that we shouldn't remove, e.g. .php, .sql, etc
      if (false === isset($allowed_mime_types[$filetype['type']])) continue;

      // don't remove files that exist on the remote site
      if (true === isset($flat_remote_attachments[$temp_local_medium])) continue;

      @unlink($upload_dir . $temp_local_medium);
    }
  }

  /**
   * @param array<int, array<string, mixed>> $site_a_attachments
   * @param array<int, array<string, mixed>> $site_b_attachments
   * @param array<string, int> $site_a_media
   * @param array<string, int> $site_b_media
   */
  public function media_diff(array $site_a_attachments, array $site_b_attachments, array $site_a_media, array $site_b_media): void
  {
    foreach ($site_b_attachments as $site_b_attachment) {
      $local_attachment_key = $this->multidimensional_search(['file' => $site_b_attachment['file']], $site_a_attachments);
      if (false === $local_attachment_key) continue;

      $remote_timestamp = strtotime((string) $site_b_attachment['date']);
      $local_timestamp = strtotime((string) $site_a_attachments[$local_attachment_key]['date']);
      if ($local_timestamp >= $remote_timestamp) {
        if (! isset($site_a_media[$site_b_attachment['file']])) {
          $this->add_files_to_migrate($site_b_attachment, $site_b_media);
        } else {
          $this->maybe_add_resized_images($site_b_attachment, $site_b_media, $site_a_media);
        }
      } else {
        $this->add_files_to_migrate($site_b_attachment, $site_b_media);
      }
    }
  }

  public function add_files_to_migrate(array $attachment, array $remote_media): void
  {
    if (isset($remote_media[$attachment['file']])) {
      $this->files_to_migrate[$attachment['file']] = $remote_media[$attachment['file']];
    }

    if (empty($attachment['sizes']) || apply_filters('wpsdb_exclude_resized_media', false)) return;

    foreach ($attachment['sizes'] as $size) {
      if (isset($remote_media[$size])) {
        $this->files_to_migrate[$size] = $remote_media[$size];
      }
    }
  }

  public function maybe_add_resized_images(array $attachment, array $site_b_media, array $site_a_media): void
  {
    if (empty($attachment['sizes']) || apply_filters('wpsdb_exclude_resized_media', false)) return;

    foreach ($attachment['sizes'] as $size) {
      if (isset($site_b_media[$size]) && ! isset($site_a_media[$size])) {
        $this->files_to_migrate[$size] = $site_b_media[$size];
      }
    }
  }

  public function respond_to_get_remote_media_listing(): mixed
  {
    $filtered_post = $this->filter_post_elements($_POST, ['action', 'temp_prefix', 'intent']);
    if (! $this->verify_signature($filtered_post, $this->settings['key'])) {
      $return = [
        'wpsdb_error'   => 1,
        'body'      => $this->invalid_content_verification_error . ' (#100mf)',
      ];
      return $this->end_ajax(serialize($return));
    }

    if (defined('UPLOADBLOGSDIR')) {
      $upload_url = home_url(UPLOADBLOGSDIR);
    } else {
      $upload_dir = wp_upload_dir();
      $upload_url = $upload_dir['baseurl'];
    }

    $this->responding_to_get_remote_media_listing = true;

    $return['remote_attachments'] = $this->get_local_attachments();
    $return['remote_media'] = $this->get_local_media();
    $return['remote_uploads_url'] = $upload_url;
    return $this->end_ajax(serialize($return));
  }

  public function migration_form_controls(): void
  {
    $this->template('migrate');
  }

  /**
   * @param string[] $profile_fields
   * @return string[]
   */
  public function accepted_profile_fields(array $profile_fields): array
  {
    $profile_fields[] = 'media_files';
    $profile_fields[] = 'remove_local_media';
    return $profile_fields;
  }

  public function load_assets(): void
  {
    // @phpstan-ignore-next-line - WPSDB_ROOT is defined in wp-sync-db.php bootstrap
    $src = WPSDB_ROOT . 'asset/js/media-files.js';
    // @phpstan-ignore-next-line - WordPress constant pattern
    $version = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? time() : $this->plugin_version;
    wp_enqueue_script('wp-sync-db-media-files-script', $src, ['jquery', 'wp-sync-db-common', 'wp-sync-db-hook', 'wp-sync-db-script'], $version, true);

    wp_localize_script('wp-sync-db-media-files-script', 'wpsdbmf_strings', [
      'determining'        => __("Determining which media files to migrate, please wait...", 'wp-sync-db-media-files'),
      'error_determining'      => __("Error while attempting to determine which media files to migrate.", 'wp-sync-db-media-files'),
      'migration_failed'      => __("Migration failed", 'wp-sync-db-media-files'),
      'problem_migrating_media'  => __("A problem occurred when migrating the media files.", 'wp-sync-db-media-files'),
      'media_files'        => __("Media Files", 'wp-sync-db-media-files'),
      'migrating_media_files'    => __("Migrating media files", 'wp-sync-db-media-files'),
    ]);
  }

  public function establish_remote_connection_data(array $data): array
  {
    $data['media_files_available'] = '1';
    $data['media_files_version'] = $this->plugin_version;
    $max_file_uploads = false;
    if (function_exists('ini_get')) {
      $max_file_uploads = ini_get('max_file_uploads');
    }

    $max_file_uploads = ($max_file_uploads === '' || $max_file_uploads === '0' || $max_file_uploads === false) ? 20 : $max_file_uploads;
    $data['media_files_max_file_uploads'] = apply_filters('wpsdbmf_max_file_uploads', $max_file_uploads);
    return $data;
  }

  /**
   * @param array<string, mixed> $needle
   * @param array<int, array<string, mixed>> $haystack
   * @return int|false
   */
  public function multidimensional_search(array $needle, array $haystack): int|false
  {
    if (empty($needle) || empty($haystack)) return false;

    foreach ($haystack as $key => $value) {
      foreach ($needle as $skey => $svalue) {
        $exists = (isset($haystack[$key][$skey]) && $haystack[$key][$skey] === $svalue);
      }

      if ($exists) return $key;
    }

    return false;
  }

  /**
   * @return mixed[]
   */
  public function get_blogs(): array
  {
    global $wpdb;

    $blogs = $wpdb->get_results(
      "SELECT blog_id
			FROM {$wpdb->blogs}
			WHERE spam = '0'
			AND deleted = '0'
			AND archived = '0'
			AND blog_id != 1
		"
    );

    $clean_blogs = [];
    foreach ($blogs as $blog) {
      $clean_blogs[] = $blog->blog_id;
    }

    return $clean_blogs;
  }

  public function download_url(string $url, int $timeout = 300): string|WP_Error
  {
    //WARNING: The file is not automatically deleted, The script must unlink() the file.
    if (! $url)
      return new WP_Error('http_no_url', __('Invalid URL Provided.'));

    $tmpfname = wp_tempnam($url);
    if (! $tmpfname)
      return new WP_Error('http_no_file', __('Could not create Temporary file.'));

    $response = wp_remote_get($url, ['timeout' => $timeout, 'stream' => true, 'filename' => $tmpfname, 'reject_unsafe_urls' => false]);

    if (is_wp_error($response)) {
      unlink($tmpfname);
      return $response;
    }

    if (200 != wp_remote_retrieve_response_code($response)) {
      unlink($tmpfname);
      return new WP_Error('http_404', trim(wp_remote_retrieve_response_message($response)));
    }

    return $tmpfname;
  }

  /**
   * @param string|false $response
   */
  public function verify_remote_post_response($response): mixed
  {
    if (false === $response) {
      $return = ['wpsdb_error' => 1, 'body' => $this->error];
      return $this->end_ajax(json_encode($return));
    }

    if (! is_serialized(trim((string) $response))) {
      $return = ['wpsdb_error'  => 1, 'body' => $response];
      return $this->end_ajax(json_encode($return));
    }

    $response = unserialize(trim((string) $response));

    if (isset($response['wpsdb_error'])) {
      return $this->end_ajax(json_encode($response));
    }

    return $response;
  }

  public function add_nonces(array $nonces): array
  {
    $nonces['migrate_media'] = wp_create_nonce('migrate-media');
    $nonces['determine_media_to_migrate'] = wp_create_nonce('determine-media-to-migrate');
    return $nonces;
  }

  /**
   * @param bool|WP_Error $outcome
   * @param array<int, array<string, mixed>> $initiate_migration_response
   * @return bool|WP_Error
   */
  public function cli_migration(bool|WP_Error $outcome, array $profile, array $verify_connection_response, array $initiate_migration_response): bool|WP_Error
  {
    global $wpsdb, $wpsdb_cli;
    if (true !== $outcome) return $outcome;

    if (!isset($profile['media_files']) || '1' !== $profile['media_files']) return $outcome;

    if (!isset($verify_connection_response['media_files_max_file_uploads'])) {
      return $wpsdb_cli->cli_error(__('WP Sync DB Media Files does not seems to be installed/active on the remote website.', 'wp-sync-db-media-files'));
    }

    $this->set_time_limit();
    $wpsdb->set_cli_migration();
    $this->set_cli_migration();

    $connection_info = explode("\n", (string) $profile['connection_info']);

    $_POST['intent'] = $profile['action'];
    $_POST['url'] = trim($connection_info[0]);
    $_POST['key'] = trim($connection_info[1]);
    $_POST['remove_local_media'] = (isset($profile['remove_local_media'])) ? 1 : 0;
    $_POST['temp_prefix'] = $verify_connection_response['temp_prefix'];

    do_action('wpsdb_cli_before_determine_media_to_migrate', $profile, $verify_connection_response, $initiate_migration_response);

    $response = $this->ajax_determine_media_to_migrate();
    if (is_wp_error($determine_media_to_migrate_response = $wpsdb_cli->verify_cli_response($response, 'ajax_determine_media_to_migrate()'))) return $determine_media_to_migrate_response;

    $remote_uploads_url = $determine_media_to_migrate_response['remote_uploads_url'];
    $files_to_migrate = $determine_media_to_migrate_response['files_to_migrate'];
    // seems like this value needs to be different depending on pull/push?
    $bottleneck = $wpsdb->get_bottleneck();

    while (!empty($files_to_migrate)) {
      $file_chunk_to_migrate = [];
      $file_chunk_size = 0;
      $number_of_files_to_migrate = 0;
      foreach ($files_to_migrate as $file_to_migrate => $file_size) {
        if ($file_chunk_to_migrate === []) {
          $file_chunk_to_migrate[] = $file_to_migrate;
          $file_chunk_size += $file_size;
          unset($files_to_migrate[$file_to_migrate]);
          ++$number_of_files_to_migrate;
        } else {
          if (($file_chunk_size + $file_size) > $bottleneck || $number_of_files_to_migrate >= $verify_connection_response['media_files_max_file_uploads']) {
            break;
          } else {
            $file_chunk_to_migrate[] = $file_to_migrate;
            $file_chunk_size += $file_size;
            unset($files_to_migrate[$file_to_migrate]);
            ++$number_of_files_to_migrate;
          }
        }

        $_POST['file_chunk'] = $file_chunk_to_migrate;
        $_POST['remote_uploads_url'] = $remote_uploads_url;

        $response = $this->ajax_migrate_media();
        if (is_wp_error($migrate_media_response = $wpsdb_cli->verify_cli_response($response, 'ajax_migrate_media()'))) return $migrate_media_response;
      }
    }

    return true;
  }
}
