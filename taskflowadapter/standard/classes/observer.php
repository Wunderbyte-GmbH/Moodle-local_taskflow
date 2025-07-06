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
 * Observer for given events.
 *
 * @package   taskflowadapter_standard
 * @author    Georg Maißer
 * @copyright 2023 Your Name
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace taskflowadapter_standard;

/**
 * Observer class that handles user events.
 */
class observer {
    /**
     * Triggered when a user profile field is deleted.
     *
     * @param \core\event\user_info_field_deleted $event
     */
    public static function user_info_field_deleted(\core\event\user_info_field_deleted $event) {
        global $DB;

        // Get the ID of the deleted field.
        $fieldid = $event->objectid;

        // Get full record to access the shortname before it’s fully gone.
        if ($record = $event->get_record_snapshot('user_info_field', $fieldid)) {
            $shortname = $record->shortname;
            // Unset the configuration for the taskflowadapter_tuines.
            unset_config('taskflowadapter_standard', $shortname);
        }
    }
}
