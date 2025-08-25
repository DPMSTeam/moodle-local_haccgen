<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_aicourse';
$plugin->version = 20250711058; // Incremented for navigation fix.
$plugin->release = '1.2';
$plugin->requires = 2022112800; // Moodle 4.4.
$plugin->maturity = MATURITY_STABLE;
$plugin->dependencies = ['editor_tiny' => ANY_VERSION];
