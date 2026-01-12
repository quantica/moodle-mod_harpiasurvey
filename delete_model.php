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

// Course module id.
$id = required_param('id', PARAM_INT);
$modelid = required_param('model', PARAM_INT);
$experimentid = optional_param('experiment', 0, PARAM_INT);

// Get course and course module.
list($course, $cm) = get_course_and_cm_from_cmid($id, 'harpiasurvey');
$harpiasurvey = $DB->get_record('harpiasurvey', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
require_capability('mod/harpiasurvey:manageexperiments', $cm->context);

$context = $cm->context;

// Verify model belongs to this harpiasurvey instance.
$model = $DB->get_record('harpiasurvey_models', [
    'id' => $modelid,
    'harpiasurveyid' => $harpiasurvey->id
], '*', MUST_EXIST);

// Check if model is used in any pages or experiments.
$usedinpages = $DB->count_records('harpiasurvey_page_models', ['modelid' => $modelid]);
$usedinexperiments = $DB->count_records('harpiasurvey_experiment_models', ['modelid' => $modelid]);

if ($usedinpages > 0 || $usedinexperiments > 0) {
    // Model is in use - cannot delete.
    $redirecturl = new moodle_url('/mod/harpiasurvey/view_experiment.php', [
        'id' => $cm->id,
        'experiment' => $experimentid,
        'tab' => 'models'
    ]);
    redirect($redirecturl, get_string('modelinuse', 'mod_harpiasurvey'), null, \core\output\notification::NOTIFY_ERROR);
}

// Confirm deletion.
$confirm = optional_param('confirm', 0, PARAM_INT);
if (!$confirm) {
    // Show confirmation page.
    $PAGE->set_url('/mod/harpiasurvey/delete_model.php', ['id' => $cm->id, 'model' => $modelid, 'experiment' => $experimentid]);
    $PAGE->set_title(get_string('deletemodel', 'mod_harpiasurvey'));
    $PAGE->set_heading($course->fullname);
    $PAGE->set_context($context);
    
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('deletemodel', 'mod_harpiasurvey'));
    
    $confirmurl = new moodle_url('/mod/harpiasurvey/delete_model.php', [
        'id' => $cm->id,
        'model' => $modelid,
        'experiment' => $experimentid,
        'confirm' => 1,
        'sesskey' => sesskey()
    ]);
    $cancelurl = new moodle_url('/mod/harpiasurvey/view_experiment.php', [
        'id' => $cm->id,
        'experiment' => $experimentid,
        'tab' => 'models'
    ]);
    
    echo $OUTPUT->confirm(
        get_string('confirmdeletemodel', 'mod_harpiasurvey', format_string($model->name)),
        $confirmurl,
        $cancelurl
    );
    
    echo $OUTPUT->footer();
    exit;
}

// Confirm deletion - verify sesskey.
require_sesskey();

// Delete the model.
$DB->delete_records('harpiasurvey_models', ['id' => $modelid]);

// Redirect back to models view.
$redirecturl = new moodle_url('/mod/harpiasurvey/view_experiment.php', [
    'id' => $cm->id,
    'experiment' => $experimentid,
    'tab' => 'models'
]);
redirect($redirecturl, get_string('modeldeleted', 'mod_harpiasurvey'), null, \core\output\notification::NOTIFY_SUCCESS);

