<?php

/*
Plugin Name: WPU Import Vimeo
Plugin URI: https://github.com/WordPressUtilities/wpuimportvimeo
Version: 0.3
Description: Import latest vimeo videos.
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
*/

class WPUImportVimeo {
    private $token = '';
    private $post_type = '';
    private $option_id = 'wpuimportvimeo_options';
    private $messages = array();
    public function __construct() {
        $this->set_options();
        add_action('plugins_loaded', array(&$this,
            'plugins_loaded'
        ));
        add_action('init', array(&$this,
            'init'
        ));
    }

    public function set_options() {

        $this->post_type = apply_filters('wpuimportvimeo_posttypehook', 'vimeo_videos');
        $this->options = array(
            'plugin_publicname' => 'Vimeo Import',
            'plugin_name' => 'Vimeo Import',
            'plugin_userlevel' => 'manage_options',
            'plugin_id' => 'wpuimportvimeo',
            'plugin_pageslug' => 'wpuimportvimeo'
        );
        $settings = get_option($this->option_id);
        if (isset($settings['token'])) {
            $this->token = $settings['token'];
        }
        $this->options['admin_url'] = admin_url('edit.php?post_type=' . $this->post_type . '&page=' . $this->options['plugin_id']);
        $this->post_type_info = array(
            'public' => true,
            'name' => 'Video Vimeo',
            'label' => 'Video Vimeo',
            'plural' => 'Videos Vimeo',
            'female' => 1,
            'menu_icon' => 'dashicons-video-alt3'
        );
    }

    public function init() {

        /* Post types */
        if (class_exists('wputh_add_post_types_taxonomies')) {
            add_filter('wputh_get_posttypes', array(&$this, 'wputh_set_theme_posttypes'));
        } else {
            register_post_type($this->post_type, $this->post_type_info);
        }

        /* Messages */
        if (is_admin()) {
            include 'inc/WPUBaseMessages.php';
            $this->messages = new \wpuimportvimeo\WPUBaseMessages($this->options['plugin_id']);
        }
        add_action('wpuimportvimeo_admin_notices', array(&$this->messages,
            'admin_notices'
        ));

        /* Settings */
        $this->settings_details = array(
            'plugin_id' => 'wpuimportvimeo',
            'option_id' => $this->option_id,
            'sections' => array(
                'import' => array(
                    'name' => __('Import Settings', 'wpuimportvimeo')
                )
            )
        );
        $this->settings = array(
            'token' => array(
                'section' => 'import',
                'label' => __('API Token', 'wpuimportvimeo')
            )
        );
        if (is_admin()) {
            include 'inc/WPUBaseSettings.php';
            new \wpuimportvimeo\WPUBaseSettings($this->settings_details, $this->settings);
        }
    }

    public function wputh_set_theme_posttypes($post_types) {
        $post_types[$this->post_type] = $this->post_type_info;
        return $post_types;
    }

    public function plugins_loaded() {
        if (!is_admin()) {
            return;
        }

        load_plugin_textdomain('wpuimportvimeo', false, dirname(plugin_basename(__FILE__)) . '/lang/');

        // Admin page
        add_action('admin_menu', array(&$this,
            'admin_menu'
        ));
        add_action('admin_post_wpuimportvimeo_postaction', array(&$this,
            'postAction'
        ));

        // Post metas
        add_filter('wputh_post_metas_boxes', array(&$this,
            'post_metas_boxes'
        ), 10, 3);
        add_filter('wputh_post_metas_fields', array(&$this,
            'post_metas_fields'
        ), 10, 3);
    }

    /* ----------------------------------------------------------
      Import
    ---------------------------------------------------------- */

    public function get_last_imported_videos_ids() {
        global $wpdb;
        return $wpdb->get_col("SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = 'wpuimportvimeo_id' ORDER BY meta_id DESC LIMIT 0,200");
    }

    public function get_latest_videos_for_me($nb = 10) {
        // Get videos
        $_url = 'https://api.vimeo.com/me/videos?per_page=' . $nb . '&access_token=' . $this->token;
        $_request = wp_remote_get($_url);
        if (!is_array($_request) || !isset($_request['body'])) {
            return false;
        }
        $_body = json_decode($_request['body']);
        if (!is_object($_body) || !isset($_body->data)) {
            return false;
        }
        return $_body->data;
    }

    public function import() {
        $_videos = $this->get_latest_videos_for_me();
        if (!is_array($_videos)) {
            return 0;
        }
        $latest_videos_ids = $this->get_last_imported_videos_ids();
        $nb_imports = 0;
        foreach ($_videos as $video) {
            $post_id = $this->create_post_from_video($video, $latest_videos_ids);
            if (is_numeric($post_id)) {
                $nb_imports++;
            }
        }
        return $nb_imports;
    }

    public function create_post_from_video($video, $latest_videos_ids) {
        global $wpdb;

        $video_id = preg_replace('/([^0-9]+)/isU', '', $video->uri);
        if (in_array($video_id, $latest_videos_ids)) {
            return false;
        }

        // Add required classes
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Create post
        $video_time = strtotime($video->modified_time);

        $video_post = array(
            'post_title' => $video->name,
            'post_content' => '' . $video->description,
            'post_date' => date('Y-m-d H:i:s', $video_time),
            'post_status' => 'draft',
            'post_author' => 1,
            'post_type' => $this->post_type
        );

        // Insert the post into the database
        $post_id = wp_insert_post($video_post);

        // Download links are available
        if (property_exists($video, 'download') && is_array($video->download) && !empty($video->download)) {
            // Sort video sources
            uasort($video->download, array(&$this, 'video_array_sort_by_width'));
            $sources = array();
            foreach ($video->download as $source) {
                if ($source->type != 'source') {
                    $sources[] = array(
                        'quality' => $source->quality,
                        'width' => $source->width,
                        'height' => $source->height,
                        'link' => $source->link
                    );
                }
            }
            add_post_meta($post_id, 'wpuimportvimeo_downloads', json_encode($sources));

        }

        // Set metas
        add_post_meta($post_id, 'wpuimportvimeo_id', $video_id);
        add_post_meta($post_id, 'wpuimportvimeo_link', $video->link);
        add_post_meta($post_id, 'wpuimportvimeo_width', $video->width);
        add_post_meta($post_id, 'wpuimportvimeo_height', $video->height);
        add_post_meta($post_id, 'wpuimportvimeo_duration', $video->duration);

        // Import thumbnail
        if (is_array($video->pictures->sizes) && !empty($video->pictures->sizes)) {

            $video_image = end($video->pictures->sizes);

            // Upload image
            $src = media_sideload_image($video_image->link, $post_id, $video->name, 'src');

            // Extract attachment id
            $att_id = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE guid='%s'",
                $src
            ));

            set_post_thumbnail($post_id, $att_id);

        }

        return $post_id;
    }

    public function video_array_sort_by_width($a, $b) {
        if ($a->width == $b->width) {
            return 0;
        }
        return ($a->width < $b->width) ? -1 : 1;
    }

    /* ----------------------------------------------------------
      Admin config
    ---------------------------------------------------------- */

    /* Admin page */

    public function admin_menu() {
        add_submenu_page('edit.php?post_type=' . $this->post_type, $this->options['plugin_name'] . ' - ' . __('Settings'), __('Import Settings', 'wpuimportvimeo'), $this->options['plugin_userlevel'], $this->options['plugin_pageslug'], array(&$this,
            'admin_settings'
        ), '', 110);
    }

    public function admin_settings() {

        echo '<div class="wrap"><h1>' . get_admin_page_title() . '</h1>';
        do_action('wpuimportvimeo_admin_notices');
        if (!empty($this->token)) {
            echo '<h2>' . __('Tools') . '</h2>';
            echo '<form action="' . admin_url('admin-post.php') . '" method="post">';
            echo '<input type="hidden" name="action" value="wpuimportvimeo_postaction">';
            $schedule = wp_next_scheduled('wpuimportvimeo__cron_hook');
            $seconds = $schedule - time();
            if ($seconds >= 60) {
                $minutes = (int) ($seconds / 60);
                $seconds = $seconds % 60;
            }
            echo '<p>' . sprintf(__('Next automated import in %s’%s’’', 'wpuimportvimeo'), $minutes, $seconds) . '</p>';
            echo '<p class="submit">';
            submit_button(__('Import now', 'wpuimportvimeo'), 'primary', 'import_now', false);
            echo ' ';
            submit_button(__('Test API', 'wpuimportvimeo'), 'primary', 'test_api', false);
            echo '</p>';
            echo '</form>';
            echo '<hr />';
        }

        echo '<form action="' . admin_url('options.php') . '" method="post">';
        settings_fields($this->settings_details['option_id']);
        do_settings_sections($this->options['plugin_id']);
        echo submit_button(__('Save Changes', 'wpuimportvimeo'));
        echo '</form>';
        echo '</div>';
        echo '</div>';
    }

    public function postAction() {
        if (isset($_POST['import_now'])) {
            $nb_imports = $this->import();
            if ($nb_imports > 0) {
                $this->messages->set_message('imported_nb', sprintf(__('Imported videos : %s', 'wpuimportvimeo'), $nb_imports));
            } else {
                $this->messages->set_message('imported_0', __('No new imports', 'wpuimportvimeo'), 'created');
            }
        }
        if (isset($_POST['test_api'])) {
            $videos = $this->get_latest_videos_for_me(1);
            if (is_array($videos) && !empty($videos)) {
                $this->messages->set_message('api_works', __('The API works great !', 'wpuimportvimeo'), 'created');
            } else {
                $this->messages->set_message('api_invalid', __('The credentials seems invalid or the user do not have videos.', 'wpuimportvimeo'), 'error');
            }
        }
        wp_safe_redirect(wp_get_referer());
        die();
    }

    /* Post metas */

    public function post_metas_boxes($boxes) {
        $boxes['vimeo_settings'] = array(
            'name' => 'Box name',
            'post_type' => array($this->post_type)
        );
        return $boxes;
    }

    public function post_metas_fields($fields) {
        $fields['wpuimportvimeo_id'] = array(
            'box' => 'vimeo_settings',
            'name' => 'Video Id'
        );
        $fields['wpuimportvimeo_link'] = array(
            'box' => 'vimeo_settings',
            'name' => 'Link'
        );
        $fields['wpuimportvimeo_width'] = array(
            'box' => 'vimeo_settings',
            'name' => 'Width'
        );
        $fields['wpuimportvimeo_height'] = array(
            'box' => 'vimeo_settings',
            'name' => 'Height'
        );
        $fields['wpuimportvimeo_duration'] = array(
            'box' => 'vimeo_settings',
            'name' => 'Duration'
        );
        $fields['wpuimportvimeo_downloads'] = array(
            'box' => 'vimeo_settings',
            'name' => 'Download links',
            'type' => 'table',
            'columns' => array(
                'quality' => array(
                    'name' => 'Quality'
                ),
                'width' => array(
                    'name' => 'Width'
                ),
                'height' => array(
                    'name' => 'Height'
                ),
                'link' => array(
                    'name' => 'link'
                )
            )
        );
        return $fields;
    }

    /* ----------------------------------------------------------
      Install
    ---------------------------------------------------------- */

    public function install() {
        wp_schedule_event(time(), 'hourly', 'wpuimportvimeo__cron_hook');
        flush_rewrite_rules();
    }

    public function deactivation() {
        wp_clear_scheduled_hook('wpuimportvimeo__cron_hook');
        flush_rewrite_rules();
    }

}

$WPUImportVimeo = new WPUImportVimeo();

register_activation_hook(__FILE__, array(&$WPUImportVimeo,
    'install'
));
register_deactivation_hook(__FILE__, array(&$WPUImportVimeo,
    'deactivation'
));

add_action('wpuimportvimeo__cron_hook', 'wpuimportvimeo__import');
function wpuimportvimeo__import() {
    global $WPUImportVimeo;
    $WPUImportVimeo->import();
}
