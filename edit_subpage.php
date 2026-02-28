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
$pageid = required_param('page', PARAM_INT);
$subpageid = optional_param('subpage', 0, PARAM_INT);

// If id is not in URL, try to get it from form data (for form submissions).
if (empty($id)) {
    if (isset($_POST['cmid'])) {
        $id = (int)$_POST['cmid'];
    }
}

// Get course and course module.
if (empty($id)) {
    throw new moodle_exception('missingparam', 'error', '', 'id');
}

list($course, $cm) = get_course_and_cm_from_cmid($id, 'harpiasurvey');
$harpiasurvey = $DB->get_record('harpiasurvey', ['id' => $cm->instance], '*', MUST_EXIST);

// Get the parent page and verify it's an aichat page with turns mode.
$page = $DB->get_record('harpiasurvey_pages', ['id' => $pageid], '*', MUST_EXIST);
$experiment = $DB->get_record('harpiasurvey_experiments', ['id' => $page->experimentid, 'harpiasurveyid' => $harpiasurvey->id], '*', MUST_EXIST);

// Verify that subpages are allowed (only for aichat pages with turns mode).
if ($page->type !== 'aichat' || $page->behavior !== 'turns') {
    throw new moodle_exception('subpagesonlyforturns', 'mod_harpiasurvey');
}

require_login($course, true, $cm);
require_capability('mod/harpiasurvey:manageexperiments', $cm->context);

$context = $cm->context;

// Prepare form data.
$formdata = new stdClass();
$formdata->cmid = $cm->id;
$formdata->pageid = $pageid;

// If editing, load subpage data.
if ($subpageid) {
    $subpage = $DB->get_record('harpiasurvey_subpages', ['id' => $subpageid, 'pageid' => $pageid], '*', MUST_EXIST);
    $formdata->id = $subpage->id;
    $formdata->title = $subpage->title;
    $formdata->description = $subpage->description;
    $formdata->descriptionformat = $subpage->descriptionformat;
    $formdata->turn_visibility_type = $subpage->turn_visibility_type;
    $formdata->turn_number = $subpage->turn_number;
    $formdata->available = $subpage->available;
} else {
    $formdata->id = 0;
}

// Set up page.
$url = new moodle_url('/mod/harpiasurvey/edit_subpage.php', ['id' => $cm->id, 'page' => $pageid]);
if ($subpageid) {
    $url->param('subpage', $subpageid);
    $pagetitle = get_string('editsubpage', 'mod_harpiasurvey', format_string($formdata->title ?? ''));
} else {
    $pagetitle = get_string('addsubpage', 'mod_harpiasurvey');
}

$PAGE->set_url($url);
$PAGE->set_title($pagetitle);
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Add breadcrumb.
$PAGE->navbar->add(format_string($harpiasurvey->name), new moodle_url('/mod/harpiasurvey/view.php', ['id' => $cm->id]));
$PAGE->navbar->add(format_string($experiment->name), new moodle_url('/mod/harpiasurvey/view_experiment.php', ['id' => $cm->id, 'experiment' => $experiment->id]));
$PAGE->navbar->add(format_string($page->title), new moodle_url('/mod/harpiasurvey/edit_page.php', ['id' => $cm->id, 'experiment' => $experiment->id, 'page' => $pageid]));
$PAGE->navbar->add($pagetitle);

// Load JavaScript for question bank modal - must be called before header().
if ($subpageid) {
    $PAGE->requires->js_call_amd('mod_harpiasurvey/question_bank_modal', 'init', [
        $cm->id,
        $pageid,
        $PAGE->user_is_editing()
    ]);
}

// Create form.
$form = new \mod_harpiasurvey\forms\subpage($url, $formdata, $context);

// Handle form submission.
if ($form->is_cancelled()) {
    redirect(new moodle_url('/mod/harpiasurvey/edit_page.php', ['id' => $cm->id, 'experiment' => $experiment->id, 'page' => $pageid]));
} else if ($data = $form->get_data()) {
    global $DB;

    // Ensure we have a valid session key to prevent resubmission.
    require_sesskey();

    // Verify we have the required course module ID (check both cmid and form data).
    $formcmid = $data->cmid ?? null;
    if (empty($formcmid) || $formcmid != $cm->id) {
        // Try to get cmid from form_id if cmid is missing.
        $formcmid = $data->form_id ?? null;
        if (empty($formcmid) || $formcmid != $cm->id) {
            throw new moodle_exception('invalidcoursemodule', 'error');
        }
    }

    // Prepare subpage data.
    $subpagedata = new stdClass();
    $subpagedata->pageid = $pageid;
    $subpagedata->title = $data->title;
    $subpagedata->turn_visibility_type = $data->turn_visibility_type;
    $subpagedata->turn_number = ($data->turn_visibility_type === 'specific_turn' && !empty($data->turn_number)) ? (int)$data->turn_number : null;
    $subpagedata->available = isset($data->available) ? (int)$data->available : 1;
    $subpagedata->timemodified = time();

    // Handle description editor.
    $descriptiondata = file_postupdate_standard_editor(
        $data,
        'description',
        $form->get_editor_options(),
        $context,
        'mod_harpiasurvey',
        'subpage',
        $data->id ?? null
    );
    $subpagedata->description = $descriptiondata->description;
    $subpagedata->descriptionformat = $descriptiondata->descriptionformat;

    if ($data->id) {
        // Update existing subpage.
        $subpagedata->id = $data->id;
        $DB->update_record('harpiasurvey_subpages', $subpagedata);
        $subpageid = $data->id;
        
        // Handle question enabled/required states.
        if ((isset($data->question_enabled) && is_array($data->question_enabled)) ||
            (isset($data->question_required) && is_array($data->question_required))) {
            // Get all subpage questions.
            $subpagequestions = $DB->get_records('harpiasurvey_subpage_questions', ['subpageid' => $data->id]);
            
            $postedrequired = [];
            if (isset($data->question_required) && is_array($data->question_required)) {
                $postedrequired = $data->question_required;
            } else if (isset($_POST['question_required']) && is_array($_POST['question_required'])) {
                $postedrequired = $_POST['question_required'];
            }

            foreach ($subpagequestions as $sq) {
                $updated = false;
                if (isset($data->question_enabled) && is_array($data->question_enabled)) {
                    $enabled = isset($data->question_enabled[$sq->id]) ? 1 : 0;
                    if ($sq->enabled != $enabled) {
                        $sq->enabled = $enabled;
                        $updated = true;
                    }
                }
                // Unchecked checkboxes are not submitted by the browser, so a missing array means all are unchecked.
                $required = isset($postedrequired[$sq->id]) ? 1 : 0;
                if (!isset($sq->required) || (int)$sq->required !== $required) {
                    $sq->required = $required;
                    $updated = true;
                }
                if ($updated) {
                    $DB->update_record('harpiasurvey_subpage_questions', $sq);
                }
            }
        }
    } else {
        // Create new subpage.
        // Get the next sort order.
        $maxsort = $DB->get_field_sql(
            "SELECT MAX(sortorder) FROM {harpiasurvey_subpages} WHERE pageid = ?",
            [$pageid]
        );
        $subpagedata->sortorder = ($maxsort !== false) ? $maxsort + 1 : 0;
        $subpagedata->timecreated = time();
        $subpageid = $DB->insert_record('harpiasurvey_subpages', $subpagedata);
    }

    redirect(new moodle_url('/mod/harpiasurvey/edit_page.php', ['id' => $cm->id, 'experiment' => $experiment->id, 'page' => $pageid]));
}

// Display the form.
echo $OUTPUT->header();
echo $OUTPUT->heading($pagetitle);
$form->display();
echo $OUTPUT->footer();
