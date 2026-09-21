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

namespace local_taskflow\output;

/**
 * Resolves the view class of a page: the active adapter's override when it exists, else the core view.
 *
 * An adapter overrides a page by shipping a class taskflowadapter_<adapter>\output\<view> that
 * implements {@see adapter_view_interface}. It may extend the core class and only swap the template,
 * or rebuild the exported data from scratch — layout, ordering and design are entirely its own.
 *
 * @package local_taskflow
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class adapter_view_resolver {
    /**
     * Returns the view class name to use for a page.
     *
     * @param string $view Short view name, e.g. "personpage" or "unitspage".
     * @return string Fully qualified class name.
     */
    public static function resolve_class(string $view): string {
        $adapter = (string)get_config('local_taskflow', 'external_api_option');
        $core = 'local_taskflow\\output\\' . $view;
        if ($adapter !== '') {
            $candidate = 'taskflowadapter_' . $adapter . '\\output\\' . $view;
            if (class_exists($candidate) && is_subclass_of($candidate, adapter_view_interface::class)) {
                return $candidate;
            }
        }
        return $core;
    }

    /**
     * Instantiates the view for a page with the given constructor arguments.
     *
     * @param string $view Short view name, e.g. "personpage" or "unitspage".
     * @param array $args Constructor arguments, in order.
     * @return adapter_view_interface
     */
    public static function instance(string $view, array $args): adapter_view_interface {
        $class = self::resolve_class($view);
        return new $class(...$args);
    }
}
