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
 * Upgrade steps for local_haccgen.
 *
 * @package     local_haccgen
 * @copyright   2026 Dynamicpixel Multimedia Solutions
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion The previous version number.
 * @return bool True on success.
 */
function xmldb_local_haccgen_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 20250712000) {
        $table = new xmldb_table('local_haccgen_timestamps');
        $table->setComment('Stores generation timestamps per draft or created course');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('record_type', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'draft');
        $table->add_field('contentid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('topic_generation_seconds', XMLDB_TYPE_NUMBER, '10,1', null, null, null, null);
        $table->add_field('topic_generated_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('content_generation_seconds', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('content_completed_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('course_fk', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('course_record_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'record_type']);
        $table->add_index('timecreated_idx', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 20250712000, 'local', 'haccgen');
    }

    if ($oldversion < 20250712001) {
        $table = new xmldb_table('local_haccgen_timestamps');
        $batchid = new xmldb_field('batchid', XMLDB_TYPE_CHAR, '40', null, null, null, null);
        $topicsummary = new xmldb_field('topicsummary', XMLDB_TYPE_TEXT, 'medium', null, null, null, null);
        if (!$dbman->field_exists($table, $batchid)) {
            $dbman->add_field($table, $batchid);
        }
        if (!$dbman->field_exists($table, $topicsummary)) {
            $dbman->add_field($table, $topicsummary);
        }
        upgrade_plugin_savepoint(true, 20250712001, 'local', 'haccgen');
    }

    return true;
}
