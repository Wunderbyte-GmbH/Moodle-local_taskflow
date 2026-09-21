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

namespace local_taskflow\local\wizard\taskflow;

use ReflectionClass;

/**
 * Step stand-in that lets agent skills reuse the rule form transformation without building forms.
 *
 * local_taskflow\form\rules\types\unit_rule::get_data() — the single place where a multistep-form
 * submission becomes the ruledata/rulejson document — instantiates the class named in each step's
 * 'formclass' with `new $classname()`. For the real step classes that constructor is the moodleform
 * constructor, which runs definition() and therefore local_multistepform\manager::definition() with an
 * empty form-data array; that emits PHP warnings ("Undefined array key uniqueid/step") outside of a
 * real form request and would fail every PHPUnit run (phpunit.xml sets failOnWarning).
 *
 * Skills therefore declare this class as 'formclass' and name the real step class in 'delegate'.
 * Both methods forward to that very class (created without its constructor, which none of the
 * transformation methods needs), so the produced rulejson is byte-for-byte what the form produces —
 * no transformation logic is reimplemented here.
 *
 * @package    local_taskflow
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class taskflow_rule_form_step {
    /** Step key holding the real form class this step stands in for. */
    public const DELEGATE_KEY = 'delegate';

    /**
     * Step 1: turn all steps into the ruledata array (delegates to form\rules\rule).
     *
     * @param array $steps
     * @return array
     */
    public function get_data_to_persist(array $steps): array {
        $delegate = $this->delegate((array)($steps[1] ?? []));
        return (array)$delegate->get_data_to_persist($steps);
    }

    /**
     * Steps 2..n: write this step's data into the rule document (delegates to the real step class).
     *
     * @param array $step
     * @param array $rulejson
     * @return void
     */
    public function set_data_to_persist(array &$step, &$rulejson): void {
        $delegate = $this->delegate($step);
        $delegate->set_data_to_persist($step, $rulejson);
    }

    /**
     * Instantiate the real step class named in the step, bypassing the moodleform constructor.
     *
     * @param array $step
     * @return object
     * @throws \coding_exception When the step does not name an existing delegate class.
     */
    private function delegate(array $step): object {
        $classname = trim((string)($step[self::DELEGATE_KEY] ?? ''));
        if ($classname === '' || !class_exists($classname)) {
            throw new \coding_exception('taskflow_rule_form_step: unknown delegate form class ' . $classname);
        }
        return (new ReflectionClass($classname))->newInstanceWithoutConstructor();
    }
}
