<?php

/*
Plugin Name: WPU Import Vimeo
Plugin URI: https://github.com/WordPressUtilities/wpuimportvimeo
Version: 0.1
Description: Import latest vimeo videos.
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
*/

class WPUImportVimeo {
    private $token = '';
    private $post_type = '';
    public function __construct() {
        $this->token = get_option('wpuimportvimeo_token');
        $this->post_type = 'vimeo_videos';
        add_action('plugins_loaded', array(&$this,
            'plugins_loaded'
        ));
        add_action('init', array(&$this,
            'init'
        ));
    }

    public function init() {
        register_post_type($this->post_type, array(
            'public' => true,
            'label' => 'Videos Vimeo'
        ));
        //$this->import();
    }

    public function plugins_loaded() {
        // Options
        add_filter('wpu_options_tabs', array(&$this,
            'options_tabs'
        ), 11, 3);
        add_filter('wpu_options_boxes', array(&$this,
            'options_boxes'
        ), 11, 3);
        add_filter('wpu_options_fields', array(&$this,
            'options_fields'
        ), 11, 3);

        // Post metas
        add_filter('wputh_post_metas_boxes', array(&$this,
            'post_metas_boxes'
        ), 10, 3);
        add_filter('wputh_post_metas_fields', array(&$this,
            'post_metas_fields'
        ), 10, 3);
    }

    public function get_last_imported_videos_ids() {
        global $wpdb;
        return $wpdb->get_col("SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = 'wpuimportvimeo_id' ORDER BY meta_id DESC LIMIT 0,200");
    }

    public function import() {
        // Get videos
        $_url = 'https://api.vimeo.com/me/videos?access_token=' . $this->token;
        $_request = wp_remote_get($_url);
        if (!is_array($_request) || !isset($_request['body'])) {
            return false;
        }
        $_body = json_decode($_request['body']);
        if (!is_object($_body)) {
            return false;
        }
        $latest_videos_ids = $this->get_last_imported_videos_ids();
        foreach ($_body->data as $video) {
            $this->create_post_from_video($video, $latest_videos_ids);
        }
    }

    public function create_post_from_video($video, $latest_videos_ids) {
        $video_id = preg_replace('/([^0-9]+)/isU', '', $video->uri);
        if (in_array($video_id, $latest_videos_ids)) {
            return false;
        }

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

        // Set metas
        add_post_meta($post_id, 'wpuimportvimeo_id', $video_id);
        add_post_meta($post_id, 'wpuimportvimeo_link', $video->link);
        add_post_meta($post_id, 'wpuimportvimeo_width', $video->width);
        add_post_meta($post_id, 'wpuimportvimeo_height', $video->height);
        add_post_meta($post_id, 'wpuimportvimeo_duration', $video->duration);
    }

    /* ----------------------------------------------------------
      Options for config
    ---------------------------------------------------------- */

    public function options_tabs($tabs) {
        $tabs['vimeo_tab'] = array(
            'name' => 'Plugin : Import Vimeo'
        );
        return $tabs;
    }

    public function options_boxes($boxes) {
        $boxes['vimeo_config'] = array(
            'tab' => 'vimeo_tab',
            'name' => 'Import Vimeo'
        );
        return $boxes;
    }

    public function options_fields($options) {
        $options['wpuimportvimeo_token'] = array(
            'label' => __('Token'),
            'box' => 'vimeo_config'
        );
        return $options;
    }

    // Post metas
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
        return $fields;
    }
}

$WPUImportVimeo = new WPUImportVimeo();
