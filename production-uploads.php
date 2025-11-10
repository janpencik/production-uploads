<?php
/**
 * Plugin Name: Production Uploads
 * Description: Replaces local upload URLs for development. Works on front-end, Gutenberg, and in Patterns.
 * Version: 1.9
 * Author: Jan Pěnčík
 */

// Don't allow direct access
if (!defined('ABSPATH')) {
    exit;
}

class Local_Prod_Upload_Replacer {

    private $local_base_url;
    private $production_base_url;
    private $option_name = 'lpu_production_url';
    private $field_id = 'lpu_production_url_field';

    /**
     * Constructor.
     */
    public function __construct() {
        $this->production_base_url = esc_url_raw(get_option($this->option_name, ''));
        $uploads_dir = wp_get_upload_dir(); 
        $this->local_base_url = $uploads_dir['baseurl'];

        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_notices', [$this, 'show_admin_notice']);

        if (!empty($this->production_base_url) && $this->local_base_url !== $this->production_base_url) {
            $this->init_filters();
        }
    }

    /**
     * Initializes all the URL replacement filters.
     */
    public function init_filters() {
        // --- Core WordPress Filters (Frontend & Backend) ---
        add_filter('wp_get_attachment_url', [$this, 'replace_url'], 10);
        add_filter('wp_get_attachment_image_src', [$this, 'filter_image_src'], 10, 4);
        add_filter('wp_get_attachment_image_srcset', [$this, 'filter_image_srcset'], 10, 5);

        // --- Gutenberg & Admin Filters ---
        add_filter('wp_prepare_attachment_for_js', [$this, 'filter_media_modal_data'], 10, 3);
        add_filter('rest_prepare_attachment', [$this, 'filter_rest_api_data'], 10, 3);
        
        // --- The Essential Fix for Gutenberg Posts/Pages ---
        add_filter('rest_prepare_post', [$this, 'filter_post_content_for_editor'], 10, 3);
        add_filter('rest_prepare_page', [$this, 'filter_post_content_for_editor'], 10, 3);

        // --- [NEW] The Fix for Reusable Blocks (Synced Patterns) ---
        add_filter('rest_prepare_wp_block', [$this, 'filter_post_content_for_editor'], 10, 3);
        
        // --- [NEW] The Fix for Block Patterns (Theme/Plugin Patterns) ---
        add_filter('rest_request_after_callbacks', [$this, 'filter_block_pattern_rest_response'], 10, 3);


        // --- Frontend Catch-all ---
        add_filter('the_content', [$this, 'filter_frontend_content'], 999);
    }

    /**
     * Registers the setting and adds the field to the Settings > Media page.
     */
    public function register_settings() {
        register_setting('media', $this->option_name, ['sanitize_callback' => 'esc_url_raw']);
        add_settings_field(
            $this->field_id,
            'Production Uploads URL',
            [$this, 'render_settings_field'],
            'media',
            'default'
        );
    }

    /**
     * Renders the HTML for the settings field.
     */
    public function render_settings_field() {
        $value = get_option($this->option_name, '');
        echo '<input type="text" name="' . esc_attr($this->option_name) . '" id="' . esc_attr($this->field_id) . '" value="' . esc_attr($value) . '" class="regular-text" placeholder="https://www.yourdomain.com/uploads">';
        echo '<p class="description">Enter the full URL to your production uploads folder. Images will be loaded from here.</p>';
    }

    /**
     * Shows an admin notice if the production URL is not set.
     */
    public function show_admin_notice() {
        if (!empty($this->production_base_url)) {
            return;
        }
        $screen = get_current_screen();
        if ($screen && $screen->id === 'options-media') {
            return;
        }
        $settings_url = admin_url('options-media.php#' . $this->field_id);
        $message = sprintf(
            '<strong>Production Uploads:</strong> The Production URL is not set. Please <a href="%s">go to Media Settings</a> to add it.',
            esc_url($settings_url)
        );
        printf('<div class="notice notice-error is-dismissible"><p>%s</p></div>', $message);
    }

    // --- All Filter Callback Functions ---

    public function replace_url($url) {
        if (!is_string($url) || empty($url)) {
            return $url;
        }
        if (strpos($url, $this->local_base_url) === 0) {
            return str_replace($this->local_base_url, $this->production_base_url, $url);
        }
        return $url;
    }

    public function filter_image_src($image, $attachment_id, $size, $icon) {
        if ($image && isset($image[0])) {
            $image[0] = $this->replace_url($image[0]);
        }
        return $image;
    }

    public function filter_image_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
        if (!$sources || !is_array($sources)) {
            return $sources;
        }
        foreach ($sources as $width => $source) {
            if (isset($source['url'])) {
                $sources[$width]['url'] = $this->replace_url($source['url']);
            }
        }
        return $sources;
    }

    public function filter_media_modal_data($response, $attachment, $meta) {
        if (!is_admin() || !$response) {
            return $response;
        }
        array_walk_recursive($response, [$this, 'walk_and_replace']);
        return $response;
    }

    public function walk_and_replace(&$item, $key) {
        if (!is_string($item)) {
            return;
        }
        if ( (strpos($key, 'url') !== false || strpos($key, 'src') !== false) && strpos($item, $this->local_base_url) === 0) {
            $item = $this->replace_url($item);
        }
    }

    public function filter_rest_api_data($response, $post, $request) {
        if (!defined('REST_REQUEST') || !REST_REQUEST) {
            return $response;
        }
        $data = $response->get_data();
        if (isset($data['source_url'])) {
            $data['source_url'] = $this->replace_url($data['source_url']);
        }
        if (isset($data['media_details']['sizes'])) {
            foreach ($data['media_details']['sizes'] as $size => $details) {
                if (isset($details['source_url'])) {
                    $data['media_details']['sizes'][$size]['source_url'] = $this->replace_url($details['source_url']);
                }
            }
        }
        $response->set_data($data);
        return $response;
    }

    public function filter_post_content_for_editor($response, $post, $request) {
        // This function now handles posts, pages, AND reusable blocks (wp_block)
        if ($request->get_param('context') !== 'edit') {
            return $response;
        }
        $data = $response->get_data();
        if (isset($data['content']['raw'])) {
            $data['content']['raw'] = str_replace(
                $this->local_base_url, 
                $this->production_base_url, 
                $data['content']['raw']
            );
        }
        $response->set_data($data);
        return $response;
    }

    /**
     * [NEW] Filters the REST API response for block patterns.
     */
    public function filter_block_pattern_rest_response($response, $handler, $request) {
        // Check if this is a request to the block patterns endpoint
        $route = $request->get_route();
        
        // This covers /wp/v2/block-patterns and the newer /wp/v2/patterns
        if (strpos($route, '/wp/v2/block-patterns') !== 0 && strpos($route, '/wp/v2/patterns') !== 0) {
            return $response;
        }

        $data = $response->get_data();

        // Check if $data is a list (index-based array)
        if (is_array($data) && isset($data[0])) {
            // List of patterns
            foreach ($data as $key => $pattern) {
                if (isset($pattern['content'])) {
                    $data[$key]['content'] = str_replace(
                        $this->local_base_url,
                        $this->production_base_url,
                        $pattern['content']
                    );
                }
            }
        } elseif (is_array($data) && isset($data['name'])) {
            // Single pattern
            if (isset($data['content'])) {
                 $data['content'] = str_replace(
                     $this->local_base_url,
                     $this->production_base_url,
                     $data['content']
                 );
            }
        }

        $response->set_data($data);
        return $response;
    }

    public function filter_frontend_content($content) {
        if (is_admin()) {
            return $content;
        }
        return str_replace($this->local_base_url, $this->production_base_url, $content);
    }
}

// Initialize the plugin
new Local_Prod_Upload_Replacer();