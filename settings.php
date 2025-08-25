<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) { // Only admins and only in admin tree.
    // Use your actual component id so the page sits under "Local plugins".
    $settings = new admin_settingpage('local_aicourse', get_string('pluginname', 'local_aicourse'));

    // (Optional) section heading.
    $settings->add(new admin_setting_heading(
        'local_aicourse/apisettings',
        get_string('apisettings', 'local_aicourse'),
        ''
    ));

    // API Key field.
    // Tip: API keys often contain non-alphanumeric characters; PARAM_RAW_TRIMMED is safer than ALPHANUMEXT.
    $settings->add(new admin_setting_configtext(
        'local_aicourse/apikey',
        get_string('apikey', 'local_aicourse'),
        get_string('apikey_desc', 'local_aicourse'),
        '', // default value
        PARAM_RAW_TRIMMED
    ));

    // API Secret field (password masked).
    $settings->add(new admin_setting_configpasswordunmask(
        'local_aicourse/apisecret',
        get_string('apisecret', 'local_aicourse'),
        get_string('apisecret_desc', 'local_aicourse'),
        ''
    ));

    // Public-link signing secret (password masked).
    $settings->add(new admin_setting_configpasswordunmask(
        'local_aicourse/linksecret',
        get_string('linksecret', 'local_aicourse'),
        get_string('linksecret_desc', 'local_aicourse'),
        '' // no default; admin must set
    ));

    // Public-link expiry (duration in seconds/minutes/hours via UI).
    $settings->add(new admin_setting_configduration(
        'local_aicourse/publiclinkttl',
        get_string('publiclinkttl', 'local_aicourse'),
        get_string('publiclinkttl_desc', 'local_aicourse'),
        3600 // 1 hour default
    ));

    // Add settings page to admin tree.
    $ADMIN->add('localplugins', $settings);
}
