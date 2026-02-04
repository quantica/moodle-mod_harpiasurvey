<?php

declare(strict_types=1);

namespace mod_harpiasurvey\forms;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/lib/formslib.php');

/**
 * Form for creating/editing subpages.
 *
 * @package    mod_harpiasurvey
 * @copyright  2025 Your Org / Author
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subpage extends \moodleform {

    /**
     * The context for this form.
     *
     * @var \context
     */
    protected $context;

    /**
     * Class constructor
     *
     * @param \moodle_url|string $url
     * @param object $formdata
     * @param \context $context
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
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;
        $customdata = $this->_customdata ?? new \stdClass();

        // Subpage ID (hidden, 0 for new).
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        if (isset($customdata->id)) {
            $mform->setDefault('id', $customdata->id);
        } else {
            $mform->setDefault('id', 0);
        }

        // Page ID (hidden).
        $mform->addElement('hidden', 'pageid');
        $mform->setType('pageid', PARAM_INT);
        if (isset($customdata->pageid)) {
            $mform->setDefault('pageid', $customdata->pageid);
        }
        $mform->setConstant('pageid', $customdata->pageid);

        // Course module ID (hidden).
        $mform->addElement('hidden', 'cmid');
        $mform->setType('cmid', PARAM_INT);
        if (isset($customdata->cmid)) {
            $mform->setDefault('cmid', $customdata->cmid);
        }
        if (isset($customdata->cmid)) {
            $mform->setConstant('cmid', $customdata->cmid);
        }
        
        // Also add form_id for compatibility.
        $mform->addElement('hidden', 'form_id');
        $mform->setType('form_id', PARAM_INT);
        if (isset($customdata->cmid)) {
            $mform->setDefault('form_id', $customdata->cmid);
            $mform->setConstant('form_id', $customdata->cmid);
        }

        // Title field.
        $mform->addElement('text', 'title', get_string('subpagetitle', 'mod_harpiasurvey'));
        $mform->addRule('title', get_string('required'), 'required', null, 'client');
        $mform->addRule('title', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->setType('title', PARAM_TEXT);
        if (isset($customdata->title)) {
            $mform->setDefault('title', $customdata->title);
        }

        // Description field (editor).
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
            'subpage',
            $customdata->id
        );

        $mform->addElement('editor', 'description_editor', get_string('subpagedescription', 'mod_harpiasurvey'), null, $this->get_editor_options());
        $mform->setType('description_editor', PARAM_CLEANHTML);
        if (isset($editordata->description_editor)) {
            $mform->setDefault('description_editor', $editordata->description_editor);
        }

        // Visibility type.
        $visibilityoptions = [
            'all_turns' => get_string('subpagevisibilityall', 'mod_harpiasurvey'),
            'first_turn' => get_string('subpagevisibilityfirst', 'mod_harpiasurvey'),
            'specific_turn' => get_string('subpagevisibilityspecific', 'mod_harpiasurvey'),
        ];
        $mform->addElement('select', 'turn_visibility_type', get_string('subpagevisibility', 'mod_harpiasurvey'), $visibilityoptions);
        $mform->addHelpButton('turn_visibility_type', 'subpagevisibility', 'mod_harpiasurvey');
        $mform->setType('turn_visibility_type', PARAM_ALPHANUMEXT);
        if (isset($customdata->turn_visibility_type)) {
            $mform->setDefault('turn_visibility_type', $customdata->turn_visibility_type);
        } else {
            $mform->setDefault('turn_visibility_type', 'all_turns');
        }

        // Turn number (only shown when visibility is 'specific_turn').
        $mform->addElement('text', 'turn_number', get_string('subpageturnnumber', 'mod_harpiasurvey'));
        $mform->addHelpButton('turn_number', 'subpageturnnumber', 'mod_harpiasurvey');
        $mform->setType('turn_number', PARAM_INT);
        $mform->hideIf('turn_number', 'turn_visibility_type', 'neq', 'specific_turn');
        if (isset($customdata->turn_number)) {
            $mform->setDefault('turn_number', $customdata->turn_number);
        }

        // Available checkbox.
        $mform->addElement('advcheckbox', 'available', get_string('available', 'mod_harpiasurvey'));
        $mform->setType('available', PARAM_INT);
        if (isset($customdata->available)) {
            $mform->setDefault('available', $customdata->available);
        } else {
            $mform->setDefault('available', 1);
        }

        // Questions section header.
        $mform->addElement('header', 'questions', get_string('questions', 'mod_harpiasurvey'));
        
        // Questions table will be added via HTML element.
        $subpageid = isset($customdata->id) ? $customdata->id : 0;
        $pageid = isset($customdata->pageid) ? $customdata->pageid : 0;
        $cmid = isset($customdata->cmid) ? $customdata->cmid : 0;
        
        if ($subpageid) {
            // Load questions for this subpage.
            global $DB, $OUTPUT;
            
            $subpagequestions = $DB->get_records_sql(
                "SELECT sq.id, sq.subpageid, sq.questionid, sq.enabled, sq.required, sq.sortorder, sq.timecreated,
                        q.name, q.type
                   FROM {harpiasurvey_subpage_questions} sq
                   JOIN {harpiasurvey_questions} q ON q.id = sq.questionid
                  WHERE sq.subpageid = :subpageid
                  ORDER BY sq.sortorder ASC",
                ['subpageid' => $subpageid]
            );
            
            // Add spacing after the "Questions" header.
            $questionstable = '<div class="mb-3"></div>';
            
            if (!empty($subpagequestions)) {
                $questionstable .= '<table class="table table-hover table-bordered" style="width: 100%;">';
                $questionstable .= '<thead class="table-dark">';
                $questionstable .= '<tr>';
                $questionstable .= '<th scope="col">' . get_string('question', 'mod_harpiasurvey') . '</th>';
                $questionstable .= '<th scope="col">' . get_string('type', 'mod_harpiasurvey') . '</th>';
                $questionstable .= '<th scope="col">' . get_string('subpagevisibility', 'mod_harpiasurvey') . '</th>';
                $questionstable .= '<th scope="col">' . get_string('subpageturnnumber', 'mod_harpiasurvey') . '</th>';
                $questionstable .= '<th scope="col">' . get_string('enabled', 'mod_harpiasurvey') . '</th>';
                $questionstable .= '<th scope="col">' . get_string('required') . '</th>';
                $questionstable .= '<th scope="col">' . get_string('actions', 'mod_harpiasurvey') . '</th>';
                $questionstable .= '</tr>';
                $questionstable .= '</thead>';
                $questionstable .= '<tbody>';
                
                foreach ($subpagequestions as $sq) {
                    $questionstable .= '<tr>';
                    $questionstable .= '<td>' . format_string($sq->name) . '</td>';
                    $questionstable .= '<td>' . get_string('type' . $sq->type, 'mod_harpiasurvey') . '</td>';
                    
                    // Visibility type dropdown.
                    $visibilityoptions = [
                        'all_turns' => get_string('subpagevisibilityall', 'mod_harpiasurvey'),
                        'first_turn' => get_string('subpagevisibilityfirst', 'mod_harpiasurvey'),
                        'specific_turn' => get_string('subpagevisibilityspecific', 'mod_harpiasurvey'),
                    ];
                    $visibilitytype = $sq->turn_visibility_type ?? 'all_turns';
                    $questionstable .= '<td>';
                    $questionstable .= '<select name="question_visibility[' . $sq->id . ']" class="form-control form-control-sm question-visibility-type" data-question-id="' . $sq->id . '">';
                    foreach ($visibilityoptions as $value => $label) {
                        $selected = ($visibilitytype === $value) ? 'selected' : '';
                        $questionstable .= '<option value="' . $value . '" ' . $selected . '>' . $label . '</option>';
                    }
                    $questionstable .= '</select>';
                    $questionstable .= '</td>';
                    
                    // Turn number (only shown when visibility is 'specific_turn').
                    $turnnumber = $sq->turn_number ?? '';
                    $turnnumberstyle = ($visibilitytype === 'specific_turn') ? '' : 'display: none;';
                    $questionstable .= '<td>';
                    $questionstable .= '<input type="number" name="question_turn_number[' . $sq->id . ']" class="form-control form-control-sm question-turn-number" data-question-id="' . $sq->id . '" value="' . $turnnumber . '" min="1" style="width: 80px; ' . $turnnumberstyle . '">';
                    $questionstable .= '</td>';
                    
                    // Enabled checkbox.
                    $enabledid = 'question_enabled_' . $sq->id;
                    $enabledchecked = $sq->enabled ? 'checked' : '';
                    $questionstable .= '<td>';
                    $questionstable .= '<input type="checkbox" id="' . $enabledid . '" name="question_enabled[' . $sq->id . ']" value="1" ' . $enabledchecked . '>';
                    $questionstable .= '</td>';

                    // Required checkbox.
                    $requiredid = 'question_required_' . $sq->id;
                    $requiredchecked = $sq->required ? 'checked' : '';
                    $questionstable .= '<td>';
                    $questionstable .= '<input type="checkbox" id="' . $requiredid . '" name="question_required[' . $sq->id . ']" value="1" ' . $requiredchecked . '>';
                    $questionstable .= '</td>';
                    
                    // Actions: remove from subpage.
                    $removeurl = new \moodle_url('/mod/harpiasurvey/remove_subpage_question.php', [
                        'id' => $cmid,
                        'page' => $pageid,
                        'subpage' => $subpageid,
                        'question' => $sq->questionid,
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
                $questionstable .= '<p class="text-muted">' . get_string('noquestionsonsubpage', 'mod_harpiasurvey') . '</p>';
            }
            
            // Action button - Add question (opens modal).
            $questionstable .= '<div class="mt-2 mb-4">';
            $questionstable .= '<button type="button" class="btn btn-secondary subpage-question-bank-modal-trigger" data-cmid="' . $cmid . '" data-pageid="' . $pageid . '" data-subpageid="' . $subpageid . '">' . get_string('addquestiontosubpage', 'mod_harpiasurvey') . '</button>';
            $questionstable .= '</div>';
            
            // Add JavaScript to handle visibility type changes.
            $questionstable .= '<script>
                $(document).ready(function() {
                    $(".question-visibility-type").on("change", function() {
                        const questionId = $(this).data("question-id");
                        const visibilityType = $(this).val();
                        const turnNumberInput = $(".question-turn-number[data-question-id=\'" + questionId + "\']");
                        if (visibilityType === "specific_turn") {
                            turnNumberInput.show();
                        } else {
                            turnNumberInput.hide();
                        }
                    });
                });
            </script>';
            
            $mform->addElement('html', $questionstable);
        } else {
            // New subpage - show message.
            $mform->addElement('static', 'questions_info', '', '<p class="text-muted">' . get_string('savesubpagetoaddquestions', 'mod_harpiasurvey') . '</p>');
        }

        // Add action buttons.
        $this->add_action_buttons();
    }

    /**
     * Get editor options for description field.
     *
     * @return array Editor options
     */
    public function get_editor_options(): array {
        return [
            'maxfiles' => 0,
            'maxbytes' => 0,
            'trusttext' => false,
            'context' => $this->context,
            'subdirs' => false,
            'changeformat' => 1,
        ];
    }

    /**
     * Form validation.
     *
     * @param array $data Form data
     * @param array $files Uploaded files
     * @return array Validation errors
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        // Validate turn_number when visibility is 'specific_turn'.
        if ($data['turn_visibility_type'] === 'specific_turn') {
            if (empty($data['turn_number']) || $data['turn_number'] < 1) {
                $errors['turn_number'] = get_string('invalidturnnumber', 'mod_harpiasurvey');
            }
        }

        return $errors;
    }
}
