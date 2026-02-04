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

namespace mod_harpiasurvey\output;

defined('MOODLE_INTERNAL') || die();

use renderable;
use templatable;
use renderer_base;

/**
 * Page view renderable class.
 *
 * @package     mod_harpiasurvey
 * @copyright   2025 Your Name
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page_view implements renderable, templatable {

    /**
     * @var object Page object
     */
    public $page;

    /**
     * @var object Context object
     */
    public $context;

    /**
     * @var string Edit URL
     */
    public $editurl;

    /**
     * @var array Questions array
     */
    public $questions;

    /**
     * @var int Course module ID
     */
    public $cmid;

    /**
     * @var int Page ID
     */
    public $pageid;

    /**
     * @var array All pages for the experiment
     */
    public $allpages;

    /**
     * @var int Experiment ID
     */
    public $experimentid;

    /**
     * @var bool Whether user can manage experiments (is admin)
     */
    public $canmanage;

    /**
     * @var bool Whether user can override navigation restrictions
     */
    public $cannavigate;

    /**
     * @var string Delete URL
     */
    public $deleteurl;

    /**
     * @var string Navigation mode (free_navigation, only_forward)
     */
    public $navigation_mode;

    /**
     * @var int|null Initial turn number from query parameter
     */
    public $initial_turn;

    /**
     * Class constructor.
     *
     * @param object $page
     * @param object $context
     * @param string $editurl
     * @param array $questions
     * @param int $cmid
     * @param int $pageid
     * @param array $allpages
     * @param int $experimentid
     * @param bool $canmanage
     * @param bool $cannavigate
     * @param string $deleteurl
     * @param string $navigation_mode
     * @param int|null $initial_turn Initial turn from query parameter
     */
    public function __construct($page, $context, $editurl, $questions = [], $cmid = 0, $pageid = 0, $allpages = [], $experimentid = 0, $canmanage = true, $cannavigate = false, $deleteurl = '', $navigation_mode = 'free_navigation', $initial_turn = null) {
        $this->page = $page;
        $this->context = $context;
        $this->editurl = $editurl;
        $this->questions = $questions;
        $this->cmid = $cmid;
        $this->pageid = $pageid;
        $this->allpages = $allpages;
        $this->experimentid = $experimentid;
        $this->canmanage = $canmanage;
        $this->cannavigate = $cannavigate;
        $this->deleteurl = $deleteurl;
        $this->navigation_mode = $navigation_mode;
        $this->initial_turn = $initial_turn;
    }

    /**
     * Export the data for the template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {
        global $CFG, $DB, $USER;

        // Format the page description.
        $description = format_text($this->page->description, $this->page->descriptionformat, [
            'context' => $this->context,
            'noclean' => false,
            'overflowdiv' => true
        ]);
        
        // Check if description has actual content (strip HTML tags and whitespace).
        $hasdescription = !empty(trim(strip_tags($description)));

        // Load existing responses for this user and page.
        $responses = [];
        $responsetimestamps = [];
        if ($this->pageid && $USER->id) {
            $userresponses = $DB->get_records_sql(
                "SELECT questionid, response, timemodified
                   FROM {harpiasurvey_responses}
                  WHERE pageid = :pageid AND userid = :userid
                    AND (turn_id IS NULL OR turn_id = 0)",
                ['pageid' => $this->pageid, 'userid' => $USER->id]
            );
            foreach ($userresponses as $response) {
                $responses[$response->questionid] = $response->response;
                $responsetimestamps[$response->questionid] = $response->timemodified;
            }
        }

        // Load response history for this user and page (regular questions only).
        $responsehistory = [];
        if ($this->pageid && $USER->id && $DB->get_manager()->table_exists('harpiasurvey_response_history')) {
            $historyrecords = $DB->get_records_sql(
                "SELECT id, questionid, response, timecreated
                   FROM {harpiasurvey_response_history}
                  WHERE pageid = :pageid AND userid = :userid
                    AND (turn_id IS NULL OR turn_id = 0)
               ORDER BY timecreated ASC",
                ['pageid' => $this->pageid, 'userid' => $USER->id]
            );
            foreach ($historyrecords as $record) {
                if (!isset($responsehistory[$record->questionid])) {
                    $responsehistory[$record->questionid] = [];
                }
                $responsehistory[$record->questionid][] = [
                    'response' => $record->response,
                    'timecreated' => $record->timecreated
                ];
            }
        }

        $formatresponse = function($questiontype, $responsevalue, $optionsmap = []) {
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
                        if (isset($optionsmap[$optionid])) {
                            $labels[] = $optionsmap[$optionid];
                        } else {
                            $labels[] = '[' . $optionid . ']';
                        }
                    }
                    return !empty($labels) ? implode(', ', $labels) : '-';
                }
                return $value;
            }

            if (in_array($questiontype, ['singlechoice', 'select', 'likert'])) {
                if (isset($optionsmap[$value])) {
                    return $optionsmap[$value];
                }
                return $value;
            }

            return $value;
        };

        // Prepare questions for rendering.
        $questionslist = [];
        $turnevaluationquestions = []; // Questions that evaluate turns (for aichat pages with turns mode).
        $hasnonaiconversationquestions = false;
        
        $pagebehavior = $this->page->behavior ?? 'continuous';
        // Check if this is a turns-based aichat page.
        $isturnspage = ($this->page->type === 'aichat' && $pagebehavior === 'turns');
        $isqamode = ($this->page->type === 'aichat' && $pagebehavior === 'qa');
        
        // Check if this is a multi-model page (for continuous mode with multiple models).
        // We need to check this early to determine if we should skip questions in the regular list.
        $pagemodels = [];
        $use_multi_model_tabs = false;
        if ($this->page->type === 'aichat') {
            $pagemodels = $DB->get_records('harpiasurvey_page_models', ['pageid' => $this->pageid], '', 'modelid');
            $modelids = array_keys($pagemodels);
            $has_multiple_models = (count($modelids) > 1);
            // Enable multi-model tabs for both continuous and turns mode when multiple models exist
            $use_multi_model_tabs = $has_multiple_models;
        }
        
        foreach ($this->questions as $question) {
            if (!$question->enabled) {
                continue; // Skip disabled questions.
            }

            // For multi-model pages, skip all questions from the regular list.
            // They will only appear in the model tabs.
            if ($use_multi_model_tabs) {
                continue;
            }

            // For turns-based and Q&A aichat pages, show all questions only in the evaluation section.
            if ($isturnspage || $isqamode) {
                continue;
            }

            global $DB;
            
            // Load question settings from JSON.
            $settings = [];
            if (!empty($question->settings)) {
                $settings = json_decode($question->settings, true) ?? [];
            }
            
            // Load saved response for this question (used for all question types).
            $savedresponse = $responses[$question->questionid] ?? null;
            
            // Get question options if it's a choice/select question.
            $options = [];
            $inputtype = 'radio'; // Default for single choice.
            $ismultiplechoice = false;
            $optionsmap = [];
            if (in_array($question->type, ['singlechoice', 'multiplechoice', 'select', 'likert'])) {
                $inputtype = ($question->type === 'singlechoice' || $question->type === 'select' || $question->type === 'likert') ? 'radio' : 'checkbox';
                $ismultiplechoice = ($question->type === 'multiplechoice');
                
                // For Likert, generate 1-5 options.
                if ($question->type === 'likert') {
                    for ($i = 1; $i <= 5; $i++) {
                        $isselected = ($savedresponse && (string)$i === $savedresponse);
                        $options[] = [
                            'id' => $i,
                            'value' => (string)$i,
                            'isdefault' => $isselected,
                            'inputtype' => 'radio',
                            'questionid' => $question->questionid,
                            'nameattr' => 'question_' . $question->questionid,
                        ];
                        $optionsmap[(string)$i] = (string)$i;
                    }
                } else {
                    // For other types, load from database.
                    $questionoptions = $DB->get_records('harpiasurvey_question_options', [
                        'questionid' => $question->questionid
                    ], 'sortorder ASC');
                    // Format the name attribute: use array notation for checkboxes, simple name for radio buttons
                    $nameattr = 'question_' . $question->questionid;
                    if ($ismultiplechoice) {
                        $nameattr .= '[]';
                    }
                    foreach ($questionoptions as $opt) {
                        // Check if this option is in the saved response.
                        $isselected = false;
                        if ($savedresponse !== null) {
                            if ($ismultiplechoice) {
                                // Multiple choice: response is JSON array.
                                $savedvalues = json_decode($savedresponse, true);
                                if (is_array($savedvalues)) {
                                    $isselected = in_array((string)$opt->id, array_map('strval', $savedvalues));
                                }
                            } else {
                                // Single choice or select: response is the option ID.
                                $isselected = ((string)$opt->id === (string)$savedresponse);
                            }
                        } else {
                            $isselected = (bool)$opt->isdefault;
                        }
                        
                        $options[] = [
                            'id' => $opt->id,
                            'value' => format_string($opt->value),
                            'isdefault' => $isselected,
                            'inputtype' => $inputtype,
                            'questionid' => $question->questionid,
                            'nameattr' => $nameattr,
                        ];
                        $optionsmap[(string)$opt->id] = format_string($opt->value);
                    }
                }
            }
            
            // Number field settings.
            $numbersettings = [];
            if ($question->type === 'number') {
                $min = $settings['min'] ?? null;
                $max = $settings['max'] ?? null;
                $allownegatives = !empty($settings['allownegatives']);
                
                // If negatives not allowed and no min set, default to 0.
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

            // Get saved response value (already loaded above as $savedresponse).
            $savedresponsevalue = $savedresponse;
            $historyitems = [];
            if (isset($responsehistory[$question->questionid])) {
                foreach ($responsehistory[$question->questionid] as $historyitem) {
                    $historyitems[] = [
                        'time' => userdate($historyitem['timecreated'], get_string('strftimedatetimeshort', 'langconfig')),
                        'response' => $formatresponse($question->type, $historyitem['response'], $optionsmap)
                    ];
                }
            }
            $historycount = count($historyitems);
            $latesthistory = $historycount > 0 ? $responsehistory[$question->questionid][$historycount - 1]['timecreated'] : null;
            $savedtimestamp = $latesthistory ?? ($responsetimestamps[$question->questionid] ?? null);
            if ($historycount === 0 && array_key_exists($question->questionid, $responses)) {
                $fallbacktimestamp = $savedtimestamp ?? time();
                $historyitems[] = [
                    'time' => userdate($fallbacktimestamp, get_string('strftimedatetimeshort', 'langconfig')),
                    'response' => $formatresponse($question->type, $savedresponsevalue, $optionsmap)
                ];
                $historycount = 1;
                if (!$savedtimestamp) {
                    $savedtimestamp = $fallbacktimestamp;
                }
            }
            
            // Track if we have questions (all questions are now regular questions, no aiconversation type).
            $hasnonaiconversationquestions = true;
            
            $questionslist[] = [
                'id' => $question->questionid,
                'name' => format_string($question->name),
                'type' => $question->type,
                'is_required' => isset($question->required) ? (bool)$question->required : true,
                'required' => isset($question->required) ? (int)$question->required : 1,
                'description' => format_text($question->description, $question->descriptionformat, [
                    'context' => $this->context,
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
                'saved_response' => $savedresponsevalue,
                'has_saved_response' => !empty($savedresponsevalue),
                'saved_timestamp' => $savedtimestamp,
                'saved_datetime' => $savedtimestamp ? userdate($savedtimestamp, get_string('strftimedatetimeshort', 'langconfig')) : null,
                'has_history' => ($historycount > 0),
                'has_history_details' => ($historycount > 1),
                'history_count' => $historycount,
                'history_items' => array_reverse($historyitems),
                'cmid' => $this->cmid,
                'pageid' => $this->pageid,
            ];
        }

        // Now process turn evaluation questions (for aichat pages with turns mode).
        // In aichat pages, all questions evaluate the page's chat conversation.
        if ($this->page->type === 'aichat' && ($this->page->behavior ?? 'continuous') === 'turns') {
            foreach ($this->questions as $question) {
                if (!$question->enabled) {
                    continue;
                }

                // For turns mode pages, if min_turn is not set, default to 1 (appears from first turn).
                // This allows questions added before min_turn was implemented to still work.
                $minturn = isset($question->min_turn) && $question->min_turn !== null ? $question->min_turn : 1;
                $showonlyturn = isset($question->show_only_turn) && $question->show_only_turn !== null ? (int)$question->show_only_turn : null;
                $hideonturn = isset($question->hide_on_turn) && $question->hide_on_turn !== null ? (int)$question->hide_on_turn : null;

                // Load question data (similar to regular questions).
                $settings = [];
                if (!empty($question->settings)) {
                    $settings = json_decode($question->settings, true) ?? [];
                }

                // Get question options if it's a choice/select question.
                $options = [];
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
                        }
                    }
                }

                // Number field settings.
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

                $turnevaluationquestions[] = [
                    'id' => $question->questionid,
                    'name' => format_string($question->name),
                    'type' => $question->type,
                    'is_required' => isset($question->required) ? (bool)$question->required : true,
                    'required' => isset($question->required) ? (int)$question->required : 1,
                    'description' => format_text($question->description, $question->descriptionformat, [
                        'context' => $this->context,
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
                    'min_turn' => $minturn, // Use calculated min_turn (defaults to 1 if not set).
                    'show_only_turn' => $showonlyturn, // Show only on this specific turn (null = all turns).
                    'hide_on_turn' => $hideonturn, // Hide on this specific turn (null = no hiding).
                    'cmid' => $this->cmid,
                    'pageid' => $this->pageid,
                ];
            }
        }

        // Calculate pagination.
        $pagination = [];
        if (!empty($this->allpages) && count($this->allpages) > 1) {
            // Convert associative array to numerically indexed array.
            $pagesarray = array_values($this->allpages);
            $totalpages = count($pagesarray);
            $currentindex = -1;
            
            // Find current page index.
            foreach ($pagesarray as $index => $p) {
                if ($p->id == $this->pageid) {
                    $currentindex = $index;
                    break;
                }
            }

            if ($currentindex >= 0) {
                $currentpagenum = $currentindex + 1;

                // Previous page - only show if navigation mode allows it.
                // In 'only_forward' mode, hide previous button for non-admins (users can't go back).
                if ($currentindex > 0 && ($this->navigation_mode === 'free_navigation' || $this->cannavigate)) {
                    $prevpage = $pagesarray[$currentindex - 1];
                    $prevurl = new \moodle_url('/mod/harpiasurvey/view_experiment.php', [
                        'id' => $this->cmid,
                        'experiment' => $this->experimentid,
                        'page' => $prevpage->id
                    ]);
                    $pagination['prev_url'] = $prevurl->out(false);
                    $pagination['has_prev'] = true;
                } else {
                    $pagination['has_prev'] = false;
                }

                // Next page.
                if ($currentindex < $totalpages - 1) {
                    $nextpage = $pagesarray[$currentindex + 1];
                    $nexturl = new \moodle_url('/mod/harpiasurvey/view_experiment.php', [
                        'id' => $this->cmid,
                        'experiment' => $this->experimentid,
                        'page' => $nextpage->id
                    ]);
                    $pagination['next_url'] = $nexturl->out(false);
                    $pagination['has_next'] = true;
                } else {
                    $pagination['has_next'] = false;
                }

                // Page numbers.
                $pagenumbers = [];
                foreach ($pagesarray as $index => $p) {
                    $pageurl = new \moodle_url('/mod/harpiasurvey/view_experiment.php', [
                        'id' => $this->cmid,
                        'experiment' => $this->experimentid,
                        'page' => $p->id
                    ]);
                    $pagenumbers[] = [
                        'number' => $index + 1,
                        'url' => $pageurl->out(false),
                        'is_current' => ($p->id == $this->pageid),
                        'is_only_forward' => (!$this->cannavigate && $this->navigation_mode === 'only_forward' && $index < $currentindex)
                    ];
                }
                $pagination['pages'] = $pagenumbers;
                $pagination['current_page'] = $currentpagenum;
                $pagination['total_pages'] = $totalpages;
            }
        }

        // Load AI chat data if this is an aichat page (chat is part of the page, not a question).
        $aichatdata = [];
        $hasaichat = false;
        $pagebehavior = $this->page->behavior ?? 'continuous';
        $isturnsmode = ($pagebehavior === 'turns');
        $isqamode = ($pagebehavior === 'qa');
        
        // Check if page type is aichat and behavior is not multi_model.
        if ($this->page->type === 'aichat' && $pagebehavior !== 'multi_model') {
            $hasaichat = true;
            
            // Load models associated with this page.
            // Reuse $pagemodels if already loaded above, otherwise load it.
            if (empty($pagemodels)) {
                $pagemodels = $DB->get_records('harpiasurvey_page_models', ['pageid' => $this->pageid], '', 'modelid');
            }
            $modelids = array_keys($pagemodels);
            $models = [];
            if (!empty($modelids)) {
                $modelsrecords = $DB->get_records_list('harpiasurvey_models', 'id', $modelids, 'name ASC');
                foreach ($modelsrecords as $model) {
                    if ($model->enabled) {
                        $models[] = [
                            'id' => $model->id,
                            'name' => format_string($model->name),
                            'model' => $model->model // Full model identifier (e.g., gpt-4o-2024-08-06)
                        ];
                    }
                }
            }
            // Note: Chat will appear even without models (showing a warning message)
            
            // Check if we have multiple models and should use tabbed interface (for both continuous and turns mode).
            // Reuse $use_multi_model_tabs if already set above, otherwise calculate it.
            if (!isset($use_multi_model_tabs)) {
                $has_multiple_models = (count($models) > 1);
                // Enable multi-model tabs for both continuous and turns mode when multiple models exist
                $use_multi_model_tabs = $has_multiple_models;
            }
            
            // Load conversation history for this page (not tied to a question anymore).
            // For multi-model tabs, we need to organize by modelid.
            $conversationhistory = [];
            $conversationhistory_by_model = []; // For multi-model tabs.
            
            if ($this->pageid && $USER->id) {
                $messages = $DB->get_records('harpiasurvey_conversations', [
                    'pageid' => $this->pageid,
                    'userid' => $USER->id
                ], 'timecreated ASC');
                
                foreach ($messages as $msg) {
                    // Skip placeholder messages created when a new root conversation is started.
                    if (trim($msg->content) === '[New conversation - send a message to start]') {
                        continue;
                    }
                    
                    $roleobj = new \stdClass();
                    $roleobj->{$msg->role} = true;
                    
                    $msgdata = [
                        'id' => $msg->id,
                        'role' => $roleobj,
                        'content' => format_text($msg->content, FORMAT_MARKDOWN, [
                            'context' => $this->context,
                            'noclean' => false,
                            'overflowdiv' => true
                        ]),
                        'parentid' => $msg->parentid,
                        'turn_id' => $msg->turn_id,
                        'modelid' => $msg->modelid ?? null,
                        'timecreated' => $msg->timecreated
                    ];
                    
                    $conversationhistory[] = $msgdata;
                    
                    // For multi-model tabs, organize by modelid.
                    if ($use_multi_model_tabs && !$isqamode && $msg->modelid) {
                        if (!isset($conversationhistory_by_model[$msg->modelid])) {
                            $conversationhistory_by_model[$msg->modelid] = [];
                        }
                        $conversationhistory_by_model[$msg->modelid][] = $msgdata;
                    }
                }
            }

            $qaquestions = [];
            $qaactiveid = null;
            if ($isqamode && !empty($conversationhistory)) {
                foreach ($conversationhistory as $msg) {
                    if (isset($msg['role']->user)) {
                        $preview = trim(strip_tags($msg['content']));
                        $preview = html_entity_decode($preview, ENT_QUOTES, 'UTF-8');
                        if (\core_text::strlen($preview) > 60) {
                            $preview = \core_text::substr($preview, 0, 60) . '...';
                        }
                        $qaquestions[] = [
                            'id' => $msg['id'],
                            'preview' => $preview,
                            'title' => $preview,
                            'is_active' => false,
                        ];
                        $qaactiveid = $msg['id'];
                    }
                }
                if ($qaactiveid !== null) {
                    $lastindex = count($qaquestions) - 1;
                    if ($lastindex >= 0) {
                        $qaquestions[$lastindex]['is_active'] = true;
                    }
                }
            }
            
            // Prepare model tabs data for multi-model interface.
            $model_tabs = [];
            if ($use_multi_model_tabs && !$isqamode) {
                // Capture $this in a variable for use in closure.
                $that = $this;
                // Helper function to format a question for template.
                $formatQuestion = function($question, $responses, $responsetimestamps) use ($DB, $that, $responsehistory, $formatresponse) {
                    // Load question settings from JSON.
                    $settings = [];
                    if (!empty($question->settings)) {
                        $settings = json_decode($question->settings, true) ?? [];
                    }
                    
                    // Load saved response for this question.
                    $savedresponse = $responses[$question->questionid] ?? null;
                    
                    // Get question options if it's a choice/select question.
                    $options = [];
                    $inputtype = 'radio';
                    $ismultiplechoice = false;
                    $optionsmap = [];
                    if (in_array($question->type, ['singlechoice', 'multiplechoice', 'select', 'likert'])) {
                        $inputtype = ($question->type === 'singlechoice' || $question->type === 'select' || $question->type === 'likert') ? 'radio' : 'checkbox';
                        $ismultiplechoice = ($question->type === 'multiplechoice');
                        
                        // For Likert, generate 1-5 options.
                        if ($question->type === 'likert') {
                            for ($i = 1; $i <= 5; $i++) {
                                $isselected = ($savedresponse && (string)$i === $savedresponse);
                                $options[] = [
                                    'id' => $i,
                                    'value' => (string)$i,
                                    'isdefault' => $isselected,
                                    'inputtype' => 'radio',
                                    'questionid' => $question->questionid,
                                    'nameattr' => 'question_' . $question->questionid,
                                ];
                                $optionsmap[(string)$i] = (string)$i;
                            }
                        } else {
                            // For other types, load from database.
                            $questionoptions = $DB->get_records('harpiasurvey_question_options', [
                                'questionid' => $question->questionid
                            ], 'sortorder ASC');
                            $nameattr = 'question_' . $question->questionid;
                            if ($ismultiplechoice) {
                                $nameattr .= '[]';
                            }
                            foreach ($questionoptions as $opt) {
                                $isselected = false;
                                if ($savedresponse !== null) {
                                    if ($ismultiplechoice) {
                                        $savedvalues = json_decode($savedresponse, true);
                                        if (is_array($savedvalues)) {
                                            $isselected = in_array((string)$opt->id, array_map('strval', $savedvalues));
                                        }
                                    } else {
                                        $isselected = ((string)$opt->id === (string)$savedresponse);
                                    }
                                } else {
                                    $isselected = (bool)$opt->isdefault;
                                }
                                
                                $options[] = [
                                    'id' => $opt->id,
                                    'value' => format_string($opt->value),
                                    'isdefault' => $isselected,
                                    'inputtype' => $inputtype,
                                    'questionid' => $question->questionid,
                                    'nameattr' => $nameattr,
                                ];
                                $optionsmap[(string)$opt->id] = format_string($opt->value);
                            }
                        }
                    }
                    
                    // Number field settings.
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
                    if (isset($responsehistory[$question->questionid])) {
                        foreach ($responsehistory[$question->questionid] as $historyitem) {
                            $historyitems[] = [
                                'time' => userdate($historyitem['timecreated'], get_string('strftimedatetimeshort', 'langconfig')),
                                'response' => $formatresponse($question->type, $historyitem['response'], $optionsmap)
                            ];
                        }
                    }
                    $historycount = count($historyitems);
                    $latesthistory = $historycount > 0 ? $responsehistory[$question->questionid][$historycount - 1]['timecreated'] : null;
                    $savedtimestamp = $latesthistory ?? ($responsetimestamps[$question->questionid] ?? null);
                    if ($historycount === 0 && array_key_exists($question->questionid, $responses)) {
                        $fallbacktimestamp = $savedtimestamp ?? time();
                        $historyitems[] = [
                            'time' => userdate($fallbacktimestamp, get_string('strftimedatetimeshort', 'langconfig')),
                            'response' => $formatresponse($question->type, $savedresponse, $optionsmap)
                        ];
                        $historycount = 1;
                        if (!$savedtimestamp) {
                            $savedtimestamp = $fallbacktimestamp;
                        }
                    }
                    
                    return [
                        'id' => $question->questionid,
                        'name' => format_string($question->name),
                        'type' => $question->type,
                        'is_required' => isset($question->required) ? (bool)$question->required : true,
                        'required' => isset($question->required) ? (int)$question->required : 1,
                        'description' => format_text($question->description, $question->descriptionformat, [
                            'context' => $that->context,
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
                        'saved_response' => $savedresponse,
                        'has_saved_response' => !empty($savedresponse),
                        'saved_timestamp' => $savedtimestamp,
                        'saved_datetime' => $savedtimestamp ? userdate($savedtimestamp, get_string('strftimedatetimeshort', 'langconfig')) : null,
                        'has_history' => ($historycount > 0),
                        'has_history_details' => ($historycount > 1),
                        'history_count' => $historycount,
                        'history_items' => array_reverse($historyitems),
                        'cmid' => $that->cmid,
                        'pageid' => $that->pageid,
                    ];
                };
                
                // Filter questions for each model.
                foreach ($models as $model) {
                    $modelhistory = $conversationhistory_by_model[$model['id']] ?? [];
                    
                    // Filter questions for this model based on model visibility rules.
                    $modelquestions = [];
                    foreach ($this->questions as $question) {
                        if (!$question->enabled) {
                            continue;
                        }
                        
                        // Check model visibility rules.
                        $showonlymodel = isset($question->show_only_model) && $question->show_only_model !== null ? (int)$question->show_only_model : null;
                        $hideonmodel = isset($question->hide_on_model) && $question->hide_on_model !== null ? (int)$question->hide_on_model : null;
                        
                        // If show_only_model is set, question only shows for that model.
                        if ($showonlymodel !== null) {
                            if ($showonlymodel != $model['id']) {
                                continue; // Skip this question for this model.
                            }
                        }
                        
                        // If hide_on_model is set, hide question for that model.
                        if ($hideonmodel !== null) {
                            if ($hideonmodel == $model['id']) {
                                continue; // Skip this question for this model.
                            }
                        }
                        
                        // Format and add question.
                        $modelquestions[] = $formatQuestion($question, $responses, $responsetimestamps);
                    }
                    
                    $model_tabs[] = [
                        'id' => $model['id'],
                        'name' => $model['name'],
                        'model' => $model['model'],
                        'conversation_history' => $modelhistory,
                        'has_history' => !empty($modelhistory),
                        'questions' => $modelquestions,
                        'has_questions' => !empty($modelquestions),
                        'cmid' => $that->cmid,
                        'pageid' => $that->pageid
                    ];
                }
            }
            
            // Prepare JSON for turn evaluation questions (for JavaScript).
            $turnevaluationquestionsjson = '';
            if (!empty($turnevaluationquestions)) {
                $turnevaluationquestionsjson = json_encode($turnevaluationquestions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $aichatdata = [
                'models' => $models,
                'has_models' => !empty($models),
                'has_multiple_models' => $has_multiple_models,
                'use_multi_model_tabs' => ($use_multi_model_tabs && !$isqamode),
                'model_tabs' => $model_tabs,
                'behavior' => $pagebehavior,
                'is_turns_mode' => $isturnsmode, // Explicit boolean.
                'is_qa_mode' => $isqamode,
                'show_sidebar' => ($isturnsmode || $pagebehavior === 'continuous'), // Show sidebar for turns and continuous mode.
                'conversation_history' => $conversationhistory,
                'has_history' => !empty($conversationhistory),
                'qa_questions' => $qaquestions,
                'has_qa_questions' => !empty($qaquestions),
                'qa_active_id' => $qaactiveid,
                'turn_evaluation_questions' => $turnevaluationquestions,
                'has_turn_evaluation_questions' => !empty($turnevaluationquestions),
                'turn_evaluation_questions_json' => $turnevaluationquestionsjson,
                'min_turns' => $this->page->min_turns ?? null,
                'has_min_turns' => ($this->page->min_turns !== null),
                'max_turns' => $this->page->max_turns ?? null,
                'has_max_turns' => ($this->page->max_turns !== null)
            ];
        } else {
            // Ensure aichatdata is always an array, even if not an aichat page
            $aichatdata = [
                'models' => [],
                'has_models' => false,
                'behavior' => 'continuous',
                'is_turns_mode' => false,
                'is_qa_mode' => false,
                'conversation_history' => [],
                'has_history' => false,
                'turn_evaluation_questions' => [],
                'has_turn_evaluation_questions' => false,
                'turn_evaluation_questions_json' => ''
            ];
        }

        // Load subpages (only for aichat pages with turns mode).
        $subpageslist = [];
        if ($hasaichat && $this->page->behavior === 'turns') {
            $subpages = $DB->get_records('harpiasurvey_subpages', ['pageid' => $this->pageid, 'available' => 1], 'sortorder ASC');
            foreach ($subpages as $subpage) {
                // Format description.
                $subpagedescription = format_text($subpage->description, $subpage->descriptionformat, [
                    'context' => $this->context,
                    'noclean' => false,
                    'overflowdiv' => true
                ]);
                
                // Load questions for this subpage.
                $subpagequestions = [];
                $subpagequestionrecords = $DB->get_records_sql(
                    "SELECT sq.id, sq.subpageid, sq.questionid, sq.enabled, sq.required, sq.sortorder,
                            sq.turn_visibility_type, sq.turn_number,
                            q.id as qid, q.name, q.type, q.description, q.descriptionformat, q.settings
                       FROM {harpiasurvey_subpage_questions} sq
                       JOIN {harpiasurvey_questions} q ON q.id = sq.questionid
                      WHERE sq.subpageid = :subpageid
                        AND sq.enabled = 1
                      ORDER BY sq.sortorder ASC",
                    ['subpageid' => $subpage->id]
                );
                
                // Process subpage questions similar to page questions.
                foreach ($subpagequestionrecords as $sq) {
                    // Load question settings from JSON.
                    $settings = [];
                    if (!empty($sq->settings)) {
                        $settings = json_decode($sq->settings, true) ?? [];
                    }
                    
                    // Load saved response for this question.
                    $savedresponse = null;
                    if (isset($responses[$sq->questionid])) {
                        $savedresponse = $responses[$sq->questionid];
                    }
                    
                    // Get question options if it's a choice/select question.
                    $options = [];
                    $inputtype = 'radio';
                    $ismultiplechoice = false;
                    if (in_array($sq->type, ['singlechoice', 'multiplechoice', 'select', 'likert'])) {
                        $options = $DB->get_records('harpiasurvey_question_options', ['questionid' => $sq->questionid], 'sortorder ASC');
                        if ($sq->type === 'multiplechoice') {
                            $inputtype = 'checkbox';
                            $ismultiplechoice = true;
                        }
                    }
                    
                    // Format question description.
                    $questiondescription = format_text($sq->description, $sq->descriptionformat, [
                        'context' => $this->context,
                        'noclean' => false,
                        'overflowdiv' => true
                    ]);
                    
                    // Prepare options for template.
                    $optionslist = [];
                    $optionsmap = [];
                    foreach ($options as $opt) {
                        $optionslist[] = [
                            'id' => $opt->id,
                            'value' => format_string($opt->value),
                            'isdefault' => (bool)$opt->isdefault,
                        ];
                        $optionsmap[(string)$opt->id] = format_string($opt->value);
                    }
                    
                    // Determine question type flags.
                    $isnumber = ($sq->type === 'number');
                    $isshorttext = ($sq->type === 'shorttext');
                    $islongtext = ($sq->type === 'longtext');
                    $islikert = ($sq->type === 'likert');
                    
                    $historyitems = [];
                    if (isset($responsehistory[$sq->questionid])) {
                        foreach ($responsehistory[$sq->questionid] as $historyitem) {
                            $historyitems[] = [
                                'time' => userdate($historyitem['timecreated'], get_string('strftimedatetimeshort', 'langconfig')),
                                'response' => $formatresponse($sq->type, $historyitem['response'], $optionsmap)
                            ];
                        }
                    }
                    $historycount = count($historyitems);
                    $latesthistory = $historycount > 0 ? $responsehistory[$sq->questionid][$historycount - 1]['timecreated'] : null;
                    $savedtimestamp = $latesthistory ?? null;
                    if ($historycount === 0 && $savedresponse !== null) {
                        $fallbacktimestamp = $savedtimestamp ?? time();
                        $historyitems[] = [
                            'time' => userdate($fallbacktimestamp, get_string('strftimedatetimeshort', 'langconfig')),
                            'response' => $formatresponse($sq->type, $savedresponse, $optionsmap)
                        ];
                        $historycount = 1;
                        if (!$savedtimestamp) {
                            $savedtimestamp = $fallbacktimestamp;
                        }
                    }

                    $subpagequestions[] = [
                        'id' => $sq->qid,
                        'questionid' => $sq->questionid,
                        'name' => format_string($sq->name),
                        'type' => $sq->type,
                        'is_required' => isset($sq->required) ? (bool)$sq->required : true,
                        'required' => isset($sq->required) ? (int)$sq->required : 1,
                        'description' => $questiondescription,
                        'has_description' => !empty(trim(strip_tags($questiondescription))),
                        'options' => $optionslist,
                        'has_options' => !empty($optionslist),
                        'is_select' => ($sq->type === 'select'),
                        'is_likert' => $islikert,
                        'is_multiple_choice' => $ismultiplechoice,
                        'input_type' => $inputtype,
                        'is_number' => $isnumber,
                        'is_shorttext' => $isshorttext,
                        'is_longtext' => $islongtext,
                        'saved_response' => $savedresponse,
                        'has_saved_response' => !empty($savedresponse),
                        'saved_timestamp' => $savedtimestamp,
                        'saved_datetime' => $savedtimestamp ? userdate($savedtimestamp, get_string('strftimedatetimeshort', 'langconfig')) : null,
                        'has_history' => ($historycount > 0),
                        'has_history_details' => ($historycount > 1),
                        'history_count' => $historycount,
                        'history_items' => array_reverse($historyitems),
                        'settings' => $settings,
                        'turn_visibility_type' => $sq->turn_visibility_type ?? 'all_turns',
                        'turn_number' => $sq->turn_number,
                    ];
                }
                
                $subpageslist[] = [
                    'id' => $subpage->id,
                    'title' => format_string($subpage->title),
                    'description' => $subpagedescription,
                    'has_description' => !empty(trim(strip_tags($subpagedescription))),
                    'turn_visibility_type' => $subpage->turn_visibility_type,
                    'turn_number' => $subpage->turn_number,
                    'questions' => $subpagequestions,
                    'has_questions' => !empty($subpagequestions),
                ];
            }
        }

        $pagetitle = format_string($this->page->title);
        $pagetitle = html_entity_decode($pagetitle, ENT_QUOTES, 'UTF-8');

        return [
            'title' => $pagetitle,
            'description' => $description,
            'has_description' => $hasdescription,
            'editurl' => $this->editurl,
            'has_editurl' => !empty($this->editurl),
            'deleteurl' => $this->deleteurl,
            'has_deleteurl' => !empty($this->deleteurl),
            'canmanage' => $this->canmanage,
            'can_navigate' => $this->cannavigate,
            'questions' => $questionslist,
            'has_questions' => !empty($questionslist),
            'has_non_aiconversation_questions' => $hasnonaiconversationquestions,
            'turn_evaluation_questions' => $turnevaluationquestions,
            'has_turn_evaluation_questions' => !empty($turnevaluationquestions),
            'pagination' => $pagination,
            'has_pagination' => !empty($pagination),
            'navigation_mode' => $this->navigation_mode,
            'is_only_forward' => (!$this->cannavigate && $this->navigation_mode === 'only_forward'),
            'canmanage' => $this->canmanage,
            'wwwroot' => $CFG->wwwroot,
            'cmid' => $this->cmid,
            'pageid' => $this->pageid,
            'is_aichat_page' => $hasaichat,
            'aichat' => $aichatdata,
            'subpages' => $subpageslist,
            'has_subpages' => !empty($subpageslist),
            'initial_turn' => $this->initial_turn,
            'has_initial_turn' => ($this->initial_turn !== null),
        ];
    }
}
