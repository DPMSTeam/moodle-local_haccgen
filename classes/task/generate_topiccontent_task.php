<?php
namespace local_aicourse\task;
defined('MOODLE_INTERNAL') || die();

class generate_topiccontent_task extends \core\task\adhoc_task {
    public function get_component() { return 'local_aicourse'; }

    public function execute() {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/local/aicourse/lib.php');
        $vendor = $CFG->dirroot . '/local/aicourse/vendor/autoload.php';
        if (file_exists($vendor)) { require_once($vendor); }

        $data = (object)$this->get_custom_data();
        $job  = $DB->get_record('local_aicourse_job', ['id' => $data->jobid], '*', MUST_EXIST);

        $job->status = 'running'; $job->progress = 10; $job->timemodified = time();
        $DB->update_record('local_aicourse_job', $job);

        try {
            $payload = json_decode($job->inputjson, true, 512, JSON_THROW_ON_ERROR);
            $topics  = $payload['topics']  ?? [];
            $options = (object)($payload['options'] ?? []);

            // 🔴 this is the important change:
            $result = \local_aicourse_api::generate_content_for_topics($topics, $options);

            $job->status = 'success';
            $job->progress = 100;
            $job->resultjson = json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $job->message = 'Topic content ready';
            $job->timemodified = time();
            $DB->update_record('local_aicourse_job', $job);

        } catch (\Throwable $e) {
            $job->status = 'error';
            $job->progress = max(10, (int)$job->progress);
            $job->message = $e->getMessage();
            $job->timemodified = time();
            $DB->update_record('local_aicourse_job', $job);
            throw $e;
        }
    }
}
