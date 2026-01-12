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
 * Experiment view renderable class.
 *
 * @package     mod_harpiasurvey
 * @copyright   2025 Your Name
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class experiment_view implements renderable, templatable {

    /**
     * @var object Experiment object
     */
    public $experiment;

    /**
     * @var array Pages array
     */
    public $pages;

    /**
     * @var object Context object
     */
    public $context;

    /**
     * @var int Course module ID
     */
    public $cmid;

    /**
     * @var int Experiment ID
     */
    public $experimentid;

    /**
     * @var object|null Viewing page object
     */
    public $viewingpage;

    /**
     * @var bool Whether we're in edit mode
     */
    public $editing;

    /**
     * @var string Form HTML (if editing)
     */
    public $formhtml;

    /**
     * @var string Page view HTML (if viewing)
     */
    public $pageviewhtml;

    /**
     * @var bool Whether user can manage experiments (is admin)
     */
    public $canmanage;

    /**
     * @var string Navigation mode (free_navigation, only_forward)
     */
    public $navigation_mode;

    /**
     * @var string Current tab (pages or models)
     */
    public $tab;

    /**
     * @var string Models view HTML
     */
    public $modelshtml;

    /**
     * Class constructor.
     *
     * @param object $experiment
     * @param array $pages
     * @param object $context
     * @param int $cmid
     * @param int $experimentid
     * @param object|null $viewingpage
     * @param bool $editing
     * @param string $formhtml
     * @param string $pageviewhtml
     * @param bool $canmanage
     * @param string $navigation_mode
     * @param string $tab
     * @param string $modelshtml
     */
    public function __construct($experiment, $pages, $context, $cmid, $experimentid, $viewingpage = null, $editing = false, $formhtml = '', $pageviewhtml = '', $canmanage = true, $navigation_mode = 'free_navigation', $tab = 'pages', $modelshtml = '') {
        $this->experiment = $experiment;
        $this->pages = $pages;
        $this->context = $context;
        $this->cmid = $cmid;
        $this->experimentid = $experimentid;
        $this->viewingpage = $viewingpage;
        $this->editing = $editing;
        $this->formhtml = $formhtml;
        $this->pageviewhtml = $pageviewhtml;
        $this->canmanage = $canmanage;
        $this->navigation_mode = $navigation_mode;
        $this->tab = $tab;
        $this->modelshtml = $modelshtml;
    }

    /**
     * Export the data for the template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {
        global $CFG;

        // Prepare pages list.
        $pageslist = [];
        $pagenum = 1;
        $currentpageid = $this->viewingpage ? $this->viewingpage->id : null;
        $currentpageindex = -1;
        
        // Find current page index for only_forward mode.
        if ($currentpageid && $this->navigation_mode === 'only_forward') {
            foreach ($this->pages as $index => $page) {
                if ($page->id == $currentpageid) {
                    $currentpageindex = $index;
                    break;
                }
            }
        }
        
        foreach ($this->pages as $index => $page) {
            $pageurl = new \moodle_url('/mod/harpiasurvey/view_experiment.php', [
                'id' => $this->cmid,
                'experiment' => $this->experimentid,
                'page' => $page->id
            ]);
            $deleteurl = new \moodle_url('/mod/harpiasurvey/delete_page.php', [
                'id' => $this->cmid,
                'experiment' => $this->experimentid,
                'page' => $page->id
            ]);
            
            // In only_forward mode, disable tabs for previous pages (only for non-admins).
            $is_disabled = false;
            if (!$this->canmanage && $this->navigation_mode === 'only_forward' && $currentpageindex >= 0 && $index < $currentpageindex) {
                $is_disabled = true;
            }
            
            $pageslist[] = [
                'id' => $page->id,
                'number' => $pagenum++,
                'title' => format_string($page->title),
                'url' => $pageurl->out(false),
                'deleteurl' => $deleteurl->out(false),
                'is_active' => ($currentpageid && $page->id == $currentpageid),
                'is_disabled' => $is_disabled,
            ];
        }

        // Prepare tabs.
        $questionsurl = new \moodle_url('/mod/harpiasurvey/question_bank.php', [
            'id' => $this->cmid,
            'experiment' => $this->experimentid
        ]);
        $pagesurl = new \moodle_url('/mod/harpiasurvey/view_experiment.php', [
            'id' => $this->cmid,
            'experiment' => $this->experimentid,
            'tab' => 'pages'
        ]);
        $modelsurl = new \moodle_url('/mod/harpiasurvey/view_experiment.php', [
            'id' => $this->cmid,
            'experiment' => $this->experimentid,
            'tab' => 'models'
        ]);

        // Add page URL.
        $addpageurl = new \moodle_url('/mod/harpiasurvey/edit_page.php', [
            'id' => $this->cmid,
            'experiment' => $this->experimentid
        ]);

        // Back to course URL.
        $courseid = $this->context->get_course_context()->instanceid;
        $backtocourseurl = new \moodle_url('/course/view.php', ['id' => $courseid]);

        return [
            'experimentname' => format_string($this->experiment->name),
            'pageslabel' => get_string('pages', 'mod_harpiasurvey'),
            'modelslabel' => get_string('models', 'mod_harpiasurvey'),
            'questionsurl' => $questionsurl->out(false),
            'pagesurl' => $pagesurl->out(false),
            'modelsurl' => $modelsurl->out(false),
            'questionslabel' => get_string('questions', 'mod_harpiasurvey'),
            'questionbanklabel' => get_string('questionbank', 'mod_harpiasurvey'),
            'pageslist' => $pageslist,
            'has_pages' => !empty($pageslist),
            'nopages' => get_string('nopages', 'mod_harpiasurvey'),
            'addpageurl' => $addpageurl->out(false),
            'addpagelabel' => get_string('addpage', 'mod_harpiasurvey'),
            'selectpagetoadd' => get_string('selectpagetoadd', 'mod_harpiasurvey'),
            'viewingpage' => $this->viewingpage !== null,
            'editing' => $this->editing,
            'formhtml' => $this->formhtml,
            'pageviewhtml' => $this->pageviewhtml,
            'modelshtml' => $this->modelshtml,
            'current_tab' => $this->tab,
            'is_pages' => ($this->tab === 'pages'),
            'is_models' => ($this->tab === 'models'),
            'backtocourseurl' => $backtocourseurl->out(false),
            'backtocourselabel' => get_string('backtocourse', 'mod_harpiasurvey'),
            'canmanage' => $this->canmanage,
            'wwwroot' => $CFG->wwwroot,
            'navigation_mode' => $this->navigation_mode,
            'is_only_forward' => ($this->navigation_mode === 'only_forward'),
        ];
    }
}

