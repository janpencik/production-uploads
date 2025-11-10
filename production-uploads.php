<?php
/**
 * Plugin Name: Production Uploads
 * Description: Replaces local upload URLs for development. Works on front-end, Gutenberg, Patterns, and WooCommerce pages.
 * Version: 2.3
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
        
        // --- HTML Output Filter for srcset attributes (fixes WooCommerce archive pages) ---
        add_filter('wp_get_attachment_image_attributes', [$this, 'filter_image_attributes'], 10, 3);
        add_filter('woocommerce_product_get_image', [$this, 'filter_woocommerce_image_html'], 10, 5);
        add_filter('woocommerce_single_product_image_thumbnail_html', [$this, 'filter_woocommerce_gallery_thumbnail'], 10, 4);
        add_filter('woocommerce_product_thumbnails', [$this, 'filter_woocommerce_gallery_html'], 10);
        add_filter('the_content', [$this, 'filter_final_content'], 9999);
        add_filter('the_excerpt', [$this, 'filter_final_content'], 9999);
        add_filter('woocommerce_short_description', [$this, 'filter_final_content'], 9999);
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

    /**
     * [NEW] Filters image attributes including srcset for WooCommerce and other themes.
     */
    public function filter_image_attributes($attr, $attachment, $size) {
        if (isset($attr['srcset'])) {
            $attr['srcset'] = $this->replace_url($attr['srcset']);
        }
        if (isset($attr['src'])) {
            $attr['src'] = $this->replace_url($attr['src']);
        }
        return $attr;
    }

    /**
     * [NEW] Filters WooCommerce product image HTML to fix srcset attributes.
     */
    public function filter_woocommerce_image_html($image, $product, $image_id, $size, $props) {
        if (is_admin()) {
            return $image;
        }
        
        // Early return if no local URL is found in content
        if (strpos($image, $this->local_base_url) === false) {
            return $image;
        }
        
        // Replace srcset attributes in the image HTML
        $image = preg_replace_callback(
            '/srcset=["\']([^"\']+)["\']/',
            function($matches) {
                $srcset = $matches[1];
                $srcset = str_replace($this->local_base_url, $this->production_base_url, $srcset);
                return 'srcset="' . $srcset . '"';
            },
            $image
        );
        
        // Also handle src attributes just in case
        $image = str_replace($this->local_base_url, $this->production_base_url, $image);
        
        return $image;
    }

    /**
     * [NEW] Filters WooCommerce gallery thumbnail HTML.
     */
    public function filter_woocommerce_gallery_thumbnail($image, $attachment_id) {
        if (is_admin()) {
            return $image;
        }
        
        // Early return if no local URL is found in content
        if (strpos($image, $this->local_base_url) === false) {
            return $image;
        }
        
        // Replace srcset attributes in the thumbnail HTML
        $image = preg_replace_callback(
            '/srcset=["\']([^"\']+)["\']/',
            function($matches) {
                $srcset = $matches[1];
                $srcset = str_replace($this->local_base_url, $this->production_base_url, $srcset);
                return 'srcset="' . $srcset . '"';
            },
            $image
        );
        
        // Also handle src attributes
        $image = str_replace($this->local_base_url, $this->production_base_url, $image);
        
        return $image;
    }

    /**
     * [NEW] Filters WooCommerce product gallery HTML.
     */
    public function filter_woocommerce_gallery_html($gallery_html) {
        if (is_admin()) {
            return $gallery_html;
        }
        
        // Early return if no local URL is found in content
        if (strpos($gallery_html, $this->local_base_url) === false) {
            return $gallery_html;
        }
        
        // Replace srcset attributes in the gallery HTML
        $gallery_html = preg_replace_callback(
            '/srcset=["\']([^"\']+)["\']/',
            function($matches) {
                $srcset = $matches[1];
                $srcset = str_replace($this->local_base_url, $this->production_base_url, $srcset);
                return 'srcset="' . $srcset . '"';
            },
            $gallery_html
        );
        
        // Also handle data-srcset attributes
        $gallery_html = preg_replace_callback(
            '/data-srcset=["\']([^"\']+)["\']/',
            function($matches) {
                $srcset = $matches[1];
                $srcset = str_replace($this->local_base_url, $this->production_base_url, $srcset);
                return 'data-srcset="' . $srcset . '"';
            },
            $gallery_html
        );
        
        // Also handle src attributes
        $gallery_html = str_replace($this->local_base_url, $this->production_base_url, $gallery_html);
        
        return $gallery_html;
    }

    

    /**
     * [NEW] Final content filter to catch any remaining srcset attributes.
     */
    public function filter_final_content($content) {
        if (is_admin()) {
            return $content;
        }
        
        // Early return if no local URL is found in content
        if (strpos($content, $this->local_base_url) === false) {
            return $content;
        }
        
        // Use regex to find and replace srcset attributes in HTML
        $content = preg_replace_callback(
            '/srcset=["\']([^"\']+)["\']/',
            function($matches) {
                $srcset = $matches[1];
                $srcset = str_replace($this->local_base_url, $this->production_base_url, $srcset);
                return 'srcset="' . $srcset . '"';
            },
            $content
        );
        
        // Also handle data-srcset attributes that some themes use
        $content = preg_replace_callback(
            '/data-srcset=["\']([^"\']+)["\']/',
            function($matches) {
                $srcset = $matches[1];
                $srcset = str_replace($this->local_base_url, $this->production_base_url, $srcset);
                return 'data-srcset="' . $srcset . '"';
            },
            $content
        );
        
        return $content;
    }
}

// Initialize the plugin
new Local_Prod_Upload_Replacer();