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

/**
 * Demo step 1 form.
 */
class message_tag_form_entity {
    /**
     * Definition.
     * @param int $recordid
     * @param array $tags
     */
    public function save_message_tags($recordid, $tags): void {
        $context = \context_system::instance();
        $tags = array_map('trim', is_array($tags) ? $tags : explode(',', (string)$tags));
        $tags = array_filter($tags);

        \core_tag_tag::set_item_tags(
            'local_taskflow',
            'local_taskflow_messages',
            $recordid,
            $context,
            $tags
        );
        global $DB;
        $tagobjects = \core_tag_tag::get_item_tags('local_taskflow', 'local_taskflow_messages', $recordid);
        foreach ($tagobjects as $tag) {
            $DB->set_field('tag', 'isstandard', 1, ['id' => $tag->id]);
        }
    }
}
