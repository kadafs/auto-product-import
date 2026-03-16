<?php
/**
 * APS Cron Trigger - Hardcoded paths for Plesk
 * NO WordPress loading whatsoever
 */

// Hardcoded absolute paths based on your server structure
$wp_config_path = '/home/artinmet/public_html/wp-config.php';

echo "APS Cron Trigger\n";
echo "================\n";

// Check if wp-config exists
if (!file_exists($wp_config_path)) {
    die("ERROR: wp-config.php not found at: $wp_config_path\n");
}

// Read wp-config line by line
$lines = file($wp_config_path);
$db_name = '';
$db_user = '';
$db_pass = '';
$db_host = '';
$table_prefix = 'wp_';

foreach ($lines as $line) {
    if (strpos($line, 'DB_NAME') !== false && preg_match("/['\"]([^'\"]+)['\"]\s*\)/", $line, $m)) {
        $db_name = $m[1];
    }
    if (strpos($line, 'DB_USER') !== false && preg_match("/['\"]([^'\"]+)['\"]\s*\)/", $line, $m)) {
        $db_user = $m[1];
    }
    if (strpos($line, 'DB_PASSWORD') !== false && preg_match("/['\"]([^'\"]+)['\"]\s*\)/", $line, $m)) {
        $db_pass = $m[1];
    }
    if (strpos($line, 'DB_HOST') !== false && preg_match("/['\"]([^'\"]+)['\"]\s*\)/", $line, $m)) {
        $db_host = $m[1];
    }
    if (strpos($line, '$table_prefix') !== false && preg_match("/['\"]([^'\"]+)['\"]/", $line, $m)) {
        $table_prefix = $m[1];
    }
}

if (empty($db_name) || empty($db_user) || empty($db_host)) {
    die("ERROR: Could not parse database config\n");
}

echo "Database: $db_name\n";
echo "Host: $db_host\n";

// Connect to database
$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($mysqli->connect_error) {
    die("ERROR: Database connection failed: " . $mysqli->connect_error . "\n");
}

// Get site URL
$result = $mysqli->query("SELECT option_value FROM {$table_prefix}options WHERE option_name='siteurl' LIMIT 1");
if (!$result) {
    die("ERROR: Could not query site URL\n");
}
$row = $result->fetch_assoc();
$site_url = $row ? $row['option_value'] : '';

// Get cron key
$result = $mysqli->query("SELECT option_value FROM {$table_prefix}options WHERE option_name='aps_cron_secret_key' LIMIT 1");
if (!$result) {
    die("ERROR: Could not query cron key\n");
}
$row = $result->fetch_assoc();
$cron_key = $row ? $row['option_value'] : '';

$mysqli->close();

if (empty($site_url) || empty($cron_key)) {
    die("ERROR: Missing site URL or cron key\n");
}

$cron_url = rtrim($site_url, '/') . '/?aps_cron=1&key=' . urlencode($cron_key);

echo "Calling: $cron_url\n\n";

// Call the URL
$response = '';
$method = '';

// Try PHP curl
if (function_exists('curl_init')) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $cron_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($http_code == 200 && !empty($response)) {
        $method = 'PHP cURL';
        echo "Method: $method\n";
        echo "HTTP Code: $http_code\n";
        echo "Response:\n$response\n";
        exit(0);
    } else if ($error) {
        echo "cURL error: $error\n";
    }
}

// Try file_get_contents
$context = stream_context_create(array(
    'http' => array(
        'timeout' => 300,
        'ignore_errors' => true
    )
));

$response = @file_get_contents($cron_url, false, $context);

if (!empty($response)) {
    $method = 'file_get_contents';
    echo "Method: $method\n";
    echo "Response:\n$response\n";
    exit(0);
}

echo "ERROR: All methods failed\n";
exit(1);
