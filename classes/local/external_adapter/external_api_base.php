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
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\external_adapter;

use stdClass;
/**
 * Class unit
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class external_api_base {
    /**
     * Private constructor to prevent direct instantiation.
     * @param string $data
     * @return array
     */
    protected function translate_incoming_data($incominguserdata) {
        $translationsmap = $this->local_taskflow_get_label_settings();
        $user = [];
        foreach ($translationsmap as $label => $value) {
            $internallabel = str_replace('translator_', '', $label);
            if (empty($value)) {
                $value = $internallabel;
            }
            $externalpath = explode('->', $value);
            $translatedvalue = $incominguserdata;
            foreach ($externalpath as $key) {
                $translatedvalue = $translatedvalue->$key ?? '';
            }
            $user[$internallabel] = $translatedvalue;
        }
        return $user;
    }

    /**
     * Retrieve only the label-value settings dynamically.
     *
     * @return array Filtered settings for label-value pairs.
     */
    private function local_taskflow_get_label_settings(): array {
        $allsettings = (array) get_config('local_taskflow');
        return array_filter(
            $allsettings,
            fn($key) => str_starts_with($key, 'translator_'),
            ARRAY_FILTER_USE_KEY
        );
    }
}
