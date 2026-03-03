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
 * External service for checking background job status.
 *
 * @package     local_haccgen
 * @category    external
 * @copyright   2026 Dynamicpixel Multimedia Solutions
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_haccgen\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;

/**
 * External API endpoint to poll job progress/status.
 */
class check_job extends external_api {

    /**
     * Define the parameters required by the execute() method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'jobid' => new external_value(PARAM_INT, 'Job id'),
        ]);
    }

    /**
     * Execute the job status check.
     *
     * @param int $jobid Job ID.
     * @return array Job status data.
     * @throws \required_capability_exception If user lacks permissions.
     */
    public static function execute(int $jobid): array {
        global $DB, $USER;

        $params = self::validate_parameters(
            self::execute_parameters(),
            ['jobid' => $jobid]
        );

        // Job may be missing (JS expects "missing").
        $job = $DB->get_record('local_haccgen_job', ['id' => $params['jobid']]);
        if (!$job) {
            return [
                'status' => 'missing',
                'errormsg' => '',
                'progress' => 0,
            ];
        }

        // Context + capability checks.
        $context = \context_course::instance($job->courseid);
        self::validate_context($context);
        require_capability('local/haccgen:manage', $context);

        // Owner or admin only.
        if ((int) $job->userid !== (int) $USER->id && !is_siteadmin()) {
            throw new \required_capability_exception(
                $context,
                'local/haccgen:manage',
                'nopermissions',
                ''
            );
        }

        // Map DB status to client-facing values.
        $rawstatus = (string) ($job->status ?? '');
        $status = 'running';

        if ($rawstatus === 'success') {
            $status = 'done';
        } else if ($rawstatus === 'failed' || $rawstatus === 'error') {
            $status = 'failed';
        } else if (
            $rawstatus === 'queued' ||
            $rawstatus === 'processing' ||
            $rawstatus === 'running'
        ) {
            $status = 'running';
        }

        return [
            'status' => $status,
            'errormsg' => (string) ($job->message ?? ''),
            'progress' => (int) ($job->progress ?? 0),
        ];
    }

    /**
     * Describe the structure of the data returned by execute().
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(
                PARAM_ALPHANUMEXT,
                'done|failed|missing|running'
            ),
            'errormsg' => new external_value(
                PARAM_RAW,
                'Error message when failed',
                VALUE_DEFAULT,
                ''
            ),
            'progress' => new external_value(
                PARAM_INT,
                'Progress percentage',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }
}
