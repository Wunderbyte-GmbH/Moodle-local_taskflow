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
 * Component-local alias of the engine's result_summary_provider_interface.
 *
 * The engine alias registrar does not cover this interface, so local_taskflow vendors
 * the alias itself: it binds to the active engine's interface when one is installed and
 * to a structurally identical local fallback otherwise, so the skill provider class
 * always loads (plan §3.5 "interface_exists guard").
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\wizard\engine;

use local_taskflow\local\wizard\engine_component;
use local_taskflow\local\wizard\taskflow\support\result_summary_provider_fallback_interface;

defined('MOODLE_INTERNAL') || die();

(static function (): void {
    $alias = __NAMESPACE__ . '\\result_summary_provider_interface';
    if (class_exists($alias, false) || interface_exists($alias, false)) {
        return;
    }
    $target = engine_component::engine_class('interfaces\\result_summary_provider_interface');
    if ($target === null) {
        $target = result_summary_provider_fallback_interface::class;
    }
    class_alias($target, $alias);
})();
