<?php
/**
 * Uninstall handler for Trushiv AI Post Generator.
 *
 * Removes all options created by the plugin when it is deleted
 * from the Plugins screen (not just deactivated).
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$btap_options = [
    'btap_anthropic_key',
    'btap_openai_key',
    'btap_post_status',
    'btap_post_language',
    'btap_category_id',
];

foreach ($btap_options as $btap_option) {
    delete_option($btap_option);

    // Also clean up the same options on multisite network installs.
    if (is_multisite()) {
        delete_site_option($btap_option);
    }
}
