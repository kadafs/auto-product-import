<?php
/**
 * Admin interface class - SECURITY HARDENED + SERVER CRON
 */

if (!defined('ABSPATH')) {
    exit;
}

class APS_Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('admin_init', array($this, 'save_settings'));
        add_action('admin_init', array($this, 'ensure_cron_key'));
    }
    
    /**
     * Ensure cron secret key exists
     */
    public function ensure_cron_key() {
        $key = get_option('aps_cron_secret_key');
        if (empty($key)) {
            $new_key = wp_generate_password(32, false);
            update_option('aps_cron_secret_key', $new_key);
        }
    }
    
    /**
     * Add admin menu with submenus
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Auto Product Sync', 'auto-product-sync'),
            __('Auto Product Sync', 'auto-product-sync'),
            'manage_options',
            'auto-product-sync',
            array($this, 'products_page'),
            'dashicons-update',
            56
        );
        
        add_submenu_page(
            'auto-product-sync',
            __('Products', 'auto-product-sync'),
            __('Products', 'auto-product-sync'),
            'manage_options',
            'auto-product-sync',
            array($this, 'products_page')
        );
        
        add_submenu_page(
            'auto-product-sync',
            __('Recent Activity', 'auto-product-sync'),
            __('Recent Activity', 'auto-product-sync'),
            'manage_options',
            'auto-product-sync-activity',
            array($this, 'activity_page')
        );
        
        add_submenu_page(
            'auto-product-sync',
            __('Settings', 'auto-product-sync'),
            __('Settings', 'auto-product-sync'),
            'manage_options',
            'auto-product-sync-settings',
            array($this, 'settings_page')
        );
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        $our_pages = array(
            'toplevel_page_auto-product-sync',
            'auto-product-sync_page_auto-product-sync-activity',
            'auto-product-sync_page_auto-product-sync-settings'
        );
        
        if (!in_array($hook, $our_pages) && $hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }
        
        wp_enqueue_script('jquery');
        wp_enqueue_script('aps-admin', APS_PLUGIN_URL . 'assets/admin.js', array('jquery'), APS_VERSION, true);
        wp_enqueue_style('aps-admin', APS_PLUGIN_URL . 'assets/admin.css', array(), APS_VERSION);
        
        wp_localize_script('aps-admin', 'aps_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('aps_nonce'),
            'strings' => array(
                'sync_single' => esc_js(__('Syncing product...', 'auto-product-sync')),
                'sync_all' => esc_js(__('Starting bulk sync...', 'auto-product-sync')),
                'sync_complete' => esc_js(__('Sync completed!', 'auto-product-sync')),
                'sync_error' => esc_js(__('Sync failed!', 'auto-product-sync')),
                'confirm_bulk' => esc_js(__('This will sync all enabled products. This may take several minutes. Continue?', 'auto-product-sync'))
            )
        ));
    }
    
    /**
     * Save settings - INPUT VALIDATION FIXED
     */
    public function save_settings() {
        if (!isset($_POST['aps_save_settings']) && !isset($_POST['aps_regenerate_key'])) {
            return;
        }
        
        if (!wp_verify_nonce($_POST['aps_nonce'], 'aps_settings')) {
            wp_die(__('Security check failed', 'auto-product-sync'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized access', 'auto-product-sync'));
        }
        
        // Handle key regeneration
        if (isset($_POST['aps_regenerate_key'])) {
            $new_key = wp_generate_password(32, false);
            update_option('aps_cron_secret_key', $new_key);
            
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Cron secret key regenerated! Update your server cron job with the new URL.', 'auto-product-sync') . '</p></div>';
            });
            return;
        }
        
        // Validate detailed logging
        $detailed_logging = isset($_POST['aps_detailed_logging']) && $_POST['aps_detailed_logging'] === 'yes' ? 'yes' : 'no';
        update_option('aps_detailed_logging', $detailed_logging);
        
        // Validate email
        $admin_email = isset($_POST['aps_admin_email']) ? sanitize_email($_POST['aps_admin_email']) : get_option('admin_email');
        if (!is_email($admin_email)) {
            $admin_email = get_option('admin_email');
        }
        update_option('aps_admin_email', $admin_email);
        
        // Validate fetch timeout (1-60 seconds)
        $fetch_timeout = isset($_POST['aps_fetch_timeout']) ? absint($_POST['aps_fetch_timeout']) : 30;
        $fetch_timeout = max(1, min(60, $fetch_timeout));
        update_option('aps_fetch_timeout', $fetch_timeout);
        
        // Validate batch size (1-25 products)
        $batch_size = isset($_POST['aps_batch_size']) ? absint($_POST['aps_batch_size']) : 10;
        $batch_size = max(1, min(25, $batch_size));
        update_option('aps_batch_size', $batch_size);
        
        // Validate skip recent sync
        $skip_recent = isset($_POST['aps_skip_recent_sync']) && $_POST['aps_skip_recent_sync'] === 'yes' ? 'yes' : 'no';
        update_option('aps_skip_recent_sync', $skip_recent);
        
        // Validate skip recent hours (1-168 hours = 1 week max)
        $skip_hours = isset($_POST['aps_skip_recent_hours']) ? absint($_POST['aps_skip_recent_hours']) : 24;
        $skip_hours = max(1, min(168, $skip_hours));
        update_option('aps_skip_recent_hours', $skip_hours);
        
        // Validate max errors (1-99)
        $max_errors = isset($_POST['aps_max_errors']) ? absint($_POST['aps_max_errors']) : 1;
        $max_errors = max(1, min(99, $max_errors));
        update_option('aps_max_errors', $max_errors);
        
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved!', 'auto-product-sync') . '</p></div>';
        });
    }
    
    /**
     * Products page (main page) - XSS FIXED
     */
    public function products_page() {
        $products = APS_Core::get_products_with_urls(100, 1);
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Auto Product Sync', 'auto-product-sync'); ?></h1>
            
            <div class="aps-admin-container">
                <div class="aps-products-section">
                    <h2><?php echo esc_html__('Products with URLs', 'auto-product-sync'); ?></h2>
                    
                    <div class="aps-actions">
                        <button type="button" id="aps-sync-all" class="button button-primary">
                            <?php echo esc_html__('Download Prices', 'auto-product-sync'); ?>
                        </button>
                        <button type="button" id="aps-clear-lock" class="button button-secondary">
                            <?php echo esc_html__('Clear Lock', 'auto-product-sync'); ?>
                        </button>
                        <span id="aps-sync-status" class="aps-status"></span>
                        <div id="aps-progress-container" style="display: none; margin-top: 10px;">
                            <div class="aps-progress">
                                <div id="aps-progress-bar" class="aps-progress-bar" style="width: 0%;"></div>
                                <div id="aps-progress-text" class="aps-progress-text">0%</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="aps-table-container">
                        <table id="aps-products-table" class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th class="sortable aps-sortable" data-column="name">
                                        <?php echo esc_html__('Product Name', 'auto-product-sync'); ?> 
                                        <span class="sort-indicator">↕</span>
                                    </th>
                                    <th class="sortable aps-sortable" data-column="category">
                                        <?php echo esc_html__('Category', 'auto-product-sync'); ?> 
                                        <span class="sort-indicator">↕</span>
                                    </th>
                                    <th class="sortable aps-sortable" data-column="enabled">
                                        <?php echo esc_html__('Enabled', 'auto-product-sync'); ?> 
                                        <span class="sort-indicator">↕</span>
                                    </th>
                                    <th class="sortable aps-sortable" data-column="status">
                                        <?php echo esc_html__('Status', 'auto-product-sync'); ?> 
                                        <span class="sort-indicator">↕</span>
                                    </th>
                                    <th class="sortable aps-sortable" data-column="retry">
                                        <?php echo esc_html__('Error Count', 'auto-product-sync'); ?> 
                                        <span class="sort-indicator">↕</span>
                                    </th>
                                    <th class="sortable aps-sortable" data-column="timestamp">
                                        <?php echo esc_html__('Timestamp', 'auto-product-sync'); ?> 
                                        <span class="sort-indicator">↕</span>
                                    </th>
                                    <th><?php echo esc_html__('Actions', 'auto-product-sync'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($products)): ?>
                                    <tr>
                                        <td colspan="7"><?php echo esc_html__('No products with URLs found', 'auto-product-sync'); ?></td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($products as $product): 
                                        $enabled = APS_Core::get_product_meta($product->ID, '_aps_enable_sync') === 'yes';
                                        $error_date = APS_Core::get_product_meta($product->ID, '_aps_error_date');
                                        $last_status = APS_Core::get_product_meta($product->ID, '_aps_last_status');
                                        $last_sync_timestamp = APS_Core::get_product_meta($product->ID, '_aps_last_sync_timestamp');
                                        $retry_count = APS_Core::get_product_meta($product->ID, '_aps_retry_count') ?: 0;
                                        $categories = APS_Core::get_product_categories($product->ID);
                                        $edit_url = admin_url('post.php?post=' . absint($product->ID) . '&action=edit');
                                    ?>
                                        <tr data-product-id="<?php echo absint($product->ID); ?>">
                                            <td class="product-name">
                                                <a href="<?php echo esc_url($edit_url); ?>" target="_blank">
                                                    <?php echo esc_html($product->post_title); ?>
                                                </a>
                                            </td>
                                            <td class="product-category">
                                                <?php if (!empty($categories)): ?>
                                                    <?php foreach ($categories as $category): ?>
                                                        <div class="category-item"><?php echo esc_html($category); ?></div>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <span class="no-category">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="product-enabled">
                                                <span class="aps-status-<?php echo $enabled ? 'enabled' : 'disabled'; ?>">
                                                    <?php echo $enabled ? esc_html__('Yes', 'auto-product-sync') : esc_html__('No', 'auto-product-sync'); ?>
                                                </span>
                                            </td>
                                            <td class="product-error">
                                                <?php if ($error_date): ?>
                                                    <div class="aps-error-date"><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($error_date))); ?></div>
                                                <?php endif; ?>
                                                <?php if ($last_status): ?>
                                                    <div class="aps-last-status"><?php echo esc_html($last_status); ?></div>
                                                <?php endif; ?>
                                                <?php if (!$error_date && !$last_status): ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                            <td class="product-retry">
                                                <span class="aps-retry-count"><?php echo absint($retry_count); ?>/<?php echo absint(get_option('aps_max_errors', 1)); ?></span>
                                            </td>
                                            <td class="product-timestamp">
                                                <?php if ($last_sync_timestamp): ?>
                                                    <?php 
                                                    $formatted_time = get_date_from_gmt(date('Y-m-d H:i:s', $last_sync_timestamp), get_option('date_format') . ' ' . get_option('time_format'));
                                                    echo esc_html($formatted_time);
                                                    ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="product-actions">
                                                <button type="button" class="button button-small aps-sync-single" data-product-id="<?php echo absint($product->ID); ?>">
                                                    <?php echo esc_html__('Sync', 'auto-product-sync'); ?>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Recent Activity page
     */
    public function activity_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Recent Activity', 'auto-product-sync'); ?></h1>
            
            <div class="aps-admin-container">
                <div class="aps-log-section">
                    <?php $this->display_recent_logs(); ?>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Settings page with Server Cron Setup
     */
    public function settings_page() {
        $detailed_logging = get_option('aps_detailed_logging', 'no');
        $admin_email = get_option('aps_admin_email', get_option('admin_email'));
        $fetch_timeout = absint(get_option('aps_fetch_timeout', 30));
        $batch_size = absint(get_option('aps_batch_size', 10));
        $cron_key = get_option('aps_cron_secret_key', '');
        $cron_url = home_url('/?aps_cron=1&key=' . $cron_key);
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Settings', 'auto-product-sync'); ?></h1>
            
            <div class="aps-admin-container">
                <div class="aps-settings-section">
                    <h2><?php echo esc_html__('Server Cron Setup', 'auto-product-sync'); ?></h2>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php echo esc_html__('Cron URL', 'auto-product-sync'); ?></th>
                            <td>
                                <input type="text" value="<?php echo esc_attr($cron_url); ?>" class="large-text" readonly onclick="this.select();" style="font-family: monospace;">
                                <button type="button" class="button" onclick="navigator.clipboard.writeText('<?php echo esc_js($cron_url); ?>'); this.textContent='Copied!'; setTimeout(() => this.textContent='Copy', 2000);">
                                    <?php echo esc_html__('Copy', 'auto-product-sync'); ?>
                                </button>
                                <p class="description"><?php echo esc_html__('Use this URL in your server cron job to trigger automatic price updates.', 'auto-product-sync'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Secret Key', 'auto-product-sync'); ?></th>
                            <td>
                                <code style="background: #f0f0f1; padding: 8px 12px; display: inline-block; margin-bottom: 10px;"><?php echo esc_html($cron_key); ?></code>
                                <form method="post" action="" style="display: inline;">
                                    <?php wp_nonce_field('aps_settings', 'aps_nonce'); ?>
                                    <button type="submit" name="aps_regenerate_key" class="button button-secondary" onclick="return confirm('<?php echo esc_js(__('This will invalidate the current cron URL. You will need to update your server cron job. Continue?', 'auto-product-sync')); ?>');">
                                        <?php echo esc_html__('Regenerate Key', 'auto-product-sync'); ?>
                                    </button>
                                </form>
                                <p class="description"><?php echo esc_html__('Keep this key secure. Regenerating will require updating your server cron job.', 'auto-product-sync'); ?></p>
                            </td>
                        </tr>
                    </table>
                    
                    <h3><?php echo esc_html__('Setup Instructions', 'auto-product-sync'); ?></h3>
                    <div class="aps-cron-tabs">
                        <nav class="nav-tab-wrapper">
                            <a href="#cpanel" class="nav-tab nav-tab-active"><?php echo esc_html__('cPanel', 'auto-product-sync'); ?></a>
                            <a href="#ssh" class="nav-tab"><?php echo esc_html__('SSH/Crontab', 'auto-product-sync'); ?></a>
                            <a href="#plesk" class="nav-tab"><?php echo esc_html__('Plesk', 'auto-product-sync'); ?></a>
                        </nav>
                        
                        <div id="cpanel" class="tab-content active">
                            <h4><?php echo esc_html__('cPanel Setup (Every 5 Minutes)', 'auto-product-sync'); ?></h4>
                            <ol>
                                <li><?php echo esc_html__('Log into your cPanel account', 'auto-product-sync'); ?></li>
                                <li><?php echo esc_html__('Navigate to "Cron Jobs" under Advanced section', 'auto-product-sync'); ?></li>
                                <li><?php echo esc_html__('In the "Add New Cron Job" section:', 'auto-product-sync'); ?>
                                    <ul style="margin-left: 20px; list-style: disc;">
                                        <li><?php echo esc_html__('Common Settings: Select "Every 5 minutes"', 'auto-product-sync'); ?></li>
                                        <li><?php echo esc_html__('Command:', 'auto-product-sync'); ?><br>
                                            <code style="background: #f0f0f1; padding: 8px; display: block; margin: 5px 0; overflow-x: auto;">/usr/bin/php -q <?php echo esc_html(ABSPATH); ?>wp-content/plugins/auto-product-sync/aps-cron-trigger.php</code>
                                        </li>
                                    </ul>
                                </li>
                                <li><?php echo esc_html__('Click "Add New Cron Job"', 'auto-product-sync'); ?></li>
                            </ol>
                            <p class="description"><strong><?php echo esc_html__('Note:', 'auto-product-sync'); ?></strong> <?php echo esc_html__('The sync runs every 5 minutes and processes one batch per run. Enable "Skip Recently Synced" below to avoid updating products synced in the last 24 hours.', 'auto-product-sync'); ?></p>
                        </div>
                        
                        <div id="ssh" class="tab-content">
                            <h4><?php echo esc_html__('SSH/Crontab Setup (Every 5 Minutes)', 'auto-product-sync'); ?></h4>
                            <ol>
                                <li><?php echo esc_html__('Connect to your server via SSH', 'auto-product-sync'); ?></li>
                                <li><?php echo esc_html__('Edit your crontab:', 'auto-product-sync'); ?><br>
                                    <code style="background: #f0f0f1; padding: 8px; display: block; margin: 5px 0;">crontab -e</code>
                                </li>
                                <li><?php echo esc_html__('Add this line (runs every 5 minutes):', 'auto-product-sync'); ?><br>
                                    <code style="background: #f0f0f1; padding: 8px; display: block; margin: 5px 0; overflow-x: auto;">*/5 * * * * /usr/bin/php -q <?php echo esc_html(ABSPATH); ?>wp-content/plugins/auto-product-sync/aps-cron-trigger.php</code>
                                </li>
                                <li><?php echo esc_html__('Save and exit (in vi/vim: press ESC, then type :wq)', 'auto-product-sync'); ?></li>
                            </ol>
                            <p><strong><?php echo esc_html__('Alternative Schedules:', 'auto-product-sync'); ?></strong></p>
                            <ul style="margin-left: 20px; list-style: disc;">
                                <li><code>*/5 * * * *</code> - <?php echo esc_html__('Every 5 minutes (recommended)', 'auto-product-sync'); ?></li>
                                <li><code>*/10 * * * *</code> - <?php echo esc_html__('Every 10 minutes', 'auto-product-sync'); ?></li>
                                <li><code>*/15 * * * *</code> - <?php echo esc_html__('Every 15 minutes', 'auto-product-sync'); ?></li>
                                <li><code>0 */2 * * *</code> - <?php echo esc_html__('Every 2 hours', 'auto-product-sync'); ?></li>
                            </ul>
                            <p class="description"><strong><?php echo esc_html__('Note:', 'auto-product-sync'); ?></strong> <?php echo esc_html__('Each cron run processes one batch of products. Enable "Skip Recently Synced" below to avoid re-syncing products within 24 hours.', 'auto-product-sync'); ?></p>
                        </div>
                        
                        <div id="plesk" class="tab-content">
                            <h4><?php echo esc_html__('Plesk Setup (Every 5 Minutes)', 'auto-product-sync'); ?></h4>
                            <ol>
                                <li><?php echo esc_html__('Log into Plesk', 'auto-product-sync'); ?></li>
                                <li><?php echo esc_html__('Navigate to your domain', 'auto-product-sync'); ?></li>
                                <li><?php echo esc_html__('Click on "Scheduled Tasks" (or "Cron Jobs")', 'auto-product-sync'); ?></li>
                                <li><?php echo esc_html__('Click "Add Task" or "Schedule a Task"', 'auto-product-sync'); ?></li>
                                <li><?php echo esc_html__('Configure the task:', 'auto-product-sync'); ?>
                                    <ul style="margin-left: 20px; list-style: disc;">
                                        <li><strong><?php echo esc_html__('Task type:', 'auto-product-sync'); ?></strong> <?php echo esc_html__('Run a command', 'auto-product-sync'); ?></li>
                                        <li><strong><?php echo esc_html__('Schedule:', 'auto-product-sync'); ?></strong>
                                            <ul style="margin-left: 20px; list-style: circle;">
                                                <li><?php echo esc_html__('Minute: */5', 'auto-product-sync'); ?></li>
                                                <li><?php echo esc_html__('Hour: *', 'auto-product-sync'); ?></li>
                                                <li><?php echo esc_html__('Day: *', 'auto-product-sync'); ?></li>
                                                <li><?php echo esc_html__('Month: *', 'auto-product-sync'); ?></li>
                                                <li><?php echo esc_html__('Weekday: *', 'auto-product-sync'); ?></li>
                                            </ul>
                                            <em><?php echo esc_html__('(This runs every 5 minutes)', 'auto-product-sync'); ?></em>
                                        </li>
                                        <li><strong><?php echo esc_html__('Command:', 'auto-product-sync'); ?></strong><br>
                                            <code style="background: #f0f0f1; padding: 8px; display: block; margin: 5px 0; overflow-x: auto;">/opt/plesk/php/8.3/bin/php /var/www/vhosts/artinmetal.com.au/artinmetal.au/wp-content/plugins/auto-product-sync/aps-cron-trigger.php</code>
                                            <p class="description"><?php echo esc_html__('Adjust the PHP path and full path to match your server configuration', 'auto-product-sync'); ?></p>
                                        </li>
                                    </ul>
                                </li>
                                <li><?php echo esc_html__('Save the task', 'auto-product-sync'); ?></li>
                                <li><?php echo esc_html__('Optional: Click "Run Now" to test', 'auto-product-sync'); ?></li>
                            </ol>
                            <p class="description"><strong><?php echo esc_html__('Note:', 'auto-product-sync'); ?></strong> <?php echo esc_html__('Each 5-minute cron run processes one batch. Enable "Skip Recently Synced" below to prevent updating products synced within 24 hours.', 'auto-product-sync'); ?></p>
                        </div>
                    </div>
                    
                    <style>
                        .aps-cron-tabs { margin-top: 20px; }
                        .nav-tab-wrapper { border-bottom: 1px solid #ccc; margin-bottom: 0; }
                        .tab-content { display: none; padding: 20px; background: #fff; border: 1px solid #ccc; border-top: none; }
                        .tab-content.active { display: block; }
                        .tab-content ol { margin-left: 20px; }
                        .tab-content ol li { margin-bottom: 15px; }
                        .tab-content code { font-size: 13px; }
                    </style>
                    
                    <script>
                    jQuery(document).ready(function($) {
                        $('.nav-tab').on('click', function(e) {
                            e.preventDefault();
                            $('.nav-tab').removeClass('nav-tab-active');
                            $('.tab-content').removeClass('active');
                            $(this).addClass('nav-tab-active');
                            $($(this).attr('href')).addClass('active');
                        });
                    });
                    </script>
                </div>
                
                <div class="aps-settings-section" style="margin-top: 20px;">
                    <h2><?php echo esc_html__('Plugin Settings', 'auto-product-sync'); ?></h2>
                    <form method="post" action="">
                        <?php wp_nonce_field('aps_settings', 'aps_nonce'); ?>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php echo esc_html__('Max Errors Before Hiding', 'auto-product-sync'); ?></th>
                                <td>
                                    <input type="number" name="aps_max_errors" value="<?php echo esc_attr(get_option('aps_max_errors', 1)); ?>" min="1" max="99" class="small-text">
                                    <p class="description"><?php echo esc_html__('Number of consecutive sync failures allowed before hiding a product from the catalog (1-99). Each failure increments the error counter. A successful sync resets the counter to 0.', 'auto-product-sync'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Skip Recently Synced Products', 'auto-product-sync'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="aps_skip_recent_sync" value="yes" <?php checked(get_option('aps_skip_recent_sync', 'no'), 'yes'); ?>>
                                        <?php echo esc_html__('Skip products synced within the last', 'auto-product-sync'); ?>
                                    </label>
                                    <input type="number" name="aps_skip_recent_hours" value="<?php echo esc_attr(get_option('aps_skip_recent_hours', 24)); ?>" min="1" max="168" class="small-text"> <?php echo esc_html__('hours', 'auto-product-sync'); ?>
                                    <p class="description"><?php echo esc_html__('When enabled, cron jobs will skip products that have been successfully synced recently. This prevents unnecessary API calls and speeds up sync times. Recommended for frequent cron schedules (every 5-15 minutes).', 'auto-product-sync'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('URL Fetch Timeout', 'auto-product-sync'); ?></th>
                                <td>
                                    <input type="number" name="aps_fetch_timeout" value="<?php echo esc_attr($fetch_timeout); ?>" min="1" max="60" class="small-text"> <?php echo esc_html__('seconds', 'auto-product-sync'); ?>
                                    <p class="description"><?php echo esc_html__('Maximum time to wait when fetching prices from URLs (1-60 seconds). Lower values speed up sync but may cause timeouts.', 'auto-product-sync'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Batch Size', 'auto-product-sync'); ?></th>
                                <td>
                                    <input type="number" name="aps_batch_size" value="<?php echo esc_attr($batch_size); ?>" min="1" max="25" class="small-text"> <?php echo esc_html__('products', 'auto-product-sync'); ?>
                                    <p class="description"><?php echo esc_html__('Number of products to process in each batch during bulk sync (1-25). Smaller batches prevent server timeouts.', 'auto-product-sync'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Detailed Logging', 'auto-product-sync'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="aps_detailed_logging" value="yes" <?php checked($detailed_logging, 'yes'); ?>>
                                        <?php echo esc_html__('Enable detailed logging', 'auto-product-sync'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Admin Email', 'auto-product-sync'); ?></th>
                                <td>
                                    <input type="email" name="aps_admin_email" value="<?php echo esc_attr($admin_email); ?>" class="regular-text" required>
                                    <p class="description"><?php echo esc_html__('Email address for error notifications', 'auto-product-sync'); ?></p>
                                </td>
                            </tr>
                        </table>
                        
                        <?php submit_button(esc_html__('Save Settings', 'auto-product-sync'), 'primary', 'aps_save_settings'); ?>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Display recent sync logs - SQL INJECTION FIXED
     */
    private function display_recent_logs() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'aps_sync_log';
        
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT l.*, p.post_title 
            FROM {$wpdb->prefix}aps_sync_log l 
            LEFT JOIN {$wpdb->posts} p ON l.product_id = p.ID 
            ORDER BY l.sync_time DESC 
            LIMIT %d",
            20
        ));
        
        if (empty($logs)) {
            echo '<p>' . esc_html__('No sync activity yet.', 'auto-product-sync') . '</p>';
            return;
        }
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>' . esc_html__('Time', 'auto-product-sync') . '</th>';
        echo '<th>' . esc_html__('Product', 'auto-product-sync') . '</th>';
        echo '<th>' . esc_html__('Status', 'auto-product-sync') . '</th>';
        echo '<th>' . esc_html__('Message', 'auto-product-sync') . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        foreach ($logs as $log) {
            $product_title = $log->post_title ? esc_html($log->post_title) : 'Product #' . absint($log->product_id);
            $status_class = sanitize_html_class('aps-log-status-' . $log->status);
            
            echo '<tr>';
            echo '<td>' . esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($log->sync_time))) . '</td>';
            echo '<td>' . $product_title . '</td>';
            echo '<td><span class="' . $status_class . '">' . esc_html(ucfirst($log->status)) . '</span></td>';
            echo '<td>' . esc_html($log->message) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
    }
}