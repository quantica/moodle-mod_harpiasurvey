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

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/enrol/locallib.php');
require_once($CFG->dirroot . '/user/lib.php');

global $DB, $CFG;

$shortname = 'E2E_HARPIA';
$fullname = 'E2E Harpia Survey';
$activityname = 'Harpia Survey E2E';

if (empty($CFG->noreplyaddress) || $CFG->noreplyaddress === 'noreply@localhost') {
    $CFG->noreplyaddress = 'noreply@example.com';
}

function get_or_create_user(string $username, string $email, string $firstname, string $lastname, string $password): stdClass {
    global $DB, $CFG;

    $existing = $DB->get_record('user', ['username' => $username, 'deleted' => 0]);
    if ($existing) {
        return $existing;
    }

    $user = (object)[
        'auth' => 'manual',
        'confirmed' => 1,
        'mnethostid' => $CFG->mnet_localhost_id,
        'username' => $username,
        'password' => $password,
        'email' => $email,
        'firstname' => $firstname,
        'lastname' => $lastname,
        'lang' => 'en',
        'timecreated' => time(),
        'timemodified' => time(),
    ];

    $userid = user_create_user($user, false, false);
    return $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
}

echo "Seeding Harpia Survey E2E data...\n\n";

$course = $DB->get_record('course', ['shortname' => $shortname]);
if (!$course) {
    $categoryid = $DB->get_field('course_categories', 'id', ['id' => 1]);
    if (!$categoryid) {
        throw new moodle_exception('Missing default course category.');
    }

    $course = create_course((object)[
        'fullname' => $fullname,
        'shortname' => $shortname,
        'category' => $categoryid,
        'summary' => 'E2E test course for Harpia Survey.',
        'summaryformat' => FORMAT_HTML,
    ]);
    echo "✓ Created course {$course->id}\n";
} else {
    echo "✓ Reusing course {$course->id}\n";
}

$teacher = get_or_create_user('teacher_e2e', 'teacher_e2e@example.com', 'Teacher', 'E2E', 'Passw0rd!');
$student = get_or_create_user('student_e2e', 'student_e2e@example.com', 'Student', 'E2E', 'Passw0rd!');
$admin = get_admin();

update_internal_user_password($teacher, 'Passw0rd!');
update_internal_user_password($student, 'Passw0rd!');
$adminpass = getenv('E2E_ADMIN_PASS');
if (!empty($adminpass)) {
    update_internal_user_password($admin, $adminpass);
}

$manual = enrol_get_plugin('manual');
$instances = enrol_get_instances($course->id, true);
$manualinstance = null;
foreach ($instances as $instance) {
    if ($instance->enrol === 'manual') {
        $manualinstance = $instance;
        break;
    }
}
if (!$manualinstance) {
    $manualinstanceid = $manual->add_default_instance($course);
    $manualinstance = $DB->get_record('enrol', ['id' => $manualinstanceid], '*', MUST_EXIST);
}

$teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
$studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);

if (!$DB->record_exists('user_enrolments', ['userid' => $teacher->id, 'enrolid' => $manualinstance->id])) {
    $manual->enrol_user($manualinstance, $teacher->id, $teacherroleid);
}
if (!$DB->record_exists('user_enrolments', ['userid' => $student->id, 'enrolid' => $manualinstance->id])) {
    $manual->enrol_user($manualinstance, $student->id, $studentroleid);
}

$harpiasurvey = $DB->get_record('harpiasurvey', ['course' => $course->id, 'name' => $activityname]);
$cm = null;
if (!$harpiasurvey) {
    $module = $DB->get_record('modules', ['name' => 'harpiasurvey'], '*', MUST_EXIST);
    $moduleinfo = (object)[
        'course' => $course->id,
        'section' => 0,
        'module' => $module->id,
        'modulename' => 'harpiasurvey',
        'name' => $activityname,
        'intro' => '',
        'introformat' => FORMAT_HTML,
        'visible' => 1,
    ];
    $moduleinfo = add_moduleinfo($moduleinfo, $course, null);
    $cm = get_coursemodule_from_id('harpiasurvey', $moduleinfo->coursemodule, $course->id, false, MUST_EXIST);
    $harpiasurvey = $DB->get_record('harpiasurvey', ['id' => $cm->instance], '*', MUST_EXIST);
    echo "✓ Created Harpia Survey instance {$harpiasurvey->id}\n";
} else {
    $cm = get_coursemodule_from_instance('harpiasurvey', $harpiasurvey->id, $course->id, false, MUST_EXIST);
    echo "✓ Reusing Harpia Survey instance {$harpiasurvey->id}\n";
}

$now = time();

$modelname = 'E2E Model';
$model = $DB->get_record('harpiasurvey_models', ['harpiasurveyid' => $harpiasurvey->id, 'name' => $modelname]);
if (!$model) {
    $modelid = $DB->insert_record('harpiasurvey_models', (object)[
        'harpiasurveyid' => $harpiasurvey->id,
        'name' => $modelname,
        'model' => 'e2e-model',
        'provider' => 'custom',
        'description' => 'Seeded model for E2E tests.',
        'enabled' => 1,
        'timecreated' => $now,
        'timemodified' => $now,
    ]);
    $model = $DB->get_record('harpiasurvey_models', ['id' => $modelid], '*', MUST_EXIST);
    echo "✓ Created model {$model->id}\n";
} else {
    echo "✓ Reusing model {$model->id}\n";
}

$experimentname = 'E2E Experiment';
$experiment = $DB->get_record('harpiasurvey_experiments', ['harpiasurveyid' => $harpiasurvey->id, 'name' => $experimentname]);
if (!$experiment) {
    $experimentid = $DB->insert_record('harpiasurvey_experiments', (object)[
        'harpiasurveyid' => $harpiasurvey->id,
        'name' => $experimentname,
        'description' => 'Seeded experiment for E2E tests.',
        'descriptionformat' => FORMAT_HTML,
        'createdby' => $admin->id,
        'published' => 1,
        'status' => 'running',
        'navigation_mode' => 'only_forward',
        'timecreated' => $now,
        'timemodified' => $now,
    ]);
    $experiment = $DB->get_record('harpiasurvey_experiments', ['id' => $experimentid], '*', MUST_EXIST);
    echo "✓ Created experiment {$experiment->id}\n";
} else {
    echo "✓ Reusing experiment {$experiment->id}\n";
}

if (!$DB->record_exists('harpiasurvey_experiment_models', [
    'experimentid' => $experiment->id,
    'modelid' => $model->id,
])) {
    $DB->insert_record('harpiasurvey_experiment_models', (object)[
        'experimentid' => $experiment->id,
        'modelid' => $model->id,
        'timecreated' => $now,
    ]);
}

$page = $DB->get_record('harpiasurvey_pages', ['experimentid' => $experiment->id, 'title' => 'Demographics']);
if (!$page) {
    $pageid = $DB->insert_record('harpiasurvey_pages', (object)[
        'experimentid' => $experiment->id,
        'title' => 'Demographics',
        'description' => 'Please answer the questions below.',
        'descriptionformat' => FORMAT_HTML,
        'type' => 'demographic',
        'behavior' => 'continuous',
        'available' => 1,
        'sortorder' => 1,
        'timecreated' => $now,
        'timemodified' => $now,
    ]);
    $page = $DB->get_record('harpiasurvey_pages', ['id' => $pageid], '*', MUST_EXIST);
} else {
    $pageid = $page->id;
}

$question = $DB->get_record('harpiasurvey_questions', ['harpiasurveyid' => $harpiasurvey->id, 'name' => 'Age']);
if (!$question) {
    $questionid = $DB->insert_record('harpiasurvey_questions', (object)[
        'harpiasurveyid' => $harpiasurvey->id,
        'name' => 'Age',
        'shortname' => 'age',
        'description' => 'Your age',
        'descriptionformat' => FORMAT_HTML,
        'type' => 'shorttext',
        'enabled' => 1,
        'timecreated' => $now,
        'timemodified' => $now,
    ]);
    $question = $DB->get_record('harpiasurvey_questions', ['id' => $questionid], '*', MUST_EXIST);
} else {
    $questionid = $question->id;
}

if (!$DB->record_exists('harpiasurvey_page_questions', ['pageid' => $pageid, 'questionid' => $questionid])) {
    $DB->insert_record('harpiasurvey_page_questions', (object)[
        'pageid' => $pageid,
        'questionid' => $questionid,
        'enabled' => 1,
        'required' => 1,
        'sortorder' => 1,
        'timecreated' => $now,
    ]);
}

$aichatpage = $DB->get_record('harpiasurvey_pages', ['experimentid' => $experiment->id, 'title' => 'AI Chat']);
if (!$aichatpage) {
    $aichatpageid = $DB->insert_record('harpiasurvey_pages', (object)[
        'experimentid' => $experiment->id,
        'title' => 'AI Chat',
        'description' => 'Ask the model a question.',
        'descriptionformat' => FORMAT_HTML,
        'type' => 'aichat',
        'behavior' => 'continuous',
        'available' => 1,
        'sortorder' => 2,
        'timecreated' => $now,
        'timemodified' => $now,
    ]);
    $aichatpage = $DB->get_record('harpiasurvey_pages', ['id' => $aichatpageid], '*', MUST_EXIST);
} else {
    $aichatpageid = $aichatpage->id;
}

if (!$DB->record_exists('harpiasurvey_page_models', [
    'pageid' => $aichatpageid,
    'modelid' => $model->id,
])) {
    $DB->insert_record('harpiasurvey_page_models', (object)[
        'pageid' => $aichatpageid,
        'modelid' => $model->id,
        'timecreated' => $now,
    ]);
}

$seeddata = [
    'courseid' => $course->id,
    'cmid' => $cm->id,
    'instanceid' => $harpiasurvey->id,
    'experimentid' => $experiment->id,
    'modelid' => $model->id,
    'pageid' => $pageid,
    'questionid' => $questionid,
    'aichatpageid' => $aichatpageid,
    'teacherid' => $teacher->id,
    'studentid' => $student->id,
];

$outputpath = __DIR__ . '/seed_e2e.json';
file_put_contents($outputpath, json_encode($seeddata, JSON_PRETTY_PRINT));

echo "✓ Seed data written to {$outputpath}\n";
echo "\nDone.\n";
