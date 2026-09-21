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

namespace local_taskflow\wizard;

use local_taskflow\local\wizard\engine_component;

/**
 * Test-time guard for tests that need the Wunderbyte-agent engine.
 *
 * local_taskflow has no hard dependency on an engine plugin (mirrors mod_booking); tests
 * that instantiate skills or the registry skip cleanly when neither local_wizard nor
 * bookingextension_agent is installed (pattern: engine testing\mod_booking_dependency).
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class local_wizard_dependency {
    /**
     * Skip the current test when no engine is installed; otherwise register the aliases.
     *
     * @return string Frankenstyle name of the active engine.
     */
    public static function require_installed(): string {
        $engine = engine_component::active();
        if ($engine === null) {
            \PHPUnit\Framework\Assert::markTestSkipped(
                'Requires a Wunderbyte-agent engine (local_wizard or bookingextension_agent).'
            );
        }
        engine_component::ensure_engine_aliases();
        return $engine;
    }

    /**
     * Fully qualified engine class below <engine>\local\wizard\, skipping when absent.
     *
     * @param string $relativeclass e.g. 'skill_registry' or 'skill_contract_validator'.
     * @return string
     */
    public static function engine_class(string $relativeclass): string {
        self::require_installed();
        $class = engine_component::engine_class($relativeclass);
        if ($class === null) {
            \PHPUnit\Framework\Assert::markTestSkipped('Engine class ' . $relativeclass . ' not available.');
        }
        return $class;
    }
}
