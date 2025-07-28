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
 * Rules table.
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\table;
use html_writer;
use local_taskflow\local\rules\rules;
use local_wunderbyte_table\output\table;
use local_wunderbyte_table\wunderbyte_table;
use moodle_url;

/**
 * Rules table
 *
 * @package     local_taskflow
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rules_table extends wunderbyte_table {
    /**
     * Add column with actions.
     * @param mixed $values
     * @return string
     */
    public function col_actions($values) {
        global $OUTPUT, $PAGE;

        $returnurl = $PAGE->url;

        $url = new moodle_url('/local/taskflow/editrule.php', [
            'id' => $values->id,
            'returnurl' => $returnurl,
        ]);

        $html = html_writer::div(html_writer::link(
            $url->out(),
            "<i class='icon fa fa-edit'></i>"
        ));
        $isactive = $this->get_activation_status($values->id);
        $data[] = [
            'label' => '', // Name of your action button.
            'href' => '#', // You can either use the link, or JS, or both.
            'iclass' => $isactive ? 'fa fa-eye' : 'fa fa-eye-slash', // Add an icon before the label.
            'arialabel' => 'eye', // Add an aria-label string to your icon.
            'title' => $values->isactive ?? '0' == '1' ?
                get_string('deactivate', 'local_taskflow') : get_string('activate', 'local_taskflow'),
            'id' => $values->id . '-'  . $this->uniqueid,
            'name' => $this->uniqueid . '-' . $values->id,
            'methodname' => 'toggleitem', // The method needs to be added to your child of wunderbyte_table class.
            'nomodal' => true,
            'data' => [ // Will be added eg as data-id = $values->id, so values can be transmitted to the method above.
                'id' => $values->id,
            ],
        ];
        table::transform_actionbuttons_array($data);
        return
            $html .
            $OUTPUT->render_from_template('local_wunderbyte_table/component_actionbutton', ['showactionbuttons' => $data]);
    }

    /**
     * Description.
     * @param string $valueid
     * @return bool
     */
    private function get_activation_status($valueid) {
        global $DB;
        $rulestatus = $DB->get_field(
            'local_taskflow_rules',
            'isactive',
            [
                'id' => $valueid,
            ]
        );
        return $rulestatus == '1' ? true : false;
    }

    /**
     * Description.
     * @param mixed $values
     * @return string
     */
    public function col_description($values) {
        $jsonobject = json_decode($values->rulejson);
        return html_writer::div($jsonobject->rulejson->rule->description);
    }

    /**
     * Is active.
     * @param mixed $values
     * @return string
     */
    public function col_isactive($values) {
        return html_writer::div($values->isactive ? get_string('yes') : get_string('no'));
    }

    /**
     * Description.
     * @param int $id
     * @param string $data
     * @return array
     */
    public function action_toggleitem(int $id, string $data) {
        $dataobject = json_decode($data);
        $ruleinstance = rules::instance($id);
        $ruleinstance->toggle_isactive();
        $uncheckedmessage = get_string('ruleuncheckedmessage', 'local_taskflow');
        $checkedmessage = get_string('rulecheckedmessage', 'local_taskflow');
        return [
           'success' => 1,
           'message' => $dataobject->state == 'true' ? $checkedmessage : $uncheckedmessage,
        ];
    }
}
