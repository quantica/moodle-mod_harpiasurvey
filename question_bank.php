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

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/lib.php');

// Course module id - can come from URL or form submission.
$id = optional_param('id', 0, PARAM_INT);
$experimentid = required_param('experiment', PARAM_INT);

// If id is not in URL, try to get it from form data.
if (empty($id)) {
    if (isset($_POST['cmid'])) {
        $id = (int)$_POST['cmid'];
    } else if (isset($_POST['form_id'])) {
        $id = (int)$_POST['form_id'];
    } else if ($experimentid) {
        // If we have experiment ID but no course module ID, get it from the experiment.
        $sql = "SELECT cm.id
                  FROM {course_modules} cm
                  JOIN {modules} md ON md.id = cm.module
                  JOIN {harpiasurvey} h ON h.id = cm.instance
                  JOIN {harpiasurvey_experiments} he ON he.harpiasurveyid = h.id
                 WHERE he.id = :experimentid
                   AND md.name = 'harpiasurvey'";
        $cmid = $DB->get_field_sql($sql, ['experimentid' => $experimentid], MUST_EXIST);
        $id = $cmid;
    }
}

// Get course and course module.
if (empty($id)) {
    throw new moodle_exception('missingparam', 'error', '', 'id');
}

// Validate that the course module is actually a harpiasurvey module before proceeding.
$moduleid = $DB->get_field('modules', 'id', ['name' => 'harpiasurvey'], MUST_EXIST);
$cmcheck = $DB->get_record('course_modules', [
    'id' => $id,
    'module' => $moduleid
], 'id, instance', IGNORE_MISSING);

if (!$cmcheck) {
    // If the course module ID doesn't match harpiasurvey, try to get it from experiment.
    if ($experimentid) {
        $sql = "SELECT cm.id
                  FROM {course_modules} cm
                  JOIN {modules} md ON md.id = cm.module
                  JOIN {harpiasurvey} h ON h.id = cm.instance
                  JOIN {harpiasurvey_experiments} he ON he.harpiasurveyid = h.id
                 WHERE he.id = :experimentid
                   AND md.name = 'harpiasurvey'";
        $cmid = $DB->get_field_sql($sql, ['experimentid' => $experimentid], IGNORE_MISSING);
        if ($cmid) {
            $id = $cmid;
        } else {
            throw new moodle_exception('invalidcoursemoduleid', 'error', '', $id);
        }
    } else {
        throw new moodle_exception('invalidcoursemoduleid', 'error', '', $id);
    }
}

list($course, $cm) = get_course_and_cm_from_cmid($id, 'harpiasurvey');
$harpiasurvey = $DB->get_record('harpiasurvey', ['id' => $cm->instance], '*', MUST_EXIST);
$experiment = $DB->get_record('harpiasurvey_experiments', ['id' => $experimentid, 'harpiasurveyid' => $harpiasurvey->id], '*', MUST_EXIST);

require_login($course, true, $cm);
require_capability('mod/harpiasurvey:manageexperiments', $cm->context);

$context = $cm->context;

// Get questions for this harpiasurvey instance.
$questions = $DB->get_records('harpiasurvey_questions', ['harpiasurveyid' => $harpiasurvey->id], 'name ASC');

// Set up page.
$url = new moodle_url('/mod/harpiasurvey/question_bank.php', ['id' => $cm->id, 'experiment' => $experiment->id]);
$PAGE->set_url($url);
$PAGE->set_title(get_string('questionbank', 'mod_harpiasurvey'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Load module stylesheet.
$PAGE->requires->css('/mod/harpiasurvey/styles.css');

// Hide the module intro/description in the activity header.
$PAGE->activityheader->set_attrs(['description' => '']);

// Add breadcrumb.
$PAGE->navbar->add(format_string($harpiasurvey->name), new moodle_url('/mod/harpiasurvey/view.php', ['id' => $cm->id]));
$PAGE->navbar->add(format_string($experiment->name), new moodle_url('/mod/harpiasurvey/view_experiment.php', ['id' => $cm->id, 'experiment' => $experiment->id]));
$PAGE->navbar->add(get_string('questionbank', 'mod_harpiasurvey'));

echo $OUTPUT->header();

// Display header.
echo $OUTPUT->heading(format_string($experiment->name) . ': ' . get_string('questionbank', 'mod_harpiasurvey'), 3);

// Create and render question bank.
require_once(__DIR__.'/classes/output/question_bank.php');
$questionbank = new \mod_harpiasurvey\output\question_bank($questions, $context, $cm->id, $experiment->id);
$renderer = $PAGE->get_renderer('mod_harpiasurvey');
echo $renderer->render_question_bank($questionbank);

$PAGE->requires->js_call_amd('mod_harpiasurvey/notification_modal', 'init');

echo $OUTPUT->footer();
