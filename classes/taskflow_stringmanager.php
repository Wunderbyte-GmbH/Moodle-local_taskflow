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

namespace local_taskflow;

/**
 * Core utility class for the taskflow plugin.
 *
 * @package local_taskflow
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author David Ala
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class taskflow_stringmanager {
    /**
     * Returns a localised string, checking the subplugin component first and
     * falling back to local_taskflow when the string is not defined there.
     *
     * @param string $identifier The string identifier.
     * @param mixed $a Optional placeholder value (string, object, or array).
     * @param string|null $lang Force a specific language code; null uses the current user's language.
     * @return string
     */
    public static function get_string(
        string $identifier,
        mixed $a = null,
        ?string $lang = null
    ): string {
        $stringmanger = get_string_manager();
        $subpluginname = get_config('local_taskflow', 'external_api_option');
        $stringcomponent = 'taskflowadapter_' . $subpluginname;
        if ($stringmanger->string_exists($identifier, $stringcomponent)) {
            return $stringmanger->get_string($identifier, $stringcomponent, $a, $lang);
        }
        return $stringmanger->get_string($identifier, 'local_taskflow', $a, $lang);
    }
}
