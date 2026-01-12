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

/**
 * Question bank modal module.
 *
 * @module     mod_harpiasurvey/question_bank_modal
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Modal from 'core/modal';
import ModalEvents from 'core/modal_events';
import Notification from 'core/notification';
import {get_string as getString} from 'core/str';
import Templates from 'core/templates';
import Config from 'core/config';
import $ from 'jquery';

let modalInstance = null;
let initialized = false;

/**
 * Initialize the question bank modal functionality.
 * Uses event delegation like aiprompts/aitools modules.
 *
 * @param {number} cmid Course module ID
 * @param {number} pageid Page ID
 */
export const init = (cmid, pageid) => {
    if (initialized) {
        // Event listener already attached (can be called multiple times).
        return;
    }

    $("body").on("click", ".question-bank-modal-trigger", function(e) {
        e.preventDefault();
        const button = $(this);
        const triggerCmid = parseInt(button.data("cmid"), 10);
        const triggerPageid = parseInt(button.data("pageid"), 10);
        showModal(triggerCmid || cmid, triggerPageid || pageid);
    });

    // Handle subpage question bank modal triggers.
    $("body").on("click", ".subpage-question-bank-modal-trigger", function(e) {
        e.preventDefault();
        const button = $(this);
        const triggerCmid = parseInt(button.data("cmid"), 10);
        const triggerPageid = parseInt(button.data("pageid"), 10);
        const triggerSubpageid = parseInt(button.data("subpageid"), 10);
        showSubpageModal(triggerCmid || cmid, triggerPageid || pageid, triggerSubpageid);
    });

    initialized = true;
};

/**
 * Show the question bank modal.
 *
 * @param {number} cmid Course module ID
 * @param {number} pageid Page ID
 * @returns {Promise}
 */
export const showModal = (cmid, pageid) => {
    if (modalInstance) {
        modalInstance.show();
        loadQuestions(cmid, pageid);
        return Promise.resolve();
    }

    return getString('questionbank', 'mod_harpiasurvey').then((title) => {
        return Modal.create({
            title: title,
            body: Templates.render('mod_harpiasurvey/question_bank_modal_body', {
                loading: true
            }),
            large: true,
            show: true,
        });
    }).then((modal) => {
        modalInstance = modal;

        // Load questions when modal is shown.
        modal.getRoot().on(ModalEvents.shown, () => {
            loadQuestions(cmid, pageid);
        });

        // Handle add question button clicks.
        modal.getRoot().on('click', '[data-action="add-question"]', (e) => {
            e.preventDefault();
            const questionId = parseInt(e.currentTarget.dataset.questionid, 10);
            addQuestionToPage(cmid, pageid, questionId);
        });

        // Clean up when modal is hidden.
        modal.getRoot().on(ModalEvents.hidden, () => {
            modal.setBody(Templates.render('mod_harpiasurvey/question_bank_modal_body', {
                loading: true
            }));
        });

        return modal;
    }).catch(Notification.exception);
};

/**
 * Load available questions via AJAX.
 *
 * @param {number} cmid Course module ID
 * @param {number} pageid Page ID
 */
const loadQuestions = (cmid, pageid) => {
    const bodyPromise = modalInstance.getBodyPromise();
    const wwwroot = Config.wwwroot;
    const ajaxUrl = wwwroot + '/mod/harpiasurvey/ajax.php';
    const params = new URLSearchParams({
        action: 'get_available_questions',
        cmid: cmid,
        pageid: pageid
    });

    fetch(ajaxUrl + '?' + params.toString())
        .then((response) => response.json())
        .then((response) => {
            if (response.success) {
                // Note: evaluates_conversation_id has been removed.
                // All questions on aichat pages automatically evaluate the page's chat conversation.

                return Templates.render('mod_harpiasurvey/question_bank_modal_body', {
                    questions: response.questions,
                    has_questions: response.questions.length > 0,
                    is_aichat_page: response.is_aichat_page || false,
                    cmid: cmid,
                    pageid: pageid
                });
            } else {
                return Templates.render('mod_harpiasurvey/question_bank_modal_body', {
                    error: response.message || 'Error loading questions',
                    has_questions: false
                });
            }
        })
        .then((html) => {
            return bodyPromise.then(() => {
                modalInstance.setBody(html);
            });
        })
        .catch((error) => {
            return bodyPromise.then(() => {
                modalInstance.setBody(Templates.render('mod_harpiasurvey/question_bank_modal_body', {
                    error: 'Error loading questions',
                    has_questions: false
                }));
            });
            Notification.exception(error);
        });
};

/**
 * Add a question to the page via AJAX.
 *
 * @param {number} cmid Course module ID
 * @param {number} pageid Page ID
 * @param {number} questionId Question ID
 */
const addQuestionToPage = (cmid, pageid, questionId) => {
    const wwwroot = Config.wwwroot;
    const sesskey = Config.sesskey || M.cfg.sesskey;
    const ajaxUrl = wwwroot + '/mod/harpiasurvey/ajax.php';

    // Note: evaluates_conversation_id has been removed.
    // All questions on aichat pages automatically evaluate the page's chat conversation.

    const params = new URLSearchParams({
        action: 'add_question_to_page',
        cmid: cmid,
        pageid: pageid,
        questionid: questionId,
        sesskey: sesskey
    });

    fetch(ajaxUrl + '?' + params.toString())
        .then((response) => response.json())
        .then((response) => {
            if (response.success) {
                Notification.addNotification({
                    message: response.message,
                    type: 'success'
                });
                // Reload the page to show the new question.
                window.location.reload();
            } else {
                Notification.addNotification({
                    message: response.message || 'Error adding question',
                    type: 'error'
                });
            }
        })
        .catch(Notification.exception);
};

let subpageModalInstance = null;

/**
 * Show the question bank modal for subpages.
 *
 * @param {number} cmid Course module ID
 * @param {number} pageid Page ID
 * @param {number} subpageid Subpage ID
 * @returns {Promise}
 */
export const showSubpageModal = (cmid, pageid, subpageid) => {
    if (subpageModalInstance) {
        subpageModalInstance.show();
        loadSubpageQuestions(cmid, pageid, subpageid);
        return Promise.resolve();
    }

    return getString('addquestiontosubpage', 'mod_harpiasurvey').then((title) => {
        return Modal.create({
            title: title,
            body: Templates.render('mod_harpiasurvey/question_bank_modal_body', {
                loading: true
            }),
            large: true,
            show: true,
        });
    }).then((modal) => {
        subpageModalInstance = modal;

        // Load questions when modal is shown.
        modal.getRoot().on(ModalEvents.shown, () => {
            loadSubpageQuestions(cmid, pageid, subpageid);
        });

        // Handle add question button clicks.
        modal.getRoot().on('click', '[data-action="add-question-to-subpage"]', (e) => {
            e.preventDefault();
            const questionId = parseInt(e.currentTarget.dataset.questionid, 10);
            addQuestionToSubpage(cmid, pageid, subpageid, questionId);
        });

        // Clean up when modal is hidden.
        modal.getRoot().on(ModalEvents.hidden, () => {
            modal.setBody(Templates.render('mod_harpiasurvey/question_bank_modal_body', {
                loading: true
            }));
        });

        return modal;
    }).catch(Notification.exception);
};

/**
 * Load available questions for subpage via AJAX.
 *
 * @param {number} cmid Course module ID
 * @param {number} pageid Page ID
 * @param {number} subpageid Subpage ID
 */
const loadSubpageQuestions = (cmid, pageid, subpageid) => {
    const bodyPromise = subpageModalInstance.getBodyPromise();
    const wwwroot = Config.wwwroot;
    const ajaxUrl = wwwroot + '/mod/harpiasurvey/ajax.php';
    const params = new URLSearchParams({
        action: 'get_available_subpage_questions',
        cmid: cmid,
        pageid: pageid,
        subpageid: subpageid
    });

    fetch(ajaxUrl + '?' + params.toString())
        .then((response) => response.json())
        .then((response) => {
            if (response.success) {
                return Templates.render('mod_harpiasurvey/question_bank_modal_body', {
                    questions: response.questions,
                    has_questions: response.questions.length > 0,
                    is_aichat_page: false,
                    cmid: cmid,
                    pageid: pageid,
                    subpageid: subpageid,
                    is_subpage: true
                });
            } else {
                return Templates.render('mod_harpiasurvey/question_bank_modal_body', {
                    error: response.message || 'Error loading questions',
                    has_questions: false
                });
            }
        })
        .then((html) => {
            return bodyPromise.then(() => {
                subpageModalInstance.setBody(html);
            });
        })
        .catch((error) => {
            return bodyPromise.then(() => {
                subpageModalInstance.setBody(Templates.render('mod_harpiasurvey/question_bank_modal_body', {
                    error: 'Error loading questions',
                    has_questions: false
                }));
            });
            Notification.exception(error);
        });
};

/**
 * Add a question to the subpage via AJAX.
 *
 * @param {number} cmid Course module ID
 * @param {number} pageid Page ID
 * @param {number} subpageid Subpage ID
 * @param {number} questionId Question ID
 */
const addQuestionToSubpage = (cmid, pageid, subpageid, questionId) => {
    const wwwroot = Config.wwwroot;
    const sesskey = Config.sesskey || M.cfg.sesskey;
    const ajaxUrl = wwwroot + '/mod/harpiasurvey/ajax.php';

    const params = new URLSearchParams({
        action: 'add_question_to_subpage',
        cmid: cmid,
        pageid: pageid,
        subpageid: subpageid,
        questionid: questionId,
        sesskey: sesskey
    });

    fetch(ajaxUrl + '?' + params.toString())
        .then((response) => response.json())
        .then((response) => {
            if (response.success) {
                Notification.addNotification({
                    message: response.message,
                    type: 'success'
                });
                // Reload the page to show the new question.
                window.location.reload();
            } else {
                Notification.addNotification({
                    message: response.message || 'Error adding question',
                    type: 'error'
                });
            }
        })
        .catch(Notification.exception);
};

