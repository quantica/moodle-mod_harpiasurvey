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

$id = required_param('id', PARAM_INT);
$pageid = required_param('page', PARAM_INT);
$subpageid = required_param('subpage', PARAM_INT);
$questionid = optional_param('question', 0, PARAM_INT);

list($course, $cm) = get_course_and_cm_from_cmid($id, 'harpiasurvey');
$harpiasurvey = $DB->get_record('harpiasurvey', ['id' => $cm->instance], '*', MUST_EXIST);

$page = $DB->get_record('harpiasurvey_pages', ['id' => $pageid], '*', MUST_EXIST);
$experiment = $DB->get_record('harpiasurvey_experiments', ['id' => $page->experimentid, 'harpiasurveyid' => $harpiasurvey->id], '*', MUST_EXIST);
$subpage = $DB->get_record('harpiasurvey_subpages', ['id' => $subpageid, 'pageid' => $pageid], '*', MUST_EXIST);

require_login($course, true, $cm);
require_capability('mod/harpiasurvey:manageexperiments', $cm->context);

$context = $cm->context;

// If a question ID is provided, add it to the subpage.
if ($questionid) {
    require_sesskey();
    
    // Check if question belongs to this harpiasurvey instance.
    $question = $DB->get_record('harpiasurvey_questions', [
        'id' => $questionid,
        'harpiasurveyid' => $harpiasurvey->id
    ], '*', MUST_EXIST);
    
    // Check if question is already on this subpage.
    $existing = $DB->get_record('harpiasurvey_subpage_questions', [
        'subpageid' => $subpageid,
        'questionid' => $questionid
    ]);
    
    if (!$existing) {
        // Get max sort order.
        $maxsort = $DB->get_field_sql(
            "SELECT MAX(sortorder) FROM {harpiasurvey_subpage_questions} WHERE subpageid = ?",
            [$subpageid]
        );
        
        // Add question to subpage.
        $subpagequestion = new stdClass();
        $subpagequestion->subpageid = $subpageid;
        $subpagequestion->questionid = $questionid;
        $subpagequestion->sortorder = ($maxsort !== false) ? $maxsort + 1 : 0;
        $subpagequestion->timecreated = time();
        $subpagequestion->enabled = 1; // Default to enabled.
        $subpagequestion->required = 1; // Default to required to match existing behavior.
        
        $DB->insert_record('harpiasurvey_subpage_questions', $subpagequestion);
    }
    
    // Redirect back to subpage edit.
    redirect(new moodle_url('/mod/harpiasurvey/edit_subpage.php', [
        'id' => $cm->id,
        'page' => $pageid,
        'subpage' => $subpageid
    ]), get_string('questionaddedtosubpage', 'mod_harpiasurvey'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// Get all questions for this harpiasurvey instance.
$questions = $DB->get_records('harpiasurvey_questions', ['harpiasurveyid' => $harpiasurvey->id], 'name ASC');

// Get questions already on this subpage.
$subpagequestionids = $DB->get_records('harpiasurvey_subpage_questions', ['subpageid' => $subpageid], '', 'questionid');
$subpagequestionids = array_keys($subpagequestionids);

// Filter out questions already on the subpage.
$availablequestions = [];
foreach ($questions as $question) {
    if (!in_array($question->id, $subpagequestionids)) {
        $availablequestions[] = $question;
    }
}

// Set up page.
$url = new moodle_url('/mod/harpiasurvey/add_subpage_question.php', [
    'id' => $cm->id,
    'page' => $pageid,
    'subpage' => $subpageid
]);
$PAGE->set_url($url);
$PAGE->set_title(get_string('addquestiontosubpage', 'mod_harpiasurvey'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Add breadcrumb.
$PAGE->navbar->add(format_string($harpiasurvey->name), new moodle_url('/mod/harpiasurvey/view.php', ['id' => $cm->id]));
$PAGE->navbar->add(format_string($experiment->name), new moodle_url('/mod/harpiasurvey/view_experiment.php', ['id' => $cm->id, 'experiment' => $experiment->id]));
$PAGE->navbar->add(format_string($page->title), new moodle_url('/mod/harpiasurvey/edit_page.php', ['id' => $cm->id, 'experiment' => $experiment->id, 'page' => $pageid]));
$PAGE->navbar->add(format_string($subpage->title), new moodle_url('/mod/harpiasurvey/edit_subpage.php', ['id' => $cm->id, 'page' => $pageid, 'subpage' => $subpageid]));
$PAGE->navbar->add(get_string('addquestiontosubpage', 'mod_harpiasurvey'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('addquestiontosubpage', 'mod_harpiasurvey'));

// Display available questions.
if (empty($availablequestions)) {
    echo $OUTPUT->notification(get_string('noquestionsavailable', 'mod_harpiasurvey'), 'info');
    echo html_writer::link(new moodle_url('/mod/harpiasurvey/edit_question.php', ['id' => $cm->id, 'experiment' => $experiment->id]), get_string('createquestion', 'mod_harpiasurvey'), ['class' => 'btn btn-primary']);
} else {
    echo html_writer::start_tag('table', ['class' => 'table table-hover table-bordered', 'style' => 'width: 100%;']);
    echo html_writer::start_tag('thead', ['class' => 'table-dark']);
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', get_string('question', 'mod_harpiasurvey'), ['scope' => 'col']);
    echo html_writer::tag('th', get_string('type', 'mod_harpiasurvey'), ['scope' => 'col']);
    echo html_writer::tag('th', get_string('description', 'mod_harpiasurvey'), ['scope' => 'col']);
    echo html_writer::tag('th', get_string('actions', 'mod_harpiasurvey'), ['scope' => 'col']);
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');
    
    foreach ($availablequestions as $question) {
        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', format_string($question->name));
        echo html_writer::tag('td', get_string('type' . $question->type, 'mod_harpiasurvey'));
        
        // Description (truncated).
        $description = format_text($question->description, $question->descriptionformat, ['context' => $context]);
        $description = strip_tags($description);
        if (strlen($description) > 100) {
            $description = substr($description, 0, 100) . '...';
        }
        echo html_writer::tag('td', $description);
        
        // Add button.
        $addurl = new moodle_url('/mod/harpiasurvey/add_subpage_question.php', [
            'id' => $cm->id,
            'page' => $pageid,
            'subpage' => $subpageid,
            'question' => $question->id,
            'sesskey' => sesskey()
        ]);
        echo html_writer::tag('td', html_writer::link($addurl, get_string('add', 'mod_harpiasurvey'), ['class' => 'btn btn-sm btn-primary']));
        echo html_writer::end_tag('tr');
    }
    
    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
}

// Back button.
echo html_writer::start_tag('div', ['class' => 'mt-3']);
$backurl = new moodle_url('/mod/harpiasurvey/edit_subpage.php', [
    'id' => $cm->id,
    'page' => $pageid,
    'subpage' => $subpageid
]);
echo html_writer::link($backurl, get_string('back', 'mod_harpiasurvey'), ['class' => 'btn btn-secondary']);
echo html_writer::end_tag('div');

echo $OUTPUT->footer();
