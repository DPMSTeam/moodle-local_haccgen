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
 * Consume a completed generation job and redirect the user to the next step.
 *
 * @package     local_haccgen
 * @copyright   2026 Dynamicpixel Multimedia Solutions
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_haccgen\session_store;

require_login();
require_sesskey();

global $DB, $USER, $CFG;

$jobid = optional_param('jobid', 0, PARAM_INT);
if (!$jobid) {
    $jobid = optional_param('id', 0, PARAM_INT);
}
if (!$jobid) {
    throw new moodle_exception('missingparam', 'error', '', 'jobid');
}

$job = $DB->get_record('local_haccgen_job', ['id' => $jobid], '*', MUST_EXIST);
require_capability('local/haccgen:manage', \context_course::instance($job->courseid));

if ($job->userid != $USER->id && !is_siteadmin()) {
    throw new moodle_exception('nopermissions', 'error');
}

if ($job->status !== 'success') {
    throw new moodle_exception('processing', 'local_haccgen');
}

$resultraw = $job->resultjson ?? '';
$result = $resultraw ? json_decode($resultraw, true) : [];
if ($resultraw && json_last_error() !== JSON_ERROR_NONE) {
    debugging(
        "[local_haccgen][consume_job] JSON decode error for job {$job->id}: " . json_last_error_msg(),
        DEBUG_DEVELOPER
    );
    $result = [];
}

/**
 * Determine whether the array is a list (0..n-1 integer keys).
 *
 * @param array $a Input array.
 * @return bool
 */
function local_haccgen_is_list(array $a): bool {
    $i = 0;
    foreach ($a as $k => $unused) {
        if ($k !== $i++) {
            return false;
        }
    }
    return true;
}

/**
 * Normalise service response into a list of topics.
 *
 * @param mixed $result Decoded job result.
 * @param string $fallbacktitle Fallback title when wrapping subtopics.
 * @return array
 */
function local_haccgen_pick_topics($result, string $fallbacktitle = 'Content'): array {
    if (is_array($result) && isset($result['topics']) && is_array($result['topics'])) {
        return $result['topics'];
    }
    if (is_array($result) && isset($result['data']['topics']) && is_array($result['data']['topics'])) {
        return $result['data']['topics'];
    }
    if (
        is_array($result) &&
        local_haccgen_is_list($result) &&
        isset($result[0]['title']) &&
        isset($result[0]['subtopics'])
    ) {
        return $result;
    }
    if (is_array($result) && isset($result['subtopics']) && is_array($result['subtopics'])) {
        return [[
            'title' => $fallbacktitle,
            'subtopics' => array_map(function ($s) {
                return is_array($s) ? $s : ['title' => 'Subtopic', 'content' => (string) $s];
            }, $result['subtopics']),
        ]];
    }
    return [];
}

require_once($CFG->libdir . '/filelib.php');

$logdir = $CFG->dataroot . '/temp/haccgen';
check_dir_exists($logdir, true, true);

file_put_contents(
    $logdir . '/consume_job_' . (int)$jobid . '.log',
    "=== " . date('c') . " job {$job->id} ===\n" .
        substr($resultraw ?: 'null', 0, 5000) . "\n\n",
    FILE_APPEND | LOCK_EX
);

// Load session-scoped data (MUC).
$haccgendata = session_store::get('haccgen_data');
if (!$haccgendata || !is_object($haccgendata)) {
    $haccgendata = new stdClass();
}

if ($job->type === 'topiccontent') {
    $title = $haccgendata->TOPICTITLE ?? 'Content';
    $topics = local_haccgen_pick_topics($result, $title);

    debugging(
        "[local_haccgen][consume_job] job {$job->id} picked topics: " . count($topics),
        DEBUG_DEVELOPER
    );

    $haccgendata->topics = $topics;
    // Store content generation timing for display on step 4.
    $haccgendata->last_content_generation_duration_seconds = max(0, (int) $job->timemodified - (int) $job->timecreated);
    $haccgendata->last_content_generation_completed_at = (int) $job->timemodified;
    session_store::set('haccgen_data', $haccgendata);

    redirect(new moodle_url('/local/haccgen/manage.php', [
        'id' => $job->courseid,
        'step' => 4,
    ]));
}

if ($job->type === 'subtopics') {
    $haccgendata->raw_subtopics = $result['subtopics'] ?? [];
    session_store::set('haccgen_data', $haccgendata);

    redirect(new moodle_url('/local/haccgen/manage.php', [
        'id' => $job->courseid,
        'step' => 3,
    ]));
}
throw new moodle_exception('unknownjobtype', 'local_haccgen');

