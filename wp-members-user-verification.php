<?php
/**
 * Plugin Name: WP-Members User Verification
 * Description: Verifies user against Action Network subscriber list and completes user profile with membership data and automatically schedules verification after registration.
 * Version: 1.9.2
 * Author: MMM Delicious
 * Developer: Mark McDonnell
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Tested up to: 6.7
 */

defined( 'ABSPATH' ) || exit;

// Auto-updates via GitHub
require_once plugin_dir_path(__FILE__) . 'lib/plugin-update-checker/plugin-update-checker.php';
\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/mmm-delicious/action-network-user-verification/',
    __FILE__,
    'action-network-user-verification'
);

add_action('user_register', 'schedule_an_verification_for_user');
function schedule_an_verification_for_user($user_id) {
    if (!wp_next_scheduled('run_an_verification_task', [$user_id])) {
        wp_schedule_single_event(time() + 10, 'run_an_verification_task', [$user_id]);
    }
}

// Hook that processes the verification task
add_action('run_an_verification_task', 'run_an_verification_for_user');
function run_an_verification_for_user($user_id) {
    $user = get_userdata($user_id);
    if (!$user) return;

    $email = $user->user_email;
    $user_ssn = get_user_meta($user_id, 'LastSNN', true);
    $api_key = get_option('action_network_api_key');

    if (!$email || !$api_key) {
        update_user_meta($user_id, 'an_verification_note', '❌ Missing email, or API key.');
        return;
    }

    $response = wp_remote_post('https://actionnetwork.org/api/v2/people', [
        'headers' => [
            'OSDI-API-Token' => $api_key,
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode([
            'email_addresses' => [['address' => $email]]
        ])
    ]);

    if (is_wp_error($response)) {
        update_user_meta($user_id, 'an_verification_note', '❌ API Error: ' . $response->get_error_message());
        return;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    $custom = $data['custom_fields'] ?? [];
    
    // ——— NEW: AFSCME ID matching ———
    $an_afscme_raw   = $custom['afscme_id']                      ?? '';
    $user_afscme_raw = get_user_meta( $user_id, 'afscme_id', true );
    $an_afscme       = trim( strtolower( $an_afscme_raw ) );
    $user_afscme     = trim( strtolower( $user_afscme_raw ) );

    // if AN has an afscme_id, require it to match
    $afscme_match = ! empty( $an_afscme )
                    && $an_afscme === $user_afscme;
    // ————————————————————————————————
    
    $an_ssn_raw = $custom['last_4_ssn'] ?? '';
    $an_ssn_raw   = preg_replace('/\.0$/', '', $an_ssn_raw);
    $user_ssn_raw = $user_ssn      ?? '';

    $an_ssn = mmm_an_normalize_ssn($an_ssn_raw);
    $user_ssn_clean = mmm_an_normalize_ssn($user_ssn_raw);

    // PATCH malformed SSN in Action Network if needed
    if (!empty($an_ssn_raw) && strlen($an_ssn_raw) < 4 && strlen($an_ssn) === 4) {
        $person_url = $data['_links']['self']['href'] ?? null;

        // SSRF protection: only allow requests to actionnetwork.org
        $allowed_host = 'actionnetwork.org';
        $parsed_host  = parse_url($person_url, PHP_URL_HOST);
        if ($person_url && $parsed_host !== $allowed_host) {
            $person_url = null;
            update_user_meta($user_id, 'an_verification_note', '⚠️ SSRF blocked: unexpected URL in API response.');
        }

        if ($person_url) {
            $patch_response = wp_remote_request($person_url, [
                'method'  => 'PATCH',
                'headers' => [
                    'OSDI-API-Token' => $api_key,
                    'Content-Type'   => 'application/json'
                ],
                'body' => json_encode([
                    'custom_fields' => [
                        'last_4_ssn' => $an_ssn
                    ]
                ])
            ]);

            if (!is_wp_error($patch_response)) {
                update_user_meta($user_id, 'an_verification_note', '🔁 Fixed malformed SSN on Action Network. Retrying match...');
                $custom['last_4_ssn'] = $an_ssn;
                $an_ssn_raw = $an_ssn;
                $an_ssn = mmm_an_normalize_ssn($an_ssn_raw);
            } else {
                update_user_meta($user_id, 'an_verification_note', '⚠️ Tried to fix AN SSN but failed: ' . $patch_response->get_error_message());
            }
        }
    }

    if ($afscme_match || ($user_ssn_clean && $an_ssn && $user_ssn_clean === $an_ssn)) {

        update_user_meta($user_id, 'an_verified', true);
        update_user_meta($user_id, 'an_verification_note', '✅ Verified and updated successfully.');

        $meta_updates = [
            'first_name'        => mmm_an_sentence_case($data['given_name'] ?? ''),
            'last_name'         => mmm_an_sentence_case($data['family_name'] ?? ''),
            'phone1'            => $data['phone_numbers'][0]['number'] ?? '',
            'billing_address_1' => $data['postal_addresses'][0]['address_lines'][0] ?? '',
            'billing_city'      => $data['postal_addresses'][0]['locality'] ?? '',
            'billing_state'     => $data['postal_addresses'][0]['region'] ?? '',
            'billing_postcode'  => $data['postal_addresses'][0]['postal_code'] ?? '',
            'birthday'          => $custom['birthday'] ?? '',
            'member_status'     => $custom['member_status'] ?? '',
            'island'            => $custom['island'] ?? '',
            'unit_number'       => $custom['unit_number'] ?? '',
            'job_title'         => $custom['job_title'] ?? '',
            'baseyard'          => $custom['baseyard'] ?? '',
            'jurisdiction'      => $custom['jurisdiction'] ?? '',
            'employer'          => $custom['employer'] ?? '',
            'bargaining_unit'   => $custom['bargaining_unit'] ?? '',
            'afscme_id'         => $custom['afscme_id'] ?? ''
        ];

        $updated_fields = [];
        $force_overwrite = false;

        foreach ($meta_updates as $meta_key => $value) {
            $current_value = get_user_meta($user_id, $meta_key, true);
            if (!empty($value) && ($force_overwrite || empty($current_value)) && $value !== $current_value) {
                update_user_meta($user_id, $meta_key, $value);
                $updated_fields[] = $meta_key;
            }
        }

        update_user_meta($user_id, 'an_last_updated_fields', implode(', ', $updated_fields));
    } else {
        update_user_meta($user_id, 'an_verified', false);
        $note = empty($an_ssn)
            ? '❌ No SSN found in Action Network'
            : '❌ SSN or email mismatch from Action Network';
        update_user_meta($user_id, 'an_verification_note', $note);
        update_user_meta($user_id, 'an_last_updated_fields', '');
    }
}

function mmm_an_normalize_ssn($ssn) {
    return str_pad(preg_replace('/\D/', '', $ssn), 4, '0', STR_PAD_LEFT);
}

function mmm_an_sentence_case($name) {
    return ucwords(strtolower(trim($name)));
}

// Settings page for API key
add_action('admin_menu', function() {
    add_options_page(
        'Action Network Settings',
        'Action Network',
        'manage_options',
        'an-verification-settings',
        'mmm_an_render_settings_page'
    );
});

add_action('admin_init', function() {
    register_setting('an_verification_settings', 'action_network_api_key', [
        'sanitize_callback' => 'sanitize_text_field',
    ]);
});

function mmm_an_render_settings_page() {
    if (!current_user_can('manage_options')) return;
    ?>
    <div class="wrap">
        <h1>Action Network Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('an_verification_settings'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="action_network_api_key">API Key (OSDI Token)</label></th>
                    <td>
                        <?php $key = get_option('action_network_api_key', ''); ?>
                        <input type="password" id="action_network_api_key" name="action_network_api_key"
                               value="<?php echo esc_attr($key); ?>" class="regular-text" autocomplete="off" />
                        <p class="description">Your Action Network OSDI API token. Found under <strong>Account > API &amp; Integrations</strong> in Action Network.</p>
                        <?php if (!empty($key)): ?>
                            <p class="description" style="color: green;">&#10003; API key is set.</p>
                        <?php else: ?>
                            <p class="description" style="color: red;">&#9888; No API key set — verification will not run.</p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save API Key'); ?>
        </form>
    </div>
    <?php
}

// Manual Profile Sync Function
add_action('edit_user_profile', 'an_sync_button_for_user');
function an_sync_button_for_user($user) {
        if (!current_user_can('manage_options')) return;

    $url = admin_url("users.php?action=an_manual_sync&user_id={$user->ID}&_wpnonce=" . wp_create_nonce('an_manual_sync'));

    echo '<h2>Action Network Sync</h2>';
    echo '<a href="' . esc_url($url) . '" class="button button-primary">Sync from Action Network</a>';
}

// Handle the button click securely
add_action('admin_init', function() {
    if (
        isset($_GET['action'], $_GET['user_id'], $_GET['_wpnonce']) &&
        $_GET['action'] === 'an_manual_sync' &&
        current_user_can('manage_options') &&
        wp_verify_nonce($_GET['_wpnonce'], 'an_manual_sync')
    ) {
        $user_id = intval($_GET['user_id']);
        run_an_verification_for_user($user_id);
        $verified  = get_user_meta($user_id, 'an_verified', true);
        $note      = get_user_meta($user_id, 'an_verification_note', true);
        $fields    = get_user_meta($user_id, 'an_last_updated_fields', true);
        wp_redirect(admin_url("user-edit.php?user_id=$user_id&an_sync=1&an_verified=$verified&an_note=" . urlencode($note) . "&an_fields=" . urlencode($fields)));
        exit;
    }
});

// Show confirmation notice after sync
add_action('admin_notices', function() {
    if (!isset($_GET['an_sync']) || $_GET['an_sync'] != 1) return;

    $verified = $_GET['an_verified'] === '1' ? '✅ Verified' : '❌ Not Verified';
    $note     = isset($_GET['an_note']) ? sanitize_text_field($_GET['an_note']) : '';
    $fields   = isset($_GET['an_fields']) && $_GET['an_fields'] !== '' ? sanitize_text_field($_GET['an_fields']) : 'No fields updated.';

    echo '<div class="notice notice-info is-dismissible">';
    echo '<p><strong>Action Network Sync Result:</strong><br>' . $verified . '<br>' . esc_html( $note ) . '<br><em>Fields updated:</em> ' . esc_html( $fields ) . '</p>';
    echo '</div>';
});

// Sync status section
add_action('edit_user_profile', 'show_an_verification_fields');
function show_an_verification_fields($user) {
    if (!current_user_can('manage_options')) return;

    $verified = get_user_meta($user->ID, 'an_verified', true);
    $note = get_user_meta($user->ID, 'an_verification_note', true);
    $fields = get_user_meta($user->ID, 'an_last_updated_fields', true);

    ?>
    <h2>Action Network Sync Status</h2>
    <table class="form-table">
        <tr>
            <th><label>Verified?</label></th>
            <td><?php echo $verified === '1' ? '✅ Yes' : ($verified === '0' ? '❌ No' : '⚠️ Not Set'); ?></td>
        </tr>
        <tr>
            <th><label>Verification Note</label></th>
            <td><pre><?php echo esc_html($note ?: '—'); ?></pre></td>
        </tr>
        <tr>
            <th><label>Last Updated Fields</label></th>
            <td><code><?php echo esc_html($fields ?: '—'); ?></code></td>
        </tr>
    </table>
    <?php
}

// Add custom columns to the Users list table
add_filter('manage_users_columns', 'add_an_sync_columns');
function add_an_sync_columns($columns) {
    $columns['an_verified'] = 'AN Verified';
    $columns['an_note'] = 'AN Note';
    return $columns;
}

// Populate the custom columns
add_filter('manage_users_custom_column', 'show_an_sync_column_data', 10, 3);
function show_an_sync_column_data($value, $column_name, $user_id) {
    if ($column_name === 'an_verified') {
        $verified = get_user_meta($user_id, 'an_verified', true);
        if ($verified === '1' || $verified === 1 || $verified === true) {
            return '✅';
        } elseif ($verified === '0' || $verified === 0 || $verified === false) {
            return '❌';
        } else {
            return '⚠️';
        }
    }

    if ($column_name === 'an_note') {
        $note = get_user_meta($user_id, 'an_verification_note', true);
        return esc_html($note ?: '—');
    }

    return $value;
}

// Register bulk action for syncing
add_filter('bulk_actions-users', function($bulk_actions) {
    $bulk_actions['an_sync_selected'] = 'Sync from Action Network';
    return $bulk_actions;
});

// Handle the bulk sync by scheduling each with WP-Cron
add_filter('handle_bulk_actions-users', function($redirect_url, $action, $user_ids) {
    if ($action !== 'an_sync_selected') return $redirect_url;

    foreach ($user_ids as $user_id) {
        wp_schedule_single_event(time() + 5, 'run_an_verification_task', [$user_id]);
    }

    return add_query_arg(['an_bulk_queued' => count($user_ids)], $redirect_url);
}, 10, 3);

// Notice after scheduling
add_action('admin_notices', function() {
    if (!isset($_GET['an_bulk_queued'])) return;

    $count = intval($_GET['an_bulk_queued']);
    echo '<div class="notice notice-success is-dismissible"><p>';
    echo "✅ Queued $count user" . ($count === 1 ? '' : 's') . " for Action Network sync.";
    echo '</p></div>';
});

// sort by member status
add_filter('manage_users_sortable_columns', function($columns) {
    $columns['member_status'] = 'member_status';
    return $columns;
});
// modify the sort by member status
add_action('pre_get_users', function($query) {
    if (!is_admin()) return;

    if ($query->get('orderby') === 'member_status') {
        $query->set('meta_key', 'member_status');
        $query->set('orderby', 'meta_value');
    }
});

// Needs Verification Link
add_filter('views_users', function($views) {
    $count = count_users();
    $args = [
        'meta_query' => [
            'relation' => 'OR',
            [
                'key'     => 'an_verified',
                'compare' => 'NOT EXISTS'
            ],
            [
                'key'     => 'an_verified',
                'value'   => '1',
                'compare' => '!='
            ]
        ]
    ];
    $user_query = new WP_User_Query($args);
    $needs_verification_count = $user_query->get_total();

    $class = (isset($_GET['an_filter']) && $_GET['an_filter'] === 'needs_verification') ? 'class="current"' : '';

    $url = add_query_arg([
        'an_filter' => 'needs_verification',
    ], admin_url('users.php'));

    $views['needs_verification'] = "<a href='" . esc_url($url) . "' $class>Needs Verification <span class='count'>($needs_verification_count)</span></a>";

    return $views;
});
add_action('pre_get_users', function($query) {
    if (!is_admin() || !isset($_GET['an_filter']) || $_GET['an_filter'] !== 'needs_verification') return;

    $query->set('meta_query', [
        'relation' => 'OR',
        [
            'key'     => 'an_verified',
            'compare' => 'NOT EXISTS'
        ],
        [
            'key'     => 'an_verified',
            'value'   => '1',
            'compare' => '!='
        ]
    ]);
});


