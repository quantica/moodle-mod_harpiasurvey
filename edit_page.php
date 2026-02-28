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
require_once(__DIR__.'/classes/local/review_conversation_importer.php');

// Course module id - can come from URL or form submission.
$id = optional_param('id', 0, PARAM_INT);
$experimentid = required_param('experiment', PARAM_INT);
$pageid = optional_param('page', 0, PARAM_INT);

// If id is not in URL, try to get it from form data (for form submissions).
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

// Prepare form data.
$formdata = new stdClass();
$formdata->cmid = $cm->id;
$formdata->experimentid = $experiment->id;
$formdata->harpiasurveyid = $harpiasurvey->id;

// If editing, load page data.
if ($pageid) {
    $page = $DB->get_record('harpiasurvey_pages', ['id' => $pageid, 'experimentid' => $experiment->id], '*', MUST_EXIST);
    $formdata->id = $page->id;
    $formdata->title = $page->title;
    $formdata->description = $page->description;
    $formdata->descriptionformat = $page->descriptionformat;
    $formdata->evaluationtitle = $page->evaluationtitle ?? '';
    $formdata->evaluationdescription = $page->evaluationdescription ?? '';
    $formdata->type = $page->type;
    $formdata->behavior = $page->behavior ?? 'continuous';
    // Set min_turns and max_turns - use empty string instead of null for form compatibility.
    $formdata->min_turns = isset($page->min_turns) && $page->min_turns !== null ? (int)$page->min_turns : '';
    $formdata->max_turns = isset($page->max_turns) && $page->max_turns !== null ? (int)$page->max_turns : '';
    $formdata->min_qa_questions = isset($page->min_qa_questions) && $page->min_qa_questions !== null ? (int)$page->min_qa_questions : '';
    $formdata->max_qa_questions = isset($page->max_qa_questions) && $page->max_qa_questions !== null ? (int)$page->max_qa_questions : '';
    $formdata->available = $page->available;
} else {
    $formdata->id = 0;
}

// Set up page.
$url = new moodle_url('/mod/harpiasurvey/edit_page.php', ['id' => $cm->id, 'experiment' => $experiment->id]);
if ($pageid) {
    $url->param('page', $pageid);
    $pagetitle = get_string('editingpage', 'mod_harpiasurvey', format_string($formdata->title ?? ''));
} else {
    $pagetitle = get_string('addingpage', 'mod_harpiasurvey');
}

$PAGE->set_url($url);
$PAGE->set_title($pagetitle);
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Load JavaScript for question bank modal - must be called before header().
// Always load it, but it will only work when a page exists (button only shows for existing pages).
$PAGE->requires->js_call_amd('mod_harpiasurvey/question_bank_modal', 'init', [
    $cm->id,
    $pageid ? $pageid : 0,
    $PAGE->user_is_editing()
]);

// Load JavaScript for edit page functionality (evaluates conversation dropdown).
if ($pageid) {
    $PAGE->requires->js_call_amd('mod_harpiasurvey/edit_page', 'init');
}

// Add breadcrumb.
$PAGE->navbar->add(format_string($harpiasurvey->name), new moodle_url('/mod/harpiasurvey/view.php', ['id' => $cm->id]));
$PAGE->navbar->add(format_string($experiment->name), new moodle_url('/mod/harpiasurvey/view_experiment.php', ['id' => $cm->id, 'experiment' => $experiment->id]));
$PAGE->navbar->add($pagetitle);

// Create form - pass the moodle_url object directly so it preserves parameters.
$form = new \mod_harpiasurvey\forms\page($url, $formdata, $context);

// Explicitly set data for all fields to ensure values are populated
// even when fields are conditionally hidden. This must be called after form construction.
// Convert formdata to array format that set_data expects.
$formdataarray = (array)$formdata;
// Ensure min_turns and max_turns are properly formatted as strings.
if (isset($formdataarray['min_turns'])) {
    $formdataarray['min_turns'] = $formdataarray['min_turns'] === '' || $formdataarray['min_turns'] === null ? '' : (string)(int)$formdataarray['min_turns'];
}
if (isset($formdataarray['max_turns'])) {
    $formdataarray['max_turns'] = $formdataarray['max_turns'] === '' || $formdataarray['max_turns'] === null ? '' : (string)(int)$formdataarray['max_turns'];
}
if (isset($formdataarray['min_qa_questions'])) {
    $formdataarray['min_qa_questions'] = $formdataarray['min_qa_questions'] === '' || $formdataarray['min_qa_questions'] === null ? '' : (string)(int)$formdataarray['min_qa_questions'];
}
if (isset($formdataarray['max_qa_questions'])) {
    $formdataarray['max_qa_questions'] = $formdataarray['max_qa_questions'] === '' || $formdataarray['max_qa_questions'] === null ? '' : (string)(int)$formdataarray['max_qa_questions'];
}
$form->set_data($formdataarray);

// Handle form submission.
if ($form->is_cancelled()) {
    redirect(new moodle_url('/mod/harpiasurvey/view_experiment.php', ['id' => $cm->id, 'experiment' => $experiment->id]));
} else if ($data = $form->get_data()) {
    global $DB, $_POST;

    // Ensure we have a valid session key to prevent resubmission.
    require_sesskey();
    
    // HTML input fields added via addElement('html') are not automatically included in $data.
    // We need to extract them from $_POST manually.
    if (isset($_POST['question_show_only_turn']) && is_array($_POST['question_show_only_turn'])) {
        $data->question_show_only_turn = $_POST['question_show_only_turn'];
    }
    if (isset($_POST['question_hide_on_turn']) && is_array($_POST['question_hide_on_turn'])) {
        $data->question_hide_on_turn = $_POST['question_hide_on_turn'];
    }
    if (isset($_POST['question_show_only_model']) && is_array($_POST['question_show_only_model'])) {
        $data->question_show_only_model = $_POST['question_show_only_model'];
    }
    if (isset($_POST['question_hide_on_model']) && is_array($_POST['question_hide_on_model'])) {
        $data->question_hide_on_model = $_POST['question_hide_on_model'];
    }

    // Verify we have the required course module ID.
    if (empty($data->cmid) || $data->cmid != $cm->id) {
        throw new moodle_exception('invalidcoursemodule', 'error');
    }

    // Prepare page data.
    $pagedata = new stdClass();
    $pagedata->experimentid = $experiment->id;
    $pagedata->title = $data->title;
    $pagedata->evaluationtitle = trim((string)($data->evaluationtitle ?? ''));
    $pagedata->evaluationdescription = trim((string)($data->evaluationdescription ?? ''));
    $pagedata->type = $data->type;
    $pagedata->behavior = isset($data->behavior) ? $data->behavior : 'continuous';
    // Handle min_turns and max_turns (only for turns behavior).
    if ($pagedata->behavior === 'turns') {
        $pagedata->min_turns = !empty($data->min_turns) ? (int)$data->min_turns : null;
        $pagedata->max_turns = !empty($data->max_turns) ? (int)$data->max_turns : null;
    } else {
        $pagedata->min_turns = null;
        $pagedata->max_turns = null;
    }
    if ($pagedata->behavior === 'qa') {
        $pagedata->min_qa_questions = !empty($data->min_qa_questions) ? (int)$data->min_qa_questions : null;
        $pagedata->max_qa_questions = !empty($data->max_qa_questions) ? (int)$data->max_qa_questions : null;
    } else {
        $pagedata->min_qa_questions = null;
        $pagedata->max_qa_questions = null;
    }
    $pagedata->available = isset($data->available) ? (int)$data->available : 1;
    $pagedata->timemodified = time();

    // Handle description editor.
    $descriptiondata = file_postupdate_standard_editor(
        $data,
        'description',
        $form->get_editor_options(),
        $context,
        'mod_harpiasurvey',
        'page',
        $data->id ?? null
    );
    $pagedata->description = $descriptiondata->description;
    $pagedata->descriptionformat = $descriptiondata->descriptionformat;

    if ($data->id) {
        // Update existing page.
        $pagedata->id = $data->id;
        $DB->update_record('harpiasurvey_pages', $pagedata);
        $pageid = $data->id;
        
        // Handle question states and turn visibility fields.
        // Get all page questions.
        $pagequestions = $DB->get_records('harpiasurvey_page_questions', ['pageid' => $data->id]);
        
        $postedrequired = [];
        if (isset($data->question_required) && is_array($data->question_required)) {
            $postedrequired = $data->question_required;
        } else if (isset($_POST['question_required']) && is_array($_POST['question_required'])) {
            $postedrequired = $_POST['question_required'];
        }

        foreach ($pagequestions as $pq) {
            $updated = false;
            
            // Page-level question mappings are always enabled.
            if ((int)$pq->enabled !== 1) {
                $pq->enabled = 1;
                $updated = true;
            }

            // Update required state (if checkbox data is provided).
            // Unchecked checkboxes are not submitted by the browser, so a missing array means all are unchecked.
            $required = isset($postedrequired[$pq->id]) ? 1 : 0;
            if (!isset($pq->required) || (int)$pq->required !== $required) {
                $pq->required = $required;
                $updated = true;
            }
            
            // Update show_only_turn field (always check, even if enabled checkbox wasn't touched).
            // HTML fields need to be extracted from $_POST as they're not in $data automatically.
            if (isset($data->question_show_only_turn) && is_array($data->question_show_only_turn)) {
                // Check if this question's field was submitted (even if empty).
                if (array_key_exists($pq->id, $data->question_show_only_turn)) {
                    $showonlyturn = trim($data->question_show_only_turn[$pq->id]);
                    $showonlyturnvalue = !empty($showonlyturn) && $showonlyturn !== '' ? (int)$showonlyturn : null;
                    // Compare with current value (handle null/empty comparison).
                    $currentvalue = isset($pq->show_only_turn) && $pq->show_only_turn !== null ? (int)$pq->show_only_turn : null;
                    if ($currentvalue !== $showonlyturnvalue) {
                        $pq->show_only_turn = $showonlyturnvalue;
                        $updated = true;
                    }
                }
            } else if (isset($_POST['question_show_only_turn']) && is_array($_POST['question_show_only_turn']) && array_key_exists($pq->id, $_POST['question_show_only_turn'])) {
                // Fallback: extract directly from $_POST if not in $data.
                $showonlyturn = trim($_POST['question_show_only_turn'][$pq->id]);
                $showonlyturnvalue = !empty($showonlyturn) && $showonlyturn !== '' ? (int)$showonlyturn : null;
                $currentvalue = isset($pq->show_only_turn) && $pq->show_only_turn !== null ? (int)$pq->show_only_turn : null;
                if ($currentvalue !== $showonlyturnvalue) {
                    $pq->show_only_turn = $showonlyturnvalue;
                    $updated = true;
                }
            }
            
            // Update hide_on_turn field (always check, even if enabled checkbox wasn't touched).
            // HTML fields need to be extracted from $_POST as they're not in $data automatically.
            if (isset($data->question_hide_on_turn) && is_array($data->question_hide_on_turn)) {
                // Check if this question's field was submitted (even if empty).
                if (array_key_exists($pq->id, $data->question_hide_on_turn)) {
                    $hideonturn = trim($data->question_hide_on_turn[$pq->id]);
                    $hideonturnvalue = !empty($hideonturn) && $hideonturn !== '' ? (int)$hideonturn : null;
                    // Compare with current value (handle null/empty comparison).
                    $currentvalue = isset($pq->hide_on_turn) && $pq->hide_on_turn !== null ? (int)$pq->hide_on_turn : null;
                    if ($currentvalue !== $hideonturnvalue) {
                        $pq->hide_on_turn = $hideonturnvalue;
                        $updated = true;
                    }
                }
            } else if (isset($_POST['question_hide_on_turn']) && is_array($_POST['question_hide_on_turn']) && array_key_exists($pq->id, $_POST['question_hide_on_turn'])) {
                // Fallback: extract directly from $_POST if not in $data.
                $hideonturn = trim($_POST['question_hide_on_turn'][$pq->id]);
                $hideonturnvalue = !empty($hideonturn) && $hideonturn !== '' ? (int)$hideonturn : null;
                $currentvalue = isset($pq->hide_on_turn) && $pq->hide_on_turn !== null ? (int)$pq->hide_on_turn : null;
                if ($currentvalue !== $hideonturnvalue) {
                    $pq->hide_on_turn = $hideonturnvalue;
                    $updated = true;
                }
            }
            
            // Update show_only_model field (always check, even if enabled checkbox wasn't touched).
            if (isset($data->question_show_only_model) && is_array($data->question_show_only_model)) {
                if (array_key_exists($pq->id, $data->question_show_only_model)) {
                    $showonlymodel = trim($data->question_show_only_model[$pq->id]);
                    $showonlymodelvalue = !empty($showonlymodel) && $showonlymodel !== '' ? (int)$showonlymodel : null;
                    $currentvalue = isset($pq->show_only_model) && $pq->show_only_model !== null ? (int)$pq->show_only_model : null;
                    if ($currentvalue !== $showonlymodelvalue) {
                        $pq->show_only_model = $showonlymodelvalue;
                        $updated = true;
                    }
                }
            } else if (isset($_POST['question_show_only_model']) && is_array($_POST['question_show_only_model']) && array_key_exists($pq->id, $_POST['question_show_only_model'])) {
                $showonlymodel = trim($_POST['question_show_only_model'][$pq->id]);
                $showonlymodelvalue = !empty($showonlymodel) && $showonlymodel !== '' ? (int)$showonlymodel : null;
                $currentvalue = isset($pq->show_only_model) && $pq->show_only_model !== null ? (int)$pq->show_only_model : null;
                if ($currentvalue !== $showonlymodelvalue) {
                    $pq->show_only_model = $showonlymodelvalue;
                    $updated = true;
                }
            }
            
            // Update hide_on_model field (always check, even if enabled checkbox wasn't touched).
            if (isset($data->question_hide_on_model) && is_array($data->question_hide_on_model)) {
                if (array_key_exists($pq->id, $data->question_hide_on_model)) {
                    $hideonmodel = trim($data->question_hide_on_model[$pq->id]);
                    $hideonmodelvalue = !empty($hideonmodel) && $hideonmodel !== '' ? (int)$hideonmodel : null;
                    $currentvalue = isset($pq->hide_on_model) && $pq->hide_on_model !== null ? (int)$pq->hide_on_model : null;
                    if ($currentvalue !== $hideonmodelvalue) {
                        $pq->hide_on_model = $hideonmodelvalue;
                        $updated = true;
                    }
                }
            } else if (isset($_POST['question_hide_on_model']) && is_array($_POST['question_hide_on_model']) && array_key_exists($pq->id, $_POST['question_hide_on_model'])) {
                $hideonmodel = trim($_POST['question_hide_on_model'][$pq->id]);
                $hideonmodelvalue = !empty($hideonmodel) && $hideonmodel !== '' ? (int)$hideonmodel : null;
                $currentvalue = isset($pq->hide_on_model) && $pq->hide_on_model !== null ? (int)$pq->hide_on_model : null;
                if ($currentvalue !== $hideonmodelvalue) {
                    $pq->hide_on_model = $hideonmodelvalue;
                    $updated = true;
                }
            }
            
            if ($updated) {
                $DB->update_record('harpiasurvey_page_questions', $pq);
            }
        }
    } else {
        // Create new page.
        // Get the next sort order.
        $maxsort = $DB->get_field_sql(
            "SELECT MAX(sortorder) FROM {harpiasurvey_pages} WHERE experimentid = ?",
            [$experiment->id]
        );
        $pagedata->sortorder = ($maxsort !== false) ? $maxsort + 1 : 0;
        $pagedata->timecreated = time();
        $pageid = $DB->insert_record('harpiasurvey_pages', $pagedata);

        // Finalize editor files with the real page id (new records don't have an itemid before insert).
        $descriptiondata = file_postupdate_standard_editor(
            $data,
            'description',
            $form->get_editor_options(),
            $context,
            'mod_harpiasurvey',
            'page',
            $pageid
        );
        $DB->update_record('harpiasurvey_pages', (object)[
            'id' => $pageid,
            'description' => $descriptiondata->description,
            'descriptionformat' => $descriptiondata->descriptionformat
        ]);
    }

    // Handle page-model associations (only for aichat pages with live interaction behaviors).
    if ($pagedata->type === 'aichat' && !in_array($pagedata->behavior, ['multi_model', 'review_conversation'], true)) {
        // Delete existing associations.
        $DB->delete_records('harpiasurvey_page_models', ['pageid' => $pageid]);
        
        // Create new associations if models were selected.
        // Note: pagemodels can be an array or a single value from autocomplete
        $selectedmodelids = [];
        if (isset($data->pagemodels)) {
            if (is_array($data->pagemodels)) {
                $selectedmodelids = $data->pagemodels;
            } else {
                // Single value
                $selectedmodelids = [$data->pagemodels];
            }
        }
        
        // Filter out empty values
        $selectedmodelids = array_filter($selectedmodelids, function($id) {
            return !empty($id) && $id > 0;
        });
        
        if (!empty($selectedmodelids)) {
            foreach ($selectedmodelids as $modelid) {
                $association = new stdClass();
                $association->pageid = $pageid;
                $association->modelid = (int)$modelid;
                $association->timecreated = time();
                $DB->insert_record('harpiasurvey_page_models', $association);
            }
        }
    } else if ($pagedata->type === 'aichat' && $pagedata->behavior === 'review_conversation') {
        // Review conversation pages do not use live model associations.
        $DB->delete_records('harpiasurvey_page_models', ['pageid' => $pageid]);
    }

    $successmessage = get_string('pagesaved', 'mod_harpiasurvey');

    // Handle review-conversation CSV upload/import.
    if ($pagedata->type === 'aichat' && $pagedata->behavior === 'review_conversation') {
        if (isset($data->reviewconversationcsv)) {
            file_save_draft_area_files(
                (int)$data->reviewconversationcsv,
                $context->id,
                'mod_harpiasurvey',
                'reviewcsv',
                $pageid,
                [
                    'subdirs' => 0,
                    'maxfiles' => 1,
                    'maxbytes' => 0,
                    'accepted_types' => ['.csv'],
                ]
            );

            $fs = get_file_storage();
            $files = $fs->get_area_files($context->id, 'mod_harpiasurvey', 'reviewcsv', $pageid, 'id DESC', false);
            if (!empty($files)) {
                $csvfile = reset($files);
                try {
                    $result = \mod_harpiasurvey\local\review_conversation_importer::import_for_page($pageid, $csvfile, $USER->id);
                    $successmessage = get_string('reviewimportsuccess', 'mod_harpiasurvey', [
                        'rows' => $result['rows'],
                        'threads' => $result['threads'],
                    ]);
                } catch (\Exception $e) {
                    $dataset = $DB->get_record('harpiasurvey_review_datasets', ['pageid' => $pageid]);
                    if ($dataset) {
                        $dataset->status = 'error';
                        $dataset->errormessage = $e->getMessage();
                        $dataset->timemodified = time();
                        $DB->update_record('harpiasurvey_review_datasets', $dataset);
                    }
                    redirect(
                        new moodle_url('/mod/harpiasurvey/view_experiment.php', ['id' => $cm->id, 'experiment' => $experiment->id]),
                        get_string('reviewimporterror', 'mod_harpiasurvey', $e->getMessage()),
                        null,
                        \core\output\notification::NOTIFY_ERROR
                    );
                }
            }
        }
    } else {
        // If page is no longer review-conversation, clean dataset/file area for this page.
        if ($DB->get_manager()->table_exists('harpiasurvey_review_targets')) {
            $DB->delete_records('harpiasurvey_review_targets', ['pageid' => $pageid]);
        }
        if ($DB->get_manager()->table_exists('harpiasurvey_review_datasets')) {
            $dataset = $DB->get_record('harpiasurvey_review_datasets', ['pageid' => $pageid]);
            if ($dataset) {
                if ($DB->get_manager()->table_exists('harpiasurvey_review_message_threads')) {
                    $DB->delete_records('harpiasurvey_review_message_threads', ['datasetid' => $dataset->id]);
                }
                if ($DB->get_manager()->table_exists('harpiasurvey_review_messages')) {
                    $DB->delete_records('harpiasurvey_review_messages', ['datasetid' => $dataset->id]);
                }
                if ($DB->get_manager()->table_exists('harpiasurvey_review_threads')) {
                    $DB->delete_records('harpiasurvey_review_threads', ['datasetid' => $dataset->id]);
                }
                $DB->delete_records('harpiasurvey_review_datasets', ['id' => $dataset->id]);
            }
        }
        get_file_storage()->delete_area_files($context->id, 'mod_harpiasurvey', 'reviewcsv', $pageid);
    }

    // Purge course cache to refresh the table display.
    rebuild_course_cache($course->id, true);

    // Redirect back to experiment view.
    redirect(
        new moodle_url('/mod/harpiasurvey/view_experiment.php', ['id' => $cm->id, 'experiment' => $experiment->id]),
        $successmessage,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Display form.
echo $OUTPUT->header();
echo $OUTPUT->heading($pagetitle);

$form->display();

echo $OUTPUT->footer();
