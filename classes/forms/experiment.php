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
 * Experiment form class.
 *
 * @package     mod_harpiasurvey
 * @copyright   2025 Your Name
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class experiment extends \moodleform {
    /**
     * The context for this form.
     *
     * @var \context
     */
    protected $context;

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
     * @throws \dml_exception
     */
    public function definition() {
        global $DB;

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

        // Also add the course module ID as 'id' parameter for the form action.
        $mform->addElement('hidden', 'form_id');
        $mform->setType('form_id', PARAM_INT);
        $mform->setDefault('form_id', $this->_customdata->cmid);
        $mform->setConstant('form_id', $this->_customdata->cmid);

        $mform->addElement('hidden', 'harpiasurveyid');
        $mform->setType('harpiasurveyid', PARAM_INT);
        $mform->setDefault('harpiasurveyid', $this->_customdata->harpiasurveyid);
        $mform->setConstant('harpiasurveyid', $this->_customdata->harpiasurveyid);

        // General section header.
        $mform->addElement('header', 'general', get_string('general', 'form'));

        // Name field.
        $mform->addElement('text', 'name', get_string('experimentname', 'mod_harpiasurvey'), ['size' => '64']);
        $mform->addRule('name', get_string('required'), 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->setType('name', PARAM_TEXT);
        if (isset($this->_customdata->name)) {
            $mform->setDefault('name', $this->_customdata->name);
        }

        // Type field (dropdown).
        $typeoptions = [
            'questions_answers' => get_string('typequestionsanswers', 'mod_harpiasurvey'),
            'dialogue' => get_string('typedialogue', 'mod_harpiasurvey'),
        ];
        $mform->addElement('select', 'type', get_string('type', 'mod_harpiasurvey'), $typeoptions);
        $mform->setType('type', PARAM_ALPHANUMEXT);
        if (isset($this->_customdata->type)) {
            $mform->setDefault('type', $this->_customdata->type);
        } else {
            $mform->setDefault('type', 'questions_answers');
        }

        // Models field (autocomplete with multiple selection).
        $models = $DB->get_records('harpiasurvey_models', ['harpiasurveyid' => $this->_customdata->harpiasurveyid], 'name ASC');
        $modeloptions = [];
        foreach ($models as $model) {
            $modeloptions[$model->id] = $model->name;
        }

        $autocompleteoptions = [
            'multiple' => true,
            'noselectionstring' => get_string('searchmodels', 'mod_harpiasurvey'),
        ];

        $mform->addElement(
            'autocomplete',
            'models',
            get_string('models', 'mod_harpiasurvey'),
            $modeloptions,
            $autocompleteoptions
        );

        // Set selected models if editing.
        if (isset($this->_customdata->id)) {
            $selectedmodels = $DB->get_records('harpiasurvey_experiment_models', ['experimentid' => $this->_customdata->id], '', 'modelid');
            if (!empty($selectedmodels)) {
                $mform->getElement('models')->setSelected(array_keys($selectedmodels));
            }
        }

        // Validation field (cross-validation dropdown).
        $validationoptions = [
            'none' => get_string('validationnone', 'mod_harpiasurvey'),
            'crossvalidation' => get_string('validationcrossvalidation', 'mod_harpiasurvey'),
        ];
        $mform->addElement('select', 'validation', get_string('validation', 'mod_harpiasurvey'), $validationoptions);
        $mform->setType('validation', PARAM_ALPHANUMEXT);
        if (isset($this->_customdata->hascrossvalidation)) {
            $mform->setDefault('validation', $this->_customdata->hascrossvalidation ? 'crossvalidation' : 'none');
        } else {
            $mform->setDefault('validation', 'none');
        }

        // Description field (editor).
        $customdata = $this->_customdata ?? new stdClass();
        
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
            'description',
            $customdata->id
        );

        $mform->addElement('editor', 'description_editor', get_string('description', 'mod_harpiasurvey'), null, $this->get_editor_options());
        $mform->setType('description_editor', PARAM_CLEANHTML);
        if (isset($editordata->description_editor)) {
            $mform->setDefault('description_editor', $editordata->description_editor);
        }

        // Max participants field (optional).
        $mform->addElement('text', 'maxparticipants', get_string('maxparticipants', 'mod_harpiasurvey'), ['size' => '10']);
        $mform->setType('maxparticipants', PARAM_INT);
        $mform->addRule('maxparticipants', get_string('invalidnumber', 'mod_harpiasurvey'), 'numeric', null, 'client');
        if (isset($this->_customdata->maxparticipants)) {
            $mform->setDefault('maxparticipants', $this->_customdata->maxparticipants);
        }

        // Start date field (optional).
        $mform->addElement('date_time_selector', 'availabilitystart', get_string('startdate', 'mod_harpiasurvey'), ['optional' => true]);
        $mform->setType('availabilitystart', PARAM_INT);
        if (isset($this->_customdata->availability) && $this->_customdata->availability > 0) {
            $mform->setDefault('availabilitystart', $this->_customdata->availability);
        }

        // End date field (optional).
        $mform->addElement('date_time_selector', 'availabilityend', get_string('enddate', 'mod_harpiasurvey'), ['optional' => true]);
        $mform->setType('availabilityend', PARAM_INT);
        if (isset($this->_customdata->availabilityend) && $this->_customdata->availabilityend > 0) {
            $mform->setDefault('availabilityend', $this->_customdata->availabilityend);
        }

        // Available field (checkbox/toggle).
        $mform->addElement('advcheckbox', 'available', get_string('available', 'mod_harpiasurvey'));
        $mform->setType('available', PARAM_BOOL);
        if (isset($this->_customdata->availability)) {
            // If availability is set and not 0, it's available.
            $mform->setDefault('available', $this->_customdata->availability > 0 ? 1 : 0);
        } else {
            $mform->setDefault('available', 1);
        }

        // Navigation mode field (dropdown).
        $navigationoptions = [
            'free_navigation' => get_string('navigationmodefreenavigation', 'mod_harpiasurvey'),
            'only_forward' => get_string('navigationmodeonlyforward', 'mod_harpiasurvey'),
        ];
        $mform->addElement('select', 'navigation_mode', get_string('navigationmode', 'mod_harpiasurvey'), $navigationoptions);
        $mform->addHelpButton('navigation_mode', 'navigationmode', 'mod_harpiasurvey');
        $mform->setType('navigation_mode', PARAM_ALPHANUMEXT);
        if (isset($this->_customdata->navigation_mode)) {
            $mform->setDefault('navigation_mode', $this->_customdata->navigation_mode);
        } else {
            $mform->setDefault('navigation_mode', 'free_navigation');
        }

        // Add action buttons.
        $this->add_action_buttons(true, get_string('continue', 'mod_harpiasurvey') . ' >');
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
     * Form validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        // Validate that at least one model is selected.
        if (empty($data['models']) || !is_array($data['models']) || count($data['models']) === 0) {
            $errors['models'] = get_string('errormodelsrequired', 'mod_harpiasurvey');
        }

        return $errors;
    }
}

