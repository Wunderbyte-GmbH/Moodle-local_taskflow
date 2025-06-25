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

namespace local_taskflow\competencies;

use advanced_testcase;
use local_taskflow\local\competencies\assignment_competency;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/user/profile/lib.php');

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class assignment_competency_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\competencies\assignment_competency
     */
    public function test_add_or_update_creates_record(): void {
        global $DB;

        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();

        $data = [
            'userid' => $user->id,
            'competencyid' => 1234,
            'competencyevidenceid' => 5678,
        ];

        $record = new assignment_competency();
        $result = $record->add_or_update($data);

        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertEquals($user->id, $result->userid);
        $this->assertEquals(1234, $result->competencyid);

        $exists = $DB->record_exists('local_taskflow_assignment_competency', ['id' => $result->id]);
        $this->assertTrue($exists);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\competencies\assignment_competency
     */
    public function test_user_has_competency_returns_true(): void {
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();

        $data = [
            'userid' => $user->id,
            'competencyid' => 5678,
            'competencyevidenceid' => 9012,
        ];

        $record = new assignment_competency();
        $record->add_or_update($data);

        $this->assertTrue(assignment_competency::user_has_competency($user->id, 5678));
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\competencies\assignment_competency
     */
    public function test_get_with_evidence_by_user_and_competency_returns_data(): void {
        global $DB;
        $this->setAdminUser();

        $user = $this->getDataGenerator()->create_user();

        // Insert user evidence.
        $evidence = (object) [
            'userid' => $user->id,
            'name' => 'Test Evidence',
            'description' => 'Some description',
            'descriptionformat' => FORMAT_HTML,
            'url' => 'https://example.com',
            'timecreated' => time(),
            'timemodified' => time(),
            'usermodified' => $user->id,
        ];
        $evidenceid = $DB->insert_record('competency_userevidence', $evidence);

        $data = [
            'userid' => $user->id,
            'competencyid' => 9999,
            'competencyevidenceid' => $evidenceid,
        ];

        $record = new assignment_competency();
        $record->add_or_update($data);

        $fetched = assignment_competency::get_with_evidence_by_user_and_competency($user->id, 9999);

        $this->assertNotEmpty($fetched);
        $this->assertEquals($user->id, $fetched->userid);
        $this->assertEquals('Test Evidence', $fetched->evidence_name);
    }
}
