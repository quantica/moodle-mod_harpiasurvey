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
// IMPORTANT: We MUST check 'cmid' and 'form_id' FIRST before 'id' because the form has a hidden 'id' field
// for the model ID, which would conflict with the course module ID parameter.
// When the form is cancelled, optional_param('id') might read the model ID (2) from POST instead of the course module ID (5).

// First, try to get cmid from POST (for form submissions/cancellations).
$id = 0;
if (isset($_POST['cmid'])) {
    $id = (int)$_POST['cmid'];
} else if (isset($_POST['form_id'])) {
    $id = (int)$_POST['form_id'];
}

// If we don't have it from POST, try GET (URL parameters).
// IMPORTANT: Only use optional_param('id') if we haven't already gotten it from POST,
// because POST['id'] might be the model ID, not the course module ID.
if (empty($id)) {
    $id = optional_param('id', 0, PARAM_INT);
}

$modelid = optional_param('model', 0, PARAM_INT);
$experimentid = optional_param('experiment', 0, PARAM_INT);

// Get course and course module.
if (empty($id)) {
    throw new moodle_exception('missingparam', 'error', '', 'id');
}

list($course, $cm) = get_course_and_cm_from_cmid($id, 'harpiasurvey');
$harpiasurvey = $DB->get_record('harpiasurvey', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
require_capability('mod/harpiasurvey:manageexperiments', $cm->context);

$context = $cm->context;

// Prepare form data.
$formdata = new stdClass();
$formdata->cmid = $cm->id;
$formdata->harpiasurveyid = $harpiasurvey->id;
if ($experimentid) {
    $formdata->experimentid = $experimentid;
}

// If editing, load model data.
if ($modelid) {
    $model = $DB->get_record('harpiasurvey_models', ['id' => $modelid, 'harpiasurveyid' => $harpiasurvey->id], '*', MUST_EXIST);
    $formdata->id = $model->id;
    $formdata->name = $model->name;
    $formdata->model = $model->model ?? '';
    $formdata->endpoint = $model->endpoint;
    $formdata->apikey = $model->apikey;
    $formdata->extrafields = $model->extrafields ?? '';
    $formdata->enabled = isset($model->enabled) ? $model->enabled : 1;
} else {
    $formdata->id = 0;
    $formdata->model = '';
    $formdata->extrafields = '';
    $formdata->enabled = 1;
}

// Set up page.
$url = new moodle_url('/mod/harpiasurvey/edit_model.php', ['id' => $cm->id]);
if ($modelid) {
    $url->param('model', $modelid);
    $pagetitle = get_string('editmodel', 'mod_harpiasurvey');
} else {
    $pagetitle = get_string('newmodel', 'mod_harpiasurvey');
}
if ($experimentid) {
    $url->param('experiment', $experimentid);
}

$PAGE->set_url($url);
$PAGE->set_title($pagetitle);
$PAGE->set_heading($course->fullname);
$PAGE->set_context($context);

// Create form - pass the moodle_url object directly so it preserves parameters.
$form = new \mod_harpiasurvey\forms\model($url, $formdata, $context);

// Handle form submission.
if ($form->is_cancelled()) {
    // Get submitted data even when cancelled to extract hidden fields.
    $submitteddata = $form->get_submitted_data();
    
    // Get experiment ID from form data or URL (for cancellations).
    if (!$experimentid) {
        $experimentid = optional_param('experiment', 0, PARAM_INT);
        if (!$experimentid && $submitteddata && isset($submitteddata->experiment)) {
            $experimentid = (int)$submitteddata->experiment;
        } else if (!$experimentid && isset($_POST['experiment'])) {
            $experimentid = (int)$_POST['experiment'];
        } else if (!$experimentid && $cancelexperimentid) {
            $experimentid = $cancelexperimentid;
        }
    }
    
    // Use the cm->id we already have (from earlier in the script) for redirect.
    // Redirect back to models view if experiment ID is provided, otherwise to course page.
    if ($experimentid) {
        redirect(new moodle_url('/mod/harpiasurvey/view_experiment.php', [
            'id' => $cm->id,
            'experiment' => $experimentid,
            'tab' => 'models'
        ]));
    } else {
        redirect(new moodle_url('/course/view.php', ['id' => $course->id]));
    }
} else if ($data = $form->get_data()) {
    global $DB;

    // Ensure we have a valid session key to prevent resubmission.
    require_sesskey();

    // Verify we have the required course module ID.
    if (empty($data->cmid) || $data->cmid != $cm->id) {
        throw new moodle_exception('invalidcoursemodule', 'error');
    }

    // Prepare model data.
    $modeldata = new stdClass();
    $modeldata->harpiasurveyid = $harpiasurvey->id;
    $modeldata->name = $data->name;
    $modeldata->model = $data->model;
    $modeldata->endpoint = $data->endpoint;
    $modeldata->apikey = $data->apikey;
    $modeldata->extrafields = $data->extrafields ?? '';
    $modeldata->enabled = isset($data->enabled) ? (int)$data->enabled : 1;
    $modeldata->timemodified = time();

    if ($data->id) {
        // Update existing model.
        $modeldata->id = $data->id;
        $DB->update_record('harpiasurvey_models', $modeldata);
        $modelid = $data->id;
    } else {
        // Create new model.
        $modeldata->timecreated = time();
        $modelid = $DB->insert_record('harpiasurvey_models', $modeldata);
    }

    // Purge course cache to refresh the table display.
    rebuild_course_cache($course->id, true);

    // Redirect back to models view if experiment ID is provided, otherwise to course page.
    $experimentid = optional_param('experiment', 0, PARAM_INT);
    if ($experimentid) {
        $redirecturl = new moodle_url('/mod/harpiasurvey/view_experiment.php', [
            'id' => $cm->id,
            'experiment' => $experimentid,
            'tab' => 'models'
        ]);
    } else {
        // Redirect to course page with section anchor.
        $section = $DB->get_field('course_modules', 'section', ['id' => $cm->id]);
        $redirecturl = new moodle_url('/course/view.php', ['id' => $course->id]);
        if ($section) {
            $redirecturl->set_anchor('section-' . $section);
        }
    }
    redirect($redirecturl, get_string('modelsaved', 'mod_harpiasurvey'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// Display form.
echo $OUTPUT->header();
echo $OUTPUT->heading($pagetitle);

$form->display();

echo $OUTPUT->footer();

