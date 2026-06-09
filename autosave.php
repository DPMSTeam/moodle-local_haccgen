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
require_once($CFG->dirroot . '/local/haccgen/lib.php');

use local_haccgen\session_store;
use local_haccgen\outline_helper;

global $DB, $CFG, $USER;

$courseid = required_param('id', PARAM_INT);
$step = required_param('step', PARAM_INT);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);
require_capability('local/haccgen:manage', $context);
require_sesskey();

// Setup logging.
$logdir = $CFG->dataroot . '/local_haccgen';
if (!is_dir($logdir)) {
    @mkdir($logdir, 0770, true);
}
$logfile = $logdir . '/autosave_' . date('Y-m-d') . '.log';

$log = function (string $label, $data = null) use ($logfile, $USER, $courseid) {
    $payload = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($payload) && strlen($payload) > 8000) {
        $payload = substr($payload, 0, 8000) . '…';
    }
    $line = sprintf(
        "[%s] uid=%s course=%s %s: %s\n",
        date('c'),
        $USER->id ?? '0',
        $courseid ?? '0',
        $label,
        (string) $payload
    );
    @file_put_contents($logfile, $line, FILE_APPEND | LOCK_EX);
};

$log('AUTO_SAVE_START', ['step' => $step]);

// Check if already auto-saved for this session.
$autosavekey = 'haccgen_autosaved_step4_' . $courseid;
$alreadyautosaved = isset($_SESSION[$autosavekey]) ? $_SESSION[$autosavekey] : false;

if ($alreadyautosaved === true) {
    $log('AUTO_SAVE_ALREADY_DONE', 'Already auto-saved in this session');
    redirect(
        new moodle_url('/local/haccgen/manage.php', ['id' => $courseid, 'step' => 4, 'autosaved' => 1]),
        get_string('alreadyautosaved', 'local_haccgen'),
        0
    );
}

// Get payload from session.
$haccgendata = session_store::get('haccgen_data');
if (!$haccgendata || empty($haccgendata->canonical_payload_json)) {
    $log('AUTO_SAVE_NO_DATA', 'No canonical payload found in session');
    redirect(
        new moodle_url('/local/haccgen/manage.php', ['id' => $courseid, 'step' => 4]),
        get_string('noautosavedata', 'local_haccgen'),
        0
    );
}

$payloadraw = $haccgendata->canonical_payload_json;
$log('AUTO_SAVE_PAYLOAD_LENGTH', strlen($payloadraw));

// Parse and validate payload.
try {
    $payload = json_decode($payloadraw, true, 512, JSON_THROW_ON_ERROR);
    $log('AUTO_SAVE_PAYLOAD_PARSED', ['topics' => count($payload['topics'] ?? [])]);
} catch (\JsonException $e) {
    $log('AUTO_SAVE_PARSE_ERROR', $e->getMessage());
    throw new moodle_exception('invalidjson', 'local_haccgen', '', 'auto-save: ' . $e->getMessage());
}

if (!is_array($payload) || empty($payload['topics']) || !is_array($payload['topics'])) {
    $log('AUTO_SAVE_INVALID_PAYLOAD', 'Missing or invalid topics');
    throw new moodle_exception('invalidjson', 'local_haccgen', '', 'auto-save: missing/invalid topics');
}

// Sanitize and prepare data (copied from savedraft.php).
$normstr = static fn($v): string => trim(mb_convert_encoding((string)$v, 'UTF-8', 'UTF-8'));

$sanitizesubtopic = static function ($in) use ($normstr) {
    $title = $normstr($in['title'] ?? '');
    $content = $in['content'] ?? [];
    if (!is_array($content)) {
        $content = ['text' => (string)$content, 'itemid' => 0];
    }
    return [
        'title' => $title,
        'content' => [
            'text' => (string)($content['text'] ?? ''),
            'itemid' => (int)($content['itemid'] ?? 0),
        ],
        'type' => $normstr($in['type'] ?? 'page'),
    ];
};

$sanitizequiz = static function ($in, string $faalbacktitle = '') use ($normstr) {
    if (!is_array($in)) {
        return null;
    }
    $title = $normstr($in['quiz_title'] ?? $faalbacktitle);
    $inst = (string)($in['instructions'] ?? '');
    $qs = is_array($in['questions'] ?? null) ? $in['questions'] : [];
    $outq = [];
    foreach ($qs as $i => $q) {
        $opts = array_values(array_map(static fn($o) => (string)$o, (array)($q['options'] ?? [])));
        $outq[] = [
            'question_id' => $q['question_id'] ?? ('q' . ($i + 1)),
            'type' => $q['type'] ?? 'multiple_choice',
            'difficulty' => $q['difficulty'] ?? 'easy',
            'question' => (string)($q['question'] ?? ''),
            'options' => $opts,
            'correct_answer' => (string)($q['correct_answer'] ?? ($q['answer'] ?? '')),
            'explanation' => (string)($q['explanation'] ?? ''),
        ];
    }
    if ($title === '' && empty($outq)) {
        return null;
    }
    return [
        'quiz_title' => $title,
        'instructions' => $inst,
        'questions' => $outq,
    ];
};

// Build structured data.
$kbase = [];
$quizbytitle = [];

foreach ($payload['topics'] as $tidx => $t) {
    $row = outline_helper::parse_payload_topic((array)$t);
    $quiz = $row['quiz_data'] ?? null;
    if ($quiz) {
        $quizbytitle[$quiz['quiz_title']] = $quiz;
    }
    $kbase[] = $row;
}

$log('AUTO_SAVE_NORMALIZED', [
    'topics' => count($kbase),
    'topics_with_quiz' => count($quizbytitle),
]);

// Save to database.
$topicsjsontostore = json_encode($kbase, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$quizjsontostore = json_encode($quizbytitle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$now = time();

$record = new stdClass();
$record->courseid = $courseid;
$record->userid = $USER->id;
$record->batchid = uniqid('autosave_', true);
$record->status = 'autosaved';
$record->topicsjson = $topicsjsontostore;
$record->quizjson = $quizjsontostore;
$record->timecreated = $now;
$record->timemodified = $now;

// Check if auto-save already exists for this course/user.
$existing = $DB->get_record('local_haccgen_content', [
    'courseid' => $courseid,
    'userid' => $USER->id,
    'status' => 'autosaved',
]);

if ($existing) {
    $record->id = $existing->id;
    $record->timemodified = $now;
    $DB->update_record('local_haccgen_content', $record);
    $logmsg = 'AUTO_SAVE_UPDATED';
    $log('AUTO_SAVE_UPDATED', ['id' => $record->id]);
} else {
    $record->id = $DB->insert_record('local_haccgen_content', $record);
    $logmsg = 'AUTO_SAVE_CREATED';
    $log('AUTO_SAVE_CREATED', ['id' => $record->id]);
}

// Also update the session data to reflect saved state.
$haccgendata->autosaved = true;
$haccgendata->autosave_id = $record->id;
$haccgendata->autosave_time = $now;
session_store::set('haccgen_data', $haccgendata);

// Mark as auto-saved for this session.
$_SESSION[$autosavekey] = true;

// Log completion.
$log('AUTO_SAVE_COMPLETED', [
    'draftid' => $record->id,
    'topics_bytes' => strlen($topicsjsontostore),
    'quiz_bytes' => strlen($quizjsontostore),
]);

// Redirect back to step 4 with success flag.
redirect(
    new moodle_url('/local/haccgen/manage.php', [
        'id' => $courseid,
        'step' => 4,
        'autosaved' => 1,
    ]),
    get_string('autosavedsuccess', 'local_haccgen'),
    0
);

