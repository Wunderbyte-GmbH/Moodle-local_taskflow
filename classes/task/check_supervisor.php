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
 * Unit class to manage users.
 *
 * @package local_taskflow
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\task;

use context_system;
use core_user\customfield\user_handler;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\plugininfo\taskflowadapter;


/**
 * Class send_taskflow_message
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_supervisor extends \core\task\adhoc_task {
    /**
     * Execute sending messags function
     * @return void
     */
    public function execute() {
        global $DB;
        $data = (object) $this->get_custom_data();
        $context = context_system::instance();
        $supervisorfieldshortname =
            external_api_base::return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_SUPERVISOR);
        $fieldid = $DB->get_record('user_info_field', ['shortname' => $supervisorfieldshortname]);
        $issupervisor = $DB->get_record('user_info_data', ['fieldid' => $fieldid, 'data' => $data->userid]);
        if (empty($issupervisor)) {
             role_unassign($data->roleid, $data->userid, $context->id);
        }
    }
}
