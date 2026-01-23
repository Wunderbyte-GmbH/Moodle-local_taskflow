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

namespace local_taskflow\local\messages\placeholders;
use core\output\html_writer;
use core_component;

/**
 * Placeholders manager.
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class placeholders_manager {
    /**
     * Return list of available placeholders.
     * @return string
     */
    public function get_list_of_placeholders() {
        return $this->create_list_of_localized_placeholders();
    }

    /**
     * Create list of localized placeholders.
     * @return string
     *
     */
    private function create_list_of_localized_placeholders() {
        $classes = core_component::get_component_classes_in_namespace(
            'local_taskflow',
            'local\messages\placeholders\types'
        );

        $placeholders = [];

        foreach ($classes as $classname => $placeholder) {
            if (class_exists($classname)) {
                $shortname = basename(str_replace('\\', '/', $classname));
                $placeholders[] = html_writer::tag(
                    'li',
                    '&lt;' . $shortname . '&gt;',
                    ['data-id' => $shortname]
                );
            }
        }

        return html_writer::tag(
            'ul',
            implode("\n", $placeholders),
            ['class' => 'booking-placeholders']
        );
    }
}
