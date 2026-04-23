<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Library functions for local_haccgen.
 *
 * @package local_haccgen
 * @copyright 2026 Dynamicpixel Multimedia Solutions
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();

global $DB, $USER, $PAGE, $OUTPUT;

$jobid = required_param('id', PARAM_INT);

$job = $DB->get_record('local_haccgen_job', ['id' => $jobid], '*', MUST_EXIST);
require_capability('local/haccgen:manage', \context_course::instance($job->courseid));

if ($job->userid != $USER->id && !is_siteadmin()) {
    throw new moodle_exception('nopermissions', 'error');
}

$PAGE->set_url(new moodle_url('/local/haccgen/job.php', ['id' => $jobid]));
$PAGE->set_title(get_string('generatingcourse', 'local_haccgen'));
$PAGE->set_heading(get_string('generatingcourse', 'local_haccgen'));

echo $OUTPUT->header();

echo $OUTPUT->render_from_template('local_haccgen/job', [
    'jobid' => $jobid,
    'statusurl' => (new moodle_url('/local/haccgen/job_status.php'))->out(false),
    'consumeurl' => (new moodle_url('/local/haccgen/consume_job.php'))->out(false),
    'sesskey' => sesskey(),
]);

$PAGE->requires->js_call_amd(
  'local_haccgen/jobprogress',
  'init',
  [$jobid]
);

echo $OUTPUT->footer();

