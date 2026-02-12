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

namespace mod_harpiasurvey\forms;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/lib/formslib.php');

/**
 * Page form class.
 *
 * @package     mod_harpiasurvey
 * @copyright   2025 Your Name
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page extends \moodleform {
    /**
     * The context for this form.
     *
     * @var \context
     */
    protected $context;

    /**
     * Additional form data to set after definition.
     *
     * @var \stdClass
     */
    protected $_formdata;

    /**
     * Class constructor
     *
     * @param moodle_url|string $url
     * @param $formdata
     * @param $context
     */
    public function __construct($url, $formdata, $context) {
        $this->context = $context;
        // Ensure URL is a moodle_url object to preserve parameters.
        if (is_string($url)) {
            $url = new \moodle_url($url);
        }
        parent::__construct($url, $formdata);
    }

    /**
     * The form definition.
     *
     * @throws \coding_exception
     */
    public function definition() {
        $mform = $this->_form;

        // Hidden fields.
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        if (isset($this->_customdata->id)) {
            $mform->setDefault('id', $this->_customdata->id);
        }

        $mform->addElement('hidden', 'cmid');
        $mform->setType('cmid', PARAM_INT);
        $mform->setDefault('cmid', $this->_customdata->cmid);
        $mform->setConstant('cmid', $this->_customdata->cmid);

        $mform->addElement('hidden', 'form_id');
        $mform->setType('form_id', PARAM_INT);
        $mform->setDefault('form_id', $this->_customdata->cmid);
        $mform->setConstant('form_id', $this->_customdata->cmid);

        $mform->addElement('hidden', 'experimentid');
        $mform->setType('experimentid', PARAM_INT);
        $mform->setDefault('experimentid', $this->_customdata->experimentid);
        $mform->setConstant('experimentid', $this->_customdata->experimentid);

        // General section header.
        $mform->addElement('header', 'general', get_string('general', 'form'));

        // Title field.
        $mform->addElement('text', 'title', get_string('title', 'mod_harpiasurvey'), ['size' => '64']);
        $mform->addRule('title', get_string('required'), 'required', null, 'client');
        $mform->addRule('title', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->setType('title', PARAM_TEXT);
        if (isset($this->_customdata->title)) {
            $mform->setDefault('title', $this->_customdata->title);
        }

        // Description field (editor).
        $customdata = $this->_customdata ?? new \stdClass();
        
        // Ensure description and descriptionformat properties exist.
        if (!isset($customdata->description)) {
            $customdata->description = '';
        }
        if (!isset($customdata->descriptionformat)) {
            $customdata->descriptionformat = FORMAT_HTML;
        }
        if (!isset($customdata->id)) {
            $customdata->id = null;
        }

        $editordata = file_prepare_standard_editor(
            $customdata,
            'description',
            $this->get_editor_options(),
            $this->context,
            'mod_harpiasurvey',
            'page',
            $customdata->id
        );

        $mform->addElement('editor', 'description_editor', get_string('description', 'mod_harpiasurvey'), null, $this->get_editor_options());
        $mform->setType('description_editor', PARAM_CLEANHTML);
        if (isset($editordata->description_editor)) {
            $mform->setDefault('description_editor', $editordata->description_editor);
        }

        // Evaluation section copy (shown above evaluation questions).
        $mform->addElement('text', 'evaluationtitle', get_string('evaluationtitle', 'mod_harpiasurvey'), ['size' => '64']);
        $mform->setType('evaluationtitle', PARAM_TEXT);
        $mform->addHelpButton('evaluationtitle', 'evaluationtitle', 'mod_harpiasurvey');
        if (isset($this->_customdata->evaluationtitle)) {
            $mform->setDefault('evaluationtitle', $this->_customdata->evaluationtitle);
        }

        $mform->addElement('textarea', 'evaluationdescription', get_string('evaluationdescription', 'mod_harpiasurvey'),
            ['rows' => 3, 'cols' => 60]);
        $mform->setType('evaluationdescription', PARAM_TEXT);
        $mform->addHelpButton('evaluationdescription', 'evaluationdescription', 'mod_harpiasurvey');
        if (isset($this->_customdata->evaluationdescription)) {
            $mform->setDefault('evaluationdescription', $this->_customdata->evaluationdescription);
        }

        // Type dropdown.
        $typeoptions = [
            'opening' => get_string('typegeneral', 'mod_harpiasurvey'),
            // 'demographic' => get_string('typedemographic', 'mod_harpiasurvey'),
            // 'interaction' => get_string('typeinteraction', 'mod_harpiasurvey'),
            // 'feedback' => get_string('typefeedback', 'mod_harpiasurvey'),
            'aichat' => get_string('typeaichat', 'mod_harpiasurvey'),
        ];
        $mform->addElement('select', 'type', get_string('type', 'mod_harpiasurvey'), $typeoptions);
        $mform->setType('type', PARAM_ALPHANUMEXT);
        if (isset($this->_customdata->type)) {
            $mform->setDefault('type', $this->_customdata->type);
        } else {
            $mform->setDefault('type', 'opening');
        }
        // Only show evaluation copy fields for AI evaluation pages.
        $mform->hideIf('evaluationtitle', 'type', 'neq', 'aichat');
        $mform->hideIf('evaluationdescription', 'type', 'neq', 'aichat');
        
        // Behavior dropdown (only for aichat pages).
        $behavioroptions = [
            'qa' => get_string('pagebehaviorqa', 'mod_harpiasurvey'),
            'continuous' => get_string('pagebehaviorcontinuous', 'mod_harpiasurvey'),
            'turns' => get_string('pagebehaviorturns', 'mod_harpiasurvey'),
        ];
        $mform->addElement('select', 'behavior', get_string('pagebehavior', 'mod_harpiasurvey'), $behavioroptions);
        $mform->addHelpButton('behavior', 'pagebehavior', 'mod_harpiasurvey');
        $mform->setType('behavior', PARAM_ALPHANUMEXT);
        if (isset($this->_customdata->behavior)) {
            $mform->setDefault('behavior', $this->_customdata->behavior);
        } else {
            $mform->setDefault('behavior', 'continuous');
        }
        // Only show behavior field for aichat pages.
        $mform->hideIf('behavior', 'type', 'neq', 'aichat');

        // Min turns field (only for turns behavior).
        $mform->addElement('text', 'min_turns', get_string('minturns', 'mod_harpiasurvey'), ['size' => '5']);
        $mform->setType('min_turns', PARAM_INT);
        $mform->addHelpButton('min_turns', 'minturns', 'mod_harpiasurvey');
        $mform->hideIf('min_turns', 'type', 'neq', 'aichat');
        $mform->hideIf('min_turns', 'behavior', 'neq', 'turns');
        // Set default value if available in customdata.
        if (isset($this->_customdata->min_turns) && $this->_customdata->min_turns !== '' && $this->_customdata->min_turns !== null) {
            $mform->setDefault('min_turns', (string)(int)$this->_customdata->min_turns);
        }

        // Max turns field (only for turns behavior).
        $mform->addElement('text', 'max_turns', get_string('maxturns', 'mod_harpiasurvey'), ['size' => '5']);
        $mform->setType('max_turns', PARAM_INT);
        $mform->addHelpButton('max_turns', 'maxturns', 'mod_harpiasurvey');
        $mform->hideIf('max_turns', 'type', 'neq', 'aichat');
        $mform->hideIf('max_turns', 'behavior', 'neq', 'turns');
        // Set default value if available in customdata.
        if (isset($this->_customdata->max_turns) && $this->_customdata->max_turns !== '' && $this->_customdata->max_turns !== null) {
            $mform->setDefault('max_turns', (string)(int)$this->_customdata->max_turns);
        }

        // Model selection (only for aichat pages).
        global $DB;
        $harpiasurveyid = isset($this->_customdata->harpiasurveyid) ? $this->_customdata->harpiasurveyid : 0;
        $experimentid = isset($this->_customdata->experimentid) ? $this->_customdata->experimentid : 0;
        if ($harpiasurveyid && $experimentid) {
            // Limit available page models to those linked to this experiment.
            $models = $DB->get_records_sql(
                "SELECT m.*
                   FROM {harpiasurvey_models} m
                   JOIN {harpiasurvey_experiment_models} em ON em.modelid = m.id
                  WHERE em.experimentid = :experimentid
                    AND m.harpiasurveyid = :harpiasurveyid
                    AND m.enabled = 1
                  ORDER BY m.name ASC",
                [
                    'experimentid' => $experimentid,
                    'harpiasurveyid' => $harpiasurveyid
                ]
            );
            $modeloptions = [];
            foreach ($models as $model) {
                $modeloptions[$model->id] = $model->name . ' (' . $model->model . ')';
            }
            
            if (!empty($modeloptions)) {
                // Load selected models for this page BEFORE adding the element.
                $pageid = isset($this->_customdata->id) ? $this->_customdata->id : 0;
                $selectedmodelids = [];
                if ($pageid) {
                    $selectedmodels = $DB->get_records('harpiasurvey_page_models', ['pageid' => $pageid], '', 'modelid');
                    $selectedmodelids = array_keys($selectedmodels);
                }
                
                $mform->addElement('autocomplete', 'pagemodels', get_string('aimodels', 'mod_harpiasurvey'), $modeloptions, [
                    'multiple' => true,
                    'noselectionstring' => get_string('noselection', 'mod_harpiasurvey'),
                ]);
                $mform->setType('pagemodels', PARAM_INT);
                $mform->addHelpButton('pagemodels', 'aimodels', 'mod_harpiasurvey');
                
                // Set default values if we have selected models.
                // Note: setDefault must be called before hideIf to ensure values are loaded.
                if (!empty($selectedmodelids)) {
                    $mform->setDefault('pagemodels', $selectedmodelids);
                }
                
                // Only show when type is aichat.
                // hideIf must be called after setDefault to ensure values are preserved.
                $mform->hideIf('pagemodels', 'type', 'neq', 'aichat');
            } else {
                // Even if no models are available, add a message field to inform the user.
                $mform->addElement('static', 'pagemodels_none', '', 
                    '<div class="alert alert-info">' . get_string('nomodelsavailable', 'mod_harpiasurvey') . '</div>');
                $mform->hideIf('pagemodels_none', 'type', 'neq', 'aichat');
            }
        }

        // Available field (checkbox/toggle).
        $mform->addElement('advcheckbox', 'available', get_string('available', 'mod_harpiasurvey'));
        $mform->setType('available', PARAM_BOOL);
        $mform->setDefault('available', 1);
        if (isset($this->_customdata->available)) {
            $mform->setDefault('available', $this->_customdata->available ? 1 : 0);
        }

        // Questions section header.
        $mform->addElement('header', 'questions', get_string('questions', 'mod_harpiasurvey'));
        
        // Questions table will be added via HTML element.
        $pageid = isset($this->_customdata->id) ? $this->_customdata->id : 0;
        $experimentid = isset($this->_customdata->experimentid) ? $this->_customdata->experimentid : 0;
        $cmid = isset($this->_customdata->cmid) ? $this->_customdata->cmid : 0;
        
        if ($pageid) {
            // Load questions for this page.
            global $DB, $OUTPUT;
            
            $pagequestions = $DB->get_records_sql(
                "SELECT pq.id, pq.pageid, pq.questionid, pq.enabled, pq.required, pq.sortorder, pq.timecreated,
                        pq.min_turn, pq.show_only_turn, pq.hide_on_turn, pq.show_only_model, pq.hide_on_model,
                        q.name, q.type
                   FROM {harpiasurvey_page_questions} pq
                   JOIN {harpiasurvey_questions} q ON q.id = pq.questionid
                  WHERE pq.pageid = :pageid
                  ORDER BY pq.sortorder ASC",
                ['pageid' => $pageid]
            );
            
            // Add spacing after the "Questions" header.
            $questionstable = '<div class="mb-3"></div>';
            
            if (!empty($pagequestions)) {
                // Check if this is a turns-based aichat page to show turn visibility fields.
                $showturnfields = false;
                $showmodelfields = false;
                if (isset($this->_customdata->type) && $this->_customdata->type === 'aichat') {
                    if (isset($this->_customdata->behavior) && $this->_customdata->behavior === 'turns') {
                        $showturnfields = true;
                    }
                    // Show model fields for continuous mode with multiple models (multi-model).
                    if (isset($this->_customdata->behavior) && $this->_customdata->behavior === 'continuous') {
                        // Check if page has multiple models.
                        $pagemodels = $DB->get_records('harpiasurvey_page_models', ['pageid' => $pageid]);
                        if (count($pagemodels) > 1) {
                            $showmodelfields = true;
                        }
                    }
                }
                
                $questionstable .= '<table class="table table-hover table-bordered" style="width: 100%;">';
                $questionstable .= '<thead class="table-dark">';
                $questionstable .= '<tr>';
                $questionstable .= '<th scope="col">' . get_string('question', 'mod_harpiasurvey') . '</th>';
                $questionstable .= '<th scope="col">' . get_string('type', 'mod_harpiasurvey') . '</th>';
                $questionstable .= '<th scope="col">' . get_string('enabled', 'mod_harpiasurvey') . '</th>';
                $questionstable .= '<th scope="col">' . get_string('required') . '</th>';
                if ($showturnfields) {
                    $questionstable .= '<th scope="col">' . get_string('showonlyturn', 'mod_harpiasurvey') . '</th>';
                    $questionstable .= '<th scope="col">' . get_string('hideonturn', 'mod_harpiasurvey') . '</th>';
                }
                if ($showmodelfields) {
                    $questionstable .= '<th scope="col">' . get_string('showonlymodel', 'mod_harpiasurvey') . '</th>';
                    $questionstable .= '<th scope="col">' . get_string('hideonmodel', 'mod_harpiasurvey') . '</th>';
                }
                $questionstable .= '<th scope="col">' . get_string('actions', 'mod_harpiasurvey') . '</th>';
                $questionstable .= '</tr>';
                $questionstable .= '</thead>';
                $questionstable .= '<tbody>';
                
                foreach ($pagequestions as $pq) {
                    $questionstable .= '<tr>';
                    $questionstable .= '<td>' . format_string($pq->name) . '</td>';
                    $questionstable .= '<td>' . get_string('type' . $pq->type, 'mod_harpiasurvey') . '</td>';
                    
                    // Enabled checkbox.
                    $enabledid = 'question_enabled_' . $pq->id;
                    $enabledchecked = $pq->enabled ? 'checked' : '';
                    $questionstable .= '<td>';
                    $questionstable .= '<input type="checkbox" id="' . $enabledid . '" name="question_enabled[' . $pq->id . ']" value="1" ' . $enabledchecked . '>';
                    $questionstable .= '</td>';

                    // Required checkbox.
                    $requiredid = 'question_required_' . $pq->id;
                    $requiredchecked = $pq->required ? 'checked' : '';
                    $questionstable .= '<td>';
                    $questionstable .= '<input type="checkbox" id="' . $requiredid . '" name="question_required[' . $pq->id . ']" value="1" ' . $requiredchecked . '>';
                    $questionstable .= '</td>';
                    
                    // Turn visibility fields (only for turns-based aichat pages).
                    if ($showturnfields) {
                        // Show only on turn N field.
                        $showonlyturnid = 'question_show_only_turn_' . $pq->id;
                        $showonlyturnvalue = isset($pq->show_only_turn) && $pq->show_only_turn !== null ? (int)$pq->show_only_turn : '';
                        $questionstable .= '<td>';
                        $questionstable .= '<input type="number" id="' . $showonlyturnid . '" name="question_show_only_turn[' . $pq->id . ']" value="' . htmlspecialchars($showonlyturnvalue) . '" min="1" style="width: 80px;" placeholder="' . get_string('allturns', 'mod_harpiasurvey') . '">';
                        $questionstable .= '</td>';
                        
                        // Hide on turn N field.
                        $hideonturnid = 'question_hide_on_turn_' . $pq->id;
                        $hideonturnvalue = isset($pq->hide_on_turn) && $pq->hide_on_turn !== null ? (int)$pq->hide_on_turn : '';
                        $questionstable .= '<td>';
                        $questionstable .= '<input type="number" id="' . $hideonturnid . '" name="question_hide_on_turn[' . $pq->id . ']" value="' . htmlspecialchars($hideonturnvalue) . '" min="1" style="width: 80px;" placeholder="' . get_string('none', 'mod_harpiasurvey') . '">';
                        $questionstable .= '</td>';
                    }
                    
                    // Model visibility fields (only for continuous multi-model aichat pages).
                    if ($showmodelfields) {
                        // Get models for this page to show in dropdown.
                        $pagemodels = $DB->get_records('harpiasurvey_page_models', ['pageid' => $pageid], '', 'modelid');
                        $modelids = array_keys($pagemodels);
                        $models = [];
                        if (!empty($modelids)) {
                            $models = $DB->get_records_list('harpiasurvey_models', 'id', $modelids, 'name ASC');
                        }
                        
                        // Show only for model dropdown.
                        $showonlymodelid = 'question_show_only_model_' . $pq->id;
                        $showonlymodelvalue = isset($pq->show_only_model) && $pq->show_only_model !== null ? (int)$pq->show_only_model : '';
                        $questionstable .= '<td>';
                        $questionstable .= '<select id="' . $showonlymodelid . '" name="question_show_only_model[' . $pq->id . ']" style="width: 150px;">';
                        $questionstable .= '<option value="">' . get_string('allmodels', 'mod_harpiasurvey') . '</option>';
                        foreach ($models as $model) {
                            $selected = ($showonlymodelvalue == $model->id) ? 'selected' : '';
                            $questionstable .= '<option value="' . $model->id . '" ' . $selected . '>' . format_string($model->name) . '</option>';
                        }
                        $questionstable .= '</select>';
                        $questionstable .= '</td>';
                        
                        // Hide on model dropdown.
                        $hideonmodelid = 'question_hide_on_model_' . $pq->id;
                        $hideonmodelvalue = isset($pq->hide_on_model) && $pq->hide_on_model !== null ? (int)$pq->hide_on_model : '';
                        $questionstable .= '<td>';
                        $questionstable .= '<select id="' . $hideonmodelid . '" name="question_hide_on_model[' . $pq->id . ']" style="width: 150px;">';
                        $questionstable .= '<option value="">' . get_string('none', 'mod_harpiasurvey') . '</option>';
                        foreach ($models as $model) {
                            $selected = ($hideonmodelvalue == $model->id) ? 'selected' : '';
                            $questionstable .= '<option value="' . $model->id . '" ' . $selected . '>' . format_string($model->name) . '</option>';
                        }
                        $questionstable .= '</select>';
                        $questionstable .= '</td>';
                    }
                    
                    // Actions: remove from page.
                    $removeurl = new \moodle_url('/mod/harpiasurvey/remove_page_question.php', [
                        'id' => $cmid,
                        'experiment' => $experimentid,
                        'page' => $pageid,
                        'question' => $pq->questionid,
                        'sesskey' => sesskey()
                    ]);
                    $removeicon = $OUTPUT->pix_icon('t/delete', get_string('remove'), 'moodle', ['class' => 'icon']);
                    $questionstable .= '<td>';
                    $questionstable .= '<a href="' . $removeurl->out(false) . '" title="' . get_string('remove', 'mod_harpiasurvey') . '">' . $removeicon . '</a>';
                    $questionstable .= '</td>';
                    $questionstable .= '</tr>';
                }
                
                $questionstable .= '</tbody>';
                $questionstable .= '</table>';
            } else {
                $questionstable .= '<p class="text-muted">' . get_string('noquestionsonpage', 'mod_harpiasurvey') . '</p>';
            }
            
            // Action button - Question bank (opens modal).
            // JavaScript initialization is handled in edit_page.php via js_call_amd.
            $questionstable .= '<div class="mt-2 mb-4">';
            $questionstable .= '<button type="button" class="btn btn-secondary mr-2 question-bank-modal-trigger" data-cmid="' . $cmid . '" data-pageid="' . $pageid . '">' . get_string('questionbank', 'mod_harpiasurvey') . '</button>';
            $questionstable .= '</div>';
            
            $mform->addElement('html', $questionstable);
        } else {
            // New page - show message.
            $mform->addElement('static', 'questions_info', '', '<p class="text-muted">' . get_string('savepagetoaddquestions', 'mod_harpiasurvey') . '</p>');
        }


        // Add action buttons.
        $this->add_action_buttons(true, get_string('save', 'mod_harpiasurvey'));
    }

    /**
     * Get editor options for description field.
     *
     * @return array
     */
    public function get_editor_options() {
        return [
            'maxfiles' => EDITOR_UNLIMITED_FILES,
            'maxbytes' => 0,
            'context' => $this->context,
            'noclean' => true,
            'autosave' => true,
            'overflowdiv' => true,
            'enable_filemanagement' => true,
        ];
    }

    /**
     * Override definition_after_data to ensure autocomplete values are set correctly.
     */
    public function definition_after_data() {
        parent::definition_after_data();
        
        $mform = $this->_form;
        $pageid = isset($this->_customdata->id) ? $this->_customdata->id : 0;
        
        // Set min_turns and max_turns values from customdata (for editing).
        // This is called after form definition, so we need to explicitly set values
        // even if they were set in definition() because hideIf might prevent them from showing.
        if (isset($this->_customdata->min_turns) && $mform->elementExists('min_turns')) {
            $minturns = $this->_customdata->min_turns;
            // Convert to string for form display.
            if ($minturns === '' || $minturns === null) {
                $minturnsvalue = '';
            } else {
                $minturnsvalue = (string)(int)$minturns;
            }
            // Force set the value using set_data approach.
            $currentvalue = $mform->getElementValue('min_turns');
            if ($currentvalue === null || (is_array($currentvalue) && empty($currentvalue))) {
                $mform->setDefault('min_turns', $minturnsvalue);
            }
        }
        
        if (isset($this->_customdata->max_turns) && $mform->elementExists('max_turns')) {
            $maxturns = $this->_customdata->max_turns;
            // Convert to string for form display.
            if ($maxturns === '' || $maxturns === null) {
                $maxturnsvalue = '';
            } else {
                $maxturnsvalue = (string)(int)$maxturns;
            }
            // Force set the value using set_data approach.
            $currentvalue = $mform->getElementValue('max_turns');
            if ($currentvalue === null || (is_array($currentvalue) && empty($currentvalue))) {
                $mform->setDefault('max_turns', $maxturnsvalue);
            }
        }
        
        // Ensure pagemodels values are set if they exist in customdata.
        if ($pageid && $mform->elementExists('pagemodels')) {
            global $DB;
            $selectedmodels = $DB->get_records('harpiasurvey_page_models', ['pageid' => $pageid], '', 'modelid');
            $selectedmodelids = array_keys($selectedmodels);
            
            if (!empty($selectedmodelids)) {
                // Get current value from form.
                $currentvalue = $mform->getElementValue('pagemodels');
                
                // If no value is set, set it to the selected models.
                if (empty($currentvalue)) {
                    $mform->setDefault('pagemodels', $selectedmodelids);
                }
            }
        }
    }

    /**
     * Form validation.
     *
     * @param array $data Form data
     * @param array $files Files
     * @return array Errors
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        
        // Validate min_turns and max_turns if behavior is turns.
        if (isset($data['behavior']) && $data['behavior'] === 'turns') {
            $minturns = !empty($data['min_turns']) ? (int)$data['min_turns'] : null;
            $maxturns = !empty($data['max_turns']) ? (int)$data['max_turns'] : null;
            
            // Min turns must be positive if set.
            if ($minturns !== null && $minturns < 1) {
                $errors['min_turns'] = get_string('invalidnumber', 'mod_harpiasurvey');
            }
            
            // Max turns must be positive if set.
            if ($maxturns !== null && $maxturns < 1) {
                $errors['max_turns'] = get_string('invalidnumber', 'mod_harpiasurvey');
            }
            
            // Max turns must be >= min turns if both are set.
            if ($minturns !== null && $maxturns !== null && $maxturns < $minturns) {
                $errors['max_turns'] = get_string('maxturnsmustbegreaterthanminturns', 'mod_harpiasurvey');
            }
        }
        
        return $errors;
    }
}
