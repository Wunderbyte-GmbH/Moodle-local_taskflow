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
 * @package   local_taskflow
 * @author    Georg Maißer
 * @copyright 2023 Your Name
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local;
use context_system;

/**
 * Observer class that handles user events.
 */
class roles {
    /**
     * Make sure supervisor role exists
     *
     * @return void
     *
     */
    public function ensure_supervisor_role() {
        global $DB;

        // 1. Check if the role exists.
        $role = $DB->get_record('role', ['shortname' => 'supervisor']);

        if (!$role) {
            // Create the role.
            $roleid = create_role(
                get_string('supervisor', 'local_taskflow'), // Localized German name.
                'supervisor', // Unique shortname.
                get_string('supervisordescription', 'local_taskflow') // Description.
            );
        } else {
            $roleid = $role->id;
        }

        // 2. Ensure capabilities are assigned.
        $context = context_system::instance();

        $capabilities = [
        'local/taskflow:issupervisor',
        'local/taskflow:viewassignment',
        'local/taskflow:editassignment',
        ];

        foreach ($capabilities as $capability) {
            $existing = $DB->get_record('role_capabilities', [
                'contextid' => $context->id,
                'roleid' => $roleid,
                'capability' => $capability,
            ]);

            if (!$existing) {
                assign_capability($capability, CAP_ALLOW, $roleid, $context);
            }
        }
        // 3. OPTIONAL: Make it assignable at system context (by admins).
        if (
            !$DB->record_exists(
                'role_context_levels',
                [
                    'roleid' => $roleid,
                    'contextlevel' => $context->contextlevel,
                ]
            )
        ) {
            $record = (object)[
                'roleid' => $roleid,
                'contextlevel' => $context->contextlevel,
            ];
            $DB->insert_record('role_context_levels', $record);
        }
    }
}
