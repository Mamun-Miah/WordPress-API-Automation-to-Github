<?php
/**
 * Plugin Name: JSON to GitHub Sync with Minified Posts
 * Description: Fetch all posts with selected fields from WP REST API, minify JSON, and upload to GitHub on post save/update.
 * Version: 1.3
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
                    <th scope="row">Website URL (without query params)</th>
                    <td><input type="text" name="json_to_github_url" value="<?php echo esc_attr(get_option('json_to_github_url')); ?>" class="regular-text" placeholder="https://your-site.com/wp-json/wp/v2/posts" /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// Helper: fetch featured image URL from media API URL fallback
function fetch_featured_image_url_from_media_link($media_api_url) {
    if (empty($media_api_url)) return '';

    $response = wp_remote_get($media_api_url);
    if (is_wp_error($response)) return '';

    $media_json = json_decode(wp_remote_retrieve_body($response), true);
    if (!empty($media_json['guid']['rendered'])) {
        return $media_json['guid']['rendered'];
    }

    return '';
}

// Fetch all posts with _embed and paginate through all posts
function fetch_all_posts_minified($base_url) {
    $all_posts = [];
    $page = 1;
    $per_page = 100;

    while (true) {
        $url = add_query_arg([
            'per_page' => $per_page,
            'page' => $page,
            '_embed' => 'author,wp:featuredmedia,wp:term',
        ], $base_url);

        $response = wp_remote_get($url);

        if (is_wp_error($response)) {
            error_log('WP Remote get error: ' . $response->get_error_message());
            break;
        }

        $posts = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($posts) || !is_array($posts)) break;

        foreach ($posts as $post) {
            $featured_image = '';

            // Try embedded featured media source_url first
            if (!empty($post['_embedded']['wp:featuredmedia'][0]['source_url'])) {
                $featured_image = $post['_embedded']['wp:featuredmedia'][0]['source_url'];
            }
            // Fallback: fetch media URL from media API endpoint
            elseif (!empty($post['_links']['wp:featuredmedia'][0]['href'])) {
                $featured_image = fetch_featured_image_url_from_media_link($post['_links']['wp:featuredmedia'][0]['href']);
            }

            $minified_post = [
                'id'            => $post['id'],
                'date'          => $post['date'],
                'modified'      => $post['modified'],
                'author'        => $post['_embedded']['author'][0]['name'] ?? '',
                'title'         => $post['title']['rendered'] ?? '',
                'content'       => $post['content']['rendered'] ?? '',
                'featured_image'=> $featured_image,
                'excerpt'       => $post['excerpt']['rendered'] ?? '',
                'categories'    => [],
                'status'        => $post['status'] ?? '',
            ];

            // Extract category names if available
            if (!empty($post['_embedded']['wp:term'][0])) {
                foreach ($post['_embedded']['wp:term'][0] as $category) {
                    if (isset($category['name'])) {
                        $minified_post['categories'][] = $category['name'];
                    }
                }
            }

            $all_posts[] = $minified_post;
        }

        if (count($posts) < $per_page) break;
        $page++;
    }

    // Return minified JSON (compact, no whitespace)
    return json_encode($all_posts);
}

// Upload to GitHub function
function json_to_github_auto_upload($post_ID, $post, $update) {
    if (wp_is_post_revision($post_ID) || wp_is_post_autosave($post_ID)) return;
    if ($post->post_type !== 'post') return;
    if (!in_array($post->post_status, ['publish', 'future'])) return;

    $token = get_option('json_to_github_token');
    $repo = get_option('json_to_github_repo');
    $branch = get_option('json_to_github_branch', 'main');
    $path = get_option('json_to_github_path', 'posts.json');
    $json_url = get_option('json_to_github_url');

    if (!$token || !$repo || !$branch || !$path || !$json_url) return;

    // Fetch minified JSON of all posts
    $json_data = fetch_all_posts_minified($json_url);

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
        'message' => 'Auto update minified posts.json from WordPress',
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
