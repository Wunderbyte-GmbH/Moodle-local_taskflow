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
use core\output\html_writer;
use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use local_shopping_cart\form\dynamic_select_users;
use local_taskflow\output\assignmentsdashboard;
use local_taskflow\output\dashboard;
use local_taskflow\shortcodes;
use stdClass;
use cache;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/externallib.php');

/**
 * Submit data to the server.
 * @package local_taskflow
 * @category external
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright 2025 Wunderbyte GmbH
 */
class load_dashboard extends external_api {
    /**
     * Define the parameters for the function.
     *
     * @return [type]
     *
     */
    public static function execute_parameters() {
        return new external_function_parameters([]);
    }

    /**
     * Execute the function.
     *
     * @param mixed $uniqueid
     * @param mixed $call
     *
     * @return [type]
     *
     */
    public static function execute(array $params = []) {
        global $DB, $PAGE, $OUTPUT;

        $arguments = [];
        $renderinstance = new dashboard(0, $arguments);
        $renderinstance->set_data();
        $jsfooter = '';

        $renderer = $PAGE->get_renderer('local_taskflow');
        $data = $renderinstance->export_for_template($renderer);

        $OUTPUT->header();
        $PAGE->start_collecting_javascript_requirements();
        $jsfooter = $PAGE->requires->get_end_code();
        return [
            'data' => json_encode($data),
            'template' => $data['template'] ?? '',
            'js' => $jsfooter,
        ];
    }

    /**
     * Define the return structure for the function.
     *
     * @return [type]
     *
     */
    public static function execute_returns() {
        return new external_single_structure(
            [
                'data' => new external_value(PARAM_RAW, 'Json encoded data'),
                'template' => new external_value(PARAM_RAW, 'template'),
                'js' => new external_value(PARAM_RAW, 'js'),
            ]
        );
    }
}
