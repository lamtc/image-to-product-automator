<?php
/**
 * Plugin Name: Image to Product Automator
 * Plugin URI: 
 * Description: Automatically creates WooCommerce products from uploaded images
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: 
 * Text Domain: image-to-product-automator
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function() {
        ?>
        <div class="notice notice-error">
            <p><?php _e('Image to Product Automator requires WooCommerce to be installed and activated!', 'image-to-product-automator'); ?></p>
        </div>
        <?php
    });
    return;
}

// Include settings page
require_once plugin_dir_path(__FILE__) . 'includes/settings-page.php';

class Image_To_Product_Automator {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Initialize settings page
        Image_To_Product_Settings::get_instance();

        // Add AJAX handler for bulk processing
        add_action('wp_ajax_process_bulk_images', array($this, 'handle_bulk_processing'));
    }

    public function handle_bulk_processing() {
        check_ajax_referer('itp_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $image_ids = isset($_POST['image_ids']) ? array_map('intval', $_POST['image_ids']) : array();
        $results = array();

        foreach ($image_ids as $attachment_id) {
            // Check if product already exists for this image
            if ($this->product_exists_for_image($attachment_id)) {
                $results[] = array(
                    'image_id' => $attachment_id,
                    'success' => false,
                    'message' => 'Product already exists for this image'
                );
                continue;
            }

            $product_id = $this->create_product_from_image($attachment_id);
            $results[] = array(
                'image_id' => $attachment_id,
                'product_id' => $product_id,
                'success' => !is_wp_error($product_id)
            );
        }

        wp_send_json_success($results);
    }

    private function product_exists_for_image($attachment_id) {
        global $wpdb;
        
        // Check if this image is already used as a featured image for any product
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $wpdb->postmeta 
            WHERE meta_key = '_thumbnail_id' 
            AND meta_value = %d",
            $attachment_id
        ));

        return $exists > 0;
    }

    private function get_next_sequence_number() {
        $last_number = get_option('itp_last_sequence_number', null);
        $start_number = get_option('itp_sequence_start', 1);
        
        if ($last_number === null) {
            $next_number = $start_number;
        } else {
            $next_number = $last_number + 1;
        }

        update_option('itp_last_sequence_number', $next_number);
        return $next_number;
    }

    private function format_sequence_number($number) {
        $digits = get_option('itp_sequence_digits', 3);
        return str_pad($number, $digits, '0', STR_PAD_LEFT);
    }

    public function create_product_from_image($attachment_id) {
        // Check if the uploaded file is an image
        if (!wp_attachment_is_image($attachment_id)) {
            return false;
        }

        // Get settings
        $prefix = get_option('itp_product_prefix', '');
        $default_price = get_option('itp_default_price', '0');
        $default_category = get_option('itp_default_category');

        // Get and format sequence number
        $sequence_number = $this->get_next_sequence_number();
        $formatted_number = $this->format_sequence_number($sequence_number);

        // Create product name with prefix and sequence number
        $product_name = trim($prefix) . ' ' . $formatted_number;

        // Create product
        $product = new WC_Product_Simple();
        
        // Set product name
        $product->set_name($product_name);
        
        // Set product status to publish
        $product->set_status('publish');
        
        // Set price from settings
        $product->set_regular_price($default_price);
        
        // Set product as virtual (optional, remove if physical products are needed)
        $product->set_virtual(true);
        
        // Save the product to get an ID
        $product_id = $product->save();

        // Set featured image
        set_post_thumbnail($product_id, $attachment_id);

        // Set category if one is selected
        if (!empty($default_category)) {
            wp_set_object_terms($product_id, (int)$default_category, 'product_cat');
        }

        return $product_id;
    }
}

// Initialize the plugin
add_action('plugins_loaded', function() {
    Image_To_Product_Automator::get_instance();
});
