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
 * Recursively extract examples from content sections.
 * This is CRITICAL for preserving examples.
 *
 * @param array $contentsections The content sections to extract examples from.
 * @return array Array of extracted examples.
 */
function local_haccgen_extract_examples($contentsections): array {
    $examples = [];
    if (!is_array($contentsections)) {
        return $examples;
    }

    foreach ($contentsections as $section) {
        // Direct examples in the section.
        if (!empty($section['examples']) && is_array($section['examples'])) {
            $examples = array_merge($examples, $section['examples']);
        }

        // Check for examples in nested content.
        if (!empty($section['content']) && is_array($section['content'])) {
            if (!empty($section['content']['examples'])) {
                $examples = array_merge($examples, $section['content']['examples']);
            }
        }

        // Check for examples in generated_content.
        if (!empty($section['generated_content']) && is_array($section['generated_content'])) {
            if (!empty($section['generated_content']['examples'])) {
                $examples = array_merge($examples, $section['generated_content']['examples']);
            }
            if (!empty($section['generated_content']['content_sections'])) {
                $nestedexamples = local_haccgen_extract_examples($section['generated_content']['content_sections']);
                $examples = array_merge($examples, $nestedexamples);
            }
        }
    }

    return array_unique($examples);
}

/**
 * Normalise service response into a list of topics, preserving all data including examples.
 *
 * @param mixed $result Decoded job result.
 * @param string $fallbacktitle Fallback title when wrapping subtopics.
 * @return array Complete topics with preserved structure.
 */
function local_haccgen_pick_topics_preserve_all($result, string $fallbacktitle = 'Content'): array {
    // If the result already has the complete topics structure, return it as-is.
    if (is_array($result) && isset($result['topics']) && is_array($result['topics'])) {
        return local_haccgen_normalize_topics($result['topics']);
    }

    if (is_array($result) && isset($result['data']['topics']) && is_array($result['data']['topics'])) {
        return local_haccgen_normalize_topics($result['data']['topics']);
    }

    // Handle the structure from generate_content_for_topics.
    if (is_array($result) && isset($result['results']) && is_array($result['results'])) {
        $topics = [];
        foreach ($result['results'] as $item) {
            if (!empty($item['generated_content'])) {
                $topic = [
                    'title' => $item['generated_content']['topic_title'] ?? $fallbacktitle,
                    'description' => $item['generated_content']['topic_description'] ?? '',
                    'subtopics' => [],
                    'quiz_data' => $item['quiz_data'] ?? null,
                ];

                // Extract content sections with examples.
                if (!empty($item['generated_content']['content_sections'])) {
                    foreach ($item['generated_content']['content_sections'] as $section) {
                        $subtopic = [
                            'title' => $section['section_title'] ?? 'Untitled Section',
                            'content' => $section['content'] ?? '',
                            'content_html' => $section['content'] ?? '',
                            'examples' => $section['examples'] ?? [], // CRITICAL: Preserve examples.
                        ];

                        // Also preserve any additional metadata.
                        if (!empty($section['learning_objectives'])) {
                            $subtopic['learning_objectives'] = $section['learning_objectives'];
                        }

                        $topic['subtopics'][] = $subtopic;
                    }
                }

                $topics[] = $topic;
            }
        }
        return $topics;
    }

    // Handle direct subtopics structure (from API).
    if (is_array($result) && isset($result['subtopics']) && is_array($result['subtopics'])) {
        $topics = [];
        foreach ($result['subtopics'] as $subtopic) {
            $topic = [
                'title' => $subtopic['title'] ?? $fallbacktitle,
                'subtopics' => [],
            ];

            // If this subtopic has its own content sections.
            if (!empty($subtopic['content_sections'])) {
                foreach ($subtopic['content_sections'] as $section) {
                    $topic['subtopics'][] = [
                        'title' => $section['section_title'] ?? 'Untitled',
                        'content' => $section['content'] ?? '',
                        'content_html' => $section['content'] ?? '',
                        'examples' => $section['examples'] ?? [], // Preserve examples.
                    ];
                }
            } else {
                // Simple subtopic.
                $topic['subtopics'][] = [
                    'title' => $subtopic['title'] ?? 'Untitled',
                    'content' => $subtopic['content'] ?? ($subtopic['description'] ?? ''),
                    'content_html' => $subtopic['content'] ?? ($subtopic['description'] ?? ''),
                    'examples' => $subtopic['examples'] ?? [], // Preserve examples.
                ];
            }

            $topics[] = $topic;
        }
        return $topics;
    }

    // Last resort: try to extract from raw array structure.
    if (is_array($result) && local_haccgen_is_list($result)) {
        return local_haccgen_normalize_topics($result);
    }

    return [];
}

/**
 * Normalize topics to ensure all fields are present, especially examples.
 *
 * @param array $topics The topics to normalize.
 * @return array Normalized topics.
 */
function local_haccgen_normalize_topics(array $topics): array {
    $normalized = [];
    foreach ($topics as $topic) {
        $normaltopic = [
            'title' => $topic['title'] ?? 'Untitled Topic',
            'description' => $topic['description'] ?? '',
            'subtopics' => [],
            'quiz_data' => $topic['quiz_data'] ?? ($topic['quiz'] ?? null),
        ];

        // Process subtopics.
        if (!empty($topic['subtopics']) && is_array($topic['subtopics'])) {
            foreach ($topic['subtopics'] as $subtopic) {
                $normalsubtopic = [
                    'title' => $subtopic['title'] ?? 'Untitled Subtopic',
                    'content' => $subtopic['content'] ?? ($subtopic['content_html'] ?? ''),
                    'content_html' => $subtopic['content_html'] ?? ($subtopic['content'] ?? ''),
                    'examples' => $subtopic['examples'] ?? [], // CRITICAL: Keep examples.
                ];

                // Preserve learning objectives if present.
                if (!empty($subtopic['learning_objectives'])) {
                    $normalsubtopic['learning_objectives'] = $subtopic['learning_objectives'];
                }

                // Preserve any JSON-encoded learning objectives.
                if (!empty($subtopic['json_learning_objectives'])) {
                    $normalsubtopic['json_learning_objectives'] = $subtopic['json_learning_objectives'];
                }

                $normaltopic['subtopics'][] = $normalsubtopic;
            }
        }

        // Also check for content_sections structure (alternative format).
        if (!empty($topic['content_sections']) && empty($normaltopic['subtopics'])) {
            foreach ($topic['content_sections'] as $section) {
                $normaltopic['subtopics'][] = [
                    'title' => $section['section_title'] ?? 'Untitled Section',
                    'content' => $section['content'] ?? '',
                    'content_html' => $section['content'] ?? '',
                    'examples' => $section['examples'] ?? [], // Preserve examples.
                ];
            }
        }

        $normalized[] = $normaltopic;
    }

    return $normalized;
}

require_once($CFG->libdir . '/filelib.php');

// Single log file for all debugging.
$logdir = $CFG->dataroot . '/local_haccgen';
if (!is_dir($logdir)) {
    @mkdir($logdir, 0770, true);
}
$logfile = $logdir . '/consume_job_debug.log';

/**
 * Helper function to write to single log file.
 *
 * @param string $message The message to log.
 * @param string $logfile The log file path.
 * @param int|null $jobid The job ID for context.
 */
function write_log($message, $logfile, $jobid = null) {
    $prefix = $jobid ? "[Job {$jobid}]" : "";
    $line = "[" . date('c') . "] {$prefix} {$message}\n";
    @file_put_contents($logfile, $line, FILE_APPEND | LOCK_EX);
}

write_log("========== CONSUME_JOB STARTED ==========", $logfile, $jobid);
write_log("Job ID: {$jobid}, Type: {$job->type}, Course: {$job->courseid}, User: {$USER->id}", $logfile, $jobid);

// Load session-scoped data (MUC).
$haccgendata = session_store::get('haccgen_data');
if (!$haccgendata || !is_object($haccgendata)) {
    $haccgendata = new stdClass();
    write_log("Created new session data object", $logfile, $jobid);
} else {
    write_log("Loaded existing session data", $logfile, $jobid);
}

if ($job->type === 'topiccontent') {
    write_log("Processing topiccontent job", $logfile, $jobid);

    $title = $haccgendata->TOPICTITLE ?? 'Content';

    // Use the new preserve-all function.
    $topics = local_haccgen_pick_topics_preserve_all($result, $title);

    write_log("Picked " . count($topics) . " topics", $logfile, $jobid);

    // Log examples count for debugging.
    $totalexamples = 0;
    foreach ($topics as $topicindex => $topic) {
        $examplesintopic = 0;
        foreach ($topic['subtopics'] ?? [] as $subtopic) {
            if (!empty($subtopic['examples']) && is_array($subtopic['examples'])) {
                $examplesintopic += count($subtopic['examples']);
            }
        }
        write_log("Topic {$topicindex} '{$topic['title']}' has {$examplesintopic} examples", $logfile, $jobid);
        $totalexamples += $examplesintopic;
    }
    write_log("Total examples across all topics: {$totalexamples}", $logfile, $jobid);

    global $OUTPUT;
    $topics = local_haccgen_prepend_about_course_topic($topics, $haccgendata, $OUTPUT);

    $haccgendata->topics = $topics;

    // Also store raw result for debugging.
    $haccgendata->last_raw_result = $result;

    // Add flags for fresh generation.
    $haccgendata->is_fresh_generation = true;
    $labels = local_haccgen_i18n_labels($haccgendata->activelang ?? $haccgendata->courselanguage ?? 'English');
    $haccgendata->about_course_added = local_haccgen_topics_have_about($topics, $labels['about']);
    write_log("Set is_fresh_generation = true, about_course_added = " .
        ($haccgendata->about_course_added ? 'true' : 'false'), $logfile, $jobid);
    // End of flags.

    // Store canonical payload for auto-save.
    if (!empty($result)) {
        $canonicalpayload = $result;

        if (!isset($canonicalpayload['topics']) && !empty($topics)) {
            $canonicalpayload = [
                'topics' => $topics,
                'meta' => [
                    'TOPICTITLE' => $haccgendata->TOPICTITLE ?? '',
                    'targetaudience' => $haccgendata->targetaudience ?? '',
                    'description' => $haccgendata->description ?? '',
                    'levelofunderstanding' => $haccgendata->levelofunderstanding ?? '',
                    'toneofnarrative' => $haccgendata->toneofnarrative ?? '',
                    'courseduration' => $haccgendata->courseduration ?? '',
                    'courselanguage' => $haccgendata->courselanguage ?? '',
                    'numberoftopics' => $haccgendata->numberoftopics ?? '',
                    'activelang' => $haccgendata->activelang ?? '',
                    'coursesummary' => $haccgendata->coursesummary ?? 'no',
                ],
            ];
        }

        $haccgendata->canonical_payload = $canonicalpayload;
        $haccgendata->canonical_payload_json = json_encode($canonicalpayload, JSON_UNESCAPED_UNICODE);

        write_log("Stored canonical payload (" . strlen($haccgendata->canonical_payload_json) . " bytes)",
            $logfile, $jobid);
    }
    // End of store canonical payload.

    // Auto-save to database (always create new record).
    write_log("Starting auto-save process...", $logfile, $jobid);

    if (!empty($topics)) {
        try {
            // Extract quiz data from topics.
            $quizdata = [];
            foreach ($topics as $topic) {
                if (!empty($topic['quiz_data'])) {
                    $quiztitle = $topic['quiz_data']['quiz_title'] ?? $topic['title'] ?? '';
                    $quizdata[$quiztitle] = $topic['quiz_data'];
                }
            }

            write_log("Extracted " . count($quizdata) . " quizzes", $logfile, $jobid);

            // Log the structure of first topic to verify examples are included.
            if (!empty($topics[0]['subtopics'][0])) {
                $samplesubtopic = $topics[0]['subtopics'][0];
                write_log("Sample subtopic structure: " . json_encode([
                    'has_title' => isset($samplesubtopic['title']),
                    'has_content' => isset($samplesubtopic['content']),
                    'has_content_html' => isset($samplesubtopic['content_html']),
                    'has_examples' => isset($samplesubtopic['examples']),
                    'examples_count' => count($samplesubtopic['examples'] ?? []),
                    'examples_sample' => array_slice($samplesubtopic['examples'] ?? [], 0, 2),
                ], JSON_UNESCAPED_UNICODE), $logfile, $jobid);
            }

            // Always create a new record - don't check for existing.
            $record = new stdClass();
            $record->courseid = $job->courseid;
            $record->userid = $USER->id;
            $record->batchid = 'autosave_' . date('Ymd_His') . '_' . $job->courseid;
            $record->status = 'autosaved';
            $record->topicsjson = json_encode($topics, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $record->quizjson = json_encode($quizdata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $record->timecreated = time();
            $record->timemodified = time();

            // Verify JSON encoding worked.
            if (json_last_error() !== JSON_ERROR_NONE) {
                write_log("ERROR encoding topicsjson: " . json_last_error_msg(), $logfile, $jobid);
                throw new Exception("JSON encoding failed: " . json_last_error_msg());
            }

            $recordid = $DB->insert_record('local_haccgen_content', $record);

            write_log("✓ AUTO-SAVE CREATED! Record ID: {$recordid}, Topics: " . count($topics),
                $logfile, $jobid);

            // Verify the saved data contains examples by retrieving and checking.
            $savedrecord = $DB->get_record('local_haccgen_content', ['id' => $recordid]);
            if ($savedrecord) {
                $savedtopics = json_decode($savedrecord->topicsjson, true);
                $savedexamples = 0;
                foreach ($savedtopics ?? [] as $t) {
                    foreach ($t['subtopics'] ?? [] as $s) {
                        $savedexamples += count($s['examples'] ?? []);
                    }
                }
                write_log("VERIFICATION: Saved data has {$savedexamples} examples", $logfile, $jobid);
            }

            // Optional: Delete old auto-saves keeping only last 5.
            $oldautosaves = $DB->get_records('local_haccgen_content', [
                'courseid' => $job->courseid,
                'userid' => $USER->id,
                'status' => 'autosaved',
            ], 'timecreated DESC', 'id', 5, 100); // Skip first 5, get the rest.

            foreach ($oldautosaves as $old) {
                $DB->delete_records('local_haccgen_content', ['id' => $old->id]);
                write_log("Deleted old auto-save record ID: {$old->id} (keeping only last 5)",
                    $logfile, $jobid);
            }

            // Count total records for this course/user.
            $totalrecords = $DB->count_records('local_haccgen_content', [
                'courseid' => $job->courseid,
                'userid' => $USER->id,
                'status' => 'autosaved',
            ]);
            write_log("Total auto-save records after cleanup: {$totalrecords}", $logfile, $jobid);

            // Set session flag.
            $autosavekey = 'haccgen_autosaved_step4_' . $job->courseid;
            $_SESSION[$autosavekey] = true;

            // Store latest auto-save info in session.
            $haccgendata->last_autosave_id = $recordid;
            $haccgendata->last_autosave_batchid = $record->batchid;
            $haccgendata->last_autosave_time = time();
            $haccgendata->last_autosave_examples_count = $totalexamples;

        } catch (Exception $e) {
            write_log("✗ AUTO-SAVE ERROR: " . $e->getMessage(), $logfile, $jobid);
            write_log("Error trace: " . $e->getTraceAsString(), $logfile, $jobid);
        }
    } else {
        write_log("✗ AUTO-SAVE SKIPPED: No topics data", $logfile, $jobid);
    }
    // End of auto-save.

    // Store content generation timing for display on step 4.
    $haccgendata->last_content_generation_duration_seconds = max(0,
        (int) $job->timemodified - (int) $job->timecreated);
    $haccgendata->last_content_generation_completed_at = (int) $job->timemodified;

    write_log("Generation duration: {$haccgendata->last_content_generation_duration_seconds} seconds",
        $logfile, $jobid);

    session_store::delete('haccgen_last_loaded_batchid');
    session_store::set('haccgen_data', $haccgendata);
    write_log("Session data saved", $logfile, $jobid);

    write_log("Redirecting to step 4", $logfile, $jobid);
    write_log("========== CONSUME_JOB COMPLETED ==========", $logfile, $jobid);

    // In consume_job.php, redirect to step 4 with auto-save ID.
    redirect(new moodle_url('/local/haccgen/manage.php', [
        'id' => $job->courseid,
        'step' => 4,
        'generated' => 1,
        'autosave_id' => $recordid ?? 0,  // Pass the auto-save ID.
    ]));
}

if ($job->type === 'subtopics') {
    write_log("Processing subtopics job", $logfile, $jobid);

    $haccgendata->raw_subtopics = $result['subtopics'] ?? [];
    session_store::set('haccgen_data', $haccgendata);

    write_log("Redirecting to step 3", $logfile, $jobid);
    write_log("========== CONSUME_JOB COMPLETED ==========", $logfile, $jobid);

    redirect(new moodle_url('/local/haccgen/manage.php', [
        'id' => $job->courseid,
        'step' => 3,
    ]));
}

throw new moodle_exception('unknownjobtype', 'local_haccgen');

