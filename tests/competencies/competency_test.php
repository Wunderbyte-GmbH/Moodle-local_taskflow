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
use local_taskflow\local\competencies\competency;
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
final class competency_test extends advanced_testcase {
    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\competencies\competency
     */
    public function test_add_load_update_delete(): void {
        global $DB;

        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();

        // Insert dummy user evidence record.
        $evidenceid = $DB->insert_record('competency_userevidence', (object)[
            'userid' => $user->id,
            'name' => 'Dummy evidence',
            'description' => 'Test',
            'descriptionformat' => FORMAT_PLAIN,
            'timecreated' => time(),
            'timemodified' => time(),
            'url' => 'https://example.com',
            'usermodified' => $user->id,
        ]);

        $data = [
            'userid' => $user->id,
            'competencyid' => 99,
            'competencyevidenceid' => $evidenceid,
        ];

        // Test creation.
        $competency = new competency();
        $record = $competency->add_or_update($data);
        $this->assertEquals($data['userid'], $record->userid);

        // Test loading by ID.
        $loaded = new competency($record->id);
        $this->assertEquals($record->competencyid, $loaded->competencyid);

        // Test update.
        $record->competencyid = 100;
        $updated = $competency->add_or_update((array) $record);
        $this->assertEquals(100, $updated->competencyid);

        // Test static check.
        $this->assertTrue(competency::user_has_competency($user->id, 100));

        // Test get with evidence.
        $withevidence = competency::get_with_evidence_by_user_and_competency($user->id, 100);
        $this->assertEquals('Dummy evidence', $withevidence->evidence_name);

        // Test delete.
        $this->assertTrue($competency->delete());
        $this->assertFalse($DB->record_exists('local_taskflow_assignment_competency', ['id' => $record->id]));
    }
}
