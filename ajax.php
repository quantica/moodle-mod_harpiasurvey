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

define('AJAX_SCRIPT', true);

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/lib.php');

$action = required_param('action', PARAM_ALPHANUMEXT);
$cmid = required_param('cmid', PARAM_INT);

// For subpage actions, pageid might come as a parameter, but it's required for most actions.
// We'll handle it conditionally below.
// For most actions, pageid is required. For subpage actions, we'll get it from the subpage.
if (!in_array($action, ['get_available_subpage_questions', 'add_question_to_subpage'])) {
    $pageid = required_param('pageid', PARAM_INT);
} else {
    $pageid = optional_param('pageid', 0, PARAM_INT);
}

list($course, $cm) = get_course_and_cm_from_cmid($cmid, 'harpiasurvey');
require_login($course, true, $cm);

// Different capabilities for different actions.
if (in_array($action, ['add_question_to_page', 'get_available_questions', 'add_question_to_subpage', 'get_available_subpage_questions'])) {
    require_capability('mod/harpiasurvey:manageexperiments', $cm->context);
} else if (in_array($action, ['save_response', 'send_ai_message', 'get_conversation_history', 'get_turn_responses', 'get_turn_questions',
                              'get_conversation_tree', 'create_branch', 'create_root', 'export_conversation'])) {
    // Students can save their own responses and interact with AI.
    require_capability('mod/harpiasurvey:view', $cm->context);
}

// Verify sesskey for write operations.
if (in_array($action, ['add_question_to_page', 'add_question_to_subpage', 'save_response', 'send_ai_message', 'create_branch', 'create_root', 'export_conversation'])) {
    require_sesskey();
}

$harpiasurvey = $DB->get_record('harpiasurvey', ['id' => $cm->instance], '*', MUST_EXIST);

// For most actions, pageid is required and we need to load the page.
if (!in_array($action, ['get_available_subpage_questions', 'add_question_to_subpage'])) {
    $page = $DB->get_record('harpiasurvey_pages', ['id' => $pageid], '*', MUST_EXIST);
} else {
    // For subpage actions, pageid will be required in the case statement.
    // We'll validate it there.
}

header('Content-Type: application/json');

/**
 * Get conversation history for a turn, considering branch tree structure.
 * 
 * For a root turn: returns only messages from that turn.
 * For a branch turn: returns messages from root up to parent_turn_id (inclusive) + messages from the branch.
 * 
 * @param object $DB Database object
 * @param int $pageid Page ID
 * @param int $userid User ID
 * @param int $turn_id Turn ID to get history for
 * @param int|null $modelid Optional model ID to scope history (for multi-model turns)
 * @return array Array of history messages with 'role' and 'content'
 */
function get_turn_history($DB, $pageid, $userid, $turn_id, $modelid = null) {
    // Check if this turn is a branch (has a parent).
    $branch = $DB->get_record('harpiasurvey_turn_branches', [
        'pageid' => $pageid,
        'userid' => $userid,
        'child_turn_id' => $turn_id
    ]);
    
    $turn_ids_to_include = [];
    
    if ($branch) {
        // This is a branch. We need to get all turns from root to parent (inclusive) + this branch.
        $parent_turn_id = $branch->parent_turn_id;
        
        // Find the root turn by traversing up the tree.
        $current_parent = $parent_turn_id;
        $path_to_root = [$parent_turn_id];
        
        // Traverse up to find the root.
        while ($current_parent) {
            $parent_branch = $DB->get_record('harpiasurvey_turn_branches', [
                'pageid' => $pageid,
                'userid' => $userid,
                'child_turn_id' => $current_parent
            ]);
            
            if ($parent_branch) {
                $current_parent = $parent_branch->parent_turn_id;
                if ($current_parent) {
                    array_unshift($path_to_root, $current_parent);
                }
            } else {
                // This is the root.
                break;
            }
        }
        
        // Include all turns from root to parent (inclusive) + the branch turn.
        $turn_ids_to_include = $path_to_root;
        $turn_ids_to_include[] = $turn_id;
    } else {
        // This is a root turn. Only include messages from this turn.
        $turn_ids_to_include = [$turn_id];
    }
    
    // Get all messages from the included turns, ordered by time.
    if (empty($turn_ids_to_include)) {
        return [];
    }
    
    list($insql, $inparams) = $DB->get_in_or_equal($turn_ids_to_include, SQL_PARAMS_NAMED);
    $params = array_merge(['pageid' => $pageid, 'userid' => $userid], $inparams);
    $modelsql = '';
    if ($modelid !== null) {
        $modelsql = ' AND modelid = :modelid';
        $params['modelid'] = $modelid;
    }
    
    $historyrecords = $DB->get_records_sql(
        "SELECT * FROM {harpiasurvey_conversations} 
         WHERE pageid = :pageid AND userid = :userid AND turn_id $insql {$modelsql}
         ORDER BY turn_id ASC, timecreated ASC",
        $params
    );
    
    $history = [];
    foreach ($historyrecords as $record) {
        // Skip placeholder messages created when a new root conversation is started.
        if (trim($record->content) === '[New conversation - send a message to start]') {
            continue;
        }
        
        $history[] = [
            'role' => $record->role,
            'content' => $record->content
        ];
    }
    
    return $history;
}

switch ($action) {
    case 'get_available_questions':
        // Get page type to filter questions appropriately
        $page_type = $page->type ?? '';
        
        // Get all questions for this harpiasurvey instance.
        $questions = $DB->get_records('harpiasurvey_questions', ['harpiasurveyid' => $harpiasurvey->id], 'name ASC');
        
        // Get questions already on this page.
        $pagequestionids = $DB->get_records('harpiasurvey_page_questions', ['pageid' => $pageid], '', 'questionid');
        $pagequestionids = array_keys($pagequestionids);
        
        // Filter out questions already on the page.
        $availablequestions = [];
        foreach ($questions as $question) {
            if (!in_array($question->id, $pagequestionids)) {
                
                $description = format_text($question->description, $question->descriptionformat, [
                    'context' => $cm->context,
                    'noclean' => false
                ]);
                $description = strip_tags($description);
                if (strlen($description) > 100) {
                    $description = substr($description, 0, 100) . '...';
                }
                
                $availablequestions[] = [
                    'id' => $question->id,
                    'name' => format_string($question->name),
                    'type' => $question->type,
                    'typedisplay' => get_string('type' . $question->type, 'mod_harpiasurvey'),
                    'description' => $description,
                ];
            }
        }
        
        echo json_encode([
            'success' => true,
            'questions' => $availablequestions,
            'is_aichat_page' => ($page_type === 'aichat')
        ]);
        break;
        
    case 'add_question_to_page':
        require_sesskey();
        
        $questionid = required_param('questionid', PARAM_INT);
        
        // Check if question belongs to this harpiasurvey instance.
        $question = $DB->get_record('harpiasurvey_questions', [
            'id' => $questionid,
            'harpiasurveyid' => $harpiasurvey->id
        ], '*', MUST_EXIST);

        if ($question->type === 'number' && $response !== null && $response !== '') {
            $normalizedresponse = str_replace(',', '.', trim((string)$response));
            if (!is_numeric($normalizedresponse)) {
                echo json_encode([
                    'success' => false,
                    'message' => get_string('invalidnumber', 'mod_harpiasurvey')
                ]);
                break;
            }
            $response = $normalizedresponse;
        }
        
        // Check if question is already on this page.
        $existing = $DB->get_record('harpiasurvey_page_questions', [
            'pageid' => $pageid,
            'questionid' => $questionid
        ]);
        
        if ($existing) {
            echo json_encode([
                'success' => false,
                'message' => get_string('questionalreadyonpage', 'mod_harpiasurvey')
            ]);
            break;
        }
        
        // Get max sort order.
        $maxsort = $DB->get_field_sql(
            "SELECT MAX(sortorder) FROM {harpiasurvey_page_questions} WHERE pageid = ?",
            [$pageid]
        );
        
        // Get page to check if it's a turns mode page.
        if (!$page || $page->id != $pageid) {
            $page = $DB->get_record('harpiasurvey_pages', ['id' => $pageid], '*', MUST_EXIST);
        }
        
        // Add question to page.
        $pagequestion = new stdClass();
        $pagequestion->pageid = $pageid;
        $pagequestion->questionid = $questionid;
        $pagequestion->sortorder = ($maxsort !== false) ? $maxsort + 1 : 0;
        $pagequestion->timecreated = time();
        $pagequestion->enabled = 1;
        
        // For aichat pages with turns mode, set min_turn = 1 by default.
        // All questions on aichat pages evaluate the page's chat conversation.
        if ($page->type === 'aichat' && ($page->behavior ?? 'continuous') === 'turns') {
            $pagequestion->min_turn = 1; // Default to appearing from turn 1.
        }
        
        $DB->insert_record('harpiasurvey_page_questions', $pagequestion);
        
        echo json_encode([
            'success' => true,
            'message' => get_string('questionaddedtopage', 'mod_harpiasurvey')
        ]);
        break;
        
    case 'save_response':
        require_sesskey();
        
        $questionid = required_param('questionid', PARAM_INT);
        $response = optional_param('response', '', PARAM_RAW);
        // Get turn_id - can be 0 or positive integer, or null for regular questions.
        $turn_id_param = optional_param('turn_id', null, PARAM_RAW);
        $turn_id = null;
        if ($turn_id_param !== null && $turn_id_param !== '' && $turn_id_param !== 'null') {
            $turn_id = (int)$turn_id_param;
            // Only accept positive integers (turn IDs start at 1).
            if ($turn_id <= 0) {
                $turn_id = null;
            }
        }
        
        // Debug: Log turn_id for troubleshooting.
        debugging("save_response: turn_id_param = " . var_export($turn_id_param, true) . ", turn_id = " . var_export($turn_id, true) . " for questionid = {$questionid}, pageid = {$pageid}", DEBUG_NORMAL);
        
        // Verify page belongs to this harpiasurvey instance.
        if (!$page || $page->id != $pageid) {
            $page = $DB->get_record('harpiasurvey_pages', ['id' => $pageid], '*', MUST_EXIST);
        }
        $experiment = $DB->get_record('harpiasurvey_experiments', ['id' => $page->experimentid], '*', MUST_EXIST);
        if ($experiment->harpiasurveyid != $harpiasurvey->id) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid page'
            ]);
            break;
        }

        // For turns mode, every evaluation response must be explicitly tied to a turn.
        if ($page->type === 'aichat' && ($page->behavior ?? '') === 'turns') {
            if ($turn_id === null) {
                echo json_encode([
                    'success' => false,
                    'message' => get_string('turnevaluationrequiresturn', 'mod_harpiasurvey')
                ]);
                break;
            }

            $turnexists = $DB->record_exists('harpiasurvey_conversations', [
                'pageid' => $pageid,
                'userid' => $USER->id,
                'turn_id' => $turn_id
            ]) || $DB->record_exists('harpiasurvey_turn_branches', [
                'pageid' => $pageid,
                'userid' => $USER->id,
                'child_turn_id' => $turn_id
            ]);

            if (!$turnexists) {
                echo json_encode([
                    'success' => false,
                    'message' => get_string('invalidturnevaluationtarget', 'mod_harpiasurvey')
                ]);
                break;
            }
        }
        
        // Verify question belongs to this harpiasurvey instance.
        $question = $DB->get_record('harpiasurvey_questions', [
            'id' => $questionid,
            'harpiasurveyid' => $harpiasurvey->id
        ], '*', MUST_EXIST);
        
        // Check if response already exists.
        // Use SQL to properly handle NULL values for turn_id.
        if ($turn_id !== null) {
            // For turn evaluation questions, search with specific turn_id.
            $existing = $DB->get_record('harpiasurvey_responses', [
                'pageid' => $pageid,
                'questionid' => $questionid,
                'userid' => $USER->id,
                'turn_id' => $turn_id
            ]);
        } else {
            // For regular questions, turn_id must be NULL.
            $existing = $DB->get_record_sql(
                "SELECT * FROM {harpiasurvey_responses} 
                 WHERE pageid = ? AND questionid = ? AND userid = ? AND turn_id IS NULL",
                [$pageid, $questionid, $USER->id]
            );
        }
        
        if ($existing) {
            // Update existing response.
            $existing->response = $response;
            $existing->timemodified = time();
            // Ensure turn_id is preserved on update.
            if ($turn_id !== null) {
                $existing->turn_id = $turn_id;
            }
            $DB->update_record('harpiasurvey_responses', $existing);

            // Log history entry.
            if ($DB->get_manager()->table_exists('harpiasurvey_response_history')) {
                $history = new stdClass();
                $history->responseid = $existing->id;
                $history->pageid = $pageid;
                $history->questionid = $questionid;
                $history->userid = $USER->id;
                $history->response = $response;
                $history->turn_id = $turn_id;
                $history->timecreated = time();
                $DB->insert_record('harpiasurvey_response_history', $history);
            }
        } else {
            // Create new response.
            $newresponse = new stdClass();
            $newresponse->pageid = $pageid;
            $newresponse->questionid = $questionid;
            $newresponse->userid = $USER->id;
            $newresponse->response = $response;
            // Explicitly set turn_id - use null for regular questions, integer for turn evaluations.
            if ($turn_id !== null) {
                $newresponse->turn_id = $turn_id;
            } else {
                $newresponse->turn_id = null;
            }
            $newresponse->timecreated = time();
            $newresponse->timemodified = time();
            $insertedid = $DB->insert_record('harpiasurvey_responses', $newresponse);

            // Log history entry.
            if ($DB->get_manager()->table_exists('harpiasurvey_response_history')) {
                $history = new stdClass();
                $history->responseid = $insertedid;
                $history->pageid = $pageid;
                $history->questionid = $questionid;
                $history->userid = $USER->id;
                $history->response = $response;
                $history->turn_id = $turn_id;
                $history->timecreated = time();
                $DB->insert_record('harpiasurvey_response_history', $history);
            }
            
            // Debug: Log if turn_id is being saved correctly.
            $verify = $DB->get_record('harpiasurvey_responses', ['id' => $insertedid], 'id, turn_id, pageid, questionid, userid');
            debugging("save_response: After insert - id = {$insertedid}, turn_id = " . var_export($verify->turn_id ?? null, true) . ", expected = " . var_export($turn_id, true), DEBUG_NORMAL);
            if ($turn_id !== null && $verify && $verify->turn_id != $turn_id) {
                // Log error but don't fail - this is just for debugging.
                debugging("Warning: turn_id mismatch after insert. Expected: {$turn_id}, Got: " . var_export($verify->turn_id, true), DEBUG_NORMAL);
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => get_string('responsesaved', 'mod_harpiasurvey'),
            'turn_id' => $turn_id // Return turn_id for confirmation.
        ]);
        break;

    case 'get_turn_responses':
        require_sesskey();

        $turn_id = required_param('turn_id', PARAM_INT);

        // Verify page belongs to this harpiasurvey instance.
        if (!$page || $page->id != $pageid) {
            $page = $DB->get_record('harpiasurvey_pages', ['id' => $pageid], '*', MUST_EXIST);
        }
        $experiment = $DB->get_record('harpiasurvey_experiments', ['id' => $page->experimentid], '*', MUST_EXIST);
        if ($experiment->harpiasurveyid != $harpiasurvey->id) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid page'
            ]);
            break;
        }

        $questionrecords = $DB->get_records_sql(
            "SELECT q.id, q.type
               FROM {harpiasurvey_page_questions} pq
               JOIN {harpiasurvey_questions} q ON q.id = pq.questionid
              WHERE pq.pageid = :pageid AND pq.enabled = 1 AND q.enabled = 1",
            ['pageid' => $pageid]
        );
        $questiontypes = [];
        foreach ($questionrecords as $q) {
            $questiontypes[$q->id] = $q->type;
        }

        $optionrecords = $DB->get_records_sql(
            "SELECT questionid, id, value
               FROM {harpiasurvey_question_options}
              WHERE questionid IN (
                    SELECT questionid FROM {harpiasurvey_page_questions} WHERE pageid = :pageid
              )
              ORDER BY questionid ASC, sortorder ASC",
            ['pageid' => $pageid]
        );
        $optionsmap = [];
        foreach ($optionrecords as $opt) {
            if (!isset($optionsmap[$opt->questionid])) {
                $optionsmap[$opt->questionid] = [];
            }
            $optionsmap[$opt->questionid][(string)$opt->id] = format_string($opt->value);
        }

        $format_response = function($questiontype, $responsevalue, $map) {
            if ($responsevalue === null) {
                return '-';
            }
            $value = is_string($responsevalue) ? trim($responsevalue) : $responsevalue;
            if ($value === '') {
                return '-';
            }
            if ($questiontype === 'multiplechoice') {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $labels = [];
                    foreach ($decoded as $optionid) {
                        if (isset($map[(string)$optionid])) {
                            $labels[] = $map[(string)$optionid];
                        } else {
                            $labels[] = '[' . $optionid . ']';
                        }
                    }
                    return !empty($labels) ? implode(', ', $labels) : '-';
                }
                return $value;
            }
            if (in_array($questiontype, ['singlechoice', 'select', 'likert'])) {
                if (isset($map[(string)$value])) {
                    return $map[(string)$value];
                }
                return $value;
            }
            return $value;
        };

        // Get all responses for this turn.
        $responses = $DB->get_records('harpiasurvey_responses', [
            'pageid' => $pageid,
            'userid' => $USER->id,
            'turn_id' => $turn_id
        ], 'questionid ASC', 'questionid, response, timemodified');

        $historyrecords = [];
        if ($DB->get_manager()->table_exists('harpiasurvey_response_history')) {
            $historyrecords = $DB->get_records_sql(
                "SELECT id, questionid, response, timecreated
                   FROM {harpiasurvey_response_history}
                  WHERE pageid = :pageid AND userid = :userid AND turn_id = :turn_id
               ORDER BY timecreated ASC",
                ['pageid' => $pageid, 'userid' => $USER->id, 'turn_id' => $turn_id]
            );
        }
        $historybyquestion = [];
        foreach ($historyrecords as $record) {
            if (!isset($historybyquestion[$record->questionid])) {
                $historybyquestion[$record->questionid] = [];
            }
            $historybyquestion[$record->questionid][] = $record;
        }

        $responsesArray = [];
        foreach ($responses as $response) {
            $questionid = $response->questionid;
            $qtype = $questiontypes[$questionid] ?? null;
            $qmap = $optionsmap[$questionid] ?? [];

            $historyitems = [];
            if (isset($historybyquestion[$questionid])) {
                foreach ($historybyquestion[$questionid] as $historyitem) {
                    $historyitems[] = [
                        'time' => userdate($historyitem->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
                        'response' => $format_response($qtype, $historyitem->response, $qmap),
                    ];
                }
            }
            $historycount = count($historyitems);
            $latesthistory = $historycount > 0 ? $historybyquestion[$questionid][$historycount - 1]->timecreated : null;
            $savedtimestamp = $latesthistory ?? $response->timemodified;

            $responsesArray[$questionid] = [
                'response' => $response->response,
                'timemodified' => $response->timemodified,
                'saved_datetime' => $savedtimestamp ? userdate($savedtimestamp, get_string('strftimedatetimeshort', 'langconfig')) : null,
                'history_count' => $historycount,
                'history_items' => array_reverse($historyitems)
            ];
        }
        
        echo json_encode([
            'success' => true,
            'responses' => $responsesArray
        ]);
        break;

    case 'get_turn_questions':
        require_sesskey();

        $turn_id = required_param('turn_id', PARAM_INT);

        if (!$page || $page->id != $pageid) {
            $page = $DB->get_record('harpiasurvey_pages', ['id' => $pageid], '*', MUST_EXIST);
        }
        $experiment = $DB->get_record('harpiasurvey_experiments', ['id' => $page->experimentid], '*', MUST_EXIST);
        if ($experiment->harpiasurveyid != $harpiasurvey->id) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid page'
            ]);
            break;
        }

        $questions = $DB->get_records_sql(
            "SELECT pq.id, pq.questionid, pq.enabled, pq.required, pq.min_turn, pq.show_only_turn, pq.hide_on_turn,
                    q.name, q.type, q.description, q.descriptionformat, q.settings
               FROM {harpiasurvey_page_questions} pq
               JOIN {harpiasurvey_questions} q ON q.id = pq.questionid
              WHERE pq.pageid = :pageid AND pq.enabled = 1 AND q.enabled = 1
           ORDER BY pq.sortorder ASC",
            ['pageid' => $pageid]
        );

        $historybyquestion = [];
        if ($DB->get_manager()->table_exists('harpiasurvey_response_history')) {
            $historyrecords = $DB->get_records_sql(
                "SELECT id, questionid, response, timecreated
                   FROM {harpiasurvey_response_history}
                  WHERE pageid = :pageid AND userid = :userid AND turn_id = :turn_id
               ORDER BY timecreated ASC",
                ['pageid' => $pageid, 'userid' => $USER->id, 'turn_id' => $turn_id]
            );
            foreach ($historyrecords as $record) {
                if (!isset($historybyquestion[$record->questionid])) {
                    $historybyquestion[$record->questionid] = [];
                }
                $historybyquestion[$record->questionid][] = $record;
            }
        }

        $responses = $DB->get_records('harpiasurvey_responses', [
            'pageid' => $pageid,
            'userid' => $USER->id,
            'turn_id' => $turn_id
        ], 'questionid ASC', 'questionid, response, timemodified');
        $responsesbyquestion = [];
        foreach ($responses as $response) {
            $responsesbyquestion[$response->questionid] = $response;
        }

        $format_response = function($questiontype, $responsevalue, $map) {
            if ($responsevalue === null) {
                return '-';
            }
            $value = is_string($responsevalue) ? trim($responsevalue) : $responsevalue;
            if ($value === '') {
                return '-';
            }
            if ($questiontype === 'multiplechoice') {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $labels = [];
                    foreach ($decoded as $optionid) {
                        if (isset($map[(string)$optionid])) {
                            $labels[] = $map[(string)$optionid];
                        } else {
                            $labels[] = '[' . $optionid . ']';
                        }
                    }
                    return !empty($labels) ? implode(', ', $labels) : '-';
                }
                return $value;
            }
            if (in_array($questiontype, ['singlechoice', 'select', 'likert'])) {
                if (isset($map[(string)$value])) {
                    return $map[(string)$value];
                }
                return $value;
            }
            return $value;
        };

        $behavior = $page->behavior ?? 'continuous';
        $questionslist = [];
        foreach ($questions as $question) {
            $minturn = isset($question->min_turn) && $question->min_turn !== null ? (int)$question->min_turn : 1;
            $showonlyturn = isset($question->show_only_turn) && $question->show_only_turn !== null ? (int)$question->show_only_turn : null;
            $hideonturn = isset($question->hide_on_turn) && $question->hide_on_turn !== null ? (int)$question->hide_on_turn : null;

            if ($behavior !== 'qa') {
                if ($showonlyturn !== null && $showonlyturn !== (int)$turn_id) {
                    continue;
                }
                if ($hideonturn !== null && $hideonturn === (int)$turn_id) {
                    continue;
                }
                if ($minturn > (int)$turn_id) {
                    continue;
                }
            }

            $settings = [];
            if (!empty($question->settings)) {
                $settings = json_decode($question->settings, true) ?? [];
            }

            $options = [];
            $optionsmap = [];
            $inputtype = 'radio';
            $ismultiplechoice = false;
            if (in_array($question->type, ['singlechoice', 'multiplechoice', 'select', 'likert'])) {
                $inputtype = ($question->type === 'singlechoice' || $question->type === 'select' || $question->type === 'likert') ? 'radio' : 'checkbox';
                $ismultiplechoice = ($question->type === 'multiplechoice');
                if ($question->type === 'likert') {
                    for ($i = 1; $i <= 5; $i++) {
                        $options[] = [
                            'id' => $i,
                            'value' => (string)$i,
                            'isdefault' => false,
                            'inputtype' => 'radio',
                            'questionid' => $question->questionid,
                            'nameattr' => 'question_' . $question->questionid . '_turn',
                        ];
                        $optionsmap[(string)$i] = (string)$i;
                    }
                } else {
                    $questionoptions = $DB->get_records('harpiasurvey_question_options', [
                        'questionid' => $question->questionid
                    ], 'sortorder ASC');
                    $nameattr = 'question_' . $question->questionid . '_turn';
                    if ($ismultiplechoice) {
                        $nameattr .= '[]';
                    }
                    foreach ($questionoptions as $opt) {
                        $options[] = [
                            'id' => $opt->id,
                            'value' => format_string($opt->value),
                            'isdefault' => (bool)$opt->isdefault,
                            'inputtype' => $inputtype,
                            'questionid' => $question->questionid,
                            'nameattr' => $nameattr,
                        ];
                        $optionsmap[(string)$opt->id] = format_string($opt->value);
                    }
                }
            }

            $numbersettings = [];
            if ($question->type === 'number') {
                $min = $settings['min'] ?? null;
                $max = $settings['max'] ?? null;
                $allownegatives = !empty($settings['allownegatives']);
                if (!$allownegatives && $min === null) {
                    $min = 0;
                }
                $numbersettings = [
                    'has_min' => $min !== null,
                    'min_value' => $min,
                    'has_max' => $max !== null,
                    'max_value' => $max,
                    'default' => $settings['default'] ?? null,
                    'step' => ($settings['numbertype'] ?? 'integer') === 'integer' ? 1 : 0.01,
                ];
            }

            $historyitems = [];
            if (isset($historybyquestion[$question->questionid])) {
                foreach ($historybyquestion[$question->questionid] as $historyitem) {
                    $historyitems[] = [
                        'time' => userdate($historyitem->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
                        'response' => $format_response($question->type, $historyitem->response, $optionsmap)
                    ];
                }
            }
            $historycount = count($historyitems);
            $latesthistory = $historycount > 0 ? $historybyquestion[$question->questionid][$historycount - 1]->timecreated : null;
            $savedtimestamp = $latesthistory ?? ($responsesbyquestion[$question->questionid]->timemodified ?? null);

            $questionslist[] = [
                'id' => $question->questionid,
                'name' => format_string($question->name),
                'type' => $question->type,
                'is_required' => isset($question->required) ? (bool)$question->required : true,
                'required' => isset($question->required) ? (int)$question->required : 1,
                'description' => format_text($question->description, $question->descriptionformat, [
                    'context' => $cm->context,
                    'noclean' => false,
                    'overflowdiv' => true
                ]),
                'options' => $options,
                'has_options' => !empty($options),
                'is_multiplechoice' => $ismultiplechoice,
                'is_select' => ($question->type === 'select'),
                'is_likert' => ($question->type === 'likert'),
                'is_number' => ($question->type === 'number'),
                'numbersettings' => $numbersettings,
                'is_shorttext' => ($question->type === 'shorttext'),
                'is_longtext' => ($question->type === 'longtext'),
                'turn_id' => $turn_id,
                'pageid' => $pageid,
                'cmid' => $cmid,
                'has_history' => ($historycount > 0),
                'has_history_details' => ($historycount > 1),
                'history_count' => $historycount,
                'history_items' => array_reverse($historyitems),
                'saved_datetime' => $savedtimestamp ? userdate($savedtimestamp, get_string('strftimedatetimeshort', 'langconfig')) : null,
            ];
        }

        $renderer = $PAGE->get_renderer('mod_harpiasurvey');
        $html = $renderer->render_from_template('mod_harpiasurvey/turn_evaluation_questions', [
            'questions' => $questionslist,
            'pageid' => $pageid,
            'turn_id' => $turn_id,
            'cmid' => $cmid,
            'has_questions' => !empty($questionslist),
            'is_qa_mode' => ($behavior === 'qa')
        ]);

        echo json_encode([
            'success' => true,
            'html' => $html
        ]);
        break;
        
    case 'send_ai_message':
        require_sesskey();
        
        $message = required_param('message', PARAM_RAW);
        $modelid = required_param('modelid', PARAM_INT);
        $parentid = optional_param('parentid', 0, PARAM_INT);
        $requested_turn_id = optional_param('turn_id', null, PARAM_INT); // Optional: requested turn ID from frontend.
        
        // Verify page belongs to this harpiasurvey instance and is aichat type.
        // First get the page (already loaded at line 43, but we need to verify experiment).
        if (!$page || $page->id != $pageid) {
            $page = $DB->get_record('harpiasurvey_pages', ['id' => $pageid], '*', MUST_EXIST);
        }
        
        // Get the experiment for this page.
        $experiment = $DB->get_record('harpiasurvey_experiments', ['id' => $page->experimentid], '*', MUST_EXIST);
        if ($experiment->harpiasurveyid != $harpiasurvey->id) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid page'
            ]);
            break;
        }
        
        if ($page->type !== 'aichat') {
            echo json_encode([
                'success' => false,
                'message' => 'This page is not an AI chat page'
            ]);
            break;
        }
        
        // Verify model belongs to this harpiasurvey instance and is enabled.
        $model = $DB->get_record('harpiasurvey_models', [
            'id' => $modelid,
            'harpiasurveyid' => $harpiasurvey->id,
            'enabled' => 1
        ], '*', MUST_EXIST);
        
        // Verify model is associated with this page.
        $pagemodel = $DB->get_record('harpiasurvey_page_models', [
            'pageid' => $pageid,
            'modelid' => $modelid
        ]);
        if (!$pagemodel) {
            echo json_encode([
                'success' => false,
                'message' => 'Model is not associated with this page'
            ]);
            break;
        }
        
        // Get behavior from page (continuous, turns, multi_model, qa).
        $behavior = $page->behavior ?? 'continuous';
        
        // For turns mode, use requested turn_id if provided, otherwise calculate it.
        $turn_id = null;
        if ($behavior === 'turns') {
            $turnscopewhere = "pageid = ? AND userid = ? AND turn_id IS NOT NULL AND modelid = ?";
            $turnscopeparams = [$pageid, $USER->id, $modelid];

            // Get the current highest turn number for this user and page.
            $currentmaxturn = $DB->get_record_sql(
                "SELECT MAX(turn_id) as max_turn FROM {harpiasurvey_conversations} 
                 WHERE {$turnscopewhere}",
                $turnscopeparams
            );
            $currentturn = ($currentmaxturn && $currentmaxturn->max_turn) ? $currentmaxturn->max_turn : 0;
            
            if ($requested_turn_id !== null) {
                // Check if the requested turn is complete for this model.
                $requestedturnmessages = $DB->get_records_sql(
                    "SELECT * FROM {harpiasurvey_conversations} 
                     WHERE pageid = ? AND userid = ? AND turn_id = ? 
                     AND modelid = ?
                     AND content != '[New conversation - send a message to start]'
                     ORDER BY timecreated ASC",
                    [$pageid, $USER->id, $requested_turn_id, $modelid]
                );
                
                $hasuser = false;
                $hasai = false;
                foreach ($requestedturnmessages as $msg) {
                    if ($msg->role === 'user') {
                        $hasuser = true;
                    } else if ($msg->role === 'assistant') {
                        $hasai = true;
                    }
                }
                
                // For turns mode, never auto-move messages to a new turn on send.
                // New turns must be created explicitly via create_branch/create_root.
                if ($hasuser && $hasai) {
                    echo json_encode([
                        'success' => false,
                        'message' => get_string('chatlocked', 'mod_harpiasurvey')
                    ]);
                    break;
                }

                // Turn is not complete - use requested turn as-is.
                $turn_id = $requested_turn_id;
            } else {
                // Fallback: Get the last turn_id for this user and page.
                $turn_id = ($currentturn > 0) ? ($currentturn + 1) : 1;
            }
            
            // Check max_turns limit before saving message.
            if (!empty($page->max_turns)) {
                // Check if the turn_id being used exceeds max_turns.
                // Block if trying to use a turn beyond max_turns.
                if ($turn_id > $page->max_turns) {
                    echo json_encode([
                        'success' => false,
                        'message' => get_string('maxturnsreached', 'mod_harpiasurvey')
                    ]);
                    break;
                }
                
                // If we're using a turn that's at max_turns, check if it's already complete.
                // Only block if the turn is complete (has both user and AI messages).
                if ($turn_id == $page->max_turns) {
                    // Check if this turn is already complete (has both user and AI messages).
                    // Filter out placeholder messages.
                    $turnmessages = $DB->get_records_sql(
                        "SELECT * FROM {harpiasurvey_conversations} 
                         WHERE pageid = ? AND userid = ? AND turn_id = ? 
                         AND modelid = ?
                         AND content != '[New conversation - send a message to start]'
                         ORDER BY timecreated ASC",
                        [$pageid, $USER->id, $turn_id, $modelid]
                    );
                    
                    // If there are no messages at all, allow the first message.
                    if (empty($turnmessages)) {
                        // No messages yet - allow sending the first message.
                        // Continue to save the message.
                    } else {
                        // Check if turn is complete.
                        $hasuser = false;
                        $hasai = false;
                        foreach ($turnmessages as $msg) {
                            if ($msg->role === 'user') {
                                $hasuser = true;
                            } else if ($msg->role === 'assistant') {
                                $hasai = true;
                            }
                        }
                        
                        // If turn is complete (has both user and AI), block sending more messages.
                        if ($hasuser && $hasai) {
                            echo json_encode([
                                'success' => false,
                                'message' => get_string('maxturnsreached', 'mod_harpiasurvey')
                            ]);
                            break;
                        }
                    }
                }
            }
        }
        
        if ($behavior === 'qa') {
            $parentid = null;
        }

        // For continuous mode, only determine parentid if not provided AND no conversation is specified.
        // If parentid is explicitly provided (even if it's a placeholder), use it.
        // Only auto-determine parentid if it's truly missing (0 or not set).
        if ($behavior === 'continuous' && $parentid <= 0) {
            // No parentid provided - this means we're starting a new conversation.
            // Don't auto-attach to the most recent message, as that could attach to the wrong conversation.
            // Leave parentid as null to create a new root conversation.
            $parentid = null;
        }
        
        // Save user message to database (questionid is NULL for page-level conversations).
        $usermessage = new stdClass();
        $usermessage->pageid = $pageid;
        $usermessage->questionid = null; // No longer tied to a question.
        $usermessage->userid = $USER->id;
        $usermessage->modelid = $modelid;
        $usermessage->role = 'user';
        $usermessage->content = $message;
        $usermessage->parentid = $parentid > 0 ? $parentid : null;
        $usermessage->turn_id = $turn_id;
        $usermessage->timecreated = time();
        $usermessageid = $DB->insert_record('harpiasurvey_conversations', $usermessage);

        // In Q&A mode, each question/answer pair must have its own evaluation scope.
        // Use the root user message id as the pair identifier and persist it as turn_id
        // on both user and assistant messages.
        if ($behavior === 'qa') {
            $turn_id = (int)$usermessageid;
            $DB->set_field('harpiasurvey_conversations', 'turn_id', $turn_id, ['id' => $usermessageid]);
        }
        
        // Get conversation history for context (if chat mode).
        $history = [];
        if ($behavior === 'chat' || $behavior === 'continuous') {
            // For continuous mode, get history from the conversation tree (parent chain).
            if ($behavior === 'continuous' && $usermessage->parentid) {
                // Build history by traversing up the parent chain.
                // IMPORTANT: Filter by modelid to ensure we only get messages from the same model.
                $currentid = $usermessage->parentid;
                $messagechain = [];
                
                // Traverse up the parent chain to build the conversation history.
                while ($currentid) {
                    $msg = $DB->get_record('harpiasurvey_conversations', ['id' => $currentid]);
                    if (!$msg || $msg->pageid != $pageid || $msg->userid != $USER->id) {
                        break;
                    }
                    // Filter by modelid to ensure we only include messages from the same model.
                    if ($msg->modelid != $modelid) {
                        // If we hit a message from a different model, stop traversing.
                        // This ensures each model has its own conversation context.
                        break;
                    }
                    // Skip placeholder messages.
                    if (trim($msg->content) !== '[New conversation - send a message to start]') {
                        $messagechain[] = $msg;
                    }
                    $currentid = $msg->parentid;
                }
                
                // Reverse to get chronological order (oldest first).
                $messagechain = array_reverse($messagechain);
                
                // Add current message.
                $messagechain[] = $usermessage;
                
                // Build history array.
                foreach ($messagechain as $record) {
                    $history[] = [
                        'role' => $record->role,
                        'content' => $record->content
                    ];
                }
            } else {
                // Fallback: get all messages for this model (for backward compatibility or when no parent).
                $historyrecords = $DB->get_records('harpiasurvey_conversations', [
                    'pageid' => $pageid,
                    'userid' => $USER->id,
                    'modelid' => $modelid // Filter by modelid
                ], 'timecreated ASC');
                
                foreach ($historyrecords as $record) {
                    // Skip placeholder messages created when a new root conversation is started.
                    if (trim($record->content) === '[New conversation - send a message to start]') {
                        continue;
                    }
                    
                    $history[] = [
                        'role' => $record->role,
                        'content' => $record->content
                    ];
                }
            }
        } else if ($behavior === 'turns') {
            // For turns mode, get history based on branch tree structure.
            $history = get_turn_history($DB, $pageid, $USER->id, $turn_id, $modelid);
        } else {
            // Q&A mode: only include current message.
            $history[] = [
                'role' => 'user',
                'content' => $message
            ];
        }
        
        // Call LLM API.
        require_once(__DIR__ . '/classes/llm_service.php');
        $llmservice = new \mod_harpiasurvey\llm_service($model);
        $response = $llmservice->send_message($history);
        
        if ($response['success']) {
            // For continuous mode, determine the root conversation ID for the response.
            $root_conversation_id = null;
            if ($behavior === 'continuous') {
                if ($usermessage->parentid) {
                    // Find the root of this conversation (traverse up to find message with parentid = null).
                    $currentid = $usermessage->parentid;
                    while ($currentid) {
                        $parentmsg = $DB->get_record('harpiasurvey_conversations', ['id' => $currentid]);
                        if (!$parentmsg || !$parentmsg->parentid) {
                            $root_conversation_id = $currentid;
                            break;
                        }
                        $currentid = $parentmsg->parentid;
                    }
                } else {
                    // This is a new root conversation - use the user message ID as root.
                    $root_conversation_id = $usermessageid;
                }
            }
            // Save AI response to database (store raw markdown).
            $aimessage = new stdClass();
            $aimessage->pageid = $pageid;
            $aimessage->questionid = null; // No longer tied to a question.
            $aimessage->userid = $USER->id;
            $aimessage->modelid = $modelid;
            $aimessage->role = 'assistant';
            $aimessage->content = $response['content'];
            $aimessage->parentid = $usermessageid;
            $aimessage->turn_id = $turn_id; // Same turn_id as user message.
            $aimessage->timecreated = time();
            $aimessageid = $DB->insert_record('harpiasurvey_conversations', $aimessage);
            
            // Format content as markdown for display.
            $content = $response['content'] ?? '';
            if (empty($content)) {
                $content = 'Empty response from AI';
            }
            
            $formattedcontent = format_text($content, FORMAT_MARKDOWN, [
                'context' => $cm->context,
                'noclean' => false,
                'overflowdiv' => true
            ]);
            
            // Ensure content is a string (format_text can return empty string but we want to be safe).
            if (!is_string($formattedcontent)) {
                $formattedcontent = (string)$formattedcontent;
            }
            
            $response_data = [
                'success' => true,
                'messageid' => $aimessageid,
                'content' => $formattedcontent,
                'parentid' => $usermessageid,
                'user_message_turn_id' => $turn_id, // Turn ID for the user message.
                'turn_id' => $turn_id // Turn ID for the AI message (same turn).
            ];
            
            // For continuous mode, include the root conversation ID.
            if ($behavior === 'continuous' && $root_conversation_id) {
                $response_data['root_conversation_id'] = $root_conversation_id;
            }
            
            echo json_encode($response_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $response['error'] ?? 'Error communicating with AI service'
            ]);
        }
        break;
        
    case 'get_conversation_history':
        // Verify page belongs to this harpiasurvey instance.
        // First get the page (already loaded at line 43, but we need to verify experiment).
        if (!$page || $page->id != $pageid) {
            $page = $DB->get_record('harpiasurvey_pages', ['id' => $pageid], '*', MUST_EXIST);
        }
        
        // Get the experiment for this page.
        $experiment = $DB->get_record('harpiasurvey_experiments', ['id' => $page->experimentid], '*', MUST_EXIST);
        if ($experiment->harpiasurveyid != $harpiasurvey->id) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid page'
            ]);
            break;
        }
        
        if ($page->type !== 'aichat') {
            echo json_encode([
                'success' => false,
                'message' => 'This page is not an AI chat page'
            ]);
            break;
        }
        
        // Get conversation history for this user and page.
        $messages = $DB->get_records('harpiasurvey_conversations', [
            'pageid' => $pageid,
            'userid' => $USER->id
        ], 'timecreated ASC');
        
        $history = [];
        foreach ($messages as $msg) {
            // Skip placeholder messages created when a new root conversation is started.
            if (trim($msg->content) === '[New conversation - send a message to start]') {
                continue;
            }
            
            // Format content as markdown for display.
            $formattedcontent = format_text($msg->content, FORMAT_MARKDOWN, [
                'context' => $cm->context,
                'noclean' => false,
                'overflowdiv' => true
            ]);
            
            $history[] = [
                'id' => $msg->id,
                'role' => $msg->role,
                'content' => $formattedcontent,
                'parentid' => $msg->parentid,
                'turn_id' => $msg->turn_id,
                'timecreated' => $msg->timecreated
            ];
        }
        
        echo json_encode([
            'success' => true,
            'messages' => $history
        ]);
        break;
        
    case 'save_turn_evaluation':
        require_sesskey();
        
        $turn_id = required_param('turn_id', PARAM_INT);
        $rating = optional_param('rating', null, PARAM_INT);
        $comment = optional_param('comment', '', PARAM_TEXT);
        
        // Verify page belongs to this harpiasurvey instance.
        if (!$page || $page->id != $pageid) {
            $page = $DB->get_record('harpiasurvey_pages', ['id' => $pageid], '*', MUST_EXIST);
        }
        
        // Get the experiment for this page.
        $experiment = $DB->get_record('harpiasurvey_experiments', ['id' => $page->experimentid], '*', MUST_EXIST);
        if ($experiment->harpiasurveyid != $harpiasurvey->id) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid page'
            ]);
            break;
        }
        
        // Verify turn exists and belongs to this user.
        $turnmessage = $DB->get_record('harpiasurvey_conversations', [
            'pageid' => $pageid,
            'userid' => $USER->id,
            'turn_id' => $turn_id,
            'role' => 'assistant'
        ]);
        if (!$turnmessage) {
            echo json_encode([
                'success' => false,
                'message' => 'Turn not found'
            ]);
            break;
        }
        
        // Check if evaluation already exists.
        $existing = $DB->get_record('harpiasurvey_turn_evaluations', [
            'pageid' => $pageid,
            'userid' => $USER->id,
            'turn_id' => $turn_id
        ]);
        
        if ($existing) {
            // Update existing evaluation.
            $existing->rating = $rating;
            $existing->comment = $comment;
            $existing->timemodified = time();
            $DB->update_record('harpiasurvey_turn_evaluations', $existing);
        } else {
            // Create new evaluation.
            $evaluation = new stdClass();
            $evaluation->pageid = $pageid;
            $evaluation->turn_id = $turn_id;
            $evaluation->userid = $USER->id;
            $evaluation->rating = $rating;
            $evaluation->comment = $comment;
            $evaluation->timecreated = time();
            $evaluation->timemodified = time();
            $DB->insert_record('harpiasurvey_turn_evaluations', $evaluation);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Evaluation saved'
        ]);
        break;

    case 'get_conversation_tree':
        require_capability('mod/harpiasurvey:view', $cm->context);
        
        // Get behavior from page.
        $behavior = $page->behavior ?? 'continuous';
        
        // For continuous mode, build tree based on root conversations (parentid is NULL).
        if ($behavior === 'continuous') {
            // Get all root conversations (where parentid is NULL).
            $rootconversations = $DB->get_records_sql(
                "SELECT id, MIN(timecreated) as timecreated
                 FROM {harpiasurvey_conversations} 
                 WHERE pageid = ? AND userid = ? AND parentid IS NULL
                 GROUP BY id
                 ORDER BY timecreated ASC",
                [$pageid, $USER->id]
            );
            
            // Build tree structure for continuous conversations.
            $roots = [];
            foreach ($rootconversations as $rootconv) {
                // Count messages in this conversation tree (simple count, not full traversal).
                // We'll count messages that have this root as ancestor by checking parentid chain.
                // For now, just count direct children (we can improve this later if needed).
                $messagecount = $DB->count_records_sql(
                    "SELECT COUNT(*) FROM {harpiasurvey_conversations}
                     WHERE pageid = ? AND userid = ? AND (id = ? OR parentid = ?)",
                    [$pageid, $USER->id, $rootconv->id, $rootconv->id]
                );
                
                // For simplicity, we'll use the root conversation ID as the "turn_id" for display.
                // In continuous mode, we don't have turns, but we need a unique identifier.
                $roots[] = [
                    'turn_id' => $rootconv->id, // Use conversation ID as identifier
                    'conversation_id' => $rootconv->id,
                    'is_root' => true,
                    'children' => [],
                    'parent_turn_id' => null,
                    'branch_label' => null,
                    'timecreated' => $rootconv->timecreated,
                    'timecreated_str' => userdate($rootconv->timecreated, get_string('strftimedatetime', 'langconfig')),
                    'message_count' => $messagecount
                ];
            }
            
            // Get current conversation (most recent root conversation).
            $currentconv = $DB->get_record_sql(
                "SELECT id FROM {harpiasurvey_conversations}
                 WHERE pageid = ? AND userid = ? AND parentid IS NULL
                 ORDER BY timecreated DESC
                 LIMIT 1",
                [$pageid, $USER->id]
            );
            $currentTurnId = $currentconv ? $currentconv->id : (count($roots) > 0 ? $roots[0]['turn_id'] : 1);
            
            echo json_encode([
                'success' => true,
                'tree' => [
                    'roots' => $roots
                ],
                'current_turn_id' => $currentTurnId,
                'behavior' => 'continuous'
            ]);
            break;
        }
        
        // For turns mode, use existing logic.
        // Get all turns for this user and page (from conversations).
        $turns = $DB->get_records_sql(
            "SELECT turn_id, MIN(timecreated) as timecreated
             FROM {harpiasurvey_conversations} 
             WHERE pageid = ? AND userid = ? AND turn_id IS NOT NULL 
             GROUP BY turn_id
             ORDER BY turn_id ASC",
            [$pageid, $USER->id]
        );
        
        // Get all branches.
        $branches = $DB->get_records('harpiasurvey_turn_branches', [
            'pageid' => $pageid,
            'userid' => $USER->id
        ], 'parent_turn_id ASC, child_turn_id ASC');
        
        // Build tree structure.
        $turnMap = [];
        $parentMap = []; // Maps child_turn_id to parent info.
        
        // First, identify all turns from conversations and mark them as potential roots.
        foreach ($turns as $turn) {
            $turnMap[$turn->turn_id] = [
                'turn_id' => $turn->turn_id,
                'is_root' => true,
                'children' => [],
                'parent_turn_id' => null,
                'branch_label' => null,
                'timecreated' => $turn->timecreated,
                'timecreated_str' => userdate($turn->timecreated, get_string('strftimedatetime', 'langconfig'))
            ];
        }
        
        // Also add turns that exist only in branches (child turns without messages yet).
        foreach ($branches as $branch) {
            // Add child turn if it doesn't exist yet.
            if (!isset($turnMap[$branch->child_turn_id])) {
                $turnMap[$branch->child_turn_id] = [
                    'turn_id' => $branch->child_turn_id,
                    'is_root' => true, // Will be set to false below if it has a parent
                    'children' => [],
                    'parent_turn_id' => null,
                    'branch_label' => null,
                    'timecreated' => $branch->timecreated,
                    'timecreated_str' => userdate($branch->timecreated, get_string('strftimedatetime', 'langconfig'))
                ];
            }
            // Add parent turn if it doesn't exist yet (might be a root without messages).
            if (!isset($turnMap[$branch->parent_turn_id])) {
                // Try to find timecreated for parent from conversations or branches if not set
                // If we can't find it, use branch creation time as fallback
                $turnMap[$branch->parent_turn_id] = [
                    'turn_id' => $branch->parent_turn_id,
                    'is_root' => true,
                    'children' => [],
                    'parent_turn_id' => null,
                    'branch_label' => null,
                    'timecreated' => $branch->timecreated, // Fallback
                    'timecreated_str' => userdate($branch->timecreated, get_string('strftimedatetime', 'langconfig'))
                ];
            }
        }
        
        // Then, process branches to build parent-child relationships.
        // First, identify which branches are "direct branches" (children of root turns).
        $directBranches = []; // Branches that should appear at root level.
        $derivedBranches = []; // Branches that should be nested.
        
        foreach ($branches as $branch) {
            if (isset($turnMap[$branch->child_turn_id])) {
                // Check if parent is a root turn.
                $parentIsRoot = isset($turnMap[$branch->parent_turn_id]) && 
                                $turnMap[$branch->parent_turn_id]['is_root'];
                
                if ($parentIsRoot) {
                    // This is a direct branch - part of the same conversation, shown at same level as root.
                    $directBranches[] = $branch;
                    // Keep as non-root (it's still a branch), but mark as direct branch.
                    $turnMap[$branch->child_turn_id]['is_root'] = false; // Still a branch, not a root
                    $turnMap[$branch->child_turn_id]['parent_turn_id'] = $branch->parent_turn_id;
                    $turnMap[$branch->child_turn_id]['branch_label'] = $branch->branch_label;
                    $turnMap[$branch->child_turn_id]['is_direct_branch'] = true; // Flag for UI (same level display)
                    // Add to parent's direct_branches array instead of children.
                    if (!isset($turnMap[$branch->parent_turn_id]['direct_branches'])) {
                        $turnMap[$branch->parent_turn_id]['direct_branches'] = [];
                    }
                    $turnMap[$branch->parent_turn_id]['direct_branches'][] = &$turnMap[$branch->child_turn_id];
                } else {
                    // This is a derived branch - will be nested.
                    $derivedBranches[] = $branch;
                    $turnMap[$branch->child_turn_id]['is_root'] = false;
                    $turnMap[$branch->child_turn_id]['parent_turn_id'] = $branch->parent_turn_id;
                    $turnMap[$branch->child_turn_id]['branch_label'] = $branch->branch_label;
                    $turnMap[$branch->child_turn_id]['is_direct_branch'] = false;
                    // Store parent relationship for nested structure.
                    $parentMap[$branch->child_turn_id] = $branch->parent_turn_id;
                }
            }
        }
        
        // Build children arrays - only for derived branches (nested structure).
        foreach ($derivedBranches as $branch) {
            $childId = $branch->child_turn_id;
            $parentId = $branch->parent_turn_id;
            if (isset($turnMap[$parentId]) && isset($turnMap[$childId])) {
                if (!isset($turnMap[$parentId]['children'])) {
                    $turnMap[$parentId]['children'] = [];
                }
                // Use references so deeper descendants remain attached when more levels are processed.
                $turnMap[$parentId]['children'][] = &$turnMap[$childId];
            }
        }
        
        // Sort children by turn_id for each parent.
        foreach ($turnMap as $turnId => &$turn) {
            usort($turn['children'], function($a, $b) {
                return $a['turn_id'] <=> $b['turn_id'];
            });
        }
        unset($turn);
        
        // Get current turn (highest turn_id from both conversations and branches).
        $maxTurnConv = $DB->get_record_sql(
            "SELECT MAX(turn_id) as max_turn 
             FROM {harpiasurvey_conversations} 
             WHERE pageid = ? AND userid = ? AND turn_id IS NOT NULL",
            [$pageid, $USER->id]
        );
        $maxTurnBranch = $DB->get_record_sql(
            "SELECT MAX(child_turn_id) as max_turn 
             FROM {harpiasurvey_turn_branches} 
             WHERE pageid = ? AND userid = ?",
            [$pageid, $USER->id]
        );
        $currentTurnId = max(
            ($maxTurnConv && $maxTurnConv->max_turn) ? $maxTurnConv->max_turn : 0,
            ($maxTurnBranch && $maxTurnBranch->max_turn) ? $maxTurnBranch->max_turn : 0
        );
        if ($currentTurnId === 0) {
            $currentTurnId = 1;
        }
        
        // Build roots array (only actual roots, not direct branches).
        // Direct branches are part of their parent conversation's tree.
        $roots = [];
        foreach ($turnMap as $turn) {
            if ($turn['is_root'] && !isset($turn['is_direct_branch'])) {
                $roots[] = $turn;
            }
        }
        
        // Sort roots by turn_id.
        usort($roots, function($a, $b) {
            return $a['turn_id'] <=> $b['turn_id'];
        });
        
        // Ensure direct_branches arrays are properly included (convert references to actual arrays).
        foreach ($roots as &$root) {
            if (isset($root['direct_branches'])) {
                // Convert reference array to actual array for JSON encoding.
                $root['direct_branches'] = array_values($root['direct_branches']);
            }
        }
        unset($root);
        
        echo json_encode([
            'success' => true,
            'tree' => [
                'roots' => $roots
            ],
            'current_turn_id' => $currentTurnId,
            'behavior' => 'turns'
        ]);
        break;

    case 'create_branch':
        require_capability('mod/harpiasurvey:view', $cm->context);
        require_sesskey();
        
        $parent_turn_id = required_param('parent_turn_id', PARAM_INT);
        
        // Verify parent turn exists - check both conversations and branches tables.
        $parentTurnExists = false;
        
        // Check if turn has messages (conversations).
        $parentTurnMsg = $DB->get_record('harpiasurvey_conversations', [
            'pageid' => $pageid,
            'userid' => $USER->id,
            'turn_id' => $parent_turn_id
        ], 'turn_id');
        
        if ($parentTurnMsg) {
            $parentTurnExists = true;
        } else {
            // Check if turn exists as a child in branches (might be a new root that was just created).
            $parentTurnBranch = $DB->get_record('harpiasurvey_turn_branches', [
                'pageid' => $pageid,
                'userid' => $USER->id,
                'child_turn_id' => $parent_turn_id
            ], 'child_turn_id');
            
            if ($parentTurnBranch) {
                $parentTurnExists = true;
            } else {
                // Check if it's a root turn (exists as parent but no messages yet).
                // For now, allow creating branch from any turn_id that's >= 1.
                if ($parent_turn_id >= 1) {
                    $parentTurnExists = true;
                }
            }
        }
        
        if (!$parentTurnExists) {
            echo json_encode([
                'success' => false,
                'message' => 'Parent turn does not exist'
            ]);
            break;
        }
        
        // Check max_turns limit before creating new branch - scoped to the conversation of parent_turn_id.
        if (!empty($page->max_turns)) {
            // Build parent map for all branches to identify conversation roots.
            $branchesForLimit = $DB->get_records('harpiasurvey_turn_branches', [
                'pageid' => $pageid,
                'userid' => $USER->id
            ]);
            $parentMap = [];
            $allTurnIds = [];
            foreach ($branchesForLimit as $b) {
                $parentMap[$b->child_turn_id] = $b->parent_turn_id;
                $allTurnIds[$b->child_turn_id] = true;
                $allTurnIds[$b->parent_turn_id] = true;
            }
            // Include turns that exist only as conversations (roots with no branches yet).
            $turnRecords = $DB->get_records_sql(
                "SELECT DISTINCT turn_id FROM {harpiasurvey_conversations}
                 WHERE pageid = ? AND userid = ? AND turn_id IS NOT NULL",
                [$pageid, $USER->id]
            );
            foreach ($turnRecords as $tr) {
                $allTurnIds[$tr->turn_id] = true;
            }

            $findRoot = function($turnId) use (&$parentMap) {
                $current = $turnId;
                $safety = 0;
                while (isset($parentMap[$current]) && $safety < 1000) {
                    $current = $parentMap[$current];
                    $safety++;
                }
                return $current;
            };

            $rootForParent = $findRoot($parent_turn_id);

            // Count nodes per root conversation.
            $countsByRoot = [];
            foreach (array_keys($allTurnIds) as $tid) {
                $root = $findRoot($tid);
                if (!isset($countsByRoot[$root])) {
                    $countsByRoot[$root] = 0;
                }
                $countsByRoot[$root]++;
            }
            // Ensure the parent conversation is counted even if not present above.
            if (!isset($countsByRoot[$rootForParent])) {
                $countsByRoot[$rootForParent] = 1;
            }

            $currentConversationTurnCount = $countsByRoot[$rootForParent];
            if ($currentConversationTurnCount >= $page->max_turns) {
                echo json_encode([
                    'success' => false,
                    'message' => get_string('maxturnsreached', 'mod_harpiasurvey')
                ]);
                break;
            }
        }
        
        // Get the next available turn_id for this user/page.
        // Check both conversations and branches to find the max turn_id.
        $maxTurnConv = $DB->get_record_sql(
            "SELECT MAX(turn_id) as max_turn 
             FROM {harpiasurvey_conversations} 
             WHERE pageid = ? AND userid = ? AND turn_id IS NOT NULL",
            [$pageid, $USER->id]
        );
        
        $maxTurnBranch = $DB->get_record_sql(
            "SELECT MAX(child_turn_id) as max_turn 
             FROM {harpiasurvey_turn_branches} 
             WHERE pageid = ? AND userid = ?",
            [$pageid, $USER->id]
        );
        
        $maxTurn = max(
            ($maxTurnConv && $maxTurnConv->max_turn) ? $maxTurnConv->max_turn : 0,
            ($maxTurnBranch && $maxTurnBranch->max_turn) ? $maxTurnBranch->max_turn : 0
        );
        
        $new_turn_id = $maxTurn + 1;
        
        // Create branch record.
        // branch_label is now just the turn_id (child turn number).
        $branch = new stdClass();
        $branch->pageid = $pageid;
        $branch->userid = $USER->id;
        $branch->parent_turn_id = $parent_turn_id;
        $branch->child_turn_id = $new_turn_id;
        $branch->branch_label = (string)$new_turn_id; // Store just the turn number
        $branch->timecreated = time();
        $DB->insert_record('harpiasurvey_turn_branches', $branch);

        // Also create a placeholder conversation entry so the branch appears immediately.
        // Reuse a model associated with this page (first associated, or first enabled model).
        $pagemodel = $DB->get_record('harpiasurvey_page_models', [
            'pageid' => $pageid
        ], '*', IGNORE_MULTIPLE);
        if (!$pagemodel) {
            $pagemodel = $DB->get_record('harpiasurvey_models', [
                'harpiasurveyid' => $harpiasurvey->id,
                'enabled' => 1
            ], 'id', IGNORE_MULTIPLE);
        }
        $modelid = $pagemodel ? $pagemodel->modelid ?? $pagemodel->id : null;

        if ($modelid) {
            $placeholder = new stdClass();
            $placeholder->pageid = $pageid;
            $placeholder->userid = $USER->id;
            $placeholder->modelid = $modelid;
            $placeholder->turn_id = $new_turn_id;
            $placeholder->parentid = null;
            $placeholder->role = 'user';
            $placeholder->content = '[New conversation - send a message to start]';
            $placeholder->timecreated = time();
            $placeholder->timemodified = time();
            $newconversationid = $DB->insert_record('harpiasurvey_conversations', $placeholder);
        } else {
            $newconversationid = null;
        }
        
        echo json_encode([
            'success' => true,
            'new_turn_id' => $new_turn_id,
            'new_conversation_id' => $newconversationid,
            'branch_label' => (string)$new_turn_id,
            'message' => 'Turn created successfully'
        ]);
        break;

    case 'create_root':
        require_capability('mod/harpiasurvey:view', $cm->context);
        require_sesskey();
        
        // Get behavior from page.
        $behavior = $page->behavior ?? 'continuous';
        
        // Get the first model associated with this page.
        $pagemodel = $DB->get_record('harpiasurvey_page_models', [
            'pageid' => $pageid
        ], '*', IGNORE_MULTIPLE);
        
        if (!$pagemodel) {
            // Fallback: get the first enabled model for this harpiasurvey instance.
            $model = $DB->get_record('harpiasurvey_models', [
                'harpiasurveyid' => $harpiasurvey->id,
                'enabled' => 1
            ], 'id', IGNORE_MULTIPLE);
            
            if (!$model) {
                echo json_encode([
                    'success' => false,
                    'message' => 'No model available for this page'
                ]);
                break;
            }
            $modelid = $model->id;
        } else {
            $modelid = $pagemodel->modelid;
        }
        
        if ($behavior === 'continuous') {
            // For continuous mode, create a root conversation (parentid = NULL, no turn_id).
            // Create a placeholder conversation record so the root appears in the tree immediately.
            $placeholder = new stdClass();
            $placeholder->pageid = $pageid;
            $placeholder->userid = $USER->id;
            $placeholder->modelid = $modelid;
            $placeholder->turn_id = null; // No turn_id for continuous mode.
            $placeholder->parentid = null; // Root conversation.
            $placeholder->role = 'user';
            $placeholder->content = '[New conversation - send a message to start]';
            $placeholder->timecreated = time();
            $placeholder->timemodified = time();
            $new_conversation_id = $DB->insert_record('harpiasurvey_conversations', $placeholder);
            
            echo json_encode([
                'success' => true,
                'new_turn_id' => $new_conversation_id, // Use conversation ID as identifier.
                'new_conversation_id' => $new_conversation_id,
                'message' => 'New root conversation created. Send a message to start it.'
            ]);
        } else {
            // For turns mode, create a new turn.
            // Get the next available turn_id for this user/page.
            // Check both conversations and branches to find the max turn_id.
            $maxTurnConv = $DB->get_record_sql(
                "SELECT MAX(turn_id) as max_turn 
                 FROM {harpiasurvey_conversations} 
                 WHERE pageid = ? AND userid = ? AND turn_id IS NOT NULL",
                [$pageid, $USER->id]
            );
            
            $maxTurnBranch = $DB->get_record_sql(
                "SELECT MAX(child_turn_id) as max_turn 
                 FROM {harpiasurvey_turn_branches} 
                 WHERE pageid = ? AND userid = ?",
                [$pageid, $USER->id]
            );
            
            $maxTurn = max(
                ($maxTurnConv && $maxTurnConv->max_turn) ? $maxTurnConv->max_turn : 0,
                ($maxTurnBranch && $maxTurnBranch->max_turn) ? $maxTurnBranch->max_turn : 0
            );

            $new_turn_id = $maxTurn + 1;
            
            // Create a placeholder conversation record so the root appears in the tree immediately.
            // This will be updated when the user sends the first actual message.
            // Use 'user' role with a placeholder message so it's treated as a normal conversation.
            $placeholder = new stdClass();
            $placeholder->pageid = $pageid;
            $placeholder->userid = $USER->id;
            $placeholder->modelid = $modelid;
            $placeholder->turn_id = $new_turn_id;
            $placeholder->role = 'user';
            $placeholder->content = '[New conversation - send a message to start]';
            $placeholder->timecreated = time();
            $placeholder->timemodified = time();
            $DB->insert_record('harpiasurvey_conversations', $placeholder);
            
            echo json_encode([
                'success' => true,
                'new_turn_id' => $new_turn_id,
                'message' => 'New root conversation created. Send a message to start it.'
            ]);
        }
        break;
        
    case 'get_available_subpage_questions':
        $subpageid = required_param('subpageid', PARAM_INT);
        $pageid = required_param('pageid', PARAM_INT);
        
        // Verify page belongs to this harpiasurvey instance.
        $page = $DB->get_record('harpiasurvey_pages', ['id' => $pageid], '*', MUST_EXIST);
        if ($page->experimentid) {
            $experiment = $DB->get_record('harpiasurvey_experiments', ['id' => $page->experimentid, 'harpiasurveyid' => $harpiasurvey->id], '*', MUST_EXIST);
        }
        
        // Verify subpage belongs to the page.
        $subpage = $DB->get_record('harpiasurvey_subpages', ['id' => $subpageid, 'pageid' => $pageid], '*', MUST_EXIST);
        
        // Get all questions for this harpiasurvey instance.
        $questions = $DB->get_records('harpiasurvey_questions', ['harpiasurveyid' => $harpiasurvey->id], 'name ASC');
        
        // Get questions already on this subpage.
        $subpagequestionids = $DB->get_records('harpiasurvey_subpage_questions', ['subpageid' => $subpageid], '', 'questionid');
        $subpagequestionids = array_keys($subpagequestionids);
        
        // Filter out questions already on the subpage.
        $availablequestions = [];
        foreach ($questions as $question) {
            if (!in_array($question->id, $subpagequestionids)) {
                $description = format_text($question->description, $question->descriptionformat, [
                    'context' => $cm->context,
                    'noclean' => false,
                    'overflowdiv' => true
                ]);
                $description = strip_tags($description);
                if (strlen($description) > 100) {
                    $description = substr($description, 0, 100) . '...';
                }
                
                $availablequestions[] = [
                    'id' => $question->id,
                    'name' => format_string($question->name),
                    'type' => $question->type,
                    'typedisplay' => get_string('type' . $question->type, 'mod_harpiasurvey'),
                    'description' => $description
                ];
            }
        }
        
        echo json_encode([
            'success' => true,
            'questions' => $availablequestions
        ]);
        break;
        
    case 'add_question_to_subpage':
        require_sesskey();
        
        $subpageid = required_param('subpageid', PARAM_INT);
        $pageid = required_param('pageid', PARAM_INT);
        $questionid = required_param('questionid', PARAM_INT);
        
        // Verify page belongs to this harpiasurvey instance.
        $page = $DB->get_record('harpiasurvey_pages', ['id' => $pageid], '*', MUST_EXIST);
        if ($page->experimentid) {
            $experiment = $DB->get_record('harpiasurvey_experiments', ['id' => $page->experimentid, 'harpiasurveyid' => $harpiasurvey->id], '*', MUST_EXIST);
        }
        
        // Verify subpage belongs to the page.
        $subpage = $DB->get_record('harpiasurvey_subpages', ['id' => $subpageid, 'pageid' => $pageid], '*', MUST_EXIST);
        
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
        
        if ($existing) {
            echo json_encode([
                'success' => false,
                'message' => get_string('questionalreadyonsubpage', 'mod_harpiasurvey')
            ]);
            break;
        }
        
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
        $subpagequestion->enabled = 1;
        $subpagequestion->turn_visibility_type = 'all_turns'; // Default to all turns.
        $subpagequestion->turn_number = null;
        
        $DB->insert_record('harpiasurvey_subpage_questions', $subpagequestion);
        
        echo json_encode([
            'success' => true,
            'message' => get_string('questionaddedtosubpage', 'mod_harpiasurvey')
        ]);
        break;
        
    case 'export_conversation':
        require_sesskey();
        
        // Get optional parameters
        $turn_id = optional_param('turn_id', null, PARAM_INT); // For turns mode: export specific conversation
        $conversation_id = optional_param('conversation_id', null, PARAM_INT); // For continuous mode: export specific conversation
        
        // Verify page belongs to this harpiasurvey instance
        if (!$page || $page->id != $pageid) {
            $page = $DB->get_record('harpiasurvey_pages', ['id' => $pageid], '*', MUST_EXIST);
        }
        $experiment = $DB->get_record('harpiasurvey_experiments', ['id' => $page->experimentid], '*', MUST_EXIST);
        if ($experiment->harpiasurveyid != $harpiasurvey->id) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid page'
            ]);
            break;
        }
        
        if ($page->type !== 'aichat') {
            echo json_encode([
                'success' => false,
                'message' => 'This page is not an AI chat page'
            ]);
            break;
        }
        
        $behavior = $page->behavior ?? 'continuous';
        
        // Collect messages to export
        $messages = [];
        
        if ($behavior === 'turns' && $turn_id) {
            // For turns mode: export all messages in the conversation pathway
            $turn_ids_to_include = [];
            $branch = $DB->get_record('harpiasurvey_turn_branches', [
                'child_turn_id' => $turn_id,
                'pageid' => $pageid,
                'userid' => $USER->id
            ]);
            
            if ($branch) {
                // Build path from root to this turn
                $parent_turn_id = $branch->parent_turn_id;
                $current_parent = $parent_turn_id;
                $path_to_root = [$parent_turn_id];
                
                while ($current_parent) {
                    $parent_branch = $DB->get_record('harpiasurvey_turn_branches', [
                        'child_turn_id' => $current_parent,
                        'pageid' => $pageid,
                        'userid' => $USER->id
                    ]);
                    if ($parent_branch) {
                        $current_parent = $parent_branch->parent_turn_id;
                        if ($current_parent) {
                            array_unshift($path_to_root, $current_parent);
                        }
                    } else {
                        break;
                    }
                }
                $turn_ids_to_include = $path_to_root;
                $turn_ids_to_include[] = $turn_id;
            } else {
                // Root turn
                $turn_ids_to_include = [$turn_id];
            }
            
            // Get all messages for these turn IDs
            if (!empty($turn_ids_to_include)) {
                list($insql, $inparams) = $DB->get_in_or_equal($turn_ids_to_include, SQL_PARAMS_NAMED);
                $inparams['pageid'] = $pageid;
                $inparams['userid'] = $USER->id;
                
                $messages = $DB->get_records_sql(
                    "SELECT * FROM {harpiasurvey_conversations} 
                     WHERE pageid = :pageid AND userid = :userid AND turn_id $insql 
                     AND content != '[New conversation - send a message to start]'
                     ORDER BY turn_id ASC, timecreated ASC",
                    $inparams
                );
            }
        } else if ($behavior === 'continuous' && $conversation_id) {
            // For continuous mode: export all messages in the conversation
            // Find root conversation
            $root = $DB->get_record('harpiasurvey_conversations', [
                'id' => $conversation_id,
                'pageid' => $pageid,
                'userid' => $USER->id
            ]);
            
            if ($root) {
                // Get all messages in this conversation tree (recursive query)
                $messages = $DB->get_records_sql(
                    "SELECT * FROM {harpiasurvey_conversations} 
                     WHERE pageid = ? AND userid = ? AND (
                         id = ? OR 
                         parentid = ? OR 
                         parentid IN (
                             SELECT id FROM {harpiasurvey_conversations} 
                             WHERE pageid = ? AND userid = ? AND (id = ? OR parentid = ?)
                         )
                     )
                     AND content != '[New conversation - send a message to start]'
                     ORDER BY timecreated ASC",
                    [$pageid, $USER->id, $conversation_id, $conversation_id, $pageid, $USER->id, $conversation_id, $conversation_id]
                );
            }
        } else {
            // Export all conversations for this page (fallback)
            $messages = $DB->get_records('harpiasurvey_conversations', [
                'pageid' => $pageid,
                'userid' => $USER->id
            ], 'timecreated ASC');
        }
        
        // Filter out placeholder messages
        $messages = array_filter($messages, function($msg) {
            return trim($msg->content) !== '[New conversation - send a message to start]';
        });
        
        // Generate CSV
        $filename = 'conversation_export_' . date('Y-m-d_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        // Output UTF-8 BOM for Excel compatibility
        echo "\xEF\xBB\xBF";
        
        // Open output stream
        $output = fopen('php://output', 'w');
        
        // Write CSV header
        fputcsv($output, [
            'Turn ID',
            'Model ID',
            'Role',
            'Content',
            'Timestamp',
            'Message ID',
            'Parent ID'
        ]);
        
        // Write messages
        foreach ($messages as $msg) {
            fputcsv($output, [
                $msg->turn_id ?? '',
                $msg->modelid ?? '',
                $msg->role ?? '',
                $msg->content ?? '',
                date('Y-m-d H:i:s', $msg->timecreated ?? 0),
                $msg->id ?? '',
                $msg->parentid ?? ''
            ]);
        }
        
        fclose($output);
        exit;
        break;
        
    default:
        echo json_encode([
            'success' => false,
            'message' => 'Invalid action'
        ]);
        break;
}
