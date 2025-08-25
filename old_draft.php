<?php
require_once('../../config.php');
require_login();

$courseid = required_param('id', PARAM_INT);
$selectedbatchid = optional_param('batchid', '', PARAM_RAW);
$userid = $USER->id;

global $DB, $SESSION, $OUTPUT, $PAGE;

$PAGE->set_url(new moodle_url('/local/aicourse/old_draft.php', ['id' => $courseid]));
$PAGE->set_context(context_course::instance($courseid));
$PAGE->set_title('Load Previous Draft');
$PAGE->set_heading('Load Previous Draft');

// Get all draft batch IDs
$sql = "SELECT batchid, MIN(timecreated) AS timecreated
        FROM {local_aicourse_contentlog}
        WHERE courseid = :courseid AND userid = :userid AND status = 'draft'
        GROUP BY batchid
        ORDER BY timecreated DESC";
$params = ['courseid' => $courseid, 'userid' => $userid];
$drafts = $DB->get_records_sql($sql, $params);

echo $OUTPUT->header();

if (empty($drafts)) {
    echo $OUTPUT->notification('No draft content found for this course.');
    echo $OUTPUT->continue_button(new moodle_url('/local/aicourse/manage.php', ['id' => $courseid, 'step' => 4]));
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::start_tag('form', ['method' => 'get', 'action' => new moodle_url('/local/aicourse/old_draft.php')]);

// Hidden course ID field
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $courseid]);

// Dropdown
echo html_writer::start_tag('div', ['class' => 'form-group']);
echo html_writer::tag('label', 'Select a Draft:', ['for' => 'batchid']);
echo html_writer::start_tag('select', ['name' => 'batchid', 'class' => 'form-control', 'required' => 'required']);

echo html_writer::tag('option', '-- Choose a draft --', ['value' => '']);

foreach ($drafts as $draft) {
    $formatted = userdate($draft->timecreated);
    $selected = ($draft->batchid === $selectedbatchid) ? ['selected' => 'selected'] : [];
    echo html_writer::tag('option', $formatted, ['value' => $draft->batchid] + $selected);
}

echo html_writer::end_tag('select');
echo html_writer::end_tag('div');

// Submit button
// Submit and Delete buttons
echo html_writer::empty_tag('br');
echo html_writer::tag('button', 'Load Draft', [
    'type' => 'submit',
    'name' => 'action',
    'value' => 'load',
    'class' => 'btn btn-primary'
]);

echo html_writer::tag('button', 'Delete Draft', [
    'type' => 'submit',
    'name' => 'action',
    'value' => 'delete',
    'class' => 'btn btn-danger',
    'onclick' => "return confirm('Are you sure you want to delete this draft?');"
]);

echo html_writer::end_tag('form');

$action = optional_param('action', '', PARAM_ALPHA);

if ($selectedbatchid && $action === 'delete') {
    // Delete all records of the selected batch
    $DB->delete_records('local_aicourse_contentlog', [
        'courseid' => $courseid,
        'userid' => $userid,
        'batchid' => $selectedbatchid,
        'status' => 'draft'
    ]);

    // Optional: Notify and redirect back
    redirect(
        new moodle_url('/local/aicourse/old_draft.php', ['id' => $courseid]),
        'Draft deleted successfully.',
        2
    );
} elseif ($selectedbatchid && $action === 'load') {
    // Existing logic to load the draft
        $records = $DB->get_records('local_aicourse_contentlog', [
        'courseid' => $courseid,
        'userid' => $userid,
        'batchid' => $selectedbatchid,
        'status' => 'draft'
    ]);

    $topics = [];
    $quizzes = [];

    foreach ($records as $record) {
        if ($record->content_type === 'subtopic') {
            [$topicTitle, $subtopicTitle] = array_map('trim', explode('>', $record->content_title));
            $found = false;

            foreach ($topics as &$t) {
                if ($t['title'] === $topicTitle) {
                    $t['subtopics'][] = [
                        'title' => $subtopicTitle,
                        'content' => ['text' => $record->content_data]
                    ];
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $topics[] = [
                    'title' => $topicTitle,
                    'subtopics' => [[
                        'title' => $subtopicTitle,
                        'content' => ['text' => $record->content_data]
                    ]]
                ];
            }
        } elseif ($record->content_type === 'quiz') {
            $quizzes[$record->content_title] = json_decode($record->content_data, true);
        }
    }

    $SESSION->aicourse_draft_data = [
        'topics' => $topics,
        'quizzes' => $quizzes
    ];

    // Redirect to manage step 4
    redirect(new moodle_url('/local/aicourse/manage.php', [
        'id' => $courseid,
        'step' => 4,
        'loaddraft' => 1
    ]));
}

echo $OUTPUT->footer();
