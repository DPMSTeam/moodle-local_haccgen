<?php
require_once(__DIR__.'/../../config.php');
$jobid = required_param('id', PARAM_INT);
require_login();
global $DB, $USER;

$job = $DB->get_record('local_aicourse_job', ['id'=>$jobid], '*', MUST_EXIST);
require_capability('local/aicourse:manage', \context_course::instance($job->courseid));
if ($job->userid != $USER->id ) {
    throw new moodle_exception('nopermissions', 'error');
}

@header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'status'   => $job->status,
    'progress' => (int)$job->progress,
    'message'  => (string)$job->message,
    'result'   => ($job->status === 'success') ? json_decode($job->resultjson, true) : null
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
exit;
