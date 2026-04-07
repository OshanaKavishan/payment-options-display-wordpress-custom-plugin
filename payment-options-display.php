<?php
/**
 * Plugin Name: Payment Type Based Price Display
 * Plugin URI: https://herastudiolk.com
 * Description: Display custom payment options below product price with payment plans and discounts
 * Version: 1.1.0
 * Author: Hera Studio LK
 * Author URI: https://herastudiolk.com
 * Text Domain: payment-options-display
 * Requires at least: 5.0
 * Requires PHP: 7.2
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

define('POD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('POD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('POD_VERSION', '1.1.0');
define('POD_GITHUB_REPO', 'HERA-Studio/payment-options-display');

/**
 * Initialize plugin update checker for private GitHub repository.
 * 
 * Requires a GitHub personal access token stored in wp-config.php:
 * define('POD_GITHUB_TOKEN', 'your_personal_access_token');
 */
function pod_initialize_update_checker() {
    $library_path = POD_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';

    // Check if library exists
    if (!file_exists($library_path)) {
        add_action('admin_notices', 'pod_missing_library_notice');
        error_log('Payment Options Display: Plugin Update Checker library not found at ' . $library_path);
        return;
    }

    require_once $library_path;

    // Priority 1: wp-config.php
    $github_token = defined('POD_GITHUB_TOKEN') ? POD_GITHUB_TOKEN : '';

    // Priority 2: Saved via admin dashboard (stored in database)
    if (empty($github_token)) {
        $github_token = get_option('pod_github_token', '');
    }

    if (empty($github_token)) {
        add_action('admin_notices', 'pod_missing_token_notice');
        error_log('Payment Options Display: GitHub token not configured.');
        return;
    }

    try {
        $update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://github.com/' . POD_GITHUB_REPO . '/',
            __FILE__,
            'payment-options-display'
        );

        $update_checker->setAuthentication($github_token);
        $update_checker->setBranch('main'); // or 'master' depending on your default branch
        
        // Store update checker status
        update_option('pod_update_checker_active', true);
        
        // Add debug info
        add_action('admin_footer', function() use ($update_checker) {
            if (current_user_can('manage_options') && isset($_GET['page']) && $_GET['page'] === 'payment-options-settings') {
                $state = $update_checker->getState();
                echo '<!-- POD Update Checker Status: Active -->';
                echo '<!-- Last Check: ' . (isset($state->lastCheck) ? date('Y-m-d H:i:s', $state->lastCheck) : 'Never') . ' -->';
            }
        });
        
    } catch (Exception $e) {
        error_log('Payment Options Display: Update checker initialization failed - ' . $e->getMessage());
        update_option('pod_update_checker_active', false);
        update_option('pod_update_checker_error', $e->getMessage());
    }
}

add_action('plugins_loaded', 'pod_initialize_update_checker', 1);

/**
 * Admin notice for missing update checker library
 */
function pod_missing_library_notice() {
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="notice notice-error">
        <p><strong>Payment Options Display:</strong> Update checker library is missing. Please ensure the <code>vendor/plugin-update-checker</code> folder is uploaded to your server.</p>
    </div>
    <?php
}

/**
 * Admin notice for missing GitHub token
 */
function pod_missing_token_notice() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    // Only show on plugin pages
    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, 'payment-options') === false) {
        return;
    }
    ?>
    <div class="notice notice-warning">
        <p>
            <strong>Payment Options Display:</strong> GitHub token not configured. 
            <a href="<?php echo admin_url('admin.php?page=payment-options-settings'); ?>">Configure it here</a> to enable automatic updates.
        </p>
    </div>
    <?php
}

class Payment_Options_Display {
    
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'payment_options';
        
        // Security: Only initialize admin functionality for users with proper capabilities
        if (is_admin()) {
            register_activation_hook(__FILE__, array($this, 'activate'));
            add_action('plugins_loaded', array($this, 'check_database_update'));
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
            
            // AJAX actions - all protected with nonce and capability checks
            add_action('wp_ajax_pod_save_payment_option', array($this, 'ajax_save_payment_option'));
            add_action('wp_ajax_pod_delete_payment_option', array($this, 'ajax_delete_payment_option'));
            add_action('wp_ajax_pod_get_payment_option', array($this, 'ajax_get_payment_option'));
            add_action('wp_ajax_pod_save_settings', array($this, 'ajax_save_settings'));
            add_action('wp_ajax_pod_toggle_status', array($this, 'ajax_toggle_status'));
            add_action('wp_ajax_pod_save_github_token', array($this, 'ajax_save_github_token'));
            add_action('wp_ajax_pod_check_updates', array($this, 'ajax_check_updates'));
        }
        
        // Frontend functionality
        add_action('wp_enqueue_scripts', array($this, 'frontend_enqueue_scripts'));
        add_action('woocommerce_single_product_summary', array($this, 'display_payment_options'), 15);
    }
    
    public function activate() {
        // Security: Check user capabilities
        if (!current_user_can('activate_plugins')) {
            return;
        }
        
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            payment_name varchar(255) DEFAULT '',
            logo_url varchar(500) DEFAULT '',
            discount_label varchar(100) DEFAULT '',
            discount_percentage decimal(5,2) DEFAULT 0,
            adjustment_value decimal(5,2) DEFAULT 0,
            payment_plan_months int DEFAULT 0,
            is_active tinyint(1) DEFAULT 1,
            display_order int DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Security: Set database version for future upgrades
        update_option('pod_db_version', POD_VERSION);
    }
    
    public function check_database_update() {
        global $wpdb;
        
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name} LIKE 'discount_label'");
        
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD discount_label varchar(100) DEFAULT '' AFTER logo_url");
        }
        
        $discount_percentage_exists = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name} LIKE 'discount_percentage'");
        
        if (empty($discount_percentage_exists)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD discount_percentage decimal(5,2) DEFAULT 0 AFTER discount_label");
        }
        
        // Add is_active column if it doesn't exist
        $is_active_exists = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name} LIKE 'is_active'");
        
        if (empty($is_active_exists)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD is_active tinyint(1) DEFAULT 1 AFTER payment_plan_months");
        }
        
        // Add adjustment_value column if it doesn't exist
        $adjustment_value_exists = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name} LIKE 'adjustment_value'");
        
        if (empty($adjustment_value_exists)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD adjustment_value decimal(5,2) DEFAULT 0 AFTER discount_percentage");
        }
        
        $wpdb->query("ALTER TABLE {$this->table_name} MODIFY payment_name varchar(255) DEFAULT ''");
    }
    
    public function manual_database_update() {
        // Security: Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        // Security: Verify nonce
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'pod_update_db')) {
            wp_die(__('Security check failed.'));
        }
        
        global $wpdb;
        
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'");
        
        if ($table_exists != $this->table_name) {
            $this->activate();
            return;
        }
        
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name} LIKE 'discount_label'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD discount_label varchar(100) DEFAULT '' AFTER logo_url");
        }
        
        $discount_percentage_exists = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name} LIKE 'discount_percentage'");
        if (empty($discount_percentage_exists)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD discount_percentage decimal(5,2) DEFAULT 0 AFTER discount_label");
        }
        
        // Add is_active column
        $is_active_exists = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name} LIKE 'is_active'");
        if (empty($is_active_exists)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD is_active tinyint(1) DEFAULT 1 AFTER payment_plan_months");
        }
        
        // Add adjustment_value column
        $adjustment_value_exists = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name} LIKE 'adjustment_value'");
        if (empty($adjustment_value_exists)) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD adjustment_value decimal(5,2) DEFAULT 0 AFTER discount_percentage");
        }
        
        $wpdb->query("ALTER TABLE {$this->table_name} MODIFY payment_name varchar(255) DEFAULT ''");
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'Payment Options',
            'Payment Options',
            'manage_options',
            'payment-options',
            array($this, 'admin_page'),
            'dashicons-money-alt',
            56
        );
        
        add_submenu_page(
            'payment-options',
            'Settings',
            'Settings',
            'manage_options',
            'payment-options-settings',
            array($this, 'settings_page')
        );
    }
    
    public function admin_enqueue_scripts($hook) {
        if ($hook != 'toplevel_page_payment-options' && $hook != 'payment-options_page_payment-options-settings') {
            return;
        }
        
        wp_enqueue_media();
        wp_enqueue_style('pod-admin-style', POD_PLUGIN_URL . 'css/admin-style.css', array(), POD_VERSION);
        wp_enqueue_script('pod-admin-script', POD_PLUGIN_URL . 'js/admin-script.js', array('jquery'), POD_VERSION, true);
        
        wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', array(), '4.1.0');
        wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', array('jquery'), '4.1.0', true);
        
        // Security: Create nonce with shorter lifetime (12 hours instead of 24)
        wp_localize_script('pod-admin-script', 'podAjax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pod_nonce')
        ));
    }
    
    public function frontend_enqueue_scripts() {
        if (function_exists('is_product') && is_product()) {
            wp_enqueue_style('pod-frontend-style', POD_PLUGIN_URL . 'css/frontend-style.css', array(), POD_VERSION);
        }
    }
    
    public function admin_page() {
        // Security: Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        global $wpdb;
        
        if (isset($_GET['update_db']) && $_GET['update_db'] == '1' && current_user_can('manage_options')) {
            // Security: Check nonce
            check_admin_referer('pod_update_db');
            $this->manual_database_update();
            echo '<div class="notice notice-success"><p>Database updated successfully!</p></div>';
        }
        
        $options = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table_name} ORDER BY display_order ASC, id ASC"));
        ?>
        <div class="wrap">
            <h1>Payment Options Management</h1>
            
            <div class="notice notice-info" style="margin-top:20px;">
                <p><strong>Having database errors?</strong> <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=payment-options&update_db=1'), 'pod_update_db'); ?>" class="button button-secondary">Click here to update database</a></p>
            </div>
            
            <div class="pod-container">
                <div class="pod-form-section">
                    <h2>Add New Payment Option</h2>
                    <form id="pod-payment-form" method="post">
                        <table class="form-table">
                            <tr>
                                <th><label for="pod_payment_name">Payment Name</label></th>
                                <td>
                                    <input type="text" id="pod_payment_name" name="payment_name" class="regular-text">
                                    <p class="description">Optional - Leave empty to show only logo and price</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="pod_logo_url">Logo Image</label></th>
                                <td>
                                    <input type="hidden" id="pod_logo_url" name="logo_url" value="">
                                    <button type="button" class="button pod-upload-btn">Upload Logo</button>
                                    <button type="button" class="button pod-remove-logo" style="display:none;">Remove Logo</button>
                                    <div id="pod-logo-preview" style="margin-top:10px;"></div>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="pod_discount">Discount Label</label></th>
                                <td>
                                    <input type="text" id="pod_discount" name="discount_label" class="regular-text" placeholder="e.g., FLAT 10% OFF">
                                    <p class="description">Optional - Add discount badge text</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="pod_adjustment_value">Price Adjustment (%)</label></th>
                                <td>
                                    <input type="number" id="pod_adjustment_value" name="adjustment_value" class="small-text" min="-100" max="100" step="0.01" value="0" placeholder="e.g., -3 or +5">
                                    <p class="description">
                                        <strong>Negative value (-)</strong> = Discount (e.g., -3 means 3% discount)<br>
                                        <strong>Positive value (+)</strong> = Fee (e.g., +5 means 5% fee)
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="pod_payment_plan">Payment Plan (Months)</label></th>
                                <td>
                                    <input type="number" id="pod_payment_plan" name="payment_plan_months" min="0" max="36" value="0" class="small-text">
                                    <p class="description">Enter number of months for payment plan (0 = no plan)</p>
                                </td>
                            </tr>
                        </table>
                        <input type="hidden" id="pod_option_id" name="option_id" value="">
                        <p class="submit">
                            <button type="submit" class="button button-primary">Save Payment Option</button>
                            <button type="button" class="button pod-cancel-edit" style="display:none;">Cancel</button>
                        </p>
                    </form>
                </div>
                
                <div class="pod-list-section">
                    <h2>Existing Payment Options</h2>
                    <?php if (empty($options)): ?>
                        <p>No payment options yet. Add your first one above!</p>
                    <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width:8%;">Status</th>
                                <th style="width:18%;">Payment Name</th>
                                <th style="width:12%;">Logo</th>
                                <th style="width:12%;">Discount</th>
                                <th style="width:12%;">Adjustment</th>
                                <th style="width:12%;">Payment Plan</th>
                                <th style="width:26%;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="pod-options-list">
                            <?php foreach ($options as $option): ?>
                            <tr data-id="<?php echo esc_attr($option->id); ?>" class="<?php echo $option->is_active ? '' : 'pod-inactive-row'; ?>">
                                <td>
                                    <label class="pod-toggle-switch">
                                        <input type="checkbox" 
                                               class="pod-status-toggle" 
                                               data-id="<?php echo esc_attr($option->id); ?>"
                                               <?php checked($option->is_active, 1); ?>>
                                        <span class="pod-toggle-slider"></span>
                                    </label>
                                </td>
                                <td><?php echo !empty($option->payment_name) ? esc_html($option->payment_name) : '<span style="color:#999;">No name</span>'; ?></td>
                                <td>
                                    <?php if (!empty($option->logo_url)): ?>
                                        <img src="<?php echo esc_url($option->logo_url); ?>" style="max-width:60px;max-height:40px;">
                                    <?php else: ?>
                                        <span style="color:#999;">No logo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo !empty($option->discount_label) ? '<span style="color:#ff5722;font-weight:600;">' . esc_html($option->discount_label) . '</span>' : '<span style="color:#999;">No discount</span>'; ?>
                                </td>
                                <td>
                                    <?php 
                                    if ($option->adjustment_value != 0) {
                                        $adj_color = $option->adjustment_value < 0 ? '#4caf50' : '#ff9800';
                                        $adj_prefix = $option->adjustment_value > 0 ? '+' : '';
                                        echo '<span style="color:' . $adj_color . ';font-weight:600;">' . $adj_prefix . esc_html($option->adjustment_value) . '%</span>';
                                    } else {
                                        echo '<span style="color:#999;">None</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                    echo $option->payment_plan_months > 0 
                                        ? esc_html($option->payment_plan_months) . ' months' 
                                        : '<span style="color:#999;">No plan</span>'; 
                                    ?>
                                </td>
                                <td>
                                    <button type="button" class="button pod-edit-btn" data-id="<?php echo esc_attr($option->id); ?>">Edit</button>
                                    <button type="button" class="button pod-delete-btn" data-id="<?php echo esc_attr($option->id); ?>">Delete</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <style>
        .pod-toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        
        .pod-toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .pod-toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
        }
        
        .pod-toggle-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .pod-toggle-slider {
            background-color: #2196F3;
        }
        
        input:checked + .pod-toggle-slider:before {
            transform: translateX(26px);
        }
        
        .pod-inactive-row {
            opacity: 0.5;
            background-color: #f9f9f9;
        }
        </style>
        <?php
    }
    
    public function settings_page() {
        // Security: Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        $apply_to_all = get_option('pod_apply_to_all', 'yes');
        $excluded_products = get_option('pod_excluded_products', array());
        $saved_token = get_option('pod_github_token', '');
        $token_source = '';
        $update_checker_active = get_option('pod_update_checker_active', false);
        $update_checker_error = get_option('pod_update_checker_error', '');

        // Check where the active token is coming from
        if (defined('POD_GITHUB_TOKEN') && POD_GITHUB_TOKEN !== '') {
            $token_source = 'wp-config';
        } elseif (!empty($saved_token)) {
            $token_source = 'database';
        }
        
        $products = wc_get_products(array(
            'limit' => -1,
            'status' => 'publish',
            'orderby' => 'name',
            'order' => 'ASC'
        ));
        ?>
        <div class="wrap">
            <h1>Payment Options Settings</h1>

            <!-- Update Checker Status -->
            <div class="pod-update-status" style="margin: 20px 0;">
                <h2>Update System Status</h2>
                <table class="widefat" style="max-width: 800px;">
                    <tr>
                        <td style="width: 200px;"><strong>Update Checker Library:</strong></td>
                        <td>
                            <?php if (file_exists(POD_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php')): ?>
                                <span style="color: #46b450;">✓ Installed</span>
                            <?php else: ?>
                                <span style="color: #dc3232;">✗ Missing</span>
                                <p class="description">Upload the <code>vendor</code> folder to enable updates.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>GitHub Token:</strong></td>
                        <td>
                            <?php if ($token_source === 'wp-config'): ?>
                                <span style="color: #46b450;">✓ Configured (wp-config.php)</span>
                            <?php elseif ($token_source === 'database'): ?>
                                <span style="color: #46b450;">✓ Configured (Database)</span>
                            <?php else: ?>
                                <span style="color: #dc3232;">✗ Not Configured</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Update Checker Status:</strong></td>
                        <td>
                            <?php if ($update_checker_active): ?>
                                <span style="color: #46b450;">✓ Active</span>
                                <button type="button" class="button button-secondary" id="pod-manual-check-btn" style="margin-left: 10px;">Check for Updates Now</button>
                            <?php else: ?>
                                <span style="color: #dc3232;">✗ Inactive</span>
                                <?php if (!empty($update_checker_error)): ?>
                                    <p class="description" style="color: #dc3232;">Error: <?php echo esc_html($update_checker_error); ?></p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Current Version:</strong></td>
                        <td><?php echo POD_VERSION; ?></td>
                    </tr>
                </table>
            </div>

            <hr style="margin:30px 0;">

            <!-- GitHub Token Section -->
            <div class="pod-token-section">
                <h2>GitHub Update Token</h2>

                <?php if ($token_source === 'wp-config'): ?>
                    <div class="notice notice-success" style="margin-left:0;">
                        <p>GitHub token is configured via <strong>wp-config.php</strong>. No action needed here.</p>
                    </div>
                <?php else: ?>
                    <?php if ($token_source === 'database'): ?>
                        <div class="notice notice-success" style="margin-left:0;">
                            <p>GitHub token is saved and active.</p>
                        </div>
                    <?php else: ?>
                        <div class="notice notice-warning" style="margin-left:0;">
                            <p><strong>No GitHub token configured.</strong> Paste your token below to enable automatic plugin updates.</p>
                        </div>
                    <?php endif; ?>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="pod_github_token">GitHub Personal Access Token</label>
                            </th>
                            <td>
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <input type="password" 
                                           id="pod_github_token" 
                                           name="pod_github_token" 
                                           class="regular-text" 
                                           value="<?php echo !empty($saved_token) ? str_repeat('•', 20) : ''; ?>"
                                           placeholder="ghp_xxxxxxxxxxxxxxxxxxxxxxxx"
                                           autocomplete="off">
                                    <button type="button" class="button button-primary" id="pod-save-token-btn">Save Token</button>
                                    <?php if (!empty($saved_token)): ?>
                                        <button type="button" class="button button-link" id="pod-remove-token-btn" style="color:#d32f2f;">Remove Token</button>
                                    <?php endif; ?>
                                </div>
                                <p class="description">
                                    Generate a token at <a href="https://github.com/settings/tokens/new" target="_blank">GitHub → Settings → Developer Settings → Personal Access Tokens</a>.<br>
                                    Make sure to give it <strong>repo</strong> scope access.
                                </p>
                            </td>
                        </tr>
                    </table>
                <?php endif; ?>
            </div>

            <hr style="margin:30px 0;">

            <!-- Product Display Settings -->
            <h2>Product Display Settings</h2>
            
            <form id="pod-settings-form" method="post">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label>Display Options</label>
                        </th>
                        <td>
                            <label>
                                <input type="radio" name="apply_to_all" value="yes" <?php checked($apply_to_all, 'yes'); ?>>
                                <strong>Apply to all products</strong>
                            </label>
                            <br><br>
                            <label>
                                <input type="radio" name="apply_to_all" value="no" <?php checked($apply_to_all, 'no'); ?>>
                                <strong>Apply to all products EXCEPT these</strong>
                            </label>
                            
                            <div id="excluded-products-section" style="margin-top: 15px; <?php echo ($apply_to_all === 'no') ? '' : 'display:none;'; ?>">
                                <p class="description">Select products to exclude from showing payment options:</p>
                                <select name="excluded_products[]" id="excluded-products" multiple style="width: 100%; max-width: 600px;">
                                    <?php foreach ($products as $product): ?>
                                        <option value="<?php echo $product->get_id(); ?>" 
                                            <?php echo in_array($product->get_id(), $excluded_products) ? 'selected' : ''; ?>>
                                            <?php echo $product->get_name() . ' (#' . $product->get_id() . ')'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" class="button button-primary">Save Settings</button>
                </p>
            </form>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#excluded-products').select2({
                placeholder: 'Search and select products to exclude...',
                allowClear: true
            });
            
            $('input[name="apply_to_all"]').on('change', function() {
                if ($(this).val() === 'no') {
                    $('#excluded-products-section').slideDown();
                } else {
                    $('#excluded-products-section').slideUp();
                }
            });
            
            // Save settings
            $('#pod-settings-form').on('submit', function(e) {
                e.preventDefault();
                
                var submitBtn = $(this).find('button[type="submit"]');
                var originalText = submitBtn.text();
                submitBtn.prop('disabled', true).text('Saving...');
                
                $.ajax({
                    url: podAjax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'pod_save_settings',
                        nonce: podAjax.nonce,
                        apply_to_all: $('input[name="apply_to_all"]:checked').val(),
                        excluded_products: $('#excluded-products').val() || []
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Settings saved successfully!');
                        } else {
                            alert('Error: ' + response.data);
                        }
                        submitBtn.prop('disabled', false).text(originalText);
                    },
                    error: function() {
                        alert('AJAX Error occurred');
                        submitBtn.prop('disabled', false).text(originalText);
                    }
                });
            });

            // Manual update check
            $('#pod-manual-check-btn').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true).text('Checking...');

                $.ajax({
                    url: podAjax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'pod_check_updates',
                        nonce: podAjax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data);
                            location.reload();
                        } else {
                            alert('Error: ' + response.data);
                        }
                        btn.prop('disabled', false).text('Check for Updates Now');
                    },
                    error: function() {
                        alert('AJAX Error occurred');
                        btn.prop('disabled', false).text('Check for Updates Now');
                    }
                });
            });

            // Save GitHub token
            $('#pod-save-token-btn').on('click', function() {
                var token = $('#pod_github_token').val().trim();

                if (!token || token === '••••••••••••••••••••') {
                    alert('Please paste your GitHub token.');
                    return;
                }

                var btn = $(this);
                btn.prop('disabled', true).text('Saving...');

                $.ajax({
                    url: podAjax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'pod_save_github_token',
                        nonce: podAjax.nonce,
                        token: token,
                        action_type: 'save'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Token saved successfully! The page will reload.');
                            location.reload();
                        } else {
                            alert('Error: ' + response.data);
                            btn.prop('disabled', false).text('Save Token');
                        }
                    },
                    error: function() {
                        alert('AJAX Error occurred');
                        btn.prop('disabled', false).text('Save Token');
                    }
                });
            });

            // Remove GitHub token
            $('#pod-remove-token-btn').on('click', function() {
                if (!confirm('Are you sure you want to remove the GitHub token? Plugin updates will stop working.')) {
                    return;
                }

                var btn = $(this);
                btn.prop('disabled', true).text('Removing...');

                $.ajax({
                    url: podAjax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'pod_save_github_token',
                        nonce: podAjax.nonce,
                        token: '',
                        action_type: 'remove'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Token removed.');
                            location.reload();
                        } else {
                            alert('Error: ' + response.data);
                            btn.prop('disabled', false).text('Remove Token');
                        }
                    },
                    error: function() {
                        alert('AJAX Error occurred');
                        btn.prop('disabled', false).text('Remove Token');
                    }
                });
            });
        });
        </script>
        <?php
    }

    // Manual update check
    public function ajax_check_updates() {
        check_ajax_referer('pod_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access');
        }

        // Force WordPress to check for updates
        delete_site_transient('update_plugins');
        wp_update_plugins();
        
        // Check if there's an update available
        $updates = get_site_transient('update_plugins');
        $plugin_file = plugin_basename(__FILE__);
        
        if (isset($updates->response[$plugin_file])) {
            $update = $updates->response[$plugin_file];
            wp_send_json_success('Update available! Version ' . $update->new_version . ' is ready to install. Please go to the Plugins page to update.');
        } else {
            wp_send_json_success('Plugin is up to date! No updates available at this time.');
        }
    }

    // Save or remove GitHub token via AJAX
    public function ajax_save_github_token() {
        check_ajax_referer('pod_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access');
        }
        
        $action_type = sanitize_text_field($_POST['action_type']);

        if ($action_type === 'remove') {
            delete_option('pod_github_token');
            delete_option('pod_update_checker_active');
            wp_send_json_success('Token removed successfully.');
            return;
        }

        if ($action_type === 'save') {
            $token = sanitize_text_field($_POST['token']);

            // Basic format validation
            if (!preg_match('/^gh[ps]_[A-Za-z0-9]{36,}$/', $token)) {
                wp_send_json_error('Invalid token format. GitHub tokens usually start with ghp_ or ghs_.');
            }

            update_option('pod_github_token', $token);
            
            // Force update check after saving token
            delete_site_transient('update_plugins');
            
            wp_send_json_success('Token saved successfully.');
            return;
        }

        wp_send_json_error('Invalid action.');
    }
    
    public function ajax_save_payment_option() {
        // Security: Verify nonce
        check_ajax_referer('pod_nonce', 'nonce');
        
        // Security: Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access');
        }
        
        // Security: Sanitize and validate all inputs
        $payment_name = sanitize_text_field(wp_unslash($_POST['payment_name']));
        $logo_url = esc_url_raw(wp_unslash($_POST['logo_url']));
        $discount_label = sanitize_text_field(wp_unslash($_POST['discount_label']));
        $adjustment_value = floatval($_POST['adjustment_value']);
        $payment_plan_months = absint($_POST['payment_plan_months']);
        $option_id = absint($_POST['option_id']);
        
        // Security: Validate adjustment value range (prevent -100% discount which would make price $0)
        if ($adjustment_value <= -100 || $adjustment_value > 100) {
            wp_send_json_error('Invalid adjustment value. Must be between -99.99 and 100.');
        }
        
        // Security: Validate payment plan months (must be 0 or positive, max 36)
        if ($payment_plan_months < 0 || $payment_plan_months > 36) {
            wp_send_json_error('Invalid payment plan. Must be between 0 and 36 months.');
        }
        
        // Validation: At least payment name OR logo must be provided
        if (empty($payment_name) && empty($logo_url)) {
            wp_send_json_error('Please provide at least a Payment Name or Logo image.');
            return;
        }
        
        $data = array(
            'payment_name' => $payment_name,
            'logo_url' => $logo_url,
            'discount_label' => $discount_label,
            'adjustment_value' => $adjustment_value,
            'payment_plan_months' => $payment_plan_months
        );
        
        global $wpdb;
        
        if ($option_id > 0) {
            // Security: Verify the option exists before updating
            $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name} WHERE id = %d", $option_id));
            if (!$exists) {
                wp_send_json_error('Payment option not found.');
            }
            
            $result = $wpdb->update($this->table_name, $data, array('id' => $option_id));
            $message = 'Payment option updated successfully!';
        } else {
            $data['is_active'] = 1;
            $result = $wpdb->insert($this->table_name, $data);
            $message = 'Payment option added successfully!';
        }
        
        if ($result !== false) {
            wp_send_json_success($message);
        } else {
            wp_send_json_error('Database error occurred');
        }
    }
    
    public function ajax_delete_payment_option() {
        // Security: Verify nonce
        check_ajax_referer('pod_nonce', 'nonce');
        
        // Security: Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access');
        }
        
        // Security: Sanitize input
        $option_id = absint($_POST['option_id']);
        
        if ($option_id === 0) {
            wp_send_json_error('Invalid option ID');
        }
        
        global $wpdb;
        
        // Security: Verify the option exists before deleting
        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name} WHERE id = %d", $option_id));
        if (!$exists) {
            wp_send_json_error('Payment option not found.');
        }
        
        $result = $wpdb->delete($this->table_name, array('id' => $option_id), array('%d'));
        
        if ($result !== false) {
            wp_send_json_success('Payment option deleted successfully!');
        } else {
            wp_send_json_error('Database error occurred');
        }
    }
    
    public function ajax_get_payment_option() {
        // Security: Verify nonce
        check_ajax_referer('pod_nonce', 'nonce');
        
        // Security: Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access');
        }
        
        // Security: Sanitize input
        $option_id = absint($_POST['option_id']);
        
        if ($option_id === 0) {
            wp_send_json_error('Invalid option ID');
        }
        
        global $wpdb;
        $option = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $option_id));
        
        if ($option) {
            wp_send_json_success($option);
        } else {
            wp_send_json_error('Option not found');
        }
    }
    
    public function ajax_save_settings() {
        // Security: Verify nonce
        check_ajax_referer('pod_nonce', 'nonce');
        
        // Security: Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access');
        }
        
        // Security: Sanitize inputs
        $apply_to_all = sanitize_text_field($_POST['apply_to_all']);
        
        // Security: Validate apply_to_all value
        if (!in_array($apply_to_all, array('yes', 'no'), true)) {
            wp_send_json_error('Invalid setting value');
        }
        
        // Security: Sanitize array of product IDs
        $excluded_products = isset($_POST['excluded_products']) ? array_map('absint', $_POST['excluded_products']) : array();
        
        update_option('pod_apply_to_all', $apply_to_all);
        update_option('pod_excluded_products', $excluded_products);
        
        wp_send_json_success('Settings saved successfully!');
    }
    
    public function ajax_toggle_status() {
        // Security: Verify nonce
        check_ajax_referer('pod_nonce', 'nonce');
        
        // Security: Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access');
        }
        
        // Security: Sanitize inputs
        $option_id = absint($_POST['option_id']);
        $is_active = absint($_POST['is_active']);
        
        if ($option_id === 0) {
            wp_send_json_error('Invalid option ID');
        }
        
        // Security: Validate is_active value (must be 0 or 1)
        if ($is_active !== 0 && $is_active !== 1) {
            wp_send_json_error('Invalid status value');
        }
        
        global $wpdb;
        
        // Security: Verify the option exists before updating
        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name} WHERE id = %d", $option_id));
        if (!$exists) {
            wp_send_json_error('Payment option not found.');
        }
        
        $result = $wpdb->update(
            $this->table_name,
            array('is_active' => $is_active),
            array('id' => $option_id),
            array('%d'),
            array('%d')
        );
        
        if ($result !== false) {
            wp_send_json_success('Status updated successfully!');
        } else {
            wp_send_json_error('Database error occurred');
        }
    }
    
    public function display_payment_options() {
        // Security: Check if required functions exist
        if (!function_exists('is_product') || !function_exists('wc_price')) {
            return;
        }
        
        if (!is_product()) {
            return;
        }
        
        global $wpdb, $product;
        
        if (!$product) {
            return;
        }
        
        $apply_to_all = get_option('pod_apply_to_all', 'yes');
        $excluded_products = get_option('pod_excluded_products', array());
        $current_product_id = absint($product->get_id());
        
        // Security: Validate excluded products are integers
        $excluded_products = array_map('absint', (array) $excluded_products);
        
        if ($apply_to_all === 'no' && in_array($current_product_id, $excluded_products, true)) {
            return;
        }
        
        // Security: Use prepared statement
        $options = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE is_active = %d ORDER BY display_order ASC, id ASC",
            1
        ));
        
        if (empty($options)) {
            return;
        }
        
        $product_price = floatval($product->get_price());
        
        if (empty($product_price)) {
            return;
        }
        
        echo '<div class="pod-payment-grid">';
        
        foreach ($options as $option) {
            // Security: Validate and sanitize option data
            $adjustment_value = floatval($option->adjustment_value);
            $payment_plan_months = absint($option->payment_plan_months);
            
            // Apply adjustment value (negative = discount, positive = fee)
            if ($adjustment_value != 0) {
                $adjustment_amount = ($product_price * $adjustment_value) / 100;
                $final_price = $product_price + $adjustment_amount;
            } else {
                $final_price = $product_price;
            }
            
            // Security: Ensure price is never negative
            $final_price = max(0, $final_price);
            
            $display_price = wc_price($final_price);
            $payment_plan_text = '';
            
            if ($payment_plan_months > 0) {
                $monthly_price = $final_price / $payment_plan_months;
                $payment_plan_text = wc_price($monthly_price) . ' x ' . absint($payment_plan_months) . ' months';
            }
            
            echo '<div class="pod-payment-box">';
            
            if (!empty($option->discount_label)) {
                echo '<div class="pod-discount-badge">' . esc_html($option->discount_label) . '</div>';
            }
            
            if (!empty($option->payment_name)) {
                echo '<div class="pod-payment-name">' . esc_html($option->payment_name) . '</div>';
            }
            
            if (!empty($option->logo_url)) {
                echo '<div class="pod-payment-logo">';
                echo '<img src="' . esc_url($option->logo_url) . '" alt="' . esc_attr($option->payment_name) . '">';
                echo '</div>';
            }
            
            echo '<div class="pod-payment-price">' . wp_kses_post($display_price) . '</div>';
            
            if (!empty($payment_plan_text)) {
                echo '<div class="pod-payment-plan">' . wp_kses_post($payment_plan_text) . '</div>';
            }
            
            echo '</div>';
        }
        
        echo '</div>';
    }
}

new Payment_Options_Display();