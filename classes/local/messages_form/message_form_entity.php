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
 * Form to create rules.
 *
 * @package   local_taskflow
 * @copyright 2025
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\messages_form;
use stdClass;

/**
 * Demo step 1 form.
 */
class message_form_entity {
    /**
     * Definition.
     * @param stdClass $formdata
     * @return int
     */
    public function prepare_message_from_form($formdata): int {
        global $USER, $DB;
        $record = new stdClass();
        $record->id = $formdata->id ?? 0;
        $record->class = $formdata->type;
        $record->message = json_encode([
            'heading' => $formdata->heading,
            'body' => $formdata->body,
        ]);
        $record->usermodified = $USER->id;
        $record->priority = $formdata->priority;
        $record->sending_settings = json_encode([
            'senddirection' => $formdata->senddirection,
            'sendstart' => $formdata->sendstart,
            'senddays' => $formdata->senddays,
        ]);
        $record->timemodified = time();

        if (empty($record->id)) {
            $record->timecreated = time();
            $record->id = $DB->insert_record('local_taskflow_messages', $record);
        } else {
            $DB->update_record('local_taskflow_messages', $record);
        }
        return $record->id;
    }

    /**
     * Definition.
     * @param int $recordid
     * @return mixed
     */
    public function prepare_record_for_form($recordid) {
        global $DB;
        $record = $DB->get_record('local_taskflow_messages', ['id' => $recordid]);
        if ($record) {
            $data = new stdClass();
            $data->id = $record->id;
            $data->type = $record->class;

            $decoded = json_decode($record->message ?? '{}');
            $data->heading = $decoded->heading ?? '';
            $data->body = $decoded->body ?? '';

            $data->priority = $record->priority;

            $sending = json_decode($record->sending_settings ?? '{}');
            $data->senddirection = $sending->senddirection ?? '';
            $data->sendstart = $sending->sendstart ?? '';
            $data->senddays = $sending->senddays ?? '';

            $tags = \core_tag_tag::get_item_tags('local_taskflow', 'messages', $record->id);
            $data->tags = array_map(fn($tag) => $tag->rawname, $tags);
            return $data;
        }
        return null;
    }
}
