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

namespace local_taskflow\local;

use html_writer;

/**
 * Class htmlcomponents
 * @package local_taskflow
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class htmlcomponents {
    /**
     * Render Bootstrap collapsible component.
     *
     * @param string $headertext
     * @param string $bodytext
     *
     * @return string
     *
     */
    public static function render_bootstrap_collapsible(string $headertext, string $bodytext) {
        // Example function body.
        $returnstring = html_writer::tag(
            'p',
            html_writer::link(
                '#pollurlplaceholders',
                $headertext,
                [
                    'class' => 'btn btn-link p-0',
                    'data-toggle' => 'collapse',
                    'role' => 'button',
                    'data-bs-toggle' => 'collapse',
                    'aria-expanded' => 'false',
                    'aria-controls' => 'pollurlplaceholders',
                ]
            )
        ) .
        html_writer::div(
            html_writer::div(
                $bodytext,
                'card card-body'
            ),
            '',
            [
                'class' => 'collapse',
                'id' => 'pollurlplaceholders',
            ]
        );

        return $returnstring;
    }
}
