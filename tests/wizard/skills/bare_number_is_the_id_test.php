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

namespace local_taskflow\wizard\skills;

use local_taskflow\local\wizard\taskflow\skills\get_rule_details_skill;
use local_taskflow\local\wizard\taskflow\taskflow_message_resolver;
use local_taskflow\wizard\local_wizard_dependency;
use ReflectionMethod;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * A bare number in a query field is the id.
 *
 * Baseline runs 28/29/33, GRD-2: « Pour la règle 2 : quels filtres ... » reached the skill as rulequery "règle 2" /
 * "rule 2" and later, once the generic noun was dropped, as "2" - searched as a name, "Rule 0 was not found".
 * Runs 26/31/32, DMD-4: "template 1" as messagequery. The field descriptions now say where a number belongs,
 * and the resolvers treat a bare number as the id whatever field it arrived in (structural, no wording).
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_taskflow\local\wizard\taskflow\taskflow_skill_base
 * @covers     \local_taskflow\local\wizard\taskflow\taskflow_message_resolver
 */
final class bare_number_is_the_id_test extends \advanced_testcase {
    /**
     * Setup.
     */
    protected function setUp(): void {
        parent::setUp();
        local_wizard_dependency::require_installed();
        $this->resetAfterTest();
    }

    /**
     * rulequery "2" resolves to rule 2, rulequery "Onboarding" still resolves by name.
     */
    public function test_a_bare_number_in_rulequery_is_the_rule_id(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $first = (int)$generator->create_rule(['name' => 'Datenschutz-Unterweisung 2026']);
        $second = (int)$generator->create_rule(['name' => 'Onboarding Pflichtschulungen']);

        $method = new ReflectionMethod(get_rule_details_skill::class, 'resolve_ruleid');
        $method->setAccessible(true);
        $skill = new get_rule_details_skill();

        $this->assertSame($second, (int)$method->invoke($skill, ['rulequery' => (string)$second]));
        $this->assertSame($second, (int)$method->invoke($skill, ['rulequery' => 'Onboarding']));
        $this->assertSame(
            $first,
            (int)$method->invoke($skill, ['ruleid' => $first, 'rulequery' => (string)$second]),
            'ruleid wins'
        );
    }

    /**
     * messagequery "1" resolves to template 1 with the same answer shape as messageid.
     */
    public function test_a_bare_number_in_messagequery_is_the_template_id(): void {
        global $DB;
        $messageid = (int)$DB->insert_record('local_taskflow_messages', (object)[
            'name' => 'Erinnerung 7 Tage vor Fälligkeit',
            'class' => 'standard',
            'message' => json_encode(['heading' => 'Erinnerung', 'body' => '<p>Bald fällig</p>']),
            'priority' => 1,
            'sending_settings' => '{}',
            'usermodified' => 2,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $bynumber = taskflow_message_resolver::resolve(['messagequery' => (string)$messageid]);
        $this->assertSame(taskflow_message_resolver::STATUS_FOUND, (string)$bynumber['status'], json_encode($bynumber));
        $this->assertSame($messageid, (int)$bynumber['messageid']);

        $unknown = taskflow_message_resolver::resolve(['messagequery' => (string)($messageid + 1000)]);
        $this->assertSame(taskflow_message_resolver::STATUS_NOT_FOUND, (string)$unknown['status']);
        $this->assertStringStartsWith('#', (string)$unknown['query'], 'reported as an id, not as a name');
    }
}
