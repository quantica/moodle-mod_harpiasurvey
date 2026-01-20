<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * The main harpiasurvey view page.
 *
 * @package     mod_harpiasurvey
 * @copyright   2025 Your Name
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/lib.php');

// Course module id.
$id = required_param('id', PARAM_INT);

list($course, $cm) = get_course_and_cm_from_cmid($id, 'harpiasurvey');
$harpiasurvey = $DB->get_record('harpiasurvey', ['id' => $cm->instance], '*', MUST_EXIST);

require_course_login($course, true, $cm);
require_capability('mod/harpiasurvey:view', $cm->context);

// Trigger course_module_viewed event.
$params = [
    'context' => $cm->context,
    'objectid' => $harpiasurvey->id,
];

$event = \mod_harpiasurvey\event\course_module_viewed::create($params);
$event->add_record_snapshot('course_modules', $cm);
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('harpiasurvey', $harpiasurvey);
$event->trigger();

// Completion.
$completion = new completion_info($course);
$completion->set_module_viewed($cm);

// Print the page header.
$PAGE->set_url('/mod/harpiasurvey/view.php', ['id' => $cm->id]);
$PAGE->set_title($course->shortname.': '.$harpiasurvey->name);
$PAGE->set_heading($course->fullname);
$PAGE->set_activity_record($harpiasurvey);

// Load module stylesheet.
$PAGE->requires->css('/mod/harpiasurvey/styles.css');

echo $OUTPUT->header();

// Print the main part of the page.
echo $OUTPUT->heading($harpiasurvey->name);

// Query experiments for this harpiasurvey instance.
$experiments = $DB->get_records('harpiasurvey_experiments', ['harpiasurveyid' => $harpiasurvey->id], 'name ASC');

// Check if user can manage experiments.
$canmanage = has_capability('mod/harpiasurvey:manageexperiments', $cm->context);

// For non-managers, only show available experiments.
if (!$canmanage) {
    $now = time();
    $experiments = array_filter($experiments, function($experiment) use ($now) {
        if (!empty($experiment->availability) && $experiment->availability > 0) {
            if ($experiment->availability > $now) {
                return false;
            }
            if (!empty($experiment->availabilityend) && $experiment->availabilityend > 0 && $experiment->availabilityend < $now) {
                return false;
            }
            return true;
        }
        return !empty($experiment->published);
    });
}

// Create and render experiments table using Mustache template.
require_once(__DIR__.'/classes/output/experiments_table.php');
$experimentstable = new \mod_harpiasurvey\output\experiments_table($experiments, $cm->context, $cm->id, $canmanage);
$renderer = $PAGE->get_renderer('mod_harpiasurvey');
$tablehtml = $renderer->render_experiments_table($experimentstable);

// Display the experiments table.
echo $tablehtml;

// Load JavaScript for sortable table (works for both view.php and course page).
$PAGE->requires->js_call_amd('mod_harpiasurvey/sortable_table', 'init');

echo $OUTPUT->footer();
