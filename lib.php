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


