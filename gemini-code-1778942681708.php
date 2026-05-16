<?php
/**
 * ParBot - Arabic Wikisource Uncategorized Pages Updater
 * Logs in as ParBot and updates ويكي مصدر:صفحات رئيسية غير مصنفة
 * with a list of uncategorized main pages (excluding subpages).
 */

// Configuration
define('API_URL', 'https://ar.wikisource.org/w/api.php');
define('USERNAME', 'ParBot');
define('TARGET_PAGE', 'ويكي مصدر:صفحات رئيسية غير مصنفة');

// Fetch password safely from environment variables
$password = getenv('WIKI_PASSWORD');
if (!$password) {
    die("Error: WIKI_PASSWORD environment variable is not set.\n");
}
define('PASSWORD', $password);

// Global cURL handle to persist cookies/session across requests
$ch = curl_init();
curl_setopt_array($ch, [
    // This file keeps your logged-in cookies in a temporary file for this run
    CURLOPT_COOKIEJAR => tmpfile(),
    CURLOPT_COOKIEFILE => '', 
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERAGENT => 'ParBot/1.0 (Arabic Wikisource maintenance bot)',
    CURLOPT_POST => true // Default to POST, we will overwrite for GETs
]);

/**
 * Helper to make API requests (handles both GET and POST)
 */
function api_request($params, $method = 'POST') {
    global $ch;
    
    // Always enforce JSON formatting
    $params['format'] = 'json';
    
    if (strtoupper($method) === 'GET') {
        curl_setopt($ch, CURLOPT_POST, false);
        curl_setopt($ch, CURLOPT_URL, API_URL . '?' . http_build_query($params));
    } else {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_URL, API_URL);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }

    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        throw new Exception('cURL Error: ' . curl_error($ch));
    }

    return json_decode($response, true);
}

/**
 * Log in to Wikisource using the API
 */
function login() {
    echo "Fetching login token...\n";
    $token_res = api_request([
        'action' => 'query',
        'meta' => 'tokens',
        'type' => 'login'
    ], 'GET');

    if (!isset($token_res['query']['tokens']['logintoken'])) {
        throw new Exception("Failed to get login token.");
    }
    $login_token = $token_res['query']['tokens']['logintoken'];

    echo "Logging in as " . USERNAME . "...\n";
    $login_res = api_request([
        'action' => 'login',
        'lgname' => USERNAME,
        'lgpassword' => PASSWORD,
        'lgtoken' => $login_token
    ]);

    if (($login_res['login']['result'] ?? '') !== 'Success') {
        throw new Exception("Login failed: " . json_encode($login_res['login'] ?? $login_res));
    }
    echo "Login successful.\n";
}

/**
 * Fetch all uncategorized pages, excluding subpages (pages with '/' in title)
 */
function get_uncategorized_pages() {
    echo "Fetching uncategorized pages...\n";
    $pages = [];
    $params = [
        'action' => 'query',
        'list' => 'querypage',
        'qppage' => 'Uncategorizedpages',
        'qplimit' => '500'
    ];

    while (true) {
        $data = api_request($params, 'GET');
        
        if (!isset($data['query']['querypage']['results'])) {
            break;
        }

        $results = $data['query']['querypage']['results'];
        foreach ($results as $item) {
            $title = $item['title'];
            // Exclude subpages (contain '/')
            if (strpos($title, '/') === false) {
                $pages[] = $title;
            }
        }

        // Handle pagination/continuation
        if (isset($data['continue'])) {
            $params = array_merge($params, $data['continue']);
        } else {
            break;
        }
    }

    echo "Found " . count($pages) . " uncategorized main pages.\n";
    sort($pages); // Alphabetic sort
    return $pages;
}

/**
 * Build the wikitext content for the target page
 */
function build_page_content($pages) {
    // Generate timestamp in UTC format matching your Python script
    $timestamp = gmdate("Y-m-d، H:i");

    $lines = [];
    $lines[] = "<noinclude>";
    $lines[] = "{{صندوق|رمادي|";
    $lines[] = "قام {{مس|ParBot}} بتحديث هذه القائمة في " . $timestamp . " (UTC)}}";
    $lines[] = "{{Div col|colwidth=20em}}";
    $lines[] = "</noinclude>";

    foreach ($pages as $title) {
        $lines[] = "# [[" . $title . "]]";
    }

    $lines[] = "[[تصنيف:صيانة ويكي مصدر]]";

    return implode("\n", $lines);
}

/**
 * Get a CSRF token for editing
 */
function get_csrf_token() {
    $res = api_request([
        'action' => 'query',
        'meta' => 'tokens'
    ], 'GET');
    
    if (!isset($res['query']['tokens']['csrftoken'])) {
        throw new Exception("Failed to get CSRF token.");
    }
    return $res['query']['tokens']['csrftoken'];
}

/**
 * Update the target page with the new content
 */
function update_page($content) {
    echo "Fetching CSRF token...\n";
    $csrf_token = get_csrf_token();

    echo "Updating page: " . TARGET_PAGE . "\n";
    $result = api_request([
        'action' => 'edit',
        'title' => TARGET_PAGE,
        'text' => $content,
        'summary' => 'بوت: تحديث قائمة الصفحات الرئيسية غير المصنفة',
        'bot' => 'true',
        'token' => $csrf_token
    ]);

    if (isset($result['edit']['result']) && $result['edit']['result'] === 'Success') {
        echo "Page updated successfully!\n";
        if (isset($result['edit']['nochange'])) {
            echo "(No changes were needed - page was already up to date.)\n";
        }
    } else {
        throw new Exception("Edit failed: " . json_encode($result));
    }
}

/**
 * Main application controller
 */
function main() {
    try {
        login();
        $pages = get_uncategorized_pages();

        if (empty($pages)) {
            echo "No uncategorized pages found. Aborting.\n";
            return;
        }

        $content = build_page_content($pages);
        echo "\n--- Preview (first 500 chars) ---\n";
        echo mb_substr($content, 0, 500) . "\n";
        echo "---\n\n";

        update_page($content);

    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// Fire up the bot
main();