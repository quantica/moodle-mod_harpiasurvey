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
$experiment = $DB->get_record('harpiasurvey_experiments', ['id' => $experimentid, 'harpiasurveyid' => $harpiasurvey->id], '*', MUST_EXIST);

require_login($course, true, $cm);

$context = $cm->context;

// Check if user is admin (can manage experiments).
$canmanage = has_capability('mod/harpiasurvey:manageexperiments', $context);
if (!$canmanage) {
    // Students need view capability.
    require_capability('mod/harpiasurvey:view', $context);
}

// Determine navigation override (admins or experiment owners).
$isowner = !empty($experiment->createdby) && ((int)$experiment->createdby === (int)$USER->id);
$cannavigate = $canmanage || $isowner;

// Get pages for this experiment.
// For students, only get available pages.
if ($canmanage) {
    $pages = $DB->get_records('harpiasurvey_pages', ['experimentid' => $experiment->id], 'sortorder ASC, timecreated ASC');
} else {
    $pages = $DB->get_records('harpiasurvey_pages', ['experimentid' => $experiment->id, 'available' => 1], 'sortorder ASC, timecreated ASC');
}

// Set up page.
$url = new moodle_url('/mod/harpiasurvey/view_experiment.php', ['id' => $cm->id, 'experiment' => $experiment->id]);
$PAGE->set_url($url);
$PAGE->set_title(format_string($experiment->name));
$PAGE->set_context($context);

// Load module stylesheet.
$PAGE->requires->css('/mod/harpiasurvey/styles.css');

// Hide the module intro/description in the activity header.
$PAGE->activityheader->set_attrs(['description' => '']);

// Check which tab we're viewing (pages or models).
$tab = optional_param('tab', 'pages', PARAM_ALPHA);

// Check if we're editing a page - if so, load JavaScript for question bank modal.
$pageid = optional_param('page', 0, PARAM_INT);
$edit = optional_param('edit', 0, PARAM_INT);
$turn = optional_param('turn', null, PARAM_INT);
if ($pageid && $edit) {
    $PAGE->requires->js_call_amd('mod_harpiasurvey/question_bank_modal', 'init', [
        $cm->id,
        $pageid,
        $PAGE->user_is_editing()
    ]);
}

// Enforce only-forward navigation for non-owners/non-admins.
$navigation_mode = $experiment->navigation_mode ?? 'free_navigation';
if (!$cannavigate && $navigation_mode === 'only_forward' && !empty($pages)) {
    $pagesarray = array_values($pages);
    $pageindex = [];
    foreach ($pagesarray as $index => $page) {
        $pageindex[$page->id] = $index;
    }

    $maxindex = -1;
    $pageids = array_keys($pageindex);
    if (!empty($pageids)) {
        list($insql, $inparams) = $DB->get_in_or_equal($pageids, SQL_PARAMS_NAMED);
        $params = $inparams + ['userid' => $USER->id];
        $responsepageids = $DB->get_fieldset_sql(
            "SELECT DISTINCT pageid FROM {harpiasurvey_responses} WHERE userid = :userid AND pageid $insql",
            $params
        );
        $conversationpageids = $DB->get_fieldset_sql(
            "SELECT DISTINCT pageid FROM {harpiasurvey_conversations} WHERE userid = :userid AND pageid $insql",
            $params
        );
        $seenpageids = array_unique(array_merge($responsepageids, $conversationpageids));
        foreach ($seenpageids as $seenpageid) {
            if (isset($pageindex[$seenpageid])) {
                $maxindex = max($maxindex, $pageindex[$seenpageid]);
            }
        }
    }

    $maxallowedindex = max(0, $maxindex + 1);
    $requestedindex = ($pageid && isset($pageindex[$pageid])) ? $pageindex[$pageid] : $maxallowedindex;
    $targetindex = null;
    if (!$pageid) {
        $targetindex = $maxallowedindex;
    } else if ($requestedindex < $maxindex) {
        $targetindex = $maxindex;
    } else if ($requestedindex > $maxallowedindex) {
        $targetindex = $maxallowedindex;
    }

    if ($targetindex !== null && isset($pagesarray[$targetindex])) {
        redirect(new moodle_url('/mod/harpiasurvey/view_experiment.php', [
            'id' => $cm->id,
            'experiment' => $experiment->id,
            'page' => $pagesarray[$targetindex]->id
        ]));
    }
}

// Add breadcrumb.
$PAGE->navbar->add(format_string($harpiasurvey->name), new moodle_url('/mod/harpiasurvey/view.php', ['id' => $cm->id]));
$PAGE->navbar->add(format_string($experiment->name));

echo $OUTPUT->header();

// Display experiment header.
$headerlabel = ($tab === 'models') ? get_string('models', 'mod_harpiasurvey') : get_string('pages', 'mod_harpiasurvey');
if ($canmanage) {
    echo $OUTPUT->heading(format_string($experiment->name) . ': ' . $headerlabel, 3);
} else {
    echo $OUTPUT->heading(format_string($experiment->name), 3);
}

// Note: pageid and edit were already retrieved above for JavaScript loading.
$viewingpage = null;
$formhtml = '';
$pageviewhtml = '';

// For students: auto-redirect to first available page if no page is selected.
if (!$canmanage && !$pageid && !empty($pages)) {
    $firstpage = reset($pages);
    redirect(new moodle_url('/mod/harpiasurvey/view_experiment.php', [
        'id' => $cm->id,
        'experiment' => $experiment->id,
        'page' => $firstpage->id
    ]));
}

if ($pageid) {
    // For students, verify the page is available.
    $conditions = ['id' => $pageid, 'experimentid' => $experiment->id];
    if (!$canmanage) {
        $conditions['available'] = 1;
    }
    $viewingpage = $DB->get_record('harpiasurvey_pages', $conditions, '*', MUST_EXIST);
    
    // Only allow editing if user can manage experiments.
    if ($edit && $canmanage) {
        // Capture form output.
        ob_start();
        require_once(__DIR__.'/classes/forms/page.php');
        $formdata = new stdClass();
        $formdata->cmid = $cm->id;
        $formdata->experimentid = $experiment->id;
        $formdata->harpiasurveyid = $harpiasurvey->id;
        $formdata->id = $viewingpage->id;
        $formdata->title = $viewingpage->title;
        $formdata->description = $viewingpage->description;
        $formdata->descriptionformat = $viewingpage->descriptionformat;
        $formdata->type = $viewingpage->type;
        $formdata->behavior = $viewingpage->behavior ?? 'continuous';
        // Set min_turns and max_turns - use empty string instead of null for form compatibility.
        $formdata->min_turns = isset($viewingpage->min_turns) && $viewingpage->min_turns !== null ? (int)$viewingpage->min_turns : '';
        $formdata->max_turns = isset($viewingpage->max_turns) && $viewingpage->max_turns !== null ? (int)$viewingpage->max_turns : '';
        $formdata->available = $viewingpage->available;
        
        $formurl = new moodle_url('/mod/harpiasurvey/edit_page.php', ['id' => $cm->id, 'experiment' => $experiment->id, 'page' => $pageid]);
        $form = new \mod_harpiasurvey\forms\page($formurl, $formdata, $context);
        
        // Explicitly set data for all fields to ensure values are populated
        // even when fields are conditionally hidden. This must be called after form construction.
        $formdataarray = (array)$formdata;
        // Ensure min_turns and max_turns are properly formatted as strings.
        if (isset($formdataarray['min_turns'])) {
            $formdataarray['min_turns'] = $formdataarray['min_turns'] === '' || $formdataarray['min_turns'] === null ? '' : (string)(int)$formdataarray['min_turns'];
        }
        if (isset($formdataarray['max_turns'])) {
            $formdataarray['max_turns'] = $formdataarray['max_turns'] === '' || $formdataarray['max_turns'] === null ? '' : (string)(int)$formdataarray['max_turns'];
        }
        $form->set_data($formdataarray);
        
        $form->display();
        $formhtml = ob_get_clean();
    } else {
        // Render page view.
        require_once(__DIR__.'/classes/output/page_view.php');
        
        // Get questions for this page (only enabled ones will be shown).
        // Get questions for this page (all question types are allowed on all page types now).
        $sql = "SELECT pq.*, q.id AS questionid, q.name, q.type, q.description, q.descriptionformat, q.settings,
                       pq.min_turn, pq.show_only_turn, pq.hide_on_turn, pq.show_only_model, pq.hide_on_model
                  FROM {harpiasurvey_page_questions} pq
                  JOIN {harpiasurvey_questions} q ON q.id = pq.questionid
                 WHERE pq.pageid = :pageid AND pq.enabled = 1 AND q.enabled = 1
              ORDER BY pq.sortorder ASC";
        
        $allpagequestions = $DB->get_records_sql($sql, ['pageid' => $pageid]);
        
        // For turns-based pages, we don't filter questions here because:
        // 1. Questions with turn visibility rules should be excluded from regular list (handled in page_view.php)
        // 2. All questions need to be available for turn evaluation questions JSON (JavaScript will filter them)
        // For non-turns pages, show all questions.
        $pagequestions = $allpagequestions;
        
        if ($canmanage) {
            $editurl = new moodle_url('/mod/harpiasurvey/view_experiment.php', ['id' => $cm->id, 'experiment' => $experiment->id, 'page' => $pageid, 'edit' => 1]);
            $editurl = $editurl->out(false);
            $deleteurl = new moodle_url('/mod/harpiasurvey/delete_page.php', ['id' => $cm->id, 'experiment' => $experiment->id, 'page' => $pageid]);
            $deleteurl = $deleteurl->out(false);
        } else {
            $editurl = '';
            $deleteurl = '';
        }
        $pageview = new \mod_harpiasurvey\output\page_view(
            $viewingpage,
            $context,
            $editurl,
            $pagequestions,
            $cm->id,
            $pageid,
            $pages,
            $experiment->id,
            $canmanage,
            $cannavigate,
            $deleteurl,
            $navigation_mode,
            $turn
        );
        $renderer = $PAGE->get_renderer('mod_harpiasurvey');
        $pageviewhtml = $renderer->render($pageview);
        
        // Load JavaScript for saving responses (only in view mode, not edit mode).
        $PAGE->requires->js_call_amd('mod_harpiasurvey/save_response', 'init', [
            $cm->id,
            $pageid
        ]);
        
        // Load JavaScript for AI conversation (only in view mode, not edit mode).
        if ($viewingpage->type === 'aichat') {
            // Use the main init function which detects and initializes the appropriate module
            $PAGE->requires->js_call_amd('mod_harpiasurvey/ai_conversation', 'init');
        }
        
        // Load JavaScript for navigation control (only in view mode, not edit mode, and only for non-admins).
        if (!$canmanage) {
            $PAGE->requires->js_call_amd('mod_harpiasurvey/navigation_control', 'init', [$cannavigate]);
        }
    }
}

// Get models for this experiment if viewing models tab.
$modelshtml = '';
if ($tab === 'models') {
    // Get all models for this harpiasurvey instance (not just those associated with the experiment).
    // This allows admins to see and manage all models, and associate them with experiments.
    $models = $DB->get_records('harpiasurvey_models', [
        'harpiasurveyid' => $harpiasurvey->id
    ], 'name ASC');
    
    // Render models view.
    require_once(__DIR__.'/classes/output/models_view.php');
    $modelsview = new \mod_harpiasurvey\output\models_view($models, $context, $cm->id, $experiment->id, $canmanage);
    $renderer = $PAGE->get_renderer('mod_harpiasurvey');
    $modelshtml = $renderer->render($modelsview);
}

// Create and render experiment view.
require_once(__DIR__.'/classes/output/experiment_view.php');
$experimentview = new \mod_harpiasurvey\output\experiment_view(
    $experiment,
    $pages,
    $context,
    $cm->id,
    $experiment->id,
    $viewingpage,
    $edit && $canmanage, // Only allow editing if user can manage.
    $formhtml,
    $pageviewhtml,
    $canmanage,
    $cannavigate,
    $navigation_mode,
    $tab,
    $modelshtml
);
$renderer = $PAGE->get_renderer('mod_harpiasurvey');
echo $renderer->render_experiment_view($experimentview);

$PAGE->requires->js_call_amd('mod_harpiasurvey/notification_modal', 'init');

echo $OUTPUT->footer();
