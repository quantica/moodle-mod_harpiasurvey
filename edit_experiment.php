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
$experimentid = optional_param('experiment', 0, PARAM_INT);

// If id is not in URL, try to get it from form data (for form submissions).
if (empty($id)) {
    if (isset($_POST['cmid'])) {
        $id = (int)$_POST['cmid'];
    } else if (isset($_POST['form_id'])) {
        $id = (int)$_POST['form_id'];
    } else if ($experimentid) {
        // If we have experiment ID but no course module ID, get it from the experiment.
        // Use a proper SQL join to ensure we get the correct course module.
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

require_login($course, true, $cm);
require_capability('mod/harpiasurvey:manageexperiments', $cm->context);

$context = $cm->context;

// Prepare form data.
$formdata = new stdClass();
$formdata->cmid = $cm->id;
$formdata->harpiasurveyid = $harpiasurvey->id;

// If editing, load experiment data.
if ($experimentid) {
    $experiment = $DB->get_record('harpiasurvey_experiments', ['id' => $experimentid, 'harpiasurveyid' => $harpiasurvey->id], '*', MUST_EXIST);
    $formdata->id = $experiment->id;
    $formdata->name = $experiment->name;
    $formdata->description = $experiment->description;
    $formdata->descriptionformat = $experiment->descriptionformat;
    $formdata->hascrossvalidation = $experiment->hascrossvalidation;
    $formdata->availability = $experiment->availability;
    $formdata->availabilityend = $experiment->availabilityend;
    $formdata->maxparticipants = $experiment->maxparticipants ?? null;
} else {
    $formdata->id = 0;
}

// Set up page.
$url = new moodle_url('/mod/harpiasurvey/edit_experiment.php', ['id' => $cm->id]);
if ($experimentid) {
    $url->param('experiment', $experimentid);
    $pagetitle = get_string('editexperiment', 'mod_harpiasurvey');
} else {
    $pagetitle = get_string('newexperiment', 'mod_harpiasurvey');
}

$PAGE->set_url($url);
$PAGE->set_title($pagetitle);
$PAGE->set_heading($course->fullname);
$PAGE->set_context($context);

// Create form - pass the moodle_url object directly so it preserves parameters.
$form = new \mod_harpiasurvey\forms\experiment($url, $formdata, $context);

// Handle form submission.
if ($form->is_cancelled()) {
    redirect(new moodle_url('/course/view.php', ['id' => $course->id]));
} else if ($data = $form->get_data()) {
    global $DB;

    // Ensure we have a valid session key to prevent resubmission.
    require_sesskey();

    // Prepare experiment data.
    $experimentdata = new stdClass();
    $experimentdata->harpiasurveyid = $harpiasurvey->id;
    $experimentdata->name = $data->name;
    $experimentdata->hascrossvalidation = ($data->validation === 'crossvalidation') ? 1 : 0;
    $experimentdata->navigation_mode = $data->navigation_mode ?? 'free_navigation';
    
    // Handle availability dates.
    if (empty($data->available)) {
        $experimentdata->availability = 0;
        $experimentdata->availabilityend = 0;
    } else if (!empty($data->availabilitystart)) {
        $experimentdata->availability = $data->availabilitystart;
        $experimentdata->availabilityend = !empty($data->availabilityend) ? $data->availabilityend : 0;
    } else {
        // If available checkbox is checked but no start date, use current time.
        $experimentdata->availability = time();
        $experimentdata->availabilityend = !empty($data->availabilityend) ? $data->availabilityend : 0;
    }
    
    // Handle max participants.
    $experimentdata->maxparticipants = !empty($data->maxparticipants) ? (int)$data->maxparticipants : null;
    
    // Set status based on availability and published state.
    // If available is checked, set status to 'running', otherwise 'draft'.
    if ($data->available && $experimentdata->availability > 0) {
        $experimentdata->status = 'running';
        $experimentdata->published = 1;
    } else {
        $experimentdata->status = 'draft';
        $experimentdata->published = 0;
    }
    
    // Preserve participants count and creator if updating.
    if ($data->id) {
        $existing = $DB->get_record('harpiasurvey_experiments', ['id' => $data->id], 'participants, createdby');
        $experimentdata->participants = $existing->participants ?? 0;
        $experimentdata->createdby = $existing->createdby ?? null;
    } else {
        $experimentdata->participants = 0;
        $experimentdata->createdby = $USER->id;
    }
    
    $experimentdata->timemodified = time();

    // Handle description editor.
    $descriptiondata = file_postupdate_standard_editor(
        $data,
        'description',
        $form->get_editor_options(),
        $context,
        'mod_harpiasurvey',
        'description',
        $data->id ?? null
    );
    $experimentdata->description = $descriptiondata->description;
    $experimentdata->descriptionformat = $descriptiondata->descriptionformat;

    if ($data->id) {
        // Update existing experiment.
        $experimentdata->id = $data->id;
        $DB->update_record('harpiasurvey_experiments', $experimentdata);
        $experimentid = $data->id;
    } else {
        // Create new experiment.
        $experimentdata->timecreated = time();
        $experimentid = $DB->insert_record('harpiasurvey_experiments', $experimentdata);
    }

    // Handle model associations.
    if (!empty($data->models) && is_array($data->models)) {
        // Delete existing associations.
        $DB->delete_records('harpiasurvey_experiment_models', ['experimentid' => $experimentid]);

        // Create new associations.
        foreach ($data->models as $modelid) {
            $association = new stdClass();
            $association->experimentid = $experimentid;
            $association->modelid = $modelid;
            $association->timecreated = time();
            $DB->insert_record('harpiasurvey_experiment_models', $association);
        }
    }

    // Purge course cache to refresh the table display.
    rebuild_course_cache($course->id, true);

    // Redirect to course page with section anchor.
    $section = $DB->get_field('course_modules', 'section', ['id' => $cm->id]);
    $redirecturl = new moodle_url('/course/view.php', ['id' => $course->id]);
    if ($section) {
        $redirecturl->set_anchor('section-' . $section);
    }
    redirect($redirecturl, get_string('experimentsaved', 'mod_harpiasurvey'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// Display form.
echo $OUTPUT->header();
echo $OUTPUT->heading($pagetitle);

$form->display();

echo $OUTPUT->footer();
