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

namespace local_taskflow\wizard\preview;

use advanced_testcase;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\wizard\taskflow\preview\taskflow_preview_renderer_factory;
use local_taskflow\local\wizard\taskflow\preview\taskflow_user_profile_preview_renderer;

/**
 * Rendering of the taskflow_user_profile side-pane card (no engine needed).
 *
 * @package    local_taskflow
 * @covers     \local_taskflow\local\wizard\taskflow\preview\taskflow_user_profile_preview_renderer
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class taskflow_user_profile_preview_renderer_test extends advanced_testcase {
    /**
     * Setup.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Full card for a real user: picture, units, supervisor, deputies, fields, status badges, links.
     */
    public function test_render_full_card(): void {
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Anna', 'lastname' => 'Muster']);
        $assigned = assignment_status_facade::get_status_identifier('assigned');
        $contractend = strtotime('2027-12-31');
        $data = [
            'user' => ['id' => (int)$user->id, 'fullname' => 'Anna Muster', 'email' => 'anna@example.org'],
            'units' => [['id' => 3, 'name' => 'Administration <i>HQ</i>']],
            'supervisor' => ['id' => 9, 'fullname' => 'Dr. Emily Smith', 'email' => 'e@example.org'],
            'deputies' => [['id' => 11, 'fullname' => 'Bert Beispiel', 'email' => 'b@example.org']],
            'contractend' => $contractend,
            'longleave' => false,
            'externalid' => '00123',
            'mapped_fields' => [
                'externalid' => ['function' => 'translator_user_externalid', 'field' => 'externalid', 'value' => '00123',
                    'value_text' => '00123'],
                'contractstart' => ['function' => 'translator_user_contractstart', 'field' => 'contractstart', 'value' => 1,
                    'value_text' => '1 January 1970'],
                'supervisor' => ['function' => 'translator_user_supervisor', 'field' => 'supervisor', 'value' => '9',
                    'value_text' => '9'],
            ],
            'is_supervisor' => true,
            'subordinates_count' => 4,
            'assignments_by_status' => [['status' => $assigned, 'label' => 'x', 'count' => 2]],
            'assignments_total' => 2,
            'adapter' => 'tuines',
            'links' => ['docs' => ['https://example.org/local/taskflow/documentation.php?file=user/units_and_users/README.md']],
        ];
        $block = (new taskflow_user_profile_preview_renderer())->render($data, 'en');

        $this->assertNotNull($block);
        $this->assertSame(taskflow_preview_renderer_factory::TYPE_USER_PROFILE, $block['type']);
        $this->assertSame([(int)$user->id], $block['payload']['userids']);
        $this->assertSame([3], $block['payload']['unitids']);
        $html = $block['html'];

        $this->assertSame(1, substr_count($html, 'taskflow-ai-preview-item'));
        $this->assertStringContainsString('Anna Muster', $html);
        $this->assertStringContainsString('anna@example.org', $html);
        $this->assertStringContainsString('user/profile.php?id=' . $user->id, $html);
        $this->assertStringContainsString('user/profile.php?id=9', $html);
        $this->assertStringContainsString('user/profile.php?id=11', $html);
        $this->assertStringContainsString('HQ', $html);
        $this->assertStringNotContainsString('<i>HQ</i>', $html);
        $this->assertStringContainsString(s(userdate($contractend, get_string('strftimedate', 'langconfig'))), $html);
        $this->assertStringContainsString('00123', $html);
        $this->assertStringContainsString('1 January 1970', $html);
        $this->assertStringContainsString(s(assignment_status_facade::get_specific_names($assigned, 'en')), $html);
        $this->assertStringContainsString('bg-primary', $html);
        $this->assertStringContainsString('visually-hidden', $html);
        $this->assertStringContainsString('mycertificates.php?userid=' . $user->id, $html);
        $this->assertStringContainsString('documentation.php?file=user/units_and_users/README.md', $html);
        $this->assertStringContainsString(get_string('agent_preview_adapter_fields', 'local_taskflow', 'tuines'), $html);
        $this->assertStringContainsString(get_string('yes'), $html);
        $this->assertStringContainsString(get_string('no'), $html);
        $this->assertDebuggingNotCalled();
    }

    /**
     * Missing user id yields null; minimal data renders the empty markers without notices.
     */
    public function test_minimal_and_null(): void {
        $renderer = new taskflow_user_profile_preview_renderer();
        $this->assertNull($renderer->render([]));
        $this->assertNull($renderer->render(['user' => ['fullname' => 'x']]));

        $block = $renderer->render(['user' => ['id' => 123456, 'fullname' => 'Ghost']]);
        $this->assertNotNull($block);
        $this->assertStringContainsString('Ghost', $block['html']);
        $this->assertStringContainsString(get_string('agent_preview_no_supervisor', 'local_taskflow'), $block['html']);
        $this->assertStringContainsString(get_string('agent_preview_none', 'local_taskflow'), $block['html']);
        $this->assertSame([123456], $block['payload']['userids']);
        $this->assertDebuggingNotCalled();
    }
}
