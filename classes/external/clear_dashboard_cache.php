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

namespace local_taskflow\external;

use context_system;
use cache;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/externallib.php');

/**
 * Remove a specific user from the dashboardfilter cache.
 *
 * @package   local_taskflow
 * @category  external
 * @copyright 2025 Wunderbyte GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class clear_dashboard_cache extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'The user ID to remove from cache'),
        ]);
    }

    /**
     * Purge the cached record for the given user.
     *
     * @param int $userid
     * @return array result message
     */
    public static function execute(int $userid): array {
        global $CFG;

        // Security: user must be logged in and have system context.
        self::validate_context(context_system::instance());

        // Input validation.
        $params = self::validate_parameters(self::execute_parameters(), ['userid' => $userid]);
        $userid = $params['userid'];

        // Access the cache.
        $cache  = cache::make('local_taskflow', 'dashboardfilter');
        $filter = $cache->get('dashboardfilter') ?: [];

        if (isset($filter[$userid])) {
            unset($filter[$userid]);
            $cache->set('dashboardfilter', $filter);
            $status  = 'removed';
            $message = "User {$userid} removed from dashboardfilter cache.";
        } else {
            $status  = 'missing';
            $message = "User {$userid} not present in cache.";
        }

        return [
            'status'  => $status,
            'message' => $message,
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'status'  => new external_value(PARAM_ALPHA, 'removed | missing'),
            'message' => new external_value(PARAM_TEXT, 'Human-readable outcome message'),
        ]);
    }
}
