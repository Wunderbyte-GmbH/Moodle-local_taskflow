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
 * This file contains language strings for the taskflow adapter.
 *
 * @package     taskflowadapter_standard
 * @copyright   2025 Wunderbyte GmbH
 * @author      David Ala
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

$string['apisettings'] = "Standard API Settings";
$string['apisettings_desc'] = "Configure taskflow key-value pairs.";
$string['choose'] = "Choose...";
$string['enter_value'] = 'Enter a suitable JSON key for this setting';
$string['function'] = 'Assign function to userprofilefield: ';
$string['internalid'] = 'Internal ID';
$string['jsonkey'] = 'JSON key for userprofilefield: ';
$string['lessfunctions'] = '<div class="alert alert-danger" role="alert">Not all functions were selected during the last save. This may cause errors.</div>';
$string['manyfunctions'] = '<div class="alert alert-danger" role="alert">Functions were selected multiple times during the last save. This may cause errors.</div>';
$string['mappingdescription'] = 'Taskflow key-value pair explanation';
$string['mappingdescription_desc'] = 'This creates the mapping. The upper field indicates which JSON field is linked to the user profile field. The lower field indicates which function this field represents. Not every user profile field must have a function.';
$string['necessaryuserprofilefields'] = "User profile fields required to be filled in for Taskflow";
$string['necessaryuserprofilefieldsdesc'] = "User profile fields that are not allowed to be empty for the user to be considered in a Taskflow update. If the selected fields are empty, user updates will not be processed in Wunderbyte Taskflow. Leave this setting empty if no fields are required.";
$string['pluginname'] = "Taskflow Adapter Standard API";
$string['set:function'] = 'Select a function';
$string['standard'] = "Standard API";
$string['subplugintype_taskflowadapter_plural'] = 'Taskflow adapter extensions';
