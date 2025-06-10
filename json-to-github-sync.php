<?php
/**
 * Plugin Name: JSON to GitHub Sync
 * Description: Automatically fetch all posts JSON from WordPress REST API in batches and upload to GitHub on post publish/update.
 * Version: 1.2
 * Author: Mamun Miah
 */

// Admin settings menu
add_action('admin_menu', function () {
    add_options_page(
        'JSON to GitHub Sync',
        'JSON to GitHub Sync',
        'manage_options',
        'json-to-github-sync',
        'json_to_github_settings_page'
    );
});

// Register settings
add_action('admin_init', function () {
    register_setting('json_to_github_settings', 'json_to_github_token');
    register_setting('json_to_github_settings', 'json_to_github_repo');
    register_setting('json_to_github_settings', 'json_to_github_branch');
    register_setting('json_to_github_settings', 'json_to_github_path');
    register_setting('json_to_github_settings', 'json_to_github_url');
});

// Settings page HTML
function json_to_github_settings_page() {
    ?>
    <div class="wrap">
        <h1>JSON to GitHub Sync Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('json_to_github_settings'); ?>
            <?php do_settings_sections('json_to_github_settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">GitHub Token</th>
                    <td><input type="text" name="json_to_github_token" value="<?php echo esc_attr(get_option('json_to_github_token')); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row">Repository (e.g. user/repo)</th>
                    <td><input type="text" name="json_to_github_repo" value="<?php echo esc_attr(get_option('json_to_github_repo')); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row">Branch</th>
                    <td><input type="text" name="json_to_github_branch" value="<?php echo esc_attr(get_option('json_to_github_branch', 'main')); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row">File Path (e.g. posts.json)</th>
                    <td><input type="text" name="json_to_github_path" value="<?php echo esc_attr(get_option('json_to_github_path', 'posts.json')); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row">JSON Source URL (without query params)</th>
                    <td><input type="text" name="json_to_github_url" value="<?php echo esc_attr(get_option('json_to_github_url')); ?>" class="regular-text" placeholder="https://your-site.com/wp-json/wp/v2/posts" /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// Fetch all posts in batches of 100 with pagination
function fetch_all_posts_json($base_url) {
    $all_posts = [];
    $page = 1;
    $per_page = 100; // WP REST API max per page

    while (true) {
        $url = add_query_arg([
            'per_page' => $per_page,
            'page'     => $page,
        ], $base_url);

        $response = wp_remote_get($url);

        if (is_wp_error($response)) {
            error_log('WP Remote get error: ' . $response->get_error_message());
            break;
        }

        $posts = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($posts) || !is_array($posts)) {
            break;
        }

        $all_posts = array_merge($all_posts, $posts);

        if (count($posts) < $per_page) {
            // No more pages
            break;
        }

        $page++;
    }

    return json_encode($all_posts);
}

// Main function to upload JSON to GitHub
function json_to_github_auto_upload($post_ID, $post, $update) {
    // Avoid autosave or revision
    if (wp_is_post_revision($post_ID) || wp_is_post_autosave($post_ID)) return;

    // Only 'post' post type
    if ($post->post_type !== 'post') return;

    // Only publish or future status
    if (!in_array($post->post_status, ['publish', 'future'])) return;

    $token = get_option('json_to_github_token');
    $repo = get_option('json_to_github_repo');
    $branch = get_option('json_to_github_branch', 'main');
    $path = get_option('json_to_github_path', 'posts.json');
    $json_url = get_option('json_to_github_url');

    if (!$token || !$repo || !$path || !$json_url) return;

    // Fetch all posts JSON paginated
    $json_data = fetch_all_posts_json($json_url);

    $headers = [
        'Authorization' => "token $token",
        'User-Agent'    => 'WordPress JSON GitHub Sync',
        'Content-Type'  => 'application/json',
    ];

    $github_api_url = "https://api.github.com/repos/$repo/contents/$path";

    // Get existing file SHA for update
    $existing = wp_remote_get($github_api_url, ['headers' => $headers]);
    $sha = null;
    if (!is_wp_error($existing) && wp_remote_retrieve_response_code($existing) === 200) {
        $existing_data = json_decode(wp_remote_retrieve_body($existing), true);
        $sha = $existing_data['sha'] ?? null;
    }

    $payload = json_encode([
        'message' => 'Auto update posts.json from WordPress',
        'content' => base64_encode($json_data),
        'branch'  => $branch,
        'sha'     => $sha,
    ]);

    wp_remote_request($github_api_url, [
        'method'  => 'PUT',
        'headers' => $headers,
        'body'    => $payload,
    ]);
}

add_action('save_post', 'json_to_github_auto_upload', 10, 3);
