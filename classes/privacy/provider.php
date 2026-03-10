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
 * Short description of file purpose.
 *
 * @package     local_haccgen
 * @copyright   2026 Dynamicpixel Multimedia Solutions
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_haccgen\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\provider as metadataprovider;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\plugin\provider as pluginprovider;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;

/**
 * Privacy API implementation for local_haccgen.
 */
class provider implements metadataprovider, pluginprovider {

    /**
     * Describe stored personal data.
     */
    public static function get_metadata(collection $collection): collection {

        $collection->add_database_table(
            'local_haccgen_job',
            [
                'userid' => 'privacy:metadata:local_haccgen_job:userid',
                'courseid' => 'privacy:metadata:local_haccgen_job:courseid',
            ],
            'privacy:metadata:local_haccgen_job'
        );

        $collection->add_database_table(
            'local_haccgen_contentlog',
            [
                'userid' => 'privacy:metadata:local_haccgen_contentlog:userid',
                'courseid' => 'privacy:metadata:local_haccgen_contentlog:courseid',
            ],
            'privacy:metadata:local_haccgen_contentlog'
        );

        $collection->add_database_table(
            'local_haccgen_content',
            [
                'userid' => 'privacy:metadata:local_haccgen_content:userid',
                'courseid' => 'privacy:metadata:local_haccgen_content:courseid',
            ],
            'privacy:metadata:local_haccgen_content'
        );

        // External AI API usage.
        $collection->add_external_location_link(
            'ai_service',
            [
                'content' => 'privacy:metadata:external:content',
            ],
            'privacy:metadata:external',
        );

        return $collection;
    }

    /**
     * Get contexts containing user data.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {

        global $DB;

        $contextlist = new contextlist();

        $sql = "SELECT DISTINCT ctx.id
                  FROM {context} ctx
                  JOIN {local_haccgen_content} c
                       ON ctx.instanceid = c.courseid
                 WHERE ctx.contextlevel = :contextlevel
                   AND c.userid = :userid";

        $params = [
            'contextlevel' => CONTEXT_COURSE,
            'userid' => $userid,
        ];

        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }

    /**
     * Export user data.
     */
    public static function export_user_data(approved_contextlist $contextlist) {

        global $DB;

        if (!$contextlist->count()) {
            return;
        }

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist as $context) {

            $records = $DB->get_records('local_haccgen_content', [
                'userid' => $userid,
            ]);

            foreach ($records as $record) {

                writer::with_context($context)->export_data(
                    ['haccgen'],
                    (object)[
                        'batchid' => $record->batchid,
                        'status' => $record->status,
                        'timecreated' => $record->timecreated,
                        'timemodified' => $record->timemodified,
                    ]
                );
            }
        }
    }

    /**
     * Delete user data.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {

        global $DB;

        $userid = $contextlist->get_user()->id;

        $DB->delete_records('local_haccgen_job', ['userid' => $userid]);
        $DB->delete_records('local_haccgen_contentlog', ['userid' => $userid]);
        $DB->delete_records('local_haccgen_content', ['userid' => $userid]);
    }
}
