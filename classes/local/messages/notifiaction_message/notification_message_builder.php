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
 * Trait for building notification messages.
 *
 * @package local_taskflow
 * @author David Ala-Flucher
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\messages\notifiaction_message;

use html_writer;
use local_taskflow\taskflow_stringmanager;

/**
 * Trait notification_message_builder
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait notification_message_builder {
    /**
     * Builds the full notification message: the German block first,
     * followed by a separator and the English block.
     *
     * @param callable $itembuilder Callback that returns the HTML list items for a given language: fn(string $lang): array
     * @param bool $addextralinebreak Add extra line break at the end (default false)
     * @return string Formatted HTML message
     */
    protected function build_notification_message(
        callable $itembuilder,
        bool $addextralinebreak = false
    ): string {
        $separator = html_writer::tag('p', str_repeat('=', 40));

        $message = $this->build_language_block($itembuilder('de'), 'de') .
            "<br>" . $separator . "<br>" .
            $this->build_language_block($itembuilder('en'), 'en');

        if ($addextralinebreak) {
            $message .= "<br><br>";
        }

        return $message;
    }

    /**
     * Builds one language block of the notification message.
     *
     * @param array $items List of HTML list items
     * @param string $lang Language of the block
     * @return string Formatted HTML block
     */
    private function build_language_block(array $items, string $lang): string {
        $intro = html_writer::tag('p', taskflow_stringmanager::get_string('notificationmessageintro', null, $lang));
        $outro = html_writer::tag('p', taskflow_stringmanager::get_string('notificationmessageoutro', null, $lang));
        $pre = html_writer::tag('p', taskflow_stringmanager::get_string('notificationmessagepreamble', null, $lang));
        $post = html_writer::tag('p', taskflow_stringmanager::get_string('notificationmessagepost', null, $lang));

        return $pre . "<br>" . $intro . "<br>" . html_writer::tag('ul', implode("\n", $items)) .
            "<br>" . $outro . "<br>" . $post;
    }
}
