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
 * A salutation glued to a resolved address does not hide the person.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_taskflow\wizard\skills;

use local_taskflow\local\wizard\taskflow\skills\diagnose_user_assignments_skill;
use local_taskflow\wizard\local_wizard_dependency;
use ReflectionMethod;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../local_wizard_dependency.php');

/**
 * Baseline run 25, DUA-3 and 7773: the constructor wrote "Madame wbtf_duval@example.invalid" and "Herr
 * wbtf_kowalczyk@example.invalid" - the re-anchored address with the salutation the user used - and
 * search_user_candidates() looked the whole string up as an e-mail address (#2453, wave 19).
 *
 * @covers \local_taskflow\local\wizard\taskflow\taskflow_skill_base
 */
final class user_query_with_salutation_test extends \advanced_testcase {
    /**
     * Set up the engine dependency.
     */
    protected function setUp(): void {
        local_wizard_dependency::require_installed();
        parent::setUp();
    }

    /**
     * The person behind "Madame <e-mail>" is found, and only that person.
     */
    public function test_the_address_inside_the_query_wins(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['email' => 'wbtf_duval@example.invalid', 'lastname' => 'Duvernay']);
        $this->getDataGenerator()->create_user(['email' => 'other@example.invalid', 'lastname' => 'Duvernay']);

        $method = new ReflectionMethod(diagnose_user_assignments_skill::class, 'search_user_candidates');
        $method->setAccessible(true);
        $candidates = $method->invoke(new diagnose_user_assignments_skill(), 'Madame wbtf_duval@example.invalid');

        $this->assertCount(1, $candidates);
        $this->assertSame((int)$user->id, (int)$candidates[0]['userid']);
    }

    /**
     * Run 31, SVO-3: "Mr Okafor" - the salutation matches nobody, the surname matches one user (wave 26).
     */
    public function test_a_salutation_before_a_unique_name_resolves(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Chidi', 'lastname' => 'Okafor']);
        $this->getDataGenerator()->create_user(['firstname' => 'Chidi', 'lastname' => 'Nwosu']);

        $method = new ReflectionMethod(diagnose_user_assignments_skill::class, 'search_user_candidates');
        $method->setAccessible(true);
        $candidates = $method->invoke(new diagnose_user_assignments_skill(), 'Mr Okafor');

        $this->assertCount(1, $candidates);
        $this->assertSame((int)$user->id, (int)$candidates[0]['userid']);

        $ambiguous = $method->invoke(new diagnose_user_assignments_skill(), 'Herr Chidi');
        $this->assertCount(2, $ambiguous, 'two users share the first name - the skill asks');
    }

    /**
     * Run 43, SVO-3 (thread 13222): the constructor sent "Mr Okafor"; on the baseline site "Mr" is a substring of
     * three other users' names (Mronz, Temry, Helmrich). As substring hits they emptied the token intersection and
     * the supervisor was reported as not found. A token counts only as a whole name word (wave 32).
     */
    public function test_a_salutation_inside_other_names_does_not_hide_the_person(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Chidi', 'lastname' => 'Okafor']);
        $this->getDataGenerator()->create_user(['firstname' => 'Lodewicus', 'lastname' => 'Mronz']);
        $this->getDataGenerator()->create_user(['firstname' => 'Roslindis', 'lastname' => 'Temry']);
        $this->getDataGenerator()->create_user(['firstname' => 'Christian', 'lastname' => 'Helmrich']);

        $method = new ReflectionMethod(diagnose_user_assignments_skill::class, 'search_user_candidates');
        $method->setAccessible(true);
        $candidates = $method->invoke(new diagnose_user_assignments_skill(), 'Mr Okafor');

        $this->assertCount(1, $candidates);
        $this->assertSame((int)$user->id, (int)$candidates[0]['userid']);

        // Non-success path: the whole-word pass never merges two people. Each word is a whole name word of a
        // different user, so the intersection stays empty in both passes and nobody is resolved.
        // ("Mr Nwosu" is no case for this pass: the substring pass before it already offers the users containing "Mr".)
        $this->assertSame([], $method->invoke(new diagnose_user_assignments_skill(), 'Mr Chidi Helmrich'));

        // Case does not matter for the word check, as for the directory search itself.
        $candidates = $method->invoke(new diagnose_user_assignments_skill(), 'MR OKAFOR');
        $this->assertCount(1, $candidates);
        $this->assertSame((int)$user->id, (int)$candidates[0]['userid']);

        // The whole-word pass is only a second pass: a part of a name next to a word nobody carries still resolves
        // through the substring pass, exactly as before wave 32.
        $candidates = $method->invoke(new diagnose_user_assignments_skill(), 'Monsieur Okaf');
        $this->assertCount(1, $candidates);
        $this->assertSame((int)$user->id, (int)$candidates[0]['userid']);
    }
}
