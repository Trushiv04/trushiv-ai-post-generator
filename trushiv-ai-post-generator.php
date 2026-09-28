<?php
/**
 * Plugin Name: Trushiv AI Post Generator
 * Description: Automatically generate WordPress posts using Claude AI + OpenAI.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Trushiv
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: trushiv-ai-post-generator
 */

if (!defined('ABSPATH')) exit;

define('BTAP_VERSION', '1.0.0');
define('BTAP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BTAP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BTAP_PLUGIN_FILE', __FILE__);

register_activation_hook(__FILE__, function () {
    add_option('btap_anthropic_key', '');
    add_option('btap_openai_key', '');
    add_option('btap_post_status', 'draft');
    add_option('btap_post_language', 'English');
    add_option('btap_category_id', '1');
});

add_action('admin_menu', function () {
    add_menu_page(
        __('Trushiv AI Post Generator', 'trushiv-ai-post-generator'),
        __('Trushiv AI Post Generator', 'trushiv-ai-post-generator'),
        'manage_options',
        'trushiv-ai-post-generator',
        'btap_main_page',
        'dashicons-superhero',
        30
    );
    add_submenu_page(
        'trushiv-ai-post-generator',
        __('Generate Post', 'trushiv-ai-post-generator'),
        __('Generate Post', 'trushiv-ai-post-generator'),
        'manage_options',
        'trushiv-ai-post-generator',
        'btap_main_page'
    );
    add_submenu_page(
        'trushiv-ai-post-generator',
        __('Settings', 'trushiv-ai-post-generator'),
        __('Settings', 'trushiv-ai-post-generator'),
        'manage_options',
        'trushiv-ai-post-generator-settings',
        'btap_settings_page'
    );
});

/**
 * Enqueue admin JS/CSS only on this plugin's own admin pages.
 */
add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos($hook, 'trushiv-ai-post-generator') === false) {
        return;
    }

    wp_enqueue_style(
        'btap-admin',
        BTAP_PLUGIN_URL . 'assets/css/admin.css',
        [],
        BTAP_VERSION
    );

    wp_enqueue_script(
        'btap-admin',
        BTAP_PLUGIN_URL . 'assets/js/admin.js',
        [],
        BTAP_VERSION,
        true
    );

    wp_localize_script('btap-admin', 'btapData', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('btap_generate_nonce'),
        'i18n'    => [
            'emptyTopic'    => __('Please type a topic!', 'trushiv-ai-post-generator'),
            'generating'    => __('Generating...', 'trushiv-ai-post-generator'),
            'generateBtn'   => __('Generate & Publish Post', 'trushiv-ai-post-generator'),
            'stepContent'   => __('Generating content with Claude...', 'trushiv-ai-post-generator'),
            'stepImage'     => __('Generating AI image...', 'trushiv-ai-post-generator'),
            'stepUpload'    => __('Uploading image to WordPress...', 'trushiv-ai-post-generator'),
            'stepPublish'   => __('Publishing post...', 'trushiv-ai-post-generator'),
            'ready'         => __('Post ready!', 'trushiv-ai-post-generator'),
            'success'       => __('Post Successfully Created!', 'trushiv-ai-post-generator'),
            'titleLabel'    => __('Title:', 'trushiv-ai-post-generator'),
            'statusLabel'   => __('Status:', 'trushiv-ai-post-generator'),
            'editPost'      => __('Edit Post', 'trushiv-ai-post-generator'),
            'viewPost'      => __('View Post', 'trushiv-ai-post-generator'),
            'error'         => __('Error', 'trushiv-ai-post-generator'),
            'networkError'  => __('Network Error', 'trushiv-ai-post-generator'),
        ],
    ]);
});

add_action('admin_init', function () {
    if (
        isset($_POST['btap_save_settings']) &&
        current_user_can('manage_options') &&
        check_admin_referer('btap_settings_nonce')
    ) {
        update_option('btap_anthropic_key', sanitize_text_field(wp_unslash($_POST['btap_anthropic_key'] ?? '')));
        update_option('btap_openai_key', sanitize_text_field(wp_unslash($_POST['btap_openai_key'] ?? '')));
        update_option('btap_post_status', sanitize_text_field(wp_unslash($_POST['btap_post_status'] ?? 'draft')));
        update_option('btap_post_language', sanitize_text_field(wp_unslash($_POST['btap_post_language'] ?? 'English')));
        update_option('btap_category_id', absint($_POST['btap_category_id'] ?? 1));
        add_action('admin_notices', function () {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved!', 'trushiv-ai-post-generator') . '</p></div>';
        });
    }
});

add_action('wp_ajax_btap_generate_post', 'btap_handle_generate');

function btap_handle_generate() {
    check_ajax_referer('btap_generate_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permission denied', 'trushiv-ai-post-generator')]);
    }

    $topic = sanitize_text_field(wp_unslash($_POST['topic'] ?? ''));
    if (empty($topic)) {
        wp_send_json_error(['message' => __('Topic cannot be empty!', 'trushiv-ai-post-generator')]);
    }

    $anthropic_key = get_option('btap_anthropic_key');
    $openai_key    = get_option('btap_openai_key');
    $language      = get_option('btap_post_language', 'English');
    $status        = get_option('btap_post_status', 'draft');
    $category_id   = (int) get_option('btap_category_id', 1);

    if (empty($anthropic_key)) {
        wp_send_json_error(['message' => __('Anthropic API key missing — add it in Settings', 'trushiv-ai-post-generator')]);
    }
    if (empty($openai_key)) {
        wp_send_json_error(['message' => __('OpenAI API key missing — add it in Settings', 'trushiv-ai-post-generator')]);
    }

    $content = btap_generate_content($topic, $anthropic_key, $language);
    if (is_wp_error($content)) {
        wp_send_json_error(['message' => $content->get_error_message()]);
    }

    $image_data = btap_generate_image($content['image_prompt'], $openai_key);
    if (is_wp_error($image_data)) {
        wp_send_json_error(['message' => $image_data->get_error_message()]);
    }

    $media_id = btap_upload_image($image_data, $topic);
    if (is_wp_error($media_id)) {
        wp_send_json_error(['message' => $media_id->get_error_message()]);
    }

    $post_id = wp_insert_post([
        'post_title'    => wp_strip_all_tags($content['title']),
        'post_content'  => wp_kses_post($content['content']),
        'post_excerpt'  => sanitize_text_field($content['excerpt']),
        'post_status'   => $status,
        'post_category' => [$category_id],
    ]);

    if (is_wp_error($post_id)) {
        /* translators: %s: error message returned by wp_insert_post(). */
        wp_send_json_error(['message' => sprintf(__('Post could not be created: %s', 'trushiv-ai-post-generator'), $post_id->get_error_message())]);
    }

    set_post_thumbnail($post_id, $media_id);

    wp_send_json_success([
        'title'    => $content['title'],
        'status'   => $status,
        'edit_url' => get_edit_post_link($post_id, 'raw'),
        'view_url' => get_permalink($post_id),
        'post_id'  => $post_id,
    ]);
}

function btap_generate_content($topic, $api_key, $language) {
    $prompt = "You are a professional blog writer. Write a complete WordPress blog post about: \"{$topic}\"\nLanguage: {$language}\nRespond ONLY with valid JSON, no markdown, no backticks:\n{\"title\":\"title here\",\"content\":\"<p>Full HTML min 600 words with h2 headings</p>\",\"excerpt\":\"max 160 chars\",\"image_prompt\":\"detailed image prompt photorealistic no text in image\"}";

    $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
        'timeout' => 60,
        'headers' => [
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ],
        'body' => wp_json_encode([
            'model'      => apply_filters('btap_claude_model', 'claude-sonnet-5'),
            'max_tokens' => 2000,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]),
    ]);

    if (is_wp_error($response)) {
        /* translators: %s: underlying WP_Error message from wp_remote_post(). */
        return new WP_Error('claude_error', sprintf(__('Could not connect to Claude API: %s', 'trushiv-ai-post-generator'), $response->get_error_message()));
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code === 401) return new WP_Error('claude_error', __('Anthropic API key is invalid — check Settings', 'trushiv-ai-post-generator'));
    if ($code === 429) return new WP_Error('claude_error', __('Anthropic rate limit — please wait a moment and retry', 'trushiv-ai-post-generator'));
    if ($code !== 200) {
        /* translators: 1: HTTP status code, 2: error message from the API. */
        return new WP_Error('claude_error', sprintf(__('Claude error %1$d: %2$s', 'trushiv-ai-post-generator'), $code, $body['error']['message'] ?? __('Unknown', 'trushiv-ai-post-generator')));
    }

    $text = $body['content'][0]['text'] ?? '';
    $data = json_decode($text, true);

    if (!$data) {
        preg_match('/\{[\s\S]*\}/', $text, $matches);
        if (empty($matches)) return new WP_Error('claude_error', __('Could not parse Claude response — please retry', 'trushiv-ai-post-generator'));
        $data = json_decode($matches[0], true);
    }

    if (empty($data['title']) || empty($data['content'])) {
        return new WP_Error('claude_error', __('Claude response incomplete — please retry', 'trushiv-ai-post-generator'));
    }

    return $data;
}

function btap_generate_image($prompt, $api_key) {
    $response = wp_remote_post('https://api.openai.com/v1/images/generations', [
        'timeout' => 120,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode([
            'model'   => 'gpt-image-1',
            'prompt'  => $prompt,
            'size'    => '1536x1024',
            'quality' => 'medium',
            'n'       => 1,
        ]),
    ]);

    if (is_wp_error($response)) {
        /* translators: %s: underlying WP_Error message from wp_remote_post(). */
        return new WP_Error('openai_error', sprintf(__('Could not connect to OpenAI: %s', 'trushiv-ai-post-generator'), $response->get_error_message()));
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code === 401) return new WP_Error('openai_error', __('OpenAI API key invalid — check platform.openai.com', 'trushiv-ai-post-generator'));
    if ($code === 429) return new WP_Error('openai_error', __('OpenAI rate limit — please wait a moment', 'trushiv-ai-post-generator'));
    if ($code !== 200) {
        /* translators: 1: HTTP status code, 2: error message from the API. */
        return new WP_Error('openai_error', sprintf(__('Image error %1$d: %2$s', 'trushiv-ai-post-generator'), $code, $body['error']['message'] ?? __('Unknown', 'trushiv-ai-post-generator')));
    }

    $b64 = $body['data'][0]['b64_json'] ?? '';
    if (empty($b64)) return new WP_Error('openai_error', __('No image data received — check OpenAI billing', 'trushiv-ai-post-generator'));

    return base64_decode($b64);
}

function btap_upload_image($image_data, $topic) {
    $upload = wp_upload_bits(
        'ai-' . sanitize_title($topic) . '-' . time() . '.png',
        null,
        $image_data
    );

    if (!empty($upload['error'])) {
        /* translators: %s: error message returned by wp_upload_bits(). */
        return new WP_Error('upload_error', sprintf(__('Image upload failed: %s', 'trushiv-ai-post-generator'), $upload['error']));
    }

    $filetype = wp_check_filetype($upload['file']);
    $attachment = [
        'post_mime_type' => $filetype['type'],
        'post_title'     => sanitize_file_name($topic),
        'post_content'   => '',
        'post_status'    => 'inherit',
    ];

    $attach_id = wp_insert_attachment($attachment, $upload['file']);
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
    wp_update_attachment_metadata($attach_id, $attach_data);

    return $attach_id;
}

function btap_main_page() {
    $anthropic_key = get_option('btap_anthropic_key');
    $openai_key    = get_option('btap_openai_key');
    $has_keys      = !empty($anthropic_key) && !empty($openai_key);
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Trushiv AI Post Generator', 'trushiv-ai-post-generator'); ?></h1>

        <?php if (!$has_keys): ?>
        <div class="notice notice-warning">
            <p>
                <?php
                printf(
                    /* translators: %s: link to the plugin's Settings page. */
                    esc_html__('API Keys missing! Add your Anthropic and OpenAI keys in %s.', 'trushiv-ai-post-generator'),
                    '<a href="' . esc_url(admin_url('admin.php?page=trushiv-ai-post-generator-settings')) . '">' . esc_html__('Settings', 'trushiv-ai-post-generator') . '</a>'
                );
                ?>
            </p>
        </div>
        <?php endif; ?>

        <div class="btap-card">
            <table class="form-table">
                <tr>
                    <th><label for="btap_topic"><?php esc_html_e('Post Topic', 'trushiv-ai-post-generator'); ?></label></th>
                    <td>
                        <input type="text" id="btap_topic" class="btap-topic-input" placeholder="<?php esc_attr_e('e.g. Benefits of Solar Energy in India', 'trushiv-ai-post-generator'); ?>" <?php disabled(!$has_keys); ?> />
                        <p class="description"><?php esc_html_e('Type a topic — Claude will handle everything else', 'trushiv-ai-post-generator'); ?></p>
                    </td>
                </tr>
            </table>

            <div class="btap-actions">
                <button id="btap_generate_btn" class="button button-primary button-large" <?php disabled(!$has_keys); ?>>
                    <?php esc_html_e('Generate & Publish Post', 'trushiv-ai-post-generator'); ?>
                </button>
            </div>

            <div id="btap_progress" class="btap-progress" style="display:none;">
                <div id="btap_step" class="btap-step"><?php esc_html_e('Starting...', 'trushiv-ai-post-generator'); ?></div>
                <div class="btap-bar-track">
                    <div id="btap_bar" class="btap-bar-fill"></div>
                </div>
            </div>

            <div id="btap_result" class="btap-result" style="display:none;"></div>
        </div>
    </div>
    <?php
}

function btap_settings_page() {
    $anthropic_key = get_option('btap_anthropic_key', '');
    $openai_key    = get_option('btap_openai_key', '');
    $post_status   = get_option('btap_post_status', 'draft');
    $language      = get_option('btap_post_language', 'English');
    $category_id   = get_option('btap_category_id', '1');
    $categories    = get_categories(['hide_empty' => false]);
    $languages     = ['English', 'Gujarati', 'Hindi', 'Marathi', 'Tamil', 'Bengali'];
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Trushiv AI Post Generator — Settings', 'trushiv-ai-post-generator'); ?></h1>
        <form method="post">
            <?php wp_nonce_field('btap_settings_nonce'); ?>
            <div class="btap-card">
                <h2><?php esc_html_e('API Keys', 'trushiv-ai-post-generator'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="btap_anthropic_key"><?php esc_html_e('Anthropic API Key', 'trushiv-ai-post-generator'); ?></label></th>
                        <td>
                            <input type="password" id="btap_anthropic_key" name="btap_anthropic_key" value="<?php echo esc_attr($anthropic_key); ?>" class="regular-text btap-full-width" placeholder="sk-ant-..." autocomplete="off" />
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s: link to the Anthropic console. */
                                    esc_html__('Get it from %s', 'trushiv-ai-post-generator'),
                                    '<a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener noreferrer">console.anthropic.com</a>'
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="btap_openai_key"><?php esc_html_e('OpenAI API Key', 'trushiv-ai-post-generator'); ?></label></th>
                        <td>
                            <input type="password" id="btap_openai_key" name="btap_openai_key" value="<?php echo esc_attr($openai_key); ?>" class="regular-text btap-full-width" placeholder="sk-proj-..." autocomplete="off" />
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s: link to the OpenAI platform. */
                                    esc_html__('Get it from %s', 'trushiv-ai-post-generator'),
                                    '<a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer">platform.openai.com</a>'
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Post Settings', 'trushiv-ai-post-generator'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="btap_post_status"><?php esc_html_e('Post Status', 'trushiv-ai-post-generator'); ?></label></th>
                        <td>
                            <select id="btap_post_status" name="btap_post_status">
                                <option value="draft" <?php selected($post_status, 'draft'); ?>><?php esc_html_e('Draft (publish after review)', 'trushiv-ai-post-generator'); ?></option>
                                <option value="publish" <?php selected($post_status, 'publish'); ?>><?php esc_html_e('Publish (go live directly)', 'trushiv-ai-post-generator'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="btap_post_language"><?php esc_html_e('Language', 'trushiv-ai-post-generator'); ?></label></th>
                        <td>
                            <select id="btap_post_language" name="btap_post_language">
                                <?php foreach ($languages as $lang): ?>
                                <option value="<?php echo esc_attr($lang); ?>" <?php selected($language, $lang); ?>><?php echo esc_html($lang); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="btap_category_id"><?php esc_html_e('Category', 'trushiv-ai-post-generator'); ?></label></th>
                        <td>
                            <select id="btap_category_id" name="btap_category_id">
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo esc_attr($cat->term_id); ?>" <?php selected($category_id, $cat->term_id); ?>><?php echo esc_html($cat->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="btap_save_settings" class="button button-primary button-large" value="<?php esc_attr_e('Save Settings', 'trushiv-ai-post-generator'); ?>" />
                </p>
            </div>
        </form>
    </div>
    <?php
}
