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
        $record->class = $this->set_messagetype($formdata->sendstart ?? '');
        $record->message = json_encode([
            'heading' => $formdata->heading,
            'body' => $formdata->body['text'],
        ]);
        $record->name = $formdata->messagename;
        $record->usermodified = $USER->id;
        $record->priority = $formdata->priority;
        $record->sending_settings = json_encode([
            'recipientrole' => $formdata->recipientrole ?? [],
            'userid' => $formdata->userid ?? 0,
            'carboncopyrole' => $formdata->carboncopyrole ?? [],
            'ccuserid' => $formdata->ccuserid ?? 0,
            'senddirection' => $formdata->senddirection,
            'eventlist' => $formdata->eventlist ?? [],
            'sendstart' => $formdata->sendstart ?? 'status_change',
            'senddays' => $formdata->senddays,
            'timeunit' => $formdata->timeunit,
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
     * @param string $sendstart
     * @return string
     */
    private function set_messagetype($sendstart) {
        if (empty($sendstart)) {
            return 'onevent';
        }
        return 'standard';
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
            $data->messagename = $record->name;

            $decoded = json_decode($record->message ?? '{}');
            $data->heading = $decoded->heading ?? '';
            $data->body = [
            'text' => $decoded->body ?? '',
            'format' => FORMAT_HTML,
            ];
            $data->priority = $record->priority ?? 2;

            $sending = json_decode($record->sending_settings ?? '{}');
            $data->recipientrole = $sending->recipientrole ?? [];
            $data->userid = $sending->userid ?? 0;
            $data->carboncopyrole = $sending->carboncopyrole ?? [];
            $data->ccuserid = $sending->ccuserid ?? 0;
            $data->senddirection = $sending->senddirection ?? '';
            $data->eventlist = $sending->eventlist ?? [];
            $data->sendstart = $sending->sendstart ?? '';
            $data->senddays = $sending->senddays ?? '';
            $data->timeunit = $sending->timeunit ?? '';

            $tags = \core_tag_tag::get_item_tags('local_taskflow', 'local_taskflow_messages', $record->id);
            $data->tags = array_map(fn($tag) => $tag->rawname, $tags);
            return $data;
        }
        return null;
    }
}
