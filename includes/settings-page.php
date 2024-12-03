<?php
if (!defined('ABSPATH')) {
    exit;
}

class Image_To_Product_Settings {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_process_bulk_images', array($this, 'process_bulk_images'));
    }

    public function add_settings_page() {
        add_menu_page(
            __('Image to Product', 'image-to-product-automator'),
            __('Image to Product', 'image-to-product-automator'),
            'manage_options',
            'image-to-product-settings',
            array($this, 'render_settings_page'),
            'dashicons-images-alt2',
            56
        );
    }

    public function register_settings() {
        register_setting('image_to_product_settings', 'itp_product_prefix');
        register_setting('image_to_product_settings', 'itp_default_price');
        register_setting('image_to_product_settings', 'itp_default_category');
        register_setting('image_to_product_settings', 'itp_sequence_start');
        register_setting('image_to_product_settings', 'itp_sequence_digits');
        register_setting('image_to_product_settings', 'itp_last_sequence_number');
    }

    public function enqueue_admin_scripts($hook) {
        if ('toplevel_page_image-to-product-settings' !== $hook) {
            return;
        }

        wp_enqueue_media();
        
        // Enqueue custom JS
        wp_enqueue_script(
            'image-to-product-admin',
            plugins_url('/assets/js/admin.js', dirname(__FILE__)),
            array('jquery'),
            '1.0.0',
            true
        );

        wp_localize_script('image-to-product-admin', 'itpAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('itp_ajax_nonce')
        ));
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('image_to_product_settings');
                do_settings_sections('image_to_product_settings');
                ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="itp_product_prefix"><?php _e('Product Name Prefix', 'image-to-product-automator'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="itp_product_prefix" name="itp_product_prefix" 
                                   value="<?php echo esc_attr(get_option('itp_product_prefix')); ?>" class="regular-text">
                            <p class="description"><?php _e('This text will be added before the image name', 'image-to-product-automator'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="itp_default_price"><?php _e('Default Price', 'image-to-product-automator'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="itp_default_price" name="itp_default_price" 
                                   value="<?php echo esc_attr(get_option('itp_default_price', '0')); ?>" step="0.01" min="0">
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="itp_default_category"><?php _e('Default Category', 'image-to-product-automator'); ?></label>
                        </th>
                        <td>
                            <?php
                            $selected_cat = get_option('itp_default_category');
                            $args = array(
                                'taxonomy' => 'product_cat',
                                'hide_empty' => false,
                                'name' => 'itp_default_category',
                                'id' => 'itp_default_category',
                                'selected' => $selected_cat,
                                'show_option_none' => __('Select a category', 'image-to-product-automator')
                            );
                            wp_dropdown_categories($args);
                            ?>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="itp_sequence_start"><?php _e('Sequence Start Number', 'image-to-product-automator'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="itp_sequence_start" name="itp_sequence_start" 
                                   value="<?php echo esc_attr(get_option('itp_sequence_start', '1')); ?>" min="0">
                            <p class="description"><?php _e('Starting number for the sequence', 'image-to-product-automator'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="itp_sequence_digits"><?php _e('Number of Digits', 'image-to-product-automator'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="itp_sequence_digits" name="itp_sequence_digits" 
                                   value="<?php echo esc_attr(get_option('itp_sequence_digits', '3')); ?>" min="1" max="10">
                            <p class="description"><?php _e('How many digits to use (e.g., 3 digits: 001, 002, etc.)', 'image-to-product-automator'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>

            <hr>

            <h2><?php _e('Bulk Image Upload', 'image-to-product-automator'); ?></h2>
            <div id="bulk-upload-container">
                <button type="button" class="button button-primary" id="select-images">
                    <?php _e('Select Images', 'image-to-product-automator'); ?>
                </button>
                <div id="selected-images-preview"></div>
                <button type="button" class="button button-primary hidden" id="process-images">
                    <?php _e('Create Products', 'image-to-product-automator'); ?>
                </button>
                <div id="processing-status"></div>
            </div>
        </div>
        <?php
    }

    public function process_bulk_images() {
        check_ajax_referer('itp_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $image_ids = isset($_POST['image_ids']) ? array_map('intval', $_POST['image_ids']) : array();
        $results = array();

        foreach ($image_ids as $attachment_id) {
            $product_id = Image_To_Product_Automator::get_instance()->create_product_from_image($attachment_id);
            $results[] = array(
                'image_id' => $attachment_id,
                'product_id' => $product_id,
                'success' => !is_wp_error($product_id)
            );
        }

        wp_send_json_success($results);
    }
}
