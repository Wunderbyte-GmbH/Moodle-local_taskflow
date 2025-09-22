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

namespace local_taskflow\local\messages\sending_condition;

use local_taskflow\local\messages\sending_condition\types\always;
use local_taskflow\local\messages\sending_condition\types\manually;
use local_taskflow\local\messages\sending_condition\types\automatically;

/**
 * Facade to decide if message can be send or not
 * @author Jacob Viertel
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sending_condition_facade {
    /**
     * Factory for the organisational units.
     * @param \local_taskflow\local\assignments\assignment $oldassignment
     * @param array $newassignment
     * @return void
     */
    public static function create(string $type): sending_condition_interface {
        return match ($type) {
            'manually'      => new manually(),
            'automatically'   => new automatically(),
            default       => new always(),
        };
    }

    /**
     * Hilfsmethode: Liste aller verfügbaren Conditions
     */
    public static function get_all(): array {
        $always = new always();
        $manually = new manually();
        $automatically = new automatically();
        return [
            $always->get_identifier() => $always->get_label(),
            $manually->get_identifier() => $manually->get_label(),
            $automatically->get_identifier() => $automatically->get_label(),
        ];
    }
}
