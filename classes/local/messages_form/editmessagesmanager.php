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

use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\htmlcomponents;
use local_taskflow\local\messages\messages_facade;
use local_taskflow\local\messages\placeholders\placeholders_manager;
use local_taskflow\local\messages\sending_condition\sending_condition_facade;
use local_taskflow\local\messages\types\chat;
use local_taskflow\local\messages\types\request;
use local_taskflow\local\messages\types\standard;
use local_taskflow\singleton_service;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');
use moodleform;
use MoodleQuickForm;
use local_taskflow\taskflow_stringmanager;

/**
 * Submit data to the server.
 * @package local_taskflow
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
            'noselectionstring' => taskflow_stringmanager::get_string('chooseuser'),
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
        $this->set_type_settings($mform);

        $this->set_general_settings($mform);

        $this->set_recepientsettings($mform, $autocompleteoptions);

        $this->set_carboncopysettings($mform, $autocompleteoptions);

        $this->set_messagecontentsettings($mform);

        $this->set_messagesettings($mform);

        // Submit button.
        $this->add_action_buttons(true, taskflow_stringmanager::get_string('messagesave'));
    }

    /**
     * Definition.
     * @param MoodleQuickForm $mform
     * @return void
     */
    private function set_messagecontentsettings(&$mform): void {
        $mform->addElement('header', 'messagecontentsettings', taskflow_stringmanager::get_string('messagecontentsettings'));
        // Heading.
        $mform->addElement('text', 'heading', taskflow_stringmanager::get_string('messageheading'), 'size="64"');
        $mform->setType('heading', PARAM_RAW);
        $mform->addRule('heading', null, 'required', null, 'client');
        // Body.
        $mform->addElement(
            'editor',
            'body',
            taskflow_stringmanager::get_string('messagebody'),
            'wrap="virtual" rows="10" cols="64"'
        );
        $mform->setType('body', PARAM_RAW);
        $mform->addRule('body', null, 'required', null, 'client');

        $placeholders = new placeholders_manager();
        $availableplaceholders = $placeholders->get_list_of_placeholders();
        $mform->addElement(
            'static',
            'pollurlplaceholdersexplanation',
            '',
            htmlcomponents::render_bootstrap_collapsible(
                taskflow_stringmanager::get_string('pollurlplaceholdersexplanation'),
                $availableplaceholders
            )
        );
    }

    /**
     * Definition.
     * @param MoodleQuickForm $mform
     * @return void
     */
    private function set_messagesettings(&$mform): void {
        $mform->addElement('header', 'messagesettings', taskflow_stringmanager::get_string('messagesettings'));
        // Tags (multiselect).
        $mform->addElement(
            'tags',
            'tags',
            taskflow_stringmanager::get_string('messagetags'),
            [
                'itemtype' => 'local_taskflow_messages',
                'component' => 'local_taskflow',
                'context' => \context_system::instance(),
            ]
        );

        // Priority.
        $mform->addElement('select', 'priority', taskflow_stringmanager::get_string('messagepriority'), [
            1 => taskflow_stringmanager::get_string('prioritylow'),
            2 => taskflow_stringmanager::get_string('prioritymedium'),
            3 => taskflow_stringmanager::get_string('priorityhigh'),
        ]);
        $mform->setType('priority', PARAM_INT);
        $mform->addRule('priority', null, 'required', null, 'client');

        // Hidden ID (for editing).
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $timeunit = $mform->createElement('select', 'timeunit', '', [
        'minutes' => get_string('minutes', 'moodle'),
        'hours' => get_string('hours', 'moodle'),
        'days' => get_string('days', 'moodle'),
        ]);
        $mform->setType('timeunit', PARAM_ALPHA);
        $senddirection = $mform->createElement('select', 'senddirection', '', [
            'before' => taskflow_stringmanager::get_string('beforecourseend'),
            'after' => taskflow_stringmanager::get_string('aftercourseend'),
        ]);
        $mform->setType('senddirection', PARAM_ALPHA);

        $sendingoptions = $this->return_sendingoptions();
        $sendstart = $mform->createElement('select', 'sendstart', '', $sendingoptions['standard']);
        $sendstartrequest = $mform->createElement('select', 'sendstartrequest', '', $sendingoptions['request']);

        $mform->setType('sendstart', PARAM_ALPHA);

        // Create the number of days element.
        $senddays = $mform->createElement(
            'text',
            'senddays',
            '',
            ['placeholder' => taskflow_stringmanager::get_string('senddays')]
        );
        $mform->setType('senddays', PARAM_INT);

        $areanames = assignment_status_facade::get_all_wanted_stati();
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

        $sendingconditionnames = sending_condition_facade::get_all();
        $sendingcondition = $mform->createElement(
            'select',
            'sendingcondition',
            get_string('searcharea', 'search'),
            $sendingconditionnames
        );

        // Group them together.
        $mform->addGroup(
            [$senddays, $timeunit, $senddirection, $sendstart, $sendstartrequest, $eventlist, $sendingcondition],
            'sendtimegroup',
            taskflow_stringmanager::get_string('senddirection'),
            ' ',
            false
        );

        $mform->hideIf('sendstart', 'messagetypes', 'eq', request::TYPE);
        $mform->hideIf('eventlist', 'messagetypes', 'eq', request::TYPE);
        $mform->hideIf('sendingcondition', 'messagetypes', 'eq', request::TYPE);
        $mform->hideIf('sendstartrequest', 'messagetypes', 'neq', request::TYPE);

        $mform->hideIf('eventlist', 'sendstart', 'neq', 'status_change');
        $mform->hideIf('sendingcondition', 'sendstart', 'neq', 'status_change');

        $mform->hideIf('sendstart', 'messagetypes', 'eq', chat::TYPE);
        $mform->hideIf('sendingcondition', 'messagetypes', 'eq', chat::TYPE);
        $mform->hideIf('eventlist', 'messagetypes', 'eq', chat::TYPE);
        $mform->hideIf('sendingcondition', 'messagetypes', 'eq', chat::TYPE);
    }

    /**
     * Definition.
     * @param MoodleQuickForm $mform
     * @param array $autocompleteoptions
     * @return void
     */
    private function set_recepientsettings(&$mform, $autocompleteoptions): void {
        $mform->addElement('header', 'recepientsettings', taskflow_stringmanager::get_string('recepientsettings'));
        $mform->setExpanded('recepientsettings');
        $mform->addElement(
            'select',
            'recipientrole',
            taskflow_stringmanager::get_string('recipientrole'),
            $this->get_recipient_list('recipientrole'),
            ['multiple' => 'multiple']
        );

        $mform->addElement(
            'autocomplete',
            'userid',
            taskflow_stringmanager::get_string('specificuserchoose'),
            [],
            $autocompleteoptions
        );

        $mform->addElement(
            'static',
            'message_typedescription',
            '',
            taskflow_stringmanager::get_string('messagetyperequiresnothing')
        );

        $mform->hideIf('recipientrole', 'messagetypes', 'eq', request::TYPE);
        $mform->hideIf('recipientrole', 'messagetypes', 'eq', chat::TYPE);
        $mform->hideIf('userid', 'messagetypes', 'eq', request::TYPE);
        $mform->hideIf('userid', 'messagetypes', 'eq', chat::TYPE);
        $mform->hideIf('recepientsettings', 'messagetypes', 'eq', request::TYPE);
        $mform->hideIf('recepientsettings', 'messagetypes', 'eq', chat::TYPE);
        $mform->hideIf('message_typedescription', 'messagetypes', 'eq', standard::TYPE);
    }

    /**
     * Definition.
     * @param MoodleQuickForm $mform
     * @param array $autocompleteoptions
     * @return void
     */
    private function set_carboncopysettings(&$mform, $autocompleteoptions): void {
        $mform->addElement('header', 'carboncopysettings', taskflow_stringmanager::get_string('carboncopysettings'));
        $mform->setExpanded('carboncopysettings');

        $mform->addElement(
            'select',
            'carboncopyrole',
            taskflow_stringmanager::get_string('carboncopyrole'),
            $this->get_recipient_list('carboncopyrole'),
            ['multiple' => 'multiple']
        );

        $mform->addElement(
            'autocomplete',
            'ccuserid',
            taskflow_stringmanager::get_string('ccspecificuserchoose'),
            [],
            $autocompleteoptions
        );

        $mform->addElement(
            'static',
            'message_typedescription_cc',
            '',
            taskflow_stringmanager::get_string('messagetyperequiresnothing')
        );

        $mform->hideIf('carboncopyrole', 'messagetypes', 'eq', chat::TYPE);
        $mform->hideIf('ccuserid', 'messagetypes', 'eq', chat::TYPE);
        $mform->hideIf('message_typedescription_cc', 'messagetypes', 'neq', chat::TYPE);
    }

    /**
     * Definition
     *
     * @param MoodleQuickForm $mform
     *
     * @return void
     *
     */
    private function set_type_settings(&$mform) {
        $mform->addElement('header', 'typesettings', taskflow_stringmanager::get_string('typesettings'));
        $mform->setExpanded('typesettings');
        // Message type.
        $types = messages_facade::get_message_types();

        $mform->addElement(
            'select',
            'messagetypes',
            taskflow_stringmanager::get_string('typesettings'),
            $types
        );
        $mform->setDefault('messagetypes', standard::TYPE);
    }

    /**
     * Definition
     *
     * @param MoodleQuickForm $mform
     *
     * @return void
     *
     */
    private function set_general_settings(&$mform) {
        $mform->addElement('header', 'generalsettings', taskflow_stringmanager::get_string('generalsettings'));
        $mform->setExpanded('generalsettings');
        $mform->addElement('text', 'messagename', taskflow_stringmanager::get_string('messagename'));
        $mform->setType('messagename', PARAM_TEXT);
    }

    /**
     * Definition.
     * @param string $type
     * @return array
     */
    private function get_recipient_list($type): array {
        $recipientlist = [
            'assignee' => taskflow_stringmanager::get_string('assignee'),
            'supervisor' => taskflow_stringmanager::get_string('supervisor'),
        ];
        if ($type == 'recipientrole') {
            $recipientlist['specificuser'] = taskflow_stringmanager::get_string('specificuser');
        } else {
            $recipientlist['ccspecificuser'] = taskflow_stringmanager::get_string('ccspecificuser');
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
            isset($data['sendstart']) &&
            $data['sendstart'] !== 'end'
        ) {
            $errors['sendtimegroup'] = taskflow_stringmanager::get_string('invalidsendingcombination');
        }
        if ($data['messagetypes'] == standard::TYPE && empty($data['recipientrole'])) {
            $errors['recipientrole'] = taskflow_stringmanager::get_string('errormissingvalue');
        }
        return $errors;
    }

    /**
     * Return sendingoptions.
     *
     * @return array
     *
     */
    private function return_sendingoptions() {
        return [
            'standard' => [
                'start' => taskflow_stringmanager::get_string('startdate'),
                'end' => taskflow_stringmanager::get_string('enddate'),
                'status_change' => taskflow_stringmanager::get_string('onstatuschange'),
            ],
            'request' => [
                'onrequestcreated' => taskflow_stringmanager::get_string('onrequestcreated'),
                'onrequestclosed' => taskflow_stringmanager::get_string('onrequestclosed'),
            ],
        ];
    }
}
