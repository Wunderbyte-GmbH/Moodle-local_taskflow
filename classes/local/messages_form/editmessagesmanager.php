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
 * Class for managing multi-step forms.
 *
 * @package   local_taskflow
 * @copyright 2025
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\local\messages_form;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');
use moodleform;

/**
 * Submit data to the server.
 * @package local_multistepform
 * @category external
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright 2025 Wunderbyte GmbH
 */
class editmessagesmanager extends moodleform {
    /**
     * Definition.
     * @return void
     */
    public function definition() {
        $mform = $this->_form;

        $path = __DIR__ . '/../messages/types';
        $messagetypes = [];
        foreach (glob($path . '/*.php') as $file) {
            $basename = basename($file, '.php');
            $messagetypes[$basename] = $basename;
        }

        $mform->addElement('select', 'type', get_string('messagetype', 'local_taskflow'), $messagetypes);
        $mform->setType('type', PARAM_ALPHANUMEXT);
        $mform->addRule('type', null, 'required', null, 'client');

        // Heading.
        $mform->addElement('text', 'heading', get_string('messageheading', 'local_taskflow'), 'size="64"');
        $mform->setType('heading', PARAM_TEXT);
        $mform->addRule('heading', null, 'required', null, 'client');

        // Body.
        $mform->addElement('textarea', 'body', get_string('messagebody', 'local_taskflow'), 'wrap="virtual" rows="10" cols="64"');
        $mform->setType('body', PARAM_RAW);

        // Tags (multiselect).
        $mform->addElement(
            'tags',
            'tags',
            get_string('messagetags', 'local_taskflow'),
            [
                'itemtype' => 'messages',
                'component' => 'local_taskflow',
                'context' => \context_system::instance(),
            ]
        );

        // Priority.
        $mform->addElement('select', 'priority', get_string('messagepriority', 'local_taskflow'), [
            1 => get_string('prioritylow', 'local_taskflow'),
            2 => get_string('prioritymedium', 'local_taskflow'),
            3 => get_string('priorityhigh', 'local_taskflow'),
        ]);
        $mform->setType('priority', PARAM_INT);
        $mform->addRule('priority', null, 'required', null, 'client');

        // Hidden ID (for editing).
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $senddirection = $mform->createElement('select', 'senddirection', '', [
            'before' => get_string('beforecourseend', 'local_taskflow'),
            'after' => get_string('aftercourseend', 'local_taskflow'),
        ]);
        $mform->setType('senddirection', PARAM_ALPHA);

        $sendstart = $mform->createElement('select', 'sendstart', '', [
            'start' => get_string('startdate', 'local_taskflow'),
            'end' => get_string('enddate', 'local_taskflow'),
        ]);
        $mform->setType('sendstart', PARAM_ALPHA);

        // Create the number of days element.
        $senddays = $mform->createElement(
            'text',
            'senddays',
            '',
            ['placeholder' => get_string('senddays', 'local_taskflow')]
        );
        $mform->setType('senddays', PARAM_INT);

        // Group them together.
        $mform->addGroup(
            [$senddirection, $sendstart, $senddays],
            'sendtimegroup',
            get_string('senddirection', 'local_taskflow'),
            ' ',
            false
        );

        // Submit button.
        $this->add_action_buttons(true, get_string('messagesave', 'local_taskflow'));
    }

    /**
     * Definition.
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if ($data['sendstart'] === 'start' && $data['senddirection'] === 'before') {
            $errors['sendtimegroup'] = get_string('invalidsendingcombination', 'local_taskflow');
        }
        return $errors;
    }
}
