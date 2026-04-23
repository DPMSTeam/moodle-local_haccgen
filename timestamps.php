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
 * Display generation timestamps for drafts and created courses (local_haccgen).
 *
 * @package     local_haccgen
 * @copyright   2026 Dynamicpixel Multimedia Solutions
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/haccgen/lib.php');

global $DB, $OUTPUT, $PAGE, $USER;

$courseid = required_param('id', PARAM_INT);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);
require_capability('local/haccgen:manage', $context);

$PAGE->set_url(new moodle_url('/local/haccgen/timestamps.php', ['id' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('generation_timestamps', 'local_haccgen'));
$PAGE->set_heading($course->fullname);

$strftimedatetime = get_string('strftimedatetime', 'langconfig');

$rows = $DB->get_records(
    'local_haccgen_timestamps',
    ['courseid' => $courseid],
    'timecreated DESC',
    '*'
);

$records = [];
foreach ($rows as $row) {
    $topicduration = null;
    if (isset($row->topic_generation_seconds) && $row->topic_generation_seconds !== null) {
        $secs = (float) $row->topic_generation_seconds;
        $topicduration = ($secs >= 60)
            ? get_string('duration_min_sec', 'local_haccgen', (object) [
                'min' => (int) floor($secs / 60),
                'sec' => (int) round($secs % 60),
            ])
            : get_string('duration_sec_only', 'local_haccgen', (object) ['sec' => (int) round($secs)]);
    }
    $contentduration = null;
    if (!empty($row->content_generation_seconds)) {
        $secs = (int) $row->content_generation_seconds;
        $contentduration = ($secs >= 60)
            ? get_string('duration_min_sec', 'local_haccgen', (object) [
                'min' => (int) floor($secs / 60),
                'sec' => (int) round($secs % 60),
            ])
            : get_string('duration_sec_only', 'local_haccgen', (object) ['sec' => $secs]);
    }

    $user = $DB->get_record('user', ['id' => $row->userid], 'id, firstname, lastname');
    $username = $user ? fullname($user) : get_string('unknownuser', 'local_haccgen');

    $topicsummarydisplay = null;
    if (!empty($row->topicsummary)) {
        $decoded = json_decode($row->topicsummary, true);
        $topicsummarydisplay = is_array($decoded)
            ? implode(', ', array_filter($decoded))
            : $row->topicsummary;
    }

    $records[] = [
        'id' => $row->id,
        'record_type' => $row->record_type,
        'badge_class' => 'badge-' . $row->record_type,
        'record_type_label' => $row->record_type === 'created'
            ? get_string('record_type_created', 'local_haccgen')
            : get_string('record_type_draft', 'local_haccgen'),
        'batchid' => $row->batchid ?? '',
        'topicsummary' => $topicsummarydisplay,
        'saved_at' => userdate($row->timecreated, $strftimedatetime),
        'saved_at_iso' => date('c', $row->timecreated),
        'topic_duration' => $topicduration,
        'topic_generated_at' => !empty($row->topic_generated_at)
            ? userdate($row->topic_generated_at, $strftimedatetime)
            : null,
        'content_duration' => $contentduration,
        'content_completed_at' => !empty($row->content_completed_at)
            ? userdate($row->content_completed_at, $strftimedatetime)
            : null,
        'username' => $username,
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_haccgen/timestamps', [
    'courseid' => $courseid,
    'coursename' => $course->fullname,
    'manageurl' => (new moodle_url('/local/haccgen/manage.php', ['id' => $courseid]))->out(false),
    'records' => $records,
    'has_records' => !empty($records),
]);
echo $OUTPUT->footer();
