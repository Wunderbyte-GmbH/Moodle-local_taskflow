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
 * The kind of request decides the assignment of a message diagnosis.
 *
 * @package    local_taskflow
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow;

use context_system;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\local\requests\request_types\types\allowselfextension;
use local_taskflow\local\requests\request_types\types\allowuploadevidence;
use local_taskflow\local\wizard\taskflow\skills\diagnose_message_delivery_skill;
use local_taskflow\local\wizard\taskflow\taskflow_skill_base;
use local_taskflow\wizard\local_wizard_dependency;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Baseline DMD-2 (L47 thread 15935, L48 16712): "Did the 'Antrag eröffnet' mail for Mr X's extension reach his
 * supervisor?" - the person has two requests, an extension on one assignment and a certificate upload on another;
 * the request-opened template is the same for both, so the skill offered both assignments although the kind of request
 * decides it. The card now carries requesttype (a declared enum); the assignment holding such a request is the target
 * when it is the only one, and the choices stay for several, none, or an unknown kind.
 *
 * @covers \local_taskflow\local\wizard\taskflow\skills\diagnose_message_delivery_skill
 */
final class diagnose_message_delivery_requesttype_test extends \advanced_testcase {
    /** @var \local_taskflow_generator */
    private $generator;
    /** @var \stdClass */
    private $employee;
    /** @var int Assignment carrying the extension request. */
    private int $extensionassignment = 0;
    /** @var int Assignment carrying the evidence request. */
    private int $evidenceassignment = 0;
    /** @var int */
    private int $contextid = 0;

    /**
     * Two rules with the same request template, one person, one request of each kind.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        if (class_exists('\\tool_mocktesttime\\time_mock')) {
            \tool_mocktesttime\time_mock::reset_mock_time();
        }
        local_wizard_dependency::require_installed();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $this->generator->set_config_values('standard');
        $this->generator->create_custom_profile_fields(['supervisor']);
        external_api_base::destroy_instance();

        $this->employee = $this->getDataGenerator()->create_user(['firstname' => 'Karl', 'lastname' => 'Mustermann']);
        $messageid = (int)$DB->insert_record('local_taskflow_messages', (object)[
            'name' => 'Request opened',
            'class' => 'request',
            'message' => json_encode(['heading' => 'New request', 'body' => '<p>A request was opened</p>']),
            'priority' => 2,
            'sending_settings' => json_encode([
                'recipientrole' => ['supervisor'],
                'carboncopyrole' => [],
                'senddirection' => 'before',
                'senddays' => '0',
                'timeunit' => 'days',
                'sendstart' => 'end',
                'sendingcondition' => 'always',
            ]),
            'usermodified' => 2,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $this->extensionassignment = $this->assignment_of_new_rule('Data protection', $messageid);
        $this->evidenceassignment = $this->assignment_of_new_rule('First aid', $messageid);
        $this->add_request($this->extensionassignment, (int)allowselfextension::ID);
        $this->add_request($this->evidenceassignment, (int)allowuploadevidence::ID);
        $this->contextid = (int)context_system::instance()->id;
    }

    /**
     * Teardown singletons.
     */
    protected function tearDown(): void {
        $this->generator->teardown();
        parent::tearDown();
    }

    /**
     * A rule with the template and the employee's assignment of it.
     *
     * @param string $name
     * @param int $messageid
     * @return int Assignment id.
     */
    private function assignment_of_new_rule(string $name, int $messageid): int {
        global $DB;
        $ruleid = (int)$this->generator->create_rule(['name' => $name, 'messages' => [$messageid]]);
        $this->generator->create_user_assignment((int)$this->employee->id, $ruleid);
        return (int)$DB->get_field(
            'local_taskflow_assignment',
            'id',
            ['userid' => $this->employee->id, 'ruleid' => $ruleid],
            MUST_EXIST
        );
    }

    /**
     * A request of one kind on an assignment.
     *
     * @param int $assignmentid
     * @param int $type
     * @return void
     */
    private function add_request(int $assignmentid, int $type): void {
        global $DB;
        $DB->insert_record('local_taskflow_requests', (object)[
            'request' => $type,
            'userid' => (int)$this->employee->id,
            'assignmentid' => $assignmentid,
            'status' => $type,
            'usermodified' => 2,
            'treated' => 0,
            'forhr' => 0,
            'comment' => '',
            'json' => '',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Preflight of a diagnosis for the person and the request mails, plus the given fields.
     *
     * @param array $extra
     * @return \bookingextension_agent\local\wizard\dto\preflight_result
     */
    private function preflight(array $extra) {
        global $USER;
        return (new diagnose_message_delivery_skill())->preflight(
            // As the constructor sent it in L47/L48 (15935, 16712): the person and the kind of message, no template name.
            array_merge(['userquery' => 'Mustermann', 'class' => 'request'], $extra),
            $this->contextid,
            (int)$USER->id
        );
    }

    /**
     * The kind of request picks the assignment: extension and evidence each find their own.
     */
    public function test_the_request_kind_picks_the_assignment(): void {
        $dto = $this->preflight(['requesttype' => 'extension']);
        $this->assertSame('pass', (string)$dto->status, json_encode($dto->issues));
        $this->assertSame($this->extensionassignment, (int)($dto->preparedinput['assignmentid'] ?? 0));

        $dto = $this->preflight(['requesttype' => 'evidence']);
        $this->assertSame('pass', (string)$dto->status, json_encode($dto->issues));
        $this->assertSame($this->evidenceassignment, (int)($dto->preparedinput['assignmentid'] ?? 0));
    }

    /**
     * Non-success paths: without a kind, with an unknown kind, or with a kind nobody requested, the assignments are
     * offered as choices - a clarification, never an error, and the text names no schema field.
     */
    public function test_without_a_deciding_kind_the_assignments_are_offered(): void {
        foreach ([[], ['requesttype' => 'somethingelse'], ['requesttype' => 'notrelevant']] as $extra) {
            $dto = $this->preflight($extra);
            $this->assertNotSame('pass', (string)$dto->status, json_encode($extra));
            $issue = null;
            foreach (json_decode(json_encode($dto->issues), true) as $candidate) {
                if ((string)($candidate['code'] ?? '') === taskflow_skill_base::ISSUE_ASSIGNMENT_CHOICE) {
                    $issue = $candidate;
                }
            }
            $this->assertNotNull($issue, 'the assignments are the choices: ' . json_encode($dto->issues));
            $this->assertSame('needs_clarification', (string)($issue['severity'] ?? ''));
            $this->assertEqualsCanonicalizing(
                [$this->extensionassignment, $this->evidenceassignment],
                array_map('intval', array_column((array)($issue['candidates'] ?? []), 'id'))
            );
            $this->assertStringNotContainsString('requesttype', (string)($issue['message'] ?? ''));
            $this->assertStringNotContainsString('assignmentid', (string)($issue['message'] ?? ''));
        }
    }

    /**
     * Two requests of the same kind stay a choice - the kind alone must not guess between them.
     */
    public function test_two_requests_of_the_kind_stay_a_choice(): void {
        $this->add_request($this->evidenceassignment, (int)allowselfextension::ID);
        $dto = $this->preflight(['requesttype' => 'extension']);
        $this->assertNotSame('pass', (string)$dto->status);
    }
}
