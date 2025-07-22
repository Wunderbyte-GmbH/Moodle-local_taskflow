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

use local_taskflow\local\assignments\status\assignment_status;
use local_taskflow\singleton_service;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');
use moodleform;
use MoodleQuickForm;

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

        global $DB;
        $mform = $this->_form;

        $autocompleteoptions = [
            'ajax' => 'core_user/form_user_selector',
            'noselectionstring' => get_string('chooseuser', 'local_taskflow'),
            'multiple' => false,
            'valuehtmlcallback' => function ($value) {
                global $OUTPUT;
                if (empty($value)) {
                    return '';
                }
                $user = singleton_service::get_instance_of_user((int)$value);
                $details = [
                    'id' => $user->id,
                    'email' => $user->email,
                    'firstname' => $user->firstname,
                    'lastname' => $user->lastname,
                ];
                return $OUTPUT->render_from_template(
                    'local_taskflow/form-user-selector-suggestion',
                    $details
                );
            },
        ];

        $this->set_recepientsettings($mform, $autocompleteoptions);

        $this->set_carboncopysettings($mform, $autocompleteoptions);

        $this->set_messagecontentsettings($mform);

        $this->set_messagesettings($mform);

        // Submit button.
        $this->add_action_buttons(true, get_string('messagesave', 'local_taskflow'));
    }

    /**
     * Definition.
     * @param MoodleQuickForm $mform
     * @return void
     */
    private function set_messagecontentsettings(&$mform): void {
        $mform->addElement('header', 'messagecontentsettings', get_string('messagecontentsettings', 'local_taskflow'));
        // Heading.
        $mform->addElement('text', 'heading', get_string('messageheading', 'local_taskflow'), 'size="64"');
        $mform->setType('heading', PARAM_TEXT);
        $mform->addRule('heading', null, 'required', null, 'client');
        // Body.
        $mform->addElement('textarea', 'body', get_string('messagebody', 'local_taskflow'), 'wrap="virtual" rows="10" cols="64"');
        $mform->setType('body', PARAM_RAW);
        $mform->addRule('body', null, 'required', null, 'client');
    }

    /**
     * Definition.
     * @param MoodleQuickForm $mform
     * @return void
     */
    private function set_messagesettings(&$mform): void {
        $mform->addElement('header', 'messagesettings', get_string('messagesettings', 'local_taskflow'));
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
            'status_change' => get_string('onstatuschange', 'local_taskflow'),
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

        $areanames = $this->get_event_list();
        $options = [
            'multiple' => true,
            'noselectionstring' => get_string('allareas', 'search'),
        ];
        $eventlist = $mform->createElement(
            'autocomplete',
            'eventlist',
            get_string('searcharea', 'search'),
            $areanames,
            $options
        );

        // Group them together.
        $mform->addGroup(
            [$senddays, $senddirection, $sendstart, $eventlist],
            'sendtimegroup',
            get_string('senddirection', 'local_taskflow'),
            ' ',
            false
        );

        $mform->hideIf('eventlist', 'sendstart', 'neq', 'status_change');
    }

    /**
     * Definition.
     * @param MoodleQuickForm $mform
     * @param array $autocompleteoptions
     * @return void
     */
    private function set_recepientsettings(&$mform, $autocompleteoptions): void {
        $mform->addElement('header', 'recepientsettings', get_string('recepientsettings', 'local_taskflow'));
        $mform->addElement(
            'select',
            'recipientrole',
            get_string('recipientrole', 'local_taskflow'),
            $this->get_recipient_list('recipientrole'),
            ['multiple' => 'multiple']
        );

        $mform->addElement(
            'autocomplete',
            'userid',
            get_string('specificuserchoose', 'local_taskflow'),
            [],
            $autocompleteoptions
        );
        $mform->addRule('recipientrole', null, 'required', null, 'client');
    }

    /**
     * Definition.
     * @param MoodleQuickForm $mform
     * @param array $autocompleteoptions
     * @return void
     */
    private function set_carboncopysettings(&$mform, $autocompleteoptions): void {
        $mform->addElement('header', 'carboncopysettings', get_string('messagesettings', 'local_taskflow'));
        $mform->setExpanded('carboncopysettings');

        $mform->addElement(
            'select',
            'carboncopyrole',
            get_string('recipientrole', 'local_taskflow'),
            $this->get_recipient_list('carboncopyrole'),
            ['multiple' => 'multiple']
        );

        $mform->addElement(
            'autocomplete',
            'ccuserid',
            get_string('ccspecificuserchoose', 'local_taskflow'),
            [],
            $autocompleteoptions
        );
    }

    /**
     * Definition.
     * @return array
     */
    private function get_event_list(): array {
        return assignment_status::get_all();
    }

    /**
     * Definition.
     * @param string $type
     * @return array
     */
    private function get_recipient_list($type): array {
        $recipientlist = [
            'assignee' => get_string('assignee', 'local_taskflow'),
            'supervisor' => get_string('supervisor', 'local_taskflow'),
        ];
        if ($type == 'recipientrole') {
            $recipientlist['specificuser'] = get_string('specificuser', 'local_taskflow');
        } else {
            $recipientlist['ccspecificuser'] = get_string('ccspecificuser', 'local_taskflow');
        }
        $personaladmin = get_config('local_taskflow', 'personal_admin_mail_field');
        if (!empty($personaladmin)) {
            $recipientlist['personaladmin'] = get_string('personaladminmailfield', 'local_taskflow');
        }
        return $recipientlist;
    }

    /**
     * Definition.
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (
            $data['senddirection'] === 'before' &&
            $data['sendstart'] !== 'end'
        ) {
            $errors['sendtimegroup'] = get_string('invalidsendingcombination', 'local_taskflow');
        }
        return $errors;
    }
}
