<?php

/*
Plugin Name: WPU Import Vimeo
Plugin URI: https://github.com/WordPressUtilities/wpuimportvimeo
Version: 0.8.9
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
        add_action('template_redirect', array(&$this,
            'download_link'
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
        $this->import_draft = (isset($settings['import_draft']) && $settings['import_draft'] == '1');
        $this->options['admin_url'] = admin_url('edit.php?post_type=' . $this->post_type . '&page=' . $this->options['plugin_id']);
        $this->post_type_info = apply_filters('wpuimportvimeo_posttypeinfo', array(
            'public' => true,
            'name' => 'Video Vimeo',
            'label' => 'Video Vimeo',
            'plural' => 'Videos Vimeo',
            'female' => 1,
            'menu_icon' => 'dashicons-video-alt3'
        ));
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
            ),
            'import_draft' => array(
                'section' => 'import',
                'type' => 'checkbox',
                'label_check' => __('Posts are created with a draft status.', 'wpuimportvimeo'),
                'label' => __('Import as draft', 'wpuimportvimeo')
            )
        );
        if (is_admin()) {

            // Settings
            include 'inc/WPUBaseSettings.php';
            new \wpuimportvimeo\WPUBaseSettings($this->settings_details, $this->settings);

            // Meta box
            add_action('add_meta_boxes', array(&$this,
                'init_metabox'
            ));
            add_action('save_post', array(&$this,
                'save_metabox'
            ));

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
        add_action('admin_menu', array(&$this,
            'import_archives_iframe'
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
        return $wpdb->get_col("SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = 'wpuimportvimeo_id' ORDER BY meta_id DESC LIMIT 0,2000");
    }

    public function get_latest_videos_for_me($nb = 10, $only_data = true) {
        return $this->get_videos_for_me($nb, 1, 'desc', $only_data);
    }

    public function get_videos_for_me($nb = 10, $paged = 1, $order = 'desc', $only_data = true) {
        // Get videos
        $_url = 'https://api.vimeo.com/me/videos?page=' . $paged . '&direction=' . $order . '&per_page=' . $nb . '&access_token=' . $this->token;
        $_request = wp_remote_get($_url);
        if (!is_array($_request) || !isset($_request['body'])) {
            return false;
        }
        $_body = json_decode($_request['body']);
        if (!is_object($_body) || !isset($_body->data)) {
            return false;
        }
        if (!$only_data) {
            return $_body;
        }
        return $_body->data;
    }

    public function get_video_by_id($video_id) {
        // Get videos
        $_url = 'https://api.vimeo.com/videos/' . $video_id . '?access_token=' . $this->token;
        $_request = wp_remote_get($_url);
        if (!is_array($_request) || !isset($_request['body'])) {
            return false;
        }
        $_body = json_decode($_request['body']);
        if (!is_object($_body) || !isset($_body->uri)) {
            return false;
        }
        return $_body;
    }

    public function is_importing() {
        return get_transient('wpuimportvimeo__is_importing') !== false;
    }

    public function import() {
        // If importing : stop
        if ($this->is_importing()) {
            return false;
        }
        // Block other imports ( for two minutes max )
        set_transient('wpuimportvimeo__is_importing', 1, 5 * MINUTE_IN_SECONDS);
        // Launch import
        $import = $this->import_videos_to_posts($this->get_latest_videos_for_me());
        // Delete transient : Import has successfully finished
        delete_transient('wpuimportvimeo__is_importing');
        return $import;
    }

    public function import_videos_to_posts($_videos) {
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
        $video_id = $this->get_video_id_from_string($video->uri);
        if (in_array($video_id, $latest_videos_ids)) {
            return false;
        }

        // Create post
        $video_time = strtotime($video->modified_time);

        $video_post = array(
            'post_title' => $video->name,
            'post_content' => '' . $video->description,
            'post_date' => date('Y-m-d H:i:s', $video_time),
            'post_status' => $this->import_draft ? 'draft' : 'published',
            'post_author' => 1,
            'post_type' => $this->post_type
        );

        // Insert the post into the database
        $post_id = wp_insert_post($video_post);

        $this->update_metas_from_video($post_id, $video);
        $this->update_image_from_video($post_id, $video);

        do_action('wpuimportvimeo_postcreatedfromvideo', $post_id, $video);

        return $post_id;
    }

    public function get_video_id_from_string($source) {
        return preg_replace('/([^0-9]+)/isU', '', $source);
    }

    public function update_content_from_video($post_id, $video) {
        if (wp_is_post_revision($post_id)) {
            return;
        }
        wp_update_post(array(
            'ID' => $post_id,
            'post_title' => $video->name,
            'post_content' => $video->description
        ));
    }

    public function update_metas_from_video($post_id, $video) {
        $video_id = $this->get_video_id_from_string($video->uri);

        // Files links are available
        if (property_exists($video, 'files') && is_array($video->files) && !empty($video->files)) {
            // Sort video sources
            uasort($video->files, array(&$this, 'video_array_sort_by_width'));
            $sources = array();
            foreach ($video->files as $source) {
                if ($source->type != 'source' && $source->quality != 'hls') {
                    $sources[] = array(
                        'quality' => $source->quality,
                        'width' => $source->width,
                        'height' => $source->height,
                        'link_secure' => $source->link_secure,
                        'link' => $source->link
                    );
                }
            }
            update_post_meta($post_id, 'wpuimportvimeo_files', json_encode($sources));
        }

        // Set metas
        update_post_meta($post_id, 'wpuimportvimeo_id', $video_id);
        update_post_meta($post_id, 'wpuimportvimeo_link', $video->link);
        update_post_meta($post_id, 'wpuimportvimeo_width', $video->width);
        update_post_meta($post_id, 'wpuimportvimeo_height', $video->height);
        update_post_meta($post_id, 'wpuimportvimeo_duration', $video->duration);

        do_action('wpuimportvimeo_metasupdatedfromvideo', $post_id);

    }

    public function update_image_from_video($post_id, $video) {
        global $wpdb;
        // Add required classes
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

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
    }

    public function video_array_sort_by_width($a, $b) {
        if (!property_exists($a, 'width') || !property_exists($b, 'width')) {
            return 0;
        }
        if ($a->width == $b->width) {
            return 0;
        }
        return ($a->width < $b->width) ? -1 : 1;
    }

    /* ----------------------------------------------------------
      Download link
    ---------------------------------------------------------- */

    public function get_download_link($post_id) {
        return site_url() . '?download_vimeo_id=' . $post_id;
    }

    public function download_link() {
        if (!isset($_GET['download_vimeo_id']) || !is_numeric($_GET['download_vimeo_id'])) {
            return false;
        }
        $video_id = $this->get_video_id_from_string(get_post_meta($_GET['download_vimeo_id'], 'wpuimportvimeo_id', 1));
        $video = $this->get_video_by_id($video_id);

        // Check if downloadable
        if (!property_exists($video, 'download')) {
            wp_redirect(site_url());
            return false;
        }

        $width = 0;
        $link = '';

        // Return biggest source link
        foreach ($video->download as $video_dl) {
            if ($video_dl->quality == 'source') {
                continue;
            }
            if (property_exists($video_dl, 'width') && $video_dl->width > $width) {
                $width = $video_dl->width;
                $link = $video_dl->link;
            }
        }

        // Redirect to file
        if (!empty($link)) {
            wp_redirect($link);
            die;
        }
        wp_redirect(site_url());
        return false;
    }

    /* ----------------------------------------------------------
      Admin config
    ---------------------------------------------------------- */

    /* Meta box */

    public function init_metabox() {
        add_meta_box('reload_infos_metabox', __('Reload infos', 'wpuimportvimeo'), array(&$this, 'callback_display_metabox'), $this->post_type, 'side');
    }

    public function callback_display_metabox($post) {
        echo '<p><label><input type="checkbox" name="update_image" value="1" />' . __('Replace image', 'wpuimportvimeo') . '</label></p>';
        echo '<p><label><input type="checkbox" name="update_content" value="1" />' . __('Replace title & content', 'wpuimportvimeo') . '</label></p>';
        echo '<p>';
        submit_button(__('Reload', 'wpuimportvimeo'), 'primary', 'reload_vimeo', false);
        echo '</p>';
    }

    public function save_metabox($post_id) {
        remove_action('save_post', array(&$this,
            'save_metabox'
        ));
        if (isset($_POST['reload_vimeo'])) {
            $video_id = $this->get_video_id_from_string(get_post_meta(get_the_ID(), 'wpuimportvimeo_id', 1));
            $video = $this->get_video_by_id($video_id);
            if ($video !== false) {
                $this->update_metas_from_video($post_id, $video);
                if (isset($_POST['update_image'])) {
                    $this->update_image_from_video($post_id, $video);
                }
                if (isset($_POST['update_content'])) {
                    $this->update_content_from_video($post_id, $video);
                }
            }
        }
        add_action('save_post', array(&$this,
            'save_metabox'
        ));
    }

    /* Admin page */

    public function admin_menu() {
        add_submenu_page('edit.php?post_type=' . $this->post_type, $this->options['plugin_name'] . ' - ' . __('Settings'), __('Import Settings', 'wpuimportvimeo'), $this->options['plugin_userlevel'], $this->options['plugin_pageslug'], array(&$this,
            'admin_settings'
        ), '', 110);
    }

    public function admin_settings() {

        echo '<div class="wrap"><h1>' . get_admin_page_title() . '</h1>';
        settings_errors($this->settings_details['option_id']);
        if (!empty($this->token)) {
            echo '<h2>' . __('Tools') . '</h2>';
            echo '<form action="' . admin_url('admin-post.php') . '" method="post">';
            echo '<input type="hidden" name="action" value="wpuimportvimeo_postaction">';
            $schedule = wp_next_scheduled('wpuimportvimeo__cron_hook');
            $seconds = $schedule - time();
            $minutes = 0;
            if ($seconds >= 60) {
                $minutes = (int) ($seconds / 60);
                $seconds = $seconds % 60;
            }
            echo '<p>' . sprintf(__('Next automated import in %s’%s’’', 'wpuimportvimeo'), $minutes, $seconds) . '</p>';
            echo '<p class="submit">';
            if (!$this->is_importing()) {
                submit_button(__('Import now', 'wpuimportvimeo'), 'primary', 'import_now', false);
                echo ' ';
            }
            submit_button(__('Test API', 'wpuimportvimeo'), 'primary', 'test_api', false);
            echo '</p>';
            echo '</form>';
            echo '<hr />';
            if (!$this->is_importing()) {
                echo '<h2>' . __('Old videos', 'wpuimportvimeo') . '</h2>';
                echo '<iframe style="height:130px;width:100%;" src="' . wp_nonce_url(get_admin_url(null, '/?wpuimportvimeo_iframe=1'), 'wpuimportvimeo_archives') . '" frameborder="0"></iframe>';
                echo '<hr />';
            }
        }

        echo '<form action="' . admin_url('options.php') . '" method="post">';
        settings_fields($this->settings_details['option_id']);
        do_settings_sections($this->options['plugin_id']);
        echo submit_button(__('Save Changes', 'wpuimportvimeo'));
        echo '</form>';
        echo '</div>';
        echo '</div>';
    }

    public function import_archives_iframe() {
        // Check correct page
        if (!isset($_GET['wpuimportvimeo_iframe'])) {
            return;
        }
        // Nonce to avoid surprisesÒ
        check_admin_referer('wpuimportvimeo_archives');

        $_importPerPage = 10;
        $_paged = 0;
        $html = '';

        // Default form : start import
        if (isset($_GET['paged']) && is_numeric($_GET['paged'])) {
            $_paged = $_GET['paged'];

            // Import videos
            $_response = $this->get_videos_for_me($_importPerPage, $_GET['paged'], 'asc', false);
            if (!is_object($_response) || !is_array($_response->data)) {
                $html = '<p>' . __('Everything seems to be imported', 'wpuimportvimeo') . '</p>';
                if (!is_object($_response)) {
                    $html = '<p>' . __('There seems to be a problem with the API. Please try again in a moment.', 'wpuimportvimeo') . '</p>';
                }
                $this->display_iframe_html($html);
                die;
            }

            $_currentPageStart = ($_paged - 1) * $_importPerPage + count($_response->data);
            $this->import_videos_to_posts($_response->data);

            $html .= '<p>' . sprintf(__('Importing %s/%s', 'wpuimportvimeo'), $_currentPageStart, $_response->total) . '</p>';

        }

        // Display continue
        $html .= '<form id="wpuimportvimeo_archives_form" method="get" action="' . get_admin_url() . '">';
        $html .= '<input type="hidden" name="wpuimportvimeo_iframe" value="1">';
        $html .= '<input type="hidden" name="paged" value="' . ($_paged + 1) . '">';
        if ($_paged > 0) {
            $html .= '<p id="autoreload-message">' . sprintf(__('Autoreload in %s seconds', 'wpuimportvimeo'), 2) . '. <a onclick="clearTimeout(window.timeoutReload);this.parentNode.parentNode.removeChild(this.parentNode);return false;" href="#">' . __('Stop', 'wpuimportvimeo') . '</a></p>';
            $html .= '<script>window.timeoutReload = setTimeout(function(){';
            $html .= 'document.getElementById(\'wpuimportvimeo_archives_form\').submit();';
            $html .= 'document.getElementById(\'wpuimportvimeo_archives_form\').innerHTML = "' . __('Loading ...', 'wpuimportvimeo') . '";';
            $html .= '},2000);</script>';
        }
        if ($_paged > 0) {
            $html .= '<div style="opacity: 0.5;">';
        }
        ob_start();
        wp_nonce_field('wpuimportvimeo_archives', '_wpnonce', false);
        submit_button(__('Import old videos', 'wpuimportvimeo'), 'primary', 'import_now');
        $html .= ob_get_clean();
        if ($_paged > 0) {
            $html .= '</div>';
        }

        $html .= '</form>';

        $this->display_iframe_html($html);

        die;
    }

    public function display_iframe_html($html) {
        echo '<!DOCTYPE HTML><html lang="fr-FR"><head><meta charset="UTF-8" />';
        echo '<link rel="stylesheet" type="text/css" href="' . includes_url() . '/css/buttons.css?ver=' . date('dmYH') . '" />';
        echo '</head><body class="wp-core-ui" style="padding:0;margin:0;">';
        echo $html;
        echo '</body></html>';
    }

    public function postAction() {
        if (isset($_POST['import_now'])) {
            $nb_imports = $this->import();
            if ($nb_imports === false) {
                $this->messages->set_message('already_import', sprintf(__('An import is already running', 'wpuimportvimeo'), $nb_imports));
            } else {
                if ($nb_imports > 0) {
                    $this->messages->set_message('imported_nb', sprintf(__('Imported videos : %s', 'wpuimportvimeo'), $nb_imports));
                } else {
                    $this->messages->set_message('imported_0', __('No new imports', 'wpuimportvimeo'), 'created');
                }
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
            'name' => 'Vimeo metas',
            'post_type' => array($this->post_type)
        );
        return $boxes;
    }

    public function post_metas_fields($fields) {
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
        $fields['wpuimportvimeo_files'] = array(
            'box' => 'vimeo_settings',
            'name' => 'Video files',
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
                    'name' => 'Link'
                ),
                'link_secure' => array(
                    'name' => 'Link secure'
                )
            )
        );
        return $fields;
    }

    /* ----------------------------------------------------------
      Install
    ---------------------------------------------------------- */

    public function install() {
        wp_clear_scheduled_hook('wpuimportvimeo__cron_hook');
        wp_schedule_event(time() + 3600, 'hourly', 'wpuimportvimeo__cron_hook');
        flush_rewrite_rules();
    }

    public function deactivation() {
        wp_clear_scheduled_hook('wpuimportvimeo__cron_hook');
        flush_rewrite_rules();
    }

    public function uninstall() {
        delete_option($this->option_id);
        delete_post_meta_by_key('wpuimportvimeo_files');
        delete_post_meta_by_key('wpuimportvimeo_downloads');
        delete_post_meta_by_key('wpuimportvimeo_id');
        delete_post_meta_by_key('wpuimportvimeo_link');
        delete_post_meta_by_key('wpuimportvimeo_width');
        delete_post_meta_by_key('wpuimportvimeo_height');
        delete_post_meta_by_key('wpuimportvimeo_duration');
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
