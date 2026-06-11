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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/lib.php');

/**
 * Plugin component name.
 *
 * @package local_haccgen
 */
const LOCAL_HACCGEN_COMPONENT = 'local_haccgen';

/**
 * File area for uploaded files.
 *
 * @package local_haccgen
 */
const LOCAL_HACCGEN_FILEAREA = 'uploads';

/**
 * Extends the course settings navigation to include an AI course management link.
 *
 * @param navigation_node $settingsnav The navigation node to extend
 * @param context $context The context of the course
 * @package local_haccgen
 */
function local_haccgen_extend_settings_navigation($settingsnav, $context) {
    global $PAGE;

    if (
        $context->contextlevel === CONTEXT_COURSE &&
        has_capability('local/haccgen:manage', $context) &&
        !empty($PAGE->course->id)
    ) {

        $courseid = $PAGE->course->id;
        $url = new moodle_url('/local/haccgen/manage.php', ['id' => $courseid]);
        $title = get_string('manageai', 'local_haccgen');

        $coursenode = $settingsnav->find(
            'courseadmin',
            navigation_node::TYPE_COURSE,
        );

        if ($coursenode) {
            $coursenode->add(
                $title,
                $url,
                navigation_node::TYPE_SETTING,
                null,
                'haccgen_manage',
            );
            $timestampsurl = new moodle_url('/local/haccgen/timestamps.php', ['id' => $courseid]);
            $coursenode->add(
                get_string('generation_timestamps', 'local_haccgen'),
                $timestampsurl,
                navigation_node::TYPE_SETTING,
                null,
                'haccgen_timestamps',
            );
        } else {
            $settingsnav->add(
                $title,
                $url,
                navigation_node::TYPE_SETTING,
                null,
                'haccgen_manage',
            );
            $timestampsurl = new moodle_url('/local/haccgen/timestamps.php', ['id' => $courseid]);
            $settingsnav->add(
                get_string('generation_timestamps', 'local_haccgen'),
                $timestampsurl,
                navigation_node::TYPE_SETTING,
                null,
                'haccgen_timestamps',
            );
        }
    }
}

/**
 * Build a signed pluginfile URL for public access without login.
 *
 * @param stored_file $file
 * @param int|null $ttl
 * @param bool $forcedownload
 * @return string
 * @throws moodle_exception
 * @package local_haccgen
 */
function local_haccgen_build_signed_url(
    stored_file $file,
    ?int $ttl = null,
    bool $forcedownload = false
): string {
    $secret = (string)get_config(LOCAL_HACCGEN_COMPONENT, 'linksecret');
    if ($secret === '') {
        throw new moodle_exception(
            'linksecret_not_set',
            LOCAL_HACCGEN_COMPONENT,
        );
    }

    if ($ttl === null) {
        $ttl = (int)get_config(LOCAL_HACCGEN_COMPONENT, 'publiclinkttl');
    }
    if ($ttl <= 0) {
        $ttl = 3600;
    }

    $expires = time() + $ttl;

    $payload = implode('|', [
        $file->get_contextid(),
        LOCAL_HACCGEN_COMPONENT,
        LOCAL_HACCGEN_FILEAREA,
        $file->get_itemid(),
        $file->get_filepath(),
        $file->get_filename(),
        $expires,
    ]);

    $token = hash_hmac('sha256', $payload, $secret);

    $url = moodle_url::make_pluginfile_url(
        $file->get_contextid(),
        LOCAL_HACCGEN_COMPONENT,
        LOCAL_HACCGEN_FILEAREA,
        $file->get_itemid(),
        $file->get_filepath(),
        $file->get_filename(),
        $forcedownload,
    );

    $url->param('expires', $expires);
    $url->param('token', $token);

    return $url->out(false);
}

/**
 * Serve files for local_haccgen.
 *
 * @param stdClass $course
 * @param stdClass|null $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool
 * @package local_haccgen
 */
function local_haccgen_pluginfile(
    $course,
    $cm,
    $context,
    $filearea,
    $args,
    $forcedownload,
    array $options = []
) {
    if ($filearea !== LOCAL_HACCGEN_FILEAREA) {
        return false;
    }

    if (!in_array(
        $context->contextlevel,
        [CONTEXT_SYSTEM, CONTEXT_COURSE, CONTEXT_MODULE],
        true,
    )) {
        return false;
    }

    $itemid = (int)array_shift($args);
    $filename = array_pop($args);

    $filepath = '/';
    if (!empty($args)) {
        $filepath = '/' . implode('/', $args) . '/';
    }

    $fs = get_file_storage();
    $file = $fs->get_file(
        $context->id,
        LOCAL_HACCGEN_COMPONENT,
        LOCAL_HACCGEN_FILEAREA,
        $itemid,
        $filepath,
        $filename,
    );

    if (!$file || $file->is_directory()) {
        send_file_not_found();
    }

    $token = optional_param('token', '', PARAM_ALPHANUM);
    $expires = optional_param('expires', 0, PARAM_INT);
    $secret = (string)get_config(LOCAL_HACCGEN_COMPONENT, 'linksecret');

    $tokengood = false;

    if ($secret !== '' && $token && $expires && time() < $expires) {
        $payload = implode('|', [
            $context->id,
            LOCAL_HACCGEN_COMPONENT,
            LOCAL_HACCGEN_FILEAREA,
            $itemid,
            $filepath,
            $filename,
            $expires,
        ]);

        $expected = hash_hmac('sha256', $payload, $secret);

        if (hash_equals($expected, $token)) {
            $tokengood = true;
        }
    }

    if ($tokengood) {
        $lifetime = max(0, $expires - time());
        $options['cacheability'] = 'public';
        send_stored_file($file, $lifetime, 0, $forcedownload, $options);
    }

    if ($context->contextlevel === CONTEXT_COURSE) {
        require_course_login($course);
    } else {
        require_login();
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}
/**
 * Get session cache instance for local_haccgen.
 *
 * @return cache
 */
function local_haccgen_session_cache(): cache {
    return cache::make('local_haccgen', 'sessiondata');
}

/**
 * Build a cache key unique to this user + course.
 *
 * @param int $courseid
 * @return string
 */
function local_haccgen_cachekey(int $courseid): string {
    global $USER;
    return $USER->id . ':' . $courseid;
}

/**
 * Load session-like data.
 *
 * @param int $courseid
 * @return stdClass
 */
function local_haccgen_get_state(int $courseid): stdClass {
    $cache = local_haccgen_session_cache();
    $key = local_haccgen_cachekey($courseid);
    $data = $cache->get($key);

    if (!$data || !is_object($data)) {
        $data = new stdClass();
    }
    return $data;
}

/**
 * Save session-like data.
 *
 * @param int $courseid
 * @param stdClass $data
 * @return void
 */
function local_haccgen_set_state(int $courseid, stdClass $data): void {
    $cache = local_haccgen_session_cache();
    $key = local_haccgen_cachekey($courseid);
    $cache->set($key, $data);
}

/**
 * Clear session-like data.
 *
 * @param int $courseid
 * @return void
 */
function local_haccgen_clear_state(int $courseid): void {
    $cache = local_haccgen_session_cache();
    $key = local_haccgen_cachekey($courseid);
    $cache->delete($key);
}

/**
 * Record generation timestamps for a draft or created course.
 *
 * @param int $courseid Course ID.
 * @param int $userid User ID.
 * @param string $recordtype 'draft' or 'created'.
 * @param int|null $contentid Optional local_haccgen_content id.
 * @param stdClass|null $haccgendata Session data with topic_generation_duration_seconds, topic_generated_at,
 *                                   last_content_generation_duration_seconds, last_content_generation_completed_at.
 * @param string|null $batchid Optional batch/run id (matches local_haccgen_content.batchid).
 * @param string|null $topicsummary Optional JSON array of topic titles for this run.
 * @return int|false Insert id or false.
 */
function local_haccgen_record_timestamps(
    int $courseid,
    int $userid,
    string $recordtype,
    ?int $contentid = null,
    ?stdClass $haccgendata = null,
    ?string $batchid = null,
    ?string $topicsummary = null
) {
    global $DB;

    $row = new stdClass();
    $row->courseid = $courseid;
    $row->userid = $userid;
    $row->record_type = ($recordtype === 'created') ? 'created' : 'draft';
    $row->contentid = $contentid;
    $row->batchid = $batchid !== null && $batchid !== '' ? core_text::substr($batchid, 0, 40) : null;
    $row->topicsummary = $topicsummary;
    $row->topic_generation_seconds = null;
    $row->topic_generated_at = null;
    $row->content_generation_seconds = null;
    $row->content_completed_at = null;
    $row->timecreated = time();

    if ($haccgendata && is_object($haccgendata)) {
        if (isset($haccgendata->topic_generation_duration_seconds)) {
            $row->topic_generation_seconds = (float) $haccgendata->topic_generation_duration_seconds;
        }
        if (!empty($haccgendata->topic_generated_at)) {
            $row->topic_generated_at = (int) $haccgendata->topic_generated_at;
        }
        if (isset($haccgendata->last_content_generation_duration_seconds)) {
            $row->content_generation_seconds = (int) $haccgendata->last_content_generation_duration_seconds;
        }
        if (!empty($haccgendata->last_content_generation_completed_at)) {
            $row->content_completed_at = (int) $haccgendata->last_content_generation_completed_at;
        }
    }

    return $DB->insert_record('local_haccgen_timestamps', $row);
}

/**
 * Localised labels used when building the "About this course" block.
 *
 * @param string $lang Active course language label.
 * @return array{about:string,learning_objectives_heading:string,learning_objectives_prefix:string}
 */
function local_haccgen_i18n_labels(string $lang): array {
    $lang = trim($lang);
    $labels = [
        'about' => get_string('aboutthiscourse', 'local_haccgen'),
        'learning_objectives_heading' => 'Learning objectives',
        'learning_objectives_prefix' => 'Learning objectives - ',
    ];

    switch ($lang) {
        case 'हिन्दी (Hindi)':
            return [
                'about' => 'इस पाठ्यक्रम के बारे में',
                'learning_objectives_heading' => 'सीखने के उद्देश्य',
                'learning_objectives_prefix' => 'सीखने के उद्देश्य - ',
            ];
        case 'తెలుగు (Telugu)':
            return [
                'about' => 'ఈ కోర్సు గురించి',
                'learning_objectives_heading' => 'అభ్యాస లక్ష్యాలు',
                'learning_objectives_prefix' => 'అభ్యాస లక్ష్యాలు - ',
            ];
        case 'தமிழ் (Tamil)':
            return [
                'about' => 'இந்த பாடநெறி பற்றி',
                'learning_objectives_heading' => 'கற்றல் நோக்கங்கள்',
                'learning_objectives_prefix' => 'கற்றல் நோக்கங்கள் - ',
            ];
        case 'ಕನ್ನಡ (Kannada)':
            return [
                'about' => 'ಈ ಕೋರ್ಸ್ ಬಗ್ಗೆ',
                'learning_objectives_heading' => 'ಕಲಿಕೆಯ ಉದ್ದೇಶಗಳು',
                'learning_objectives_prefix' => 'ಕಲಿಕೆಯ ಉದ್ದೇಶಗಳು - ',
            ];
        case 'বাংলা (Bengali)':
            return [
                'about' => 'এই কোর্স সম্পর্কে',
                'learning_objectives_heading' => 'শেখার উদ্দেশ্য',
                'learning_objectives_prefix' => 'শেখার উদ্দেশ্য - ',
            ];
        default:
            return $labels;
    }
}

/**
 * Resolve course-level learning objectives from session data.
 *
 * @param stdClass $haccgendata Session payload.
 * @return string[]
 */
function local_haccgen_get_course_learning_objectives(stdClass $haccgendata): array {
    if (!empty($haccgendata->learning_objectives1)) {
        return array_values(array_filter(array_map('strval', (array) $haccgendata->learning_objectives1)));
    }

    $objectives = [];
    foreach ((array) ($haccgendata->raw_subtopics ?? []) as $topic) {
        if (!is_array($topic)) {
            continue;
        }
        foreach ((array) ($topic['learning_objectives'] ?? []) as $lo) {
            $lo = trim((string) $lo);
            if ($lo !== '') {
                $objectives[] = $lo;
            }
        }
    }

    return array_values(array_unique($objectives));
}

/**
 * Whether an outline topic from the API is the generated course summary.
 *
 * @param array $subtopic Raw subtopic from generate_subtopics.
 * @param int $index Zero-based position in the outline list.
 * @param string $coursesummary Session/form value (yes/no).
 * @param int $total Total topics in the outline list (0 when unknown).
 * @return bool
 */
function local_haccgen_is_course_summary_subtopic(
    array $subtopic,
    int $index,
    string $coursesummary,
    int $total = 0
): bool {
    if (!empty($subtopic['is_course_summary']) || !empty($subtopic['is_summary'])) {
        return true;
    }

    $type = strtolower(trim((string) ($subtopic['type'] ?? '')));
    if (in_array($type, ['course_summary', 'summary', 'course-summary'], true)) {
        return true;
    }

    if ($coursesummary !== 'yes') {
        return false;
    }

    // Title heuristic: course summary is expected as the last outline topic.
    if ($total > 0 && $index !== $total - 1) {
        return false;
    }

    $title = strtolower(trim((string) ($subtopic['title'] ?? '')));
    if ($title === 'summary' || $title === 'course overview' || $title === 'overview') {
        return true;
    }
    return (bool) preg_match(
        '/\b(course\s+summary|summary\s+of(\s+the)?\s+course|overall\s+summary|executive\s+summary|course\s+overview)\b/i',
        $title
    );
}

/**
 * Move an existing course-summary topic to the end of the outline list.
 *
 * @param array $subtopics Raw subtopics from generate_subtopics.
 * @param string $coursesummary Session/form value (yes/no).
 * @return array
 */
function local_haccgen_move_course_summary_to_end(array $subtopics, string $coursesummary): array {
    if ($coursesummary !== 'yes' || count($subtopics) < 2) {
        return $subtopics;
    }

    $total = count($subtopics);
    $summaryindex = null;
    foreach ($subtopics as $index => $subtopic) {
        if (!is_array($subtopic)) {
            continue;
        }
        if (local_haccgen_is_course_summary_subtopic($subtopic, $index, $coursesummary, $total)) {
            $summaryindex = $index;
            break;
        }
    }

    if ($summaryindex === null || $summaryindex === $total - 1) {
        return $subtopics;
    }

    $summary = $subtopics[$summaryindex];
    array_splice($subtopics, $summaryindex, 1);
    $subtopics[] = $summary;

    return $subtopics;
}

/**
 * Normalise the remote course_summary outline object for step 3.
 *
 * The subscription/content service returns course_summary as a sibling of subtopics
 * (see subscription_manager api.php count_words_from_course_summary).
 *
 * @param mixed $summary Raw course_summary from generate_subtopics response.
 * @return array|null Normalised outline topic or null when unusable.
 */
function local_haccgen_normalize_course_summary_outline_item($summary): ?array {
    if (!is_array($summary) || empty($summary)) {
        return null;
    }

    // Some services wrap the row in a one-element list.
    if (isset($summary[0]) && is_array($summary[0]) && !isset($summary['title'])) {
        $summary = $summary[0];
    }

    $title = trim((string) ($summary['title'] ?? ''));
    if ($title === '') {
        $title = 'Course Summary';
    }

    $objectives = $summary['learning_objectives'] ?? [];
    if (!is_array($objectives)) {
        $objectives = [];
    }

    return [
        'id' => $summary['id'] ?? uniqid('topic_'),
        'title' => $title,
        'description' => (string) ($summary['description'] ?? ''),
        'estimated_duration' => (string) ($summary['estimated_duration'] ?? 'Less than 15 minutes'),
        'learning_objectives' => array_values(array_filter(array_map('strval', $objectives))),
        'case_study_connection' => $summary['case_study_connection'] ?? null,
        'is_course_summary' => true,
        'is_summary' => true,
        'type' => (string) ($summary['type'] ?? 'course_summary'),
    ];
}

/**
 * Merge top-level course_summary from generate_subtopics into the subtopics list.
 *
 * @param array $response Full generate_subtopics API response.
 * @param string $coursesummary Session/form value (yes/no).
 * @return array Outline subtopics including summary when provided.
 */
function local_haccgen_merge_api_course_summary_into_subtopics(array $response, string $coursesummary): array {
    $subtopics = is_array($response['subtopics'] ?? null) ? $response['subtopics'] : [];

    if ($coursesummary !== 'yes' || empty($response['course_summary'])) {
        return $subtopics;
    }

    $summaryitem = local_haccgen_normalize_course_summary_outline_item($response['course_summary']);
    if ($summaryitem === null) {
        return $subtopics;
    }

    $total = count($subtopics);
    foreach ($subtopics as $index => $subtopic) {
        if (!is_array($subtopic)) {
            continue;
        }
        if (local_haccgen_is_course_summary_subtopic($subtopic, $index, 'yes', $total)) {
            return local_haccgen_move_course_summary_to_end($subtopics, 'yes');
        }
    }

    $subtopics[] = $summaryitem;
    return $subtopics;
}

/**
 * Ensure a course-summary outline row exists when the user requested one.
 *
 * If the remote API omitted a summary topic, append a placeholder row so step 3
 * and content generation still include it.
 *
 * @param array $subtopics Raw subtopics from generate_subtopics.
 * @param string $coursesummary Session/form value (yes/no).
 * @return array
 */
function local_haccgen_ensure_course_summary_outline_topic(array $subtopics, string $coursesummary): array {
    if ($coursesummary !== 'yes' || empty($subtopics)) {
        return $subtopics;
    }

    $total = count($subtopics);
    foreach ($subtopics as $index => $subtopic) {
        if (!is_array($subtopic)) {
            continue;
        }
        if (local_haccgen_is_course_summary_subtopic($subtopic, $index, $coursesummary, $total)) {
            return local_haccgen_move_course_summary_to_end($subtopics, $coursesummary);
        }
    }

    $subtopics[] = [
        'id' => uniqid('topic_'),
        'title' => 'Course Summary',
        'description' => 'A high-level summary of the entire course.',
        'estimated_duration' => 'Less than 15 minutes',
        'learning_objectives' => [],
        'is_course_summary' => true,
        'type' => 'course_summary',
    ];

    return $subtopics;
}

/**
 * Resolve whether a step-3 outline topic should include a quiz.
 *
 * @param array $subtopic Raw subtopic from generate_subtopics.
 * @param bool $iscoursesummary Whether this row is the course summary topic.
 * @return bool
 */
function local_haccgen_resolve_topic_has_quiz(array $subtopic, bool $iscoursesummary): bool {
    if ($iscoursesummary) {
        return false;
    }

    // Step 3: quiz is opt-in via the edit modal. Do not inherit include_quiz,
    // has_quiz, or quiz_count from the generate_subtopics API response.
    return false;
}

/**
 * Format one step-3 outline topic for the topic editor UI.
 *
 * @param array $subtopic Raw subtopic from generate_subtopics.
 * @param int $index Zero-based position in the outline list.
 * @param int $total Total topics in the outline list.
 * @param string $coursesummary Session/form value (yes/no).
 * @return array
 */
function local_haccgen_format_step3_outline_topic(
    array $subtopic,
    int $index,
    int $total,
    string $coursesummary = 'no'
): array {
    $iscoursesummary = local_haccgen_is_course_summary_subtopic($subtopic, $index, $coursesummary, $total);
    $hasquiz = local_haccgen_resolve_topic_has_quiz($subtopic, $iscoursesummary);

    $topicdata = [
        'id' => $subtopic['id'] ?? uniqid('topic_'),
        'title' => $subtopic['title'] ?? 'Untitled',
        'description' => $subtopic['description'] ?? '',
        'estimated_duration' => $subtopic['estimated_duration'] ?? '',
        'learning_objectives' => $subtopic['learning_objectives'] ?? [],
        'has_quiz' => $hasquiz,
        'quiz_question_count' => $hasquiz
            ? (int) ($subtopic['quiz_question_count'] ?? $subtopic['quiz_count'] ?? 1)
            : 0,
        'is_course_summary' => $iscoursesummary,
    ];

    return array_merge($topicdata, [
        'is_first' => $index === 0,
        'is_last' => $index === $total - 1,
        '@index_plus_one' => $index + 1,
        'encoded_topicdata' => rawurlencode(json_encode(
            $topicdata,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        )),
    ]);
}

/**
 * Build Mustache context for the preview_course template.
 *
 * @param stdClass $haccgendata Session payload with course metadata.
 * @param array $extras Additional template fields.
 * @return array
 */
function local_haccgen_preview_course_context(stdClass $haccgendata, array $extras = []): array {
    $lang = $haccgendata->activelang ?? $haccgendata->courselanguage ?? 'English';
    $labels = local_haccgen_i18n_labels($lang);
    $coursesummary = $haccgendata->coursesummary ?? 'no';

    $audiencetags = [];
    if (!empty($haccgendata->targetaudience)) {
        $audiencetags = array_values(array_filter(array_map('trim', explode(',', (string) $haccgendata->targetaudience))));
    }

    $context = [
        'TOPICTITLE' => $haccgendata->TOPICTITLE ?? '',
        'targetaudience' => $haccgendata->targetaudience ?? '',
        'levelofunderstanding' => $haccgendata->levelofunderstanding ?? '',
        'toneofnarrative' => $haccgendata->toneofnarrative ?? '',
        'courseduration' => $haccgendata->courseduration ?? '',
        'coursesummary' => $coursesummary,
        'is_summary_yes' => $coursesummary === 'yes',
        'is_summary_no' => $coursesummary === 'no',
        'pdfuploaded' => $haccgendata->pdf_file ?? '',
        'audiencetags' => $audiencetags,
        'learning_objectives_heading' => $labels['learning_objectives_heading'] ?? 'Learning objectives',
    ];

    return array_merge($context, $extras);
}

/**
 * Whether topics already contain an "About this course" entry.
 *
 * @param array $topics Structured topics list.
 * @param string $abouttitle Localised about-topic title.
 * @return bool
 */
function local_haccgen_topics_have_about(array $topics, string $abouttitle): bool {
    foreach ($topics as $topic) {
        if (trim((string) ($topic['title'] ?? '')) === $abouttitle) {
            return true;
        }
    }
    return false;
}

/**
 * Prepend the "About this course" topic when missing.
 *
 * @param array $topics Structured topics list.
 * @param stdClass $haccgendata Session payload with course metadata.
 * @param object $output Moodle output renderer ($OUTPUT).
 * @return array Updated topics list.
 */
function local_haccgen_prepend_about_course_topic(array $topics, stdClass $haccgendata, object $output): array {
    $lang = $haccgendata->activelang ?? $haccgendata->courselanguage ?? 'English';
    $labels = local_haccgen_i18n_labels($lang);
    $abouttitle = $labels['about'] ?? get_string('aboutthiscourse', 'local_haccgen');

    if (local_haccgen_topics_have_about($topics, $abouttitle)) {
        return $topics;
    }

    $courselevelobjectives = local_haccgen_get_course_learning_objectives($haccgendata);
    if (!empty($courselevelobjectives)) {
        $courselevelcontent = '<ul>';
        foreach ($courselevelobjectives as $obj) {
            $courselevelcontent .= '<li>' . s($obj) . '</li>';
        }
        $courselevelcontent .= '</ul>';
    } else {
        $courselevelcontent = '';
    }

    $previewcontext = local_haccgen_preview_course_context($haccgendata, [
        'objectives_html' => $courselevelcontent,
    ]);

    $previewhtml = $output->render_from_template('local_haccgen/preview_course', $previewcontext);

    $courseoverviewtopic = [
        'title' => $abouttitle,
        'subtopics' => [
            [
                'title' => $abouttitle,
                'content_html' => $previewhtml,
                'content' => ['text' => $previewhtml, 'itemid' => 0],
                'examples' => [],
            ],
        ],
    ];

    array_unshift($topics, $courseoverviewtopic);
    return $topics;
}

/**
 * Format a Unix save timestamp using the LMS site timezone.
 *
 * Uses the timezone configured in Site administration → Location,
 * not the viewing user's personal profile timezone.
 *
 * @param int $timestamp Unix epoch seconds.
 * @return string Formatted date/time or empty string if invalid.
 */
function local_haccgen_format_saved_time(int $timestamp): string {
    if ($timestamp <= 0) {
        return '';
    }

    return userdate(
        $timestamp,
        get_string('strftimedatetime', 'langconfig'),
        \core_date::get_server_timezone()
    );
}


