<?php
require_once(__DIR__ . '/../../config.php');
require __DIR__ . '/vendor/autoload.php';
require_once($CFG->dirroot . '/local/aicourse/lib.php');
require_once($CFG->dirroot . '/local/aicourse/settings.php');
require_once($CFG->dirroot . '/local/aicourse/lib/Parsedown.php');  // Adjust the path if needed
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/question/editlib.php');
require_once($CFG->dirroot . '/question/engine/bank.php');

$parsedown = new Parsedown();

$courseid = required_param('id', PARAM_INT);
$step = optional_param('step', 1, PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);
require_capability('local/aicourse:manage', $context);

$PAGE->set_url('/local/aicourse/manage.php', ['id' => $courseid, 'step' => $step]);
$PAGE->set_title(get_string('manageai', 'local_aicourse'));
$PAGE->set_heading($course->fullname);
$PAGE->requires->js('/lib/requirejs.php'); // ✅ load require()
$context = context_system::instance(); // Or context_module::instance($cmid)

$editorid = 'id_contenteditor'; // This must match the textarea ID in the template

// REQUIRED: editor options array
$editoroptions = [
    'maxfiles' => 0,
    'maxbytes' => 0,
    'trusttext' => true,
    'context' => $context,
];

// This attaches the TinyMCE or Atto editor to the textarea
$editor = editors_get_preferred_editor(FORMAT_HTML);
$editorinitjs = $editor->use_editor($editorid, $editoroptions);

// Create the actual <textarea> element
$textarea = html_writer::tag('textarea', '', [
    'id' => $editorid,
    'name' => 'contenteditor',
    'rows' => 10,
    'cols' => 60,
    'class' => 'form-control'
]);

ob_start();

// Initialize or convert session data to an object
if (!isset($SESSION->aicourse_data) || !is_object($SESSION->aicourse_data)) {
    if (isset($SESSION->aicourse_data) && is_array($SESSION->aicourse_data)) {
        // Convert existing array to object, taking the last entry if multiple
        $SESSION->aicourse_data = (object) array_pop($SESSION->aicourse_data);
    } else {
        $SESSION->aicourse_data = new stdClass();
    }
}

$hasdraft = $DB->record_exists('local_aicourse_contentlog', [
    'courseid' => $courseid,
    'userid' => $USER->id,
    'status' => 'draft'
]);


$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = optional_param('action', '', PARAM_TEXT);
    $topicsjsonreq = optional_param('topicsjson', '', PARAM_RAW);
    $quizjsonreq   = optional_param('quizjson',   '', PARAM_RAW);
    if ($action === 'back') {
        $prevstep = max(1, $step - 1); // Use max to prevent negative step
        ob_end_clean();
        redirect(new moodle_url('/local/aicourse/manage.php', ['id' => $courseid, 'step' => $prevstep]));
        exit;
    }

    $data = new stdClass();
    $generation_type = optional_param('generation_type', 'ai', PARAM_TEXT);
    if ($step == 1) {
        $data->generation_type = $generation_type;
        if ($generation_type === 'ai') {
            // Validate AI fields
            $data->coursename = optional_param('TOPICTITLE', '', PARAM_TEXT);
            if (trim($data->coursename) === '') {
                $errors['TOPICTITLE'] = get_string('error_required', 'local_aicourse');
            }
            $data->targetaudience = optional_param('targetaudience', '', PARAM_TEXT);
            if (empty($data->targetaudience)) {
                $errors['targetaudience'] = get_string('error_required', 'local_aicourse');
            }
            $data->description = optional_param('description', '', PARAM_TEXT);

            // Explicitly ignore all uploaded fields
            $data->coursename_uploaded = '';
            $data->targetaudience_uploaded = '';
            $data->description_uploaded = '';
            $data->pdf_file = '';
            $data->pdf_fileid = 0;
            $data->pdf_reference_url = '';

            //new fields for AI generation
            $data->customprompt = optional_param('customprompt', '', PARAM_TEXT);
            if ($is_draft) {
                $formdata['loaddrafturl'] = new moodle_url('/local/aicourse/old_draft.php', ['id' => $courseid]);
            }
        } else {
            // Explicitly unset AI fields to prevent validation
            unset($_POST['TOPICTITLE']);
            unset($_POST['targetaudience']);
            unset($_POST['description']);
            unset($_POST['customprompt']);

            // Validate uploaded fields
            $data->coursename = optional_param('TOPICTITLE_uploaded', '', PARAM_TEXT);
            if (trim($data->coursename) === '') {
                $errors['TOPICTITLE_uploaded'] = get_string('error_required', 'local_aicourse');
            }
            $data->targetaudience = optional_param('targetaudience_uploaded', '', PARAM_TEXT);
            if (empty($data->targetaudience)) {
                $errors['targetaudience_uploaded'] = get_string('error_required', 'local_aicourse');
            }
            $data->description = optional_param('description_uploaded', '', PARAM_TEXT);

            $data->customprompt = optional_param('customprompt', '', PARAM_TEXT);

            if (isset($_FILES['pdf_upload']) && $_FILES['pdf_upload']['error'] !== UPLOAD_ERR_NO_FILE) {
                $file = $_FILES['pdf_upload'];
                if ($file['error'] === UPLOAD_ERR_OK) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime_type = finfo_file($finfo, $file['tmp_name']);
                    finfo_close($finfo);

                    if ($mime_type === 'application/pdf') {
                        $fs = get_file_storage();
                        $originalname = clean_filename($file['name']);
                        $ext = pathinfo($originalname, PATHINFO_EXTENSION);
                        $basename = pathinfo($originalname, PATHINFO_FILENAME);
                        $unique_filename = $basename . '_' . time() . '.' . $ext;

                        $data->pdf_file = $unique_filename;

                        // Values you will also pass to make_pluginfile_url:
                        $contextid = $context->id;
                        $component = 'local_aicourse';
                        $filearea  = 'uploads';
                        $itemid    = $courseid;
                        $filepath  = '/'; // or '/sub/dir/' (leading & trailing slash)
                        $filename  = $unique_filename; // same name you saved in $filerecord

// Create the file:
                        $filerecord = [
                            'contextid' => $contextid,
                              'component' => $component,
                            'filearea'  => $filearea,
                            'itemid'    => $itemid,
                             'filepath'  => $filepath,
                            'filename'  => $filename,
                            'timecreated' => time(),
                            'timemodified' => time(),
                         ];
                   $newfile = $fs->create_file_from_pathname($filerecord, $file['tmp_name']);

// Sign:
                   $expires = time() + 3600;
                   $secret  = (string)get_config('local_aicourse', 'linksecret'); // same on all nodes
                   $payload = implode('|', [$contextid, $component, $filearea, $itemid, $filepath, $filename, $expires]);
                    $token   = hash_hmac('sha256', $payload, $secret);

// URL:
                   $url = moodle_url::make_pluginfile_url($contextid, $component, $filearea, $itemid, $filepath, $filename);
                   $url->param('expires', $expires);
                   $url->param('token', $token);

                   $data->pdf_reference_url = $url->out(false);
                        $data->description = '';
                    } else {
                        $errors['pdf_upload'] = get_string('invalid_pdf', 'local_aicourse');
                    }
                }
            } else {
                $errors['pdf_upload'] = get_string('error_required', 'local_aicourse');
            }
            // Explicitly ignore all AI fields
            $data->TOPICTITLE_ai = '';
            $data->targetaudience_ai = '';
            $data->description_ai = '';
            $data->customprompt_ai = '';
        }

        $data->courseduration = 'Less than 15 minutes';
        $data->levelofunderstanding = 'Beginner';
        $data->toneofnarrative = 'Formal';
        if ($is_draft) {
            $formdata['loaddrafturl'] = new moodle_url('/local/aicourse/old_draft.php', ['id' => $courseid]);
        }
    } elseif ($step == 2) {
        $valid_levels = ['Beginner', 'Intermediate', 'Advanced'];
        $valid_durations = ['Less than 15 minutes', 'Less than 30 minutes', 'Less than 60 minutes', 'Less than 90 minutes', 'Less than 120 minutes'];
        $valid_tones = ['Formal', 'Conversational', 'Engaging'];
        $valid_languages = ['English', 'Hindi'];
        $min_topics = 2;
        $max_topics = 10;

        $data->levelofunderstanding = required_param('levelofunderstanding', PARAM_TEXT);
        if (!in_array($data->levelofunderstanding, $valid_levels)) {
            $errors['levelofunderstanding'] = get_string('please_select', 'local_aicourse');
        }

        $data->toneofnarrative = required_param('toneofnarrative', PARAM_TEXT);
        if (!in_array($data->toneofnarrative, $valid_tones)) {
            $errors['toneofnarrative'] = get_string('please_select', 'local_aicourse');
        }

        $data->courseduration = required_param('courseduration', PARAM_TEXT);
        if (!in_array($data->courseduration, $valid_durations)) {
            $errors['courseduration'] = get_string('please_select', 'local_aicourse');
        }

        // New field: Language selection
        $data->courselanguage = required_param('courselanguage', PARAM_TEXT);
        if (!in_array($data->courselanguage, $valid_languages)) {
            $errors['courselanguage'] = get_string('please_select', 'local_aicourse');
        }

        // New field: Number of topics (integer between 2–10)
        $data->numberoftopics = optional_param('numberoftopics', 5, PARAM_INT);
        if ($data->numberoftopics < $min_topics || $data->numberoftopics > $max_topics) {
            $errors['numberoftopics'] = get_string('invalid_topic_count', 'local_aicourse'); // You should define this string in your lang file
        }

        // Existing session data retrieval
        $data->coursename = $SESSION->aicourse_data->TOPICTITLE ?? '';
        $data->targetaudience = $SESSION->aicourse_data->targetaudience ?? '';
        $data->description = $SESSION->aicourse_data->description ?? '';
        $data->generation_type = $SESSION->aicourse_data->generation_type ?? 'ai';
        $data->pdf_file = $SESSION->aicourse_data->pdf_file ?? '';
        $data->pdf_fileid = $SESSION->aicourse_data->pdf_fileid ?? 0;
        $data->pdf_reference_url = $SESSION->aicourse_data->pdf_reference_url ?? '';
        $data->customprompt = $SESSION->aicourse_data->customprompt ?? '';
    } elseif ($step == 3) {
        $data->topic_order = optional_param('topic_order', '', PARAM_RAW);
        $data->coursename = $SESSION->aicourse_data->TOPICTITLE ?? '';
        $data->targetaudience = $SESSION->aicourse_data->targetaudience ?? '';
        $data->description = $SESSION->aicourse_data->description ?? '';
        $data->levelofunderstanding = $SESSION->aicourse_data->levelofunderstanding ?? 'Beginner';
        $data->toneofnarrative = $SESSION->aicourse_data->toneofnarrative ?? 'Formal';
        $data->courseduration = $SESSION->aicourse_data->courseduration ?? 'Less than 15 minutes';
        $data->generation_type = $SESSION->aicourse_data->generation_type ?? 'ai';
        $data->pdf_file = $SESSION->aicourse_data->pdf_file ?? '';
        $data->pdf_fileid = $SESSION->aicourse_data->pdf_fileid ?? 0;
        $data->customprompt = $SESSION->aicourse_data->customprompt ?? '';
        $data->courselanguage = $SESSION->aicourse_data->courselanguage ?? 'English';
        $data->pdf_reference_url = $SESSION->aicourse_data->pdf_reference_url ?? '';
        $data->numberoftopics = $SESSION->aicourse_data->numberoftopics ?? 5;
    } elseif ($step == 4) {
        $data->coursename = $SESSION->aicourse_data->TOPICTITLE ?? '';
        $data->targetaudience = $SESSION->aicourse_data->targetaudience ?? '';
        $data->description = $SESSION->aicourse_data->description ?? '';
        $data->levelofunderstanding = $SESSION->aicourse_data->levelofunderstanding ?? 'Beginner';
        $data->toneofnarrative = $SESSION->aicourse_data->toneofnarrative ?? 'Formal';
        $data->courseduration = $SESSION->aicourse_data->courseduration ?? 'Less than 15 minutes';
        $data->generation_type = $SESSION->aicourse_data->generation_type ?? 'ai';
        $data->pdf_file = $SESSION->aicourse_data->pdf_file ?? '';
        $data->pdf_fileid = $SESSION->aicourse_data->pdf_fileid ?? 0;
        $data->customprompt = $SESSION->aicourse_data->customprompt ?? '';
        $data->pdf_reference_url = $SESSION->aicourse_data->pdf_reference_url ?? '';
        $data->courselanguage = $SESSION->aicourse_data->courselanguage ?? 'English';
        $data->numberoftopics = $SESSION->aicourse_data->numberoftopics ?? 5;
    }

    if (empty($errors)) {
        $SESSION->aicourse_data->TOPICTITLE = $data->coursename ?? '';
        $SESSION->aicourse_data->targetaudience = $data->targetaudience ?? '';
        $SESSION->aicourse_data->description = $data->description ?? '';
        $SESSION->aicourse_data->levelofunderstanding = $data->levelofunderstanding ?? '';
        $SESSION->aicourse_data->toneofnarrative = $data->toneofnarrative ?? '';
        $SESSION->aicourse_data->courseduration = $data->courseduration ?? '';
        $SESSION->aicourse_data->generation_type = $data->generation_type ?? 'ai';
        $SESSION->aicourse_data->pdf_file = $data->pdf_file ?? '';
        $SESSION->aicourse_data->pdf_fileid = $data->pdf_fileid ?? 0;
        $SESSION->aicourse_data->courselanguage = $data->courselanguage ?? 'English';
        $SESSION->aicourse_data->numberoftopics = $data->numberoftopics ?? 5;
        $SESSION->aicourse_data->customprompt = $data->customprompt ?? '';
        $SESSION->aicourse_data->pdf_reference_url = $data->pdf_reference_url;
        $is_draft = optional_param('savedraft', 0, PARAM_BOOL);


        if ($step == 3 && $action === 'save' && !empty($data->topic_order)) {
            try {

                $topics = json_decode($data->topic_order, true, 512, JSON_THROW_ON_ERROR);

                $job = (object)[
                    'userid'       => $USER->id,
                    'courseid'     => $courseid,
                    'type'         => 'topiccontent',
                    'status'       => 'queued',
                    'progress'     => 0,
                    'message'      => null,
                    'inputjson'    => json_encode([
                        'topics'  => $topics,
                        'options' => [
                            'coursename'           => $SESSION->aicourse_data->TOPICTITLE ?? '',
                            'targetaudience'       => $SESSION->aicourse_data->targetaudience ?? '',
                            'description'          => $SESSION->aicourse_data->description ?? '',
                            'levelofunderstanding' => $SESSION->aicourse_data->levelofunderstanding ?? 'Beginner',
                            'toneofnarrative'      => $SESSION->aicourse_data->toneofnarrative ?? 'Formal',
                            'courseduration'       => $SESSION->aicourse_data->courseduration ?? 'Less than 15 minutes',
                            'numberoftopics'       => $SESSION->aicourse_data->numberoftopics ?? 5,
                            'case_study_data'      => $SESSION->aicourse_data->case_study_data ?? null,
                        ],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'timecreated'  => time(),
                    'timemodified' => time(),
                ];

                $job->id = $DB->insert_record('local_aicourse_job', $job);
                // error_log("AI Course Job {$job->id}: created with status 'queued'.");
                $task = new \local_aicourse\task\generate_topiccontent_task();
                // error_log("AI Course Job {$job->id}: queued for processing by adhoc task.");
                $task->set_custom_data(['jobid' => $job->id]);
                $task->set_userid($USER->id);
                $task->set_component('local_aicourse');
                \core\task\manager::queue_adhoc_task($task); // or task_manager::queue_adhoc_task($task);

                // Redirect to polling page (job.php). Do not touch $_SESSION here.
                redirect(new moodle_url('/local/aicourse/job.php', ['id' => $job->id]));
                exit; // be explicit

            } catch (Throwable $e) {
                $errors['general'] = get_string('invalidtopicorder', 'local_aicourse', $e->getMessage());
            }
        } elseif ($step == 4 && $action === 'save') {

            if ($is_draft) {
                // 🔁 Handle saving to your "draft table" here.
                // include_once(__DIR__ . '/savedraft.php');
                require_once($CFG->dirroot . '/local/aicourse/savedraft.php');
                exit;
            }


            if ($topicsjsonreq !== '') {

                $subtopiccontents = json_decode($topicsjsonreq, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new moodle_exception(
                        'invalidjson',
                        'local_aicourse',
                        '',
                        'topicsjson: ' . json_last_error_msg()
                    );
                }
                $formdata['topicsjson'] = $topicsjsonreq;
            } else {

                $subtopiccontents       = $SESSION->aicourse_data->topicsjson ?? [];
                $formdata['topicsjson'] = json_encode(
                    $subtopiccontents,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            }

            $SESSION->aicourse_data->topicsjson = $subtopiccontents;

            if (!function_exists('normalise_title')) {
                function normalise_title(string $s): string
                {
                    $s = preg_replace('/^quiz:\s*/i', '', $s);
                    return mb_strtolower(trim($s));
                }
            }

            if ($quizjsonreq !== '') {
                $quizcontents = json_decode($quizjsonreq, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new moodle_exception(
                        'invalidjson',
                        'local_aicourse',
                        '',
                        'quizjson: ' . json_last_error_msg()
                    );
                }
                $cleanQuiz = [];
                foreach ($quizcontents as $rawKey => $payload) {
                    $cleanKey = normalise_title($rawKey);
                    if (empty($payload['quiz_title'])) {
                        $payload['quiz_title'] = preg_replace('/^quiz:\s*/i', '', trim($rawKey));
                    }
                    $cleanQuiz[$cleanKey] = $payload;
                }
                $quizcontents = $cleanQuiz;
                $formdata['quizjson'] = $quizjsonreq;
            } else {
                $quizcontents = $SESSION->aicourse_data->quizjson ?? [];
                $formdata['quizjson'] = json_encode(
                    $quizcontents,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
                error_log('Using session quiz JSON fallback');
            }

            $SESSION->aicourse_data->quizjson = $quizcontents;

            $SESSION->aicourse_data->topicsjson = $subtopiccontents;

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new moodle_exception('invalidjson', 'local_aicourse', '', 'JSON decode error: ' . json_last_error_msg());
            }

            $newcourse = get_course($courseid);
            $module    = $DB->get_record('modules', ['name' => 'page'], '*', MUST_EXIST);


            $existingsections = $DB->get_records('course_sections', ['course' => $courseid]);
            $sectionnumbers   = array_map(static function ($s) {
                return (int)$s->section;
            }, $existingsections);
            $sectionnumber    = !empty($sectionnumbers) ? max($sectionnumbers) + 1 : 1;

            if (!function_exists('buildTopicsFromMixed')) {
                function buildTopicsFromMixed($flat, array $baseTopics): array
                {
                    if (is_string($flat)) {
                        try {
                            $flat = json_decode($flat, true, 512, JSON_THROW_ON_ERROR);
                        } catch (Throwable $e) {
                            $flat = [];
                        }
                    }
                    if (!is_array($flat)) $flat = [];

                    $index = [];
                    foreach ($baseTopics as $tIdx => $topic) {
                        foreach ($topic['subtopics'] as $sIdx => $sub) {
                            $key = mb_strtolower(trim((string)($sub['title'] ?? '')));
                            $index[$key] = ['t' => $tIdx, 's' => $sIdx];
                        }
                    }

                    $result          = $baseTopics;
                    $defaultTopicIdx = 0;

                    foreach ($flat as $rawTitle => $rawContent) {
                        $cleanTitle = mb_strtolower(trim((string)$rawTitle));
                        $newText    = is_array($rawContent) ? ($rawContent['text'] ?? '') : (string)$rawContent;
                        $newItemid  = is_array($rawContent) ? (int)($rawContent['itemid'] ?? 0) : 0;

                        if (isset($index[$cleanTitle])) {
                            $t = $index[$cleanTitle]['t'];
                            $s = $index[$cleanTitle]['s'];

                            $finalText = trim($newText);
                            $result[$t]['subtopics'][$s]['content'] = [
                                'text'   => $finalText,
                                'itemid' => $newItemid
                            ];
                            $result[$t]['subtopics'][$s]['content_html'] = $finalText;
                        } else {
                            $result[$defaultTopicIdx]['subtopics'][] = [
                                'title'        => $rawTitle,
                                'content'      => ['text' => trim($newText), 'itemid' => $newItemid],
                                'examples'     => [],
                                'content_html' => trim($newText),
                            ];
                            $index[$cleanTitle] = [
                                't' => $defaultTopicIdx,
                                's' => count($result[$defaultTopicIdx]['subtopics']) - 1
                            ];
                        }
                    }
                    return $result;
                }
            }

            $baseTopics = json_decode(json_encode($SESSION->aicourse_data2->topics ?? []), true);
            if (!is_array($baseTopics)) $baseTopics = [];
            $topics = buildTopicsFromMixed($subtopiccontents, $baseTopics);

            // ----- Insert course-level WYWL section at start -----
            $courseLevelObjectives = $SESSION->aicourse_data->learning_objectives1 ?? [];
            // error_log('Course level objectives: ' . print_r($courseLevelObjectives, true));

            // Only add if there is content
            if (!empty($courseLevelObjectives)) {
                if (is_array($courseLevelObjectives)) {
                    // Build a bullet-point HTML list
                    $courseLevelContent = '<ul>';
                    foreach ($courseLevelObjectives as $obj) {
                        $courseLevelContent .= '<li>' . htmlspecialchars($obj) . '</li>';
                    }
                    $courseLevelContent .= '</ul>';
                } else {
                    $courseLevelContent = htmlspecialchars((string)$courseLevelObjectives);
                }

                // Create the section structure
                // Check if WYWL course-level section already exists
                $hasCourseWywl = false;
                foreach ($topics as $topic) {
                    if (isset($topic['title']) && $topic['title'] === 'About this course') {
                        $hasCourseWywl = true;
                        break;
                    }
                }

                $courseLevelObjectives = $SESSION->aicourse_data->learning_objectives1 ?? [];

                // Only add if not already present (to preserve edited content)
                if (!$hasCourseWywl && !empty($courseLevelObjectives)) {
                    if (is_array($courseLevelObjectives)) {
                        $courseLevelContent = '<ul>';
                        foreach ($courseLevelObjectives as $obj) {
                            $courseLevelContent .= '<li>' . htmlspecialchars($obj) . '</li>';
                        }
                        $courseLevelContent .= '</ul>';
                    } else {
                        $courseLevelContent = htmlspecialchars((string)$courseLevelObjectives);
                    }

                    // Create the section structure
                    $courseOverviewTopic = [
                        'title' => 'About this course',
                        'subtopics' => [
                            [
                                'title' => 'Inside this course',
                                'content' => $courseLevelContent,
                                'type' => 'page'
                            ]
                        ]
                    ];

                    // Insert at the start so it becomes the first section
                    array_unshift($topics, $courseOverviewTopic);
                }
            }
            // ----- End insert -----

            // --- FIX WYWL placement: ensure it sits as the FIRST subtopic of its OWN topic ---
            $norm = static function ($s) {
                return mb_strtolower(trim((string)$s));
            };
            $COURSE_WYWL = 'About this course';

            // ------------------------------
            // 1) Handle course-level WYWL (top of course)
            // ------------------------------
            $userCourseWywlHtml = null;
            $userCourseWywlItemid = 0;

            if (is_array($subtopiccontents)) {
                foreach ($subtopiccontents as $rawTitle => $rawContent) {
                    if ($norm($rawTitle) === $norm($COURSE_WYWL)) {
                        if (is_array($rawContent)) {
                            $userCourseWywlHtml = trim(
                                $rawContent['text']
                                    ?? $rawContent['content']
                                    ?? $rawContent['content_html']
                                    ?? ''
                            );
                            $userCourseWywlItemid = (int)($rawContent['itemid'] ?? 0);
                        } else {
                            $userCourseWywlHtml = trim((string)$rawContent);
                        }
                        unset($subtopiccontents[$rawTitle]); // critical: avoid dup in merge
                        break;
                    }
                }
            }

            // Merge base topics without WYWL noise
            $baseTopics = json_decode(json_encode($SESSION->aicourse_data2->topics ?? []), true);
            if (!is_array($baseTopics)) $baseTopics = [];
            $topics = buildTopicsFromMixed($subtopiccontents, $baseTopics);

            // fallback content if no edited WYWL came in
            if ($userCourseWywlHtml === null) {
                $courseLevelObjectives = $SESSION->aicourse_data->learning_objectives1 ?? [];
                if (!empty($courseLevelObjectives)) {
                    if (is_array($courseLevelObjectives)) {
                        $tmp = '<ul>';
                        foreach ($courseLevelObjectives as $obj) {
                            $tmp .= '<li>' . htmlspecialchars($obj) . '</li>';
                        }
                        $tmp .= '</ul>';
                        $userCourseWywlHtml = $tmp;
                    } else {
                        $userCourseWywlHtml = htmlspecialchars((string)$courseLevelObjectives);
                    }
                } else {
                    $userCourseWywlHtml = '';
                }
            }

            // remove any existing course-level WYWL topics
            $topics = array_values(array_filter($topics, function ($topic) use ($norm, $COURSE_WYWL) {
                return $norm($topic['title'] ?? '') !== $norm($COURSE_WYWL);
            }));

            // prepend fresh course-level WYWL topic
            $courseOverviewTopic = [
                'title' => $COURSE_WYWL,
                'subtopics' => [[
                    'title'   => $COURSE_WYWL,
                    'content' => [
                        'text'   => $userCourseWywlHtml,
                        'itemid' => $userCourseWywlItemid
                    ],
                    'type' => 'page'
                ]]
            ];
            array_unshift($topics, $courseOverviewTopic);


            // 2) Handle topic-level WYWL (per topic)
            // ------------------------------
            $topicWywlMap = [];

            // Step A: Extract WYWL from flat input
            if (is_array($subtopiccontents)) {
                foreach ($subtopiccontents as $rawTitle => $rawContent) {
                    if (preg_match('/^Learning objectives\s*-\s*(.+)$/i', $rawTitle, $m)) {
                        $topicName = trim($m[1]);
                        $topicWywlMap[$topicName] = [
                            'title'   => $rawTitle,
                            'content' => is_array($rawContent)
                                ? ($rawContent['text'] ?? $rawContent['content_html'] ?? $rawContent['content'] ?? '')
                                : (string)$rawContent,
                            'itemid'  => is_array($rawContent) ? (int)($rawContent['itemid'] ?? 0) : 0
                        ];
                        unset($subtopiccontents[$rawTitle]); // remove from flat map
                    }
                }
            }

            // Step B: Purge any misplaced WYWL subtopics from ALL topics
            foreach ($topics as &$topic) {
                $topic['subtopics'] = array_values(array_filter($topic['subtopics'] ?? [], function ($s) use ($norm) {
                    return !preg_match('/^Learning objectives\s*-/i', (string)($s['title'] ?? ''));
                }));
            }
            unset($topic);

            // Step C: Reinsert WYWL ONLY in the correct topic as first subtopic
            foreach ($topics as &$topic) {
                $tTitle = trim((string)($topic['title'] ?? ''));
                if (isset($topicWywlMap[$tTitle])) {
                    $wywl = $topicWywlMap[$tTitle];
                    array_unshift($topic['subtopics'], [
                        'title'   => $wywl['title'],
                        'content' => [
                            'text'   => $wywl['content'],
                            'itemid' => $wywl['itemid']
                        ],
                        'type' => 'page'
                    ]);
                }
            }
            unset($topic);



            foreach ($topics as $topic) {
                $topicname = $topic['title'] ?? 'Untitled Topic';
                $subtopics = $topic['subtopics'] ?? [];
                if (empty($subtopics)) {
                    throw new moodle_exception('nosubtopics', 'local_aicourse', '', 'No subtopics found for this topic.');
                }

                $section   = course_create_section($courseid, $sectionnumber);
                $sectionid = is_object($section) ? $section->id : $section;
                if ($sectionid) {
                    $DB->set_field('course_sections', 'name', $topicname, ['id' => $sectionid]);
                }

                foreach ($subtopics as $sub) {
                    $subtopicname = $sub['title'] ?? 'Untitled Subtopic';

                    $editor = $sub['content'] ?? '';
                    if (!is_array($editor)) {

                        $editor = ['text' => (string)$editor, 'itemid' => 0];
                    }
                    $draftid = (int)($editor['itemid'] ?? 0);
                    $html    = $editor['text'] ?? '';

                    $page                = new stdClass();
                    $page->course        = $courseid;
                    $page->name          = $subtopicname;
                    $page->content       = '';
                    $page->contentformat = FORMAT_HTML;
                    $page->intro         = '';
                    $page->introformat   = FORMAT_HTML;
                    $page->timemodified  = time();
                    $page->id            = $DB->insert_record('page', $page);


                    $cm                 = new stdClass();
                    $cm->course         = $courseid;
                    $cm->module         = $module->id;
                    $cm->instance       = $page->id;
                    $cm->section        = $sectionnumber;
                    $cm->visible        = 1;
                    $cm->groupmode      = 0;
                    $cm->groupingid     = 0;
                    $cm->added          = time();
                    $cm->id             = add_course_module($cm);
                    course_add_cm_to_section($courseid, $cm->id, $sectionnumber);


                    $cmcontext  = context_module::instance($cm->id);
                    $editoropts = [
                        'maxfiles' => 10,
                        'context'  => $cmcontext,
                        'subdirs'  => 0
                    ];
                    $html = file_save_draft_area_files(
                        $draftid,
                        $cmcontext->id,
                        'mod_page',
                        'content',
                        0,
                        $editoropts,
                        $html
                    );

                    $page->content = $html;
                    $DB->update_record('page', $page);
                }


                if (!empty($topic['quiz_included']) && !empty($topic['quiz_data'])) {
                    $quizdata     = $topic['quiz_data'];
                    $quizmoduleid = $DB->get_field('modules', 'id', ['name' => 'quiz'], MUST_EXIST);

                    $quizsettings = (object)[
                        'modulename'           => 'quiz',
                        'module'               => $quizmoduleid,
                        'course'               => $courseid,
                        'section'              => $sectionnumber,
                        'visible'              => 1,
                        'visibleold'           => 1,
                        'visibleoncoursepage'  => 1,
                        // 'name'                 => $quizdata['quiz_title'] ?? 'Untitled Quiz',
                        'name' =>  ($quizdata['quiz_title'] ?? 'Untitled Quiz'),

                        'intro'                => '<p>' . ($quizdata['instructions'] ?? '') . '</p>',
                        'introformat'          => FORMAT_HTML,
                        'preferredbehaviour'   => 'deferredfeedback',
                        'grade'                => 10,
                        'sumgrades'            => count($quizdata['questions'] ?? []),
                        'questionsperpage'     => 1,
                        'timeopen'             => 0,
                        'timeclose'            => 0,
                        'timelimit'            => 0,
                        'quizpassword'         => ''
                    ];

                    $cmquiz  = add_moduleinfo($quizsettings, $newcourse);

                    $quizid = $cmquiz->instance;

                    $DB->update_record('quiz', [
                        'id'                    => $quizid,
                        'reviewattempt'         => 69632,
                        'reviewcorrectness'     => 4096,
                        'reviewmaxmarks'      => 4096,
                        'reviewmarks'           => 4096,
                        'reviewspecificfeedback' => 4096,
                        'reviewgeneralfeedback' => 4096,
                        'reviewrightanswer'     => 4096,
                        'reviewoverallfeedback' => 4096
                    ]);

                    $realcm  = get_coursemodule_from_instance('quiz', $cmquiz->instance, $courseid, false, MUST_EXIST);
                    $quiz    = $DB->get_record('quiz', ['id' => $cmquiz->instance], '*', MUST_EXIST);
                    $quizctx = context_module::instance($realcm->id);

                    // Default category for this quiz context
                    $catobj = question_make_default_categories([$quizctx]);
                    if (!isset($catobj->id)) {
                        // Could not get cat, skip
                        continue;
                    }
                    $catid = (int)$catobj->id;

                    foreach ($quizdata['questions'] as $index => $qdata) {
                        if (($qdata['type'] ?? 'multiple_choice') !== 'multiple_choice') {
                            continue;
                        }

                        $form = new stdClass();
                        $form->category                 = $catid;
                        $form->contextid                = $quizctx->id;
                        $form->qtype                    = 'multichoice';
                        $form->name                     = $qdata['question'];
                        $form->questiontext             = ['text' => $qdata['question'], 'format' => FORMAT_HTML];
                        $form->generalfeedback          = ['text' => $qdata['explanation'] ?? '', 'format' => FORMAT_HTML];
                        $form->defaultmark              = 1;
                        $form->penalty                  = 0.1;
                        $form->single                   = 1;
                        $form->shuffleanswers           = 1;
                        $form->answernumbering          = 'abc';
                        $form->correctfeedback          = ['text' => '', 'format' => FORMAT_HTML];
                        $form->partiallycorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
                        $form->incorrectfeedback        = ['text' => '', 'format' => FORMAT_HTML];
                        $form->layout                   = 0;
                        $form->showstandardinstruction  = 1;
                        $form->shownumcorrect           = 1;

                        $form->answer   = [];
                        $form->fraction = [];
                        $form->feedback = [];

                        foreach ($qdata['options'] as $i => $optionText) {
                            $letter    = chr(65 + $i); // A,B,C...
                            $isCorrect = strtoupper($qdata['correct_answer']) === $letter;
                            $form->answer[]   = ['text' => $optionText, 'format' => FORMAT_HTML];
                            $form->fraction[] = $isCorrect ? 1 : 0;
                            $form->feedback[] = ['text' => $isCorrect ? 'Correct!' : 'Incorrect.', 'format' => FORMAT_HTML];
                        }

                        $qstub          = new stdClass();
                        $qstub->category = $catid;
                        $qstub->qtype   = 'multichoice';

                        try {
                            $qtype    = question_bank::get_qtype('multichoice');
                            $question = $qtype->save_question($qstub, $form);
                            quiz_add_quiz_question($question->id, $quiz);
                        } catch (Exception $e) {
                        }
                    }
                }

                $sectionnumber++;
                $SESSION->aicourse_data->topics   = $subtopiccontents;
                $SESSION->aicourse_data->quizjson = $quizcontents;
            }

            rebuild_course_cache($courseid);
            unset($SESSION->aicourse_data);
            redirect(new moodle_url('/course/view.php', ['id' => $courseid]), get_string('content_generated', 'local_aicourse'));
            exit;
        } elseif ($step < 3) {
            $nextstep = $step + 1;
            ob_end_clean();
            redirect(new moodle_url('/local/aicourse/manage.php', ['id' => $courseid, 'step' => $nextstep]));
            exit;
        }
    }
}

$step_states = [
    'is_step1_active' => $step >= 1,
    'is_step2_active' => $step >= 2,
    'is_step3_active' => $step >= 3,
    'is_step4_active' => $step >= 4,

    'is_connector1_active' => $step >= 2,
    'is_connector2_active' => $step >= 3,
    'is_connector3_active' => $step >= 4,

];

$formdata = [
    'action' => new moodle_url('/local/aicourse/manage.php', ['id' => $courseid, 'step' => $step]),
    'cancelurl' => new moodle_url('/course/view.php', ['id' => $courseid]),
    'courseid' => $courseid,
    'errors' => $errors,
    'TOPICTITLE' => $SESSION->aicourse_data->TOPICTITLE ?? '',
    'targetaudience' => $SESSION->aicourse_data->targetaudience ?? '',
    'audiencetags' => !empty($SESSION->aicourse_data->targetaudience) ? explode(',', $SESSION->aicourse_data->targetaudience) : [],
    'language' => isset($_POST['language']) ? clean_param($_POST['language'], PARAM_TEXT) : ($SESSION->aicourse_data->language ?? 'english'),
    'levelofunderstanding' => isset($_POST['levelofunderstanding']) ? clean_param($_POST['levelofunderstanding'], PARAM_TEXT) : ($SESSION->aicourse_data->levelofunderstanding ?? ''),
    'toneofnarrative' => isset($_POST['toneofnarrative']) ? clean_param($_POST['toneofnarrative'], PARAM_TEXT) : ($SESSION->aicourse_data->toneofnarrative ?? ''),
    'courseduration' => isset($_POST['courseduration']) ? clean_param($_POST['courseduration'], PARAM_TEXT) : ($SESSION->aicourse_data->courseduration ?? ''),

    'description' => $SESSION->aicourse_data->description ?? '',
    'generation_type' => $SESSION->aicourse_data->generation_type ?? 'ai',
    'pdfuploaded' => $SESSION->aicourse_data->pdf_file ?? '',
    'currentstep' => $step,
    'step_states' => $step_states,
    'has_levelofunderstanding' => !empty($SESSION->aicourse_data->levelofunderstanding),
    'has_toneofnarrative' => !empty($SESSION->aicourse_data->toneofnarrative),
    'has_courseduration' => !empty($SESSION->aicourse_data->courseduration),
    'is_level_beginner' => ($SESSION->aicourse_data->levelofunderstanding ?? '') === 'Beginner',
    'is_level_intermediate' => ($SESSION->aicourse_data->levelofunderstanding ?? '') === 'Intermediate',
    'is_level_advanced' => ($SESSION->aicourse_data->levelofunderstanding ?? '') === 'Advanced',
    'is_tone_formal' => ($SESSION->aicourse_data->toneofnarrative ?? '') === 'Formal',
    'is_tone_conversational' => ($SESSION->aicourse_data->toneofnarrative ?? '') === 'Conversational',
    'is_tone_engaging' => ($SESSION->aicourse_data->toneofnarrative ?? '') === 'Engaging',
    'is_duration_10minutes' => ($SESSION->aicourse_data->courseduration ?? '') === 'Less than 10 minutes',
    'is_duration_15minutes' => ($SESSION->aicourse_data->courseduration ?? '') === 'Less than 15 minutes',
    'is_duration_30minutes' => ($SESSION->aicourse_data->courseduration ?? '') === 'Less than 30 minutes',
    'courselanguage' => $SESSION->aicourse_data->courselanguage ?? 'English',
    'numberoftopics' => $SESSION->aicourse_data->numberoftopics ?? 5,
    'is_language_en' => ($SESSION->aicourse_data->courselanguage ?? '') === 'English',
    'is_language_hn' => ($SESSION->aicourse_data->courselanguage ?? '') === 'Hindi',
    'customprompt' => $SESSION->aicourse_data->customprompt ?? '',
    'contenteditor' => $textarea,


];

if ($step == 3) {
    $data = new stdClass();
    $data->coursename = $SESSION->aicourse_data->TOPICTITLE ?? '';
    $data->targetaudience = $SESSION->aicourse_data->targetaudience ?? '';
    $data->levelofunderstanding = $SESSION->aicourse_data->levelofunderstanding ?? 'Beginner';
    $data->toneofnarrative = $SESSION->aicourse_data->toneofnarrative ?? 'Conversational';
    $data->courseduration = $SESSION->aicourse_data->courseduration ?? 'Less than 30 minutes';
    $data->generation_type = $SESSION->aicourse_data->generation_type ?? 'ai';
    $data->pdf_fileid = $SESSION->aicourse_data->pdf_fileid ?? 0;
    $data->description = $SESSION->aicourse_data->description ?? '';
    $data->customprompt = $SESSION->aicourse_data->customprompt ?? '';
    $data->courselanguage = $SESSION->aicourse_data->courselanguage ?? 'English';
    $data->numberoftopics = $SESSION->aicourse_data->numberoftopics ?? 5;
    $data->pdf_reference_url = $SESSION->aicourse_data->pdf_reference_url ?? '';


    // Validate required fields
    $required = ['coursename', 'targetaudience', 'levelofunderstanding', 'toneofnarrative', 'courseduration', 'numberoftopics'];

    foreach ($required as $field) {
        if (empty($data->$field)) {
            $formdata['errors']['general'] = get_string('missingparam', 'local_aicourse', $field);
            $formdata['has_topics'] = false;
            $formdata['topics'] = [];
            break;
        }
    }
    // Validate allowed values
    $valid_levels = ['Beginner', 'Intermediate', 'Advanced'];
    $valid_tones = ['Conversational', 'Professional', 'Friendly', 'Technical', 'Formal', 'Engaging'];
    $valid_durations = ['Less than 15 minutes', 'Less than 30 minutes', 'Less than 60 minutes', 'Less than 90 minutes', 'Less than 120 minutes'];
    $valid_languages = ['English', 'Hindi'];
    if (!in_array($data->levelofunderstanding, $valid_levels)) {
        $formdata['errors']['general'] = get_string('invalidparam', 'local_aicourse', 'level of understanding');
    } elseif (!in_array($data->toneofnarrative, $valid_tones)) {
        $formdata['errors']['general'] = get_string('invalidparam', 'local_aicourse', 'tone of narrative');
    } elseif (!in_array($data->courseduration, $valid_durations)) {
        $formdata['errors']['general'] = get_string('invalidparam', 'local_aicourse', 'course duration');
    } elseif (!in_array($data->courselanguage, $valid_languages)) {
        $formdata['errors']['general'] = get_string('invalidparam', 'local_aicourse', 'language');
    } elseif ($data->numberoftopics < 2 || $data->numberoftopics > 10) {
        $formdata['errors']['general'] = get_string('invalidparam', 'local_aicourse', 'number of topics');
    }
    if (empty($formdata['errors'])) {
        try {
            // ✅ Single API call
            $result = local_aicourse_api::generate_subtopics_only($data);

            $subtopics = $result['subtopics'];
            $caseStudy = $result['case_study_data'] ?? null;
            $learningObjectives1 = $result['learning_objectives'] ?? [];

            // ✅ Store in session (AFTER actual data retrieved)
            $SESSION->aicourse_data->course_title = $data->coursename;
            $SESSION->aicourse_data->raw_subtopics = $subtopics;
            $SESSION->aicourse_data->courselanguage = $data->courselanguage;
            $SESSION->aicourse_data->numberoftopics = $data->numberoftopics;
            $SESSION->aicourse_data->learning_objectives1 = $learningObjectives1;
            // error_log('learning_objectives: ' . json_encode($learningObjectives1, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $SESSION->aicourse_data->case_study_data = is_array($caseStudy)
                ? $caseStudy
                : json_decode(json_encode($caseStudy), true); // ensure array format

            // ✅ Store it in local $data for later steps
            $data->case_study_data = $SESSION->aicourse_data->case_study_data;

            // ✅ Format topics
            $formdata['topics'] = array_map(function ($index, $subtopic) use ($subtopics) {
                $topicData = [
                    'id' => $subtopic['id'] ?? uniqid('topic_'),
                    'title' => $subtopic['title'] ?? 'Untitled',
                    'description' => $subtopic['description'] ?? '',
                    'estimated_duration' => $subtopic['estimated_duration'] ?? '',
                    'learning_objectives' => $subtopic['learning_objectives'] ?? [],
                ];

                return array_merge($topicData, [
                    'is_first' => $index === 0,
                    'is_last' => $index === count($subtopics) - 1,
                    '@index_plus_one' => $index + 1,
                    'encoded_topicdata' => rawurlencode(json_encode($topicData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                ]);
            }, array_keys($subtopics), $subtopics);

            $formdata['has_topics'] = true;
        } catch (moodle_exception $e) {
            // ✅ Directly show the custom message from api.php language strings
            $formdata['errors']['general'] = $e->getMessage();
            $formdata['has_topics'] = false;
            $formdata['topics'] = [];

            debugging('Subtopic generation failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        } catch (Exception $e) {
            // ✅ Catch any unexpected error
            $formdata['errors']['general'] = get_string('contentgenerationfailed', 'local_aicourse');
            $formdata['has_topics'] = false;
            $formdata['topics'] = [];

            debugging('Unexpected error during subtopic generation: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
} elseif ($step == 4) {


    $issavedraft = optional_param('savedraft', 0, PARAM_BOOL);

    if ($issavedraft) {
        // Redirect to savedraft handler
        redirect(new moodle_url('/local/aicourse/savedraft.php', [
            'id' => $courseid,
            'topicsjson' => $formdata['topicsjson'],
            'quizjson' => $formdata['quizjson'],
        ]));
        exit;
    }


    $formdata['topics'] = $SESSION->aicourse_data->topics ?? [];
    // error_log('Topics data: ' . print_r($formdata['topics'], true));
    // initialize maps
    $subtopicContentMap = [];
    $quizContentMap = [];
    $learningObjectivesMap = [];

    // iterate by index (avoid foreach by reference issues)
    $topics = $formdata['topics'] ?? [];

    for ($t = 0; $t < count($topics); $t++) {
        $topic = $topics[$t]; // copy, not reference
        $processedSubtopics = [];
        $topicObjectives = []; // reset for THIS topic only

        $existingSubs = $topic['subtopics'] ?? [];

        foreach ($existingSubs as $s => $sub) {
            $displayTitle = $sub['title'] ?? "Untitled Subtopic";

            if (!empty($sub['learning_objectives'])) {
                $objectives = array_map('strval', $sub['learning_objectives']);
                $sub['json_learning_objectives'] = json_encode($objectives, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $learningObjectivesMap[$displayTitle] = $objectives;
                $topicObjectives = array_merge($topicObjectives, $objectives);
            }

            $subtopicContentMap[$displayTitle] = $sub['content_html'] ?? '<p>No content available.</p>';
            $processedSubtopics[] = $sub;
        }

        // Create WYWL for THIS topic
        if (!empty($topicObjectives)) {
            $topicObjectives = array_values(array_unique($topicObjectives));
            $objectivesHtml = '<h4>Learning objectives</h4><ul><li>' .
                implode('</li><li>', $topicObjectives) .
                '</li></ul>';

            $wywlTitle = "Learning objectives - {$topic['title']}";
            $wywlSub = [
                'title' => $wywlTitle,
                'content_html' => $objectivesHtml,
                'json_learning_objectives' => json_encode($topicObjectives, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ];

            // Insert at first position
            array_unshift($processedSubtopics, $wywlSub);

            $subtopicContentMap[$wywlTitle] = $objectivesHtml;
        }

        // Assign processed subtopics back to THIS topic only
        $topic['subtopics'] = $processedSubtopics;
        $topics[$t] = $topic;
    }

    $formdata['topics'] = $topics;
    // Insert course-level WYWL as Topic 1
    $courseLevelObjectives = $SESSION->aicourse_data->learning_objectives1 ?? [];
    if (!empty($courseLevelObjectives)) {
        if (is_array($courseLevelObjectives)) {
            $courseLevelContent = '<ul>';
            foreach ($courseLevelObjectives as $obj) {
                $courseLevelContent .= '<li>' . htmlspecialchars($obj) . '</li>';
            }
            $courseLevelContent .= '</ul>';
        } else {
            $courseLevelContent = htmlspecialchars((string)$courseLevelObjectives);
        }

        $courseOverviewTopic = [
            'title' => 'About this course',
            'subtopics' => [
                [
                    'title' => 'Inside this course',
                    'content_html' => $courseLevelContent
                ]
            ]
        ];
        // Also add to subtopic content map for frontend rendering
        $subtopicContentMap['Inside this course'] = $courseLevelContent;

        array_unshift($formdata['topics'], $courseOverviewTopic);
    }




    // build quiz map (unchanged)
    foreach ($formdata['topics'] as $topic) {
        if (!empty($topic['quiz_data']['quiz_title']) && !empty($topic['quiz_data']['questions'])) {
            $quizTitle = $topic['quiz_data']['quiz_title'];
            $questions = [];
            foreach ($topic['quiz_data']['questions'] as $q) {
                $questions[] = [
                    'question' => $q['question'] ?? '',
                    'options'  => $q['options'] ?? [],
                    'answer'   => $q['correct_answer'] ?? '',
                    'explanation' => $q['explanation'] ?? ''
                ];
            }
            $quizContentMap[$quizTitle] = [
                'instructions' => $topic['quiz_data']['instructions'] ?? '',
                'questions' => $questions
            ];
        }
    }

    // final JSON to pass to the template
    $formdata['subtopicContentJson'] = json_encode($subtopicContentMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // error_log('subtopicContentJson: ' . $formdata['subtopicContentJson']);
    $formdata['quizContentJson'] = json_encode($quizContentMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $formdata['learningObjectiveJson'] = json_encode($learningObjectivesMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $formdata['topicsjson'] = json_encode($formdata['topics'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
//$formdata = [
// 'courseid' => $courseid,
// 'hasdraft' => $hasdraft,
// ... other data
//];
$formdata['courseid'] = $courseid;
$formdata['hasdraft'] = $hasdraft;

$formdata['TOPICTITLE_helpicon'] = [
    'text' => 'Enter the main title for your topic.',
    'component' => 'local_aicourse',
];

$formdata['targetaudience_helpicon'] = [
    'text' => 'Who this course is intended for (e.g., students, professionals).',
    'component' => 'local_aicourse',
];

$formdata['description_helpicon'] = [
    'text' => 'A brief summary of what this course covers.',
    'component' => 'local_aicourse',
];

$formdata['pdfupload_helpicon'] = [
    'text' => 'Upload a PDF file containing course material or content to extract topics from.',
    'component' => 'local_aicourse',
];

$formdata['levelofunderstanding_helpicon'] = [
    'text' => 'Select the learner’s proficiency level (e.g., Beginner, Intermediate, Advanced).',
    'component' => 'local_aicourse',
];

$formdata['toneofnarrative_helpicon'] = [
    'text' => 'Choose the tone you want the course to follow (e.g., Formal, Conversational, Engaging).',
    'component' => 'local_aicourse',
];

$formdata['courseduration_helpicon'] = [
    'text' => 'Specify how long the course should be (e.g., Less than 15, 30, or 60 minutes).',
    'component' => 'local_aicourse',
];


echo $OUTPUT->header();
if ($step == 1) {
    echo $OUTPUT->render_from_template('local_aicourse/ai_form', $formdata);
} elseif ($step == 2) {
    echo $OUTPUT->render_from_template('local_aicourse/step2_form', $formdata);
} elseif ($step == 3) {
    echo $OUTPUT->render_from_template('local_aicourse/step3_form', $formdata);
} elseif ($step == 4) {
    echo $OUTPUT->render_from_template('local_aicourse/step4_form', $formdata);
}
echo $OUTPUT->footer();

ob_end_flush();
