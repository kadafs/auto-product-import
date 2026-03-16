<?php
/**
 * Scheduling functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class APS_Scheduler {
    
    public function __construct() {
        add_action('aps_scheduled_sync', array($this, 'run_scheduled_sync'));
        add_action('init', array($this, 'schedule_sync'));
    }
    
    /**
     * Schedule sync based on settings
     */
    public function schedule_sync() {
        $frequency = get_option('aps_schedule_frequency', 'off');
        $time = get_option('aps_schedule_time', '02:00');
        
        // Clear existing scheduled event
        wp_clear_scheduled_hook('aps_scheduled_sync');
        
        if ($frequency === 'off') {
            return;
        }
        
        // Parse time
        list($hour, $minute) = explode(':', $time);
        $hour = intval($hour);
        $minute = intval($minute);
        
        // Calculate next run time
        $next_run = $this->calculate_next_run_time($frequency, $hour, $minute);
        
        // Schedule the event
        wp_schedule_event($next_run, $frequency, 'aps_scheduled_sync');
        
        // Register custom intervals if needed
        add_filter('cron_schedules', array($this, 'add_custom_cron_intervals'));
    }
    
    /**
     * Add custom cron intervals
     */
    public function add_custom_cron_intervals($schedules) {
        if (!isset($schedules['weekly'])) {
            $schedules['weekly'] = array(
                'interval' => 604800, // 7 days in seconds
                'display' => __('Weekly', 'auto-product-sync')
            );
        }
        
        if (!isset($schedules['monthly'])) {
            $schedules['monthly'] = array(
                'interval' => 2635200, // 30.5 days in seconds (average month)
                'display' => __('Monthly', 'auto-product-sync')
            );
        }
        
        return $schedules;
    }
    
    /**
     * Calculate next run time
     */
    private function calculate_next_run_time($frequency, $hour, $minute) {
        $now = current_time('timestamp');
        $today = strtotime(date('Y-m-d', $now));
        $scheduled_time_today = $today + ($hour * 3600) + ($minute * 60);
        
        switch ($frequency) {
            case 'daily':
                if ($now < $scheduled_time_today) {
                    return $scheduled_time_today;
                } else {
                    return $scheduled_time_today + 86400; // Tomorrow
                }
                
            case 'weekly':
                $days_ahead = 7;
                if ($now < $scheduled_time_today) {
                    $days_ahead = 0;
                }
                return $scheduled_time_today + ($days_ahead * 86400);
                
            case 'monthly':
                $next_month = strtotime('+1 month', $today);
                if ($now < $scheduled_time_today) {
                    return $scheduled_time_today;
                } else {
                    return $next_month + ($hour * 3600) + ($minute * 60);
                }
                
            default:
                return $scheduled_time_today + 86400;
        }
    }
    
    /**
     * Run scheduled sync
     */
    public function run_scheduled_sync() {
        $logger = new APS_Logger();
        $logger->log('Starting scheduled sync', 'info');
        
        // Use the core class method to trigger bulk sync process
        $core = new APS_Core();
        $core->run_scheduled_sync_process();
        
        $logger->log('Scheduled sync completed', 'info');
        
        // Reschedule next run
        $this->schedule_sync();
    }
    
    /**
     * Get next scheduled run time
     */
    public function get_next_scheduled_run() {
        $timestamp = wp_next_scheduled('aps_scheduled_sync');
        
        if (!$timestamp) {
            return false;
        }
        
        return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }
    
    /**
     * Manual trigger of scheduled sync
     */
    public function trigger_manual_sync() {
        wp_schedule_single_event(time(), 'aps_scheduled_sync');
    }
}