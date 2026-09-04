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

namespace local_taskflow\local\wizard;

/**
 * Resolves which AI engine plugin serves the Wunderbyte agent for local_taskflow.
 *
 * The engine exists in two structurally identical plugins: the standalone
 * local_wizard plugin and the bookingextension_agent subplugin. When both are
 * installed local_wizard takes precedence. Consumers in local_taskflow never
 * hardcode one engine component; they resolve classes through this helper and
 * reference engine contract types only through the component-local aliases
 * local_taskflow\local\wizard\engine\* (registered by the engine itself).
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class engine_component {
    /**
     * The engines in precedence order: the standalone local_wizard outranks the bundled
     * agent. The order mirrors the engine-side authorization_service::active_engine_component()
     * and mod_booking\local\wizard\engine_component; the three must agree.
     *
     * @var string[]
     */
    private const ENGINES_BY_PRECEDENCE = ['local_wizard', 'bookingextension_agent'];

    /**
     * Frankenstyle name of the active engine plugin, or null if none is installed.
     *
     * @return string|null
     */
    public static function active(): ?string {
        foreach (self::ENGINES_BY_PRECEDENCE as $candidate) {
            try {
                $plugininfo = \core_plugin_manager::instance()->get_plugin_info($candidate);
            } catch (\Throwable $e) {
                $plugininfo = null;
            }
            if ($plugininfo !== null && $plugininfo->is_installed_and_upgraded()) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Fully qualified class name of an engine class below <engine>\local\wizard\, or null.
     *
     * @param string $relativeclass Class path below the engine's local\wizard namespace,
     *                              e.g. 'skill_registry' or 'interfaces\\skill_interface'.
     * @return string|null Null when no engine is installed or the class does not exist.
     */
    public static function engine_class(string $relativeclass): ?string {
        $component = self::active();
        if ($component === null) {
            return null;
        }
        $class = '\\' . $component . '\\local\\wizard\\' . ltrim($relativeclass, '\\');
        return (class_exists($class) || interface_exists($class)) ? $class : null;
    }

    /**
     * Register local_taskflow's engine aliases via the active engine's registrar.
     *
     * Only needed on paths that touch skill/engine types WITHOUT going through the
     * engine's own skill discovery (which registers them itself) - i.e. local_taskflow's
     * PHPUnit tests that instantiate skills directly.
     *
     * @return void
     */
    public static function ensure_engine_aliases(): void {
        $component = self::active();
        if ($component === null) {
            return;
        }
        $registrar = '\\' . $component . '\\local\\wizard\\services\\engine_alias_registrar';
        if (class_exists($registrar)) {
            $registrar::ensure_component_aliases('local_taskflow');
        }
    }
}
