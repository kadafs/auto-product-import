<?php
/**
 * Logging functionality - ERROR HANDLING IMPROVED
 */

if (!defined('ABSPATH')) {
    exit;
}

class APS_Logger {
    
    private $detailed_logging;
    private $max_log_size = 10485760; // 10MB
    
    public function __construct() {
        $this->detailed_logging = get_option('aps_detailed_logging', 'no') === 'yes';
    }
    
    /**
     * Log a message
     */
    public function log($message, $level = 'info') {
        // Always log errors and success messages
        if (!$this->detailed_logging && !in_array($level, array('error', 'success'))) {
            return;
        }
        
        // Sanitize message
        $message = sanitize_text_field($message);
        
        $log_entry = sprintf(
            "[%s] [%s] %s\n",
            current_time('Y-m-d H:i:s'),
            strtoupper(sanitize_key($level)),
            $message
        );
        
        // Write to WordPress debug log if enabled
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('APS: ' . $log_entry);
        }
        
        // Write to custom log file
        $this->write_to_log_file($log_entry);
    }
    
    /**
     * Write to custom log file - ERROR HANDLING ADDED
     */
    private function write_to_log_file($log_entry) {
        $uploads_dir = wp_upload_dir();
        $log_dir = $uploads_dir['basedir'] . '/aps-logs';
        
        // Create log directory if it doesn't exist
        if (!file_exists($log_dir)) {
            if (!wp_mkdir_p($log_dir)) {
                error_log('APS: Failed to create log directory');
                return;
            }
            
            // Create .htaccess to protect log files
            $htaccess_content = "Order deny,allow\nDeny from all\n";
            $htaccess_result = @file_put_contents($log_dir . '/.htaccess', $htaccess_content);
            
            if ($htaccess_result === false) {
                error_log('APS: Failed to create .htaccess file');
            }
        }
        
        $log_file = $log_dir . '/aps-' . date('Y-m') . '.log';
        
        // Check file size before writing
        if (file_exists($log_file) && filesize($log_file) > $this->max_log_size) {
            $this->rotate_logs($log_dir);
        }
        
        // Append to log file with error handling
        $result = @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
        
        if ($result === false) {
            error_log('APS: Failed to write to log file: ' . $log_file);
        }
    }
    
    /**
     * Rotate log files
     */
    private function rotate_logs($log_dir) {
        $files = glob($log_dir . '/aps-*.log');
        
        if (empty($files) || count($files) <= 3) {
            return;
        }
        
        // Sort files by modification time
        usort($files, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        
        // Remove oldest files (keep only 3 most recent)
        $files_to_remove = array_slice($files, 0, -3);
        foreach ($files_to_remove as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }
    
    /**
     * Get log files
     */
    public function get_log_files() {
        $uploads_dir = wp_upload_dir();
        $log_dir = $uploads_dir['basedir'] . '/aps-logs';
        
        if (!file_exists($log_dir)) {
            return array();
        }
        
        $files = glob($log_dir . '/aps-*.log');
        if (empty($files)) {
            return array();
        }
        
        $log_files = array();
        
        foreach ($files as $file) {
            if (file_exists($file)) {
                $log_files[] = array(
                    'name' => basename($file),
                    'path' => $file,
                    'size' => filesize($file),
                    'modified' => filemtime($file)
                );
            }
        }
        
        // Sort by modification time (newest first)
        usort($log_files, function($a, $b) {
            return $b['modified'] - $a['modified'];
        });
        
        return $log_files;
    }
    
    /**
     * Read log file content - SECURITY IMPROVED
     */
    public function read_log_file($filename) {
        // Sanitize filename to prevent directory traversal
        $filename = basename($filename);
        
        // Validate filename format
        if (!preg_match('/^aps-\d{4}-\d{2}\.log$/', $filename)) {
            return false;
        }
        
        $uploads_dir = wp_upload_dir();
        $log_file = $uploads_dir['basedir'] . '/aps-logs/' . $filename;
        
        if (!file_exists($log_file)) {
            return false;
        }
        
        // Check if file is within allowed directory
        $real_path = realpath($log_file);
        $real_log_dir = realpath($uploads_dir['basedir'] . '/aps-logs/');
        
        if (strpos($real_path, $real_log_dir) !== 0) {
            error_log('APS: Attempted directory traversal: ' . $filename);
            return false;
        }
        
        return @file_get_contents($log_file);
    }
    
    /**
     * Clear log file - SECURITY IMPROVED
     */
    public function clear_log_file($filename) {
        // Sanitize filename
        $filename = basename($filename);
        
        // Validate filename format
        if (!preg_match('/^aps-\d{4}-\d{2}\.log$/', $filename)) {
            return false;
        }
        
        $uploads_dir = wp_upload_dir();
        $log_file = $uploads_dir['basedir'] . '/aps-logs/' . $filename;
        
        if (!file_exists($log_file)) {
            return false;
        }
        
        // Verify file is in correct directory
        $real_path = realpath($log_file);
        $real_log_dir = realpath($uploads_dir['basedir'] . '/aps-logs/');
        
        if (strpos($real_path, $real_log_dir) !== 0) {
            error_log('APS: Attempted directory traversal: ' . $filename);
            return false;
        }
        
        return @unlink($log_file);
    }
}
