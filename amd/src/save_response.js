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
 * Save response module for harpiasurvey.
 *
 * @module     mod_harpiasurvey/save_response
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import {get_string as getString} from 'core/str';
import Config from 'core/config';
import $ from 'jquery';

let initialized = false;
let editLabel = 'Edit';

const lockQuestionItem = (questionItem) => {
    questionItem.attr('data-response-locked', '1');
    questionItem.removeAttr('data-response-editing');
    questionItem.find('input, select, textarea').prop('disabled', true);
};

const unlockQuestionItem = (questionItem) => {
    questionItem.attr('data-response-locked', '0');
    questionItem.attr('data-response-editing', '1');
    questionItem.find('input, select, textarea').prop('disabled', false);
};

const ensureEditButton = (questionItem) => {
    let controls = questionItem.find('.question-edit-controls');
    if (controls.length === 0) {
        controls = $('<div class="mt-2 question-edit-controls"></div>');
        questionItem.append(controls);
    }

    let button = controls.find('.question-edit-btn');
    if (button.length === 0) {
        button = $('<button type="button" class="btn btn-sm btn-outline-secondary question-edit-btn"></button>');
        controls.append(button);
    }
    button.text(editLabel);
    button.show();
};

const hideEditButton = (questionItem) => {
    questionItem.find('.question-edit-btn').hide();
};

const hasSavedMessage = (questionItem) => {
    return questionItem.find('.saved-response-message').length > 0;
};

/**
 * Get a human-readable response display for a question.
 *
 * @param {jQuery} questionItem Question container
 * @param {string} questionType Question type
 * @return {string} Display text
 */
const getResponseDisplay = (questionItem, questionType) => {
    let display = '';

    if (questionType === 'singlechoice' || questionType === 'likert') {
        const checked = questionItem.find('input[type="radio"]:checked');
        if (checked.length > 0) {
            const id = checked.attr('id');
            const label = id ? questionItem.find(`label[for="${id}"]`).text().trim() : '';
            display = label || checked.val();
        }
    } else if (questionType === 'multiplechoice') {
        const labels = [];
        questionItem.find('input[type="checkbox"]:checked').each(function() {
            const id = $(this).attr('id');
            const label = id ? questionItem.find(`label[for="${id}"]`).text().trim() : '';
            labels.push(label || $(this).val());
        });
        display = labels.join(', ');
    } else if (questionType === 'select') {
        const select = questionItem.find('select');
        const selected = select.find('option:selected');
        display = selected.length ? selected.text().trim() : '';
    } else if (questionType === 'number') {
        const input = questionItem.find('input[type="number"]');
        display = input.val();
    } else if (questionType === 'shorttext') {
        const input = questionItem.find('input[type="text"]');
        display = input.val();
    } else if (questionType === 'longtext') {
        const textarea = questionItem.find('textarea');
        display = textarea.val();
    }

    if (!display) {
        return '-';
    }

    return display;
};

/**
 * Initialize the save response functionality.
 *
 */
export const init = () => {
    if (initialized) {
        return;
    }

    getString('edit', 'moodle').then((str) => {
        editLabel = str;
    }).catch(() => {
        editLabel = 'Edit';
    });

    // Pre-populate select dropdowns with saved values.
    $('select[data-saved-value]').each(function() {
        const select = $(this);
        const savedValue = select.data('saved-value');
        if (savedValue) {
            select.val(savedValue);
        }
    });

    // Lock already-saved questions on load.
    $('.question-item').each(function() {
        const questionItem = $(this);
        if (hasSavedMessage(questionItem)) {
            lockQuestionItem(questionItem);
            ensureEditButton(questionItem);
        }
    });

    // Enable editing for a previously saved question.
    $(document).on('click', '.question-edit-btn', function(e) {
        e.preventDefault();
        const questionItem = $(this).closest('.question-item');
        unlockQuestionItem(questionItem);
        hideEditButton(questionItem);
        const firstInput = questionItem.find('input, select, textarea').first();
        if (firstInput.length > 0) {
            firstInput.focus();
        }
    });

    // Handle save all button click.
    $(document).on('click', '.save-all-responses-btn', function(e) {
        e.preventDefault();
        const button = $(this);
        const cmid = parseInt(button.data('cmid'), 10);
        const pageid = parseInt(button.data('pageid'), 10);
        const container = button.closest('.page-questions');
        const statusDiv = container.length ? container.find('.save-all-status') : $('.save-all-status');

        // Collect all questions and their responses.
        const questionsToSave = [];
        const questionItems = container.length ? container.find('.question-item') : $('.question-item');
        questionItems.each(function() {
            const questionItem = $(this);

            const questionid = parseInt(questionItem.data('questionid'), 10);
            const questiontype = questionItem.data('questiontype');

            if (!questionid || !questiontype) {
                return; // Skip if we can't find question ID or type.
            }

            const isLocked = String(questionItem.attr('data-response-locked')) === '1';
            const isEditing = String(questionItem.attr('data-response-editing')) === '1';
            if (isLocked && !isEditing) {
                return;
            }

            // Get the response value based on question type.
            let response = '';

            if (questiontype === 'singlechoice' || questiontype === 'likert') {
                const selected = questionItem.find('input[type="radio"]:checked');
                if (selected.length > 0) {
                    response = selected.val();
                }
            } else if (questiontype === 'multiplechoice') {
                const selected = questionItem.find('input[type="checkbox"]:checked');
                const values = [];
                selected.each(function() {
                    values.push($(this).val());
                });
                response = JSON.stringify(values);
            } else if (questiontype === 'select') {
                const selected = questionItem.find('select').val();
                if (selected) {
                    response = selected;
                }
            } else if (questiontype === 'number' || questiontype === 'shorttext') {
                const input = questionItem.find('input[type="number"], input[type="text"]');
                response = input.val();
            } else if (questiontype === 'longtext') {
                const textarea = questionItem.find('textarea');
                response = textarea.val();
            }

            questionsToSave.push({
                questionid: questionid,
                questiontype: questiontype,
                response: response,
                questionItem: questionItem
            });
        });

        if (questionsToSave.length === 0) {
            Notification.addNotification({
                message: 'No editable questions to save. Click Edit on a question first.',
                type: 'info'
            });
            return;
        }

        // Save all responses sequentially.
        saveAllResponses(cmid, pageid, questionsToSave, button, statusDiv);
    });

    initialized = true;
};

/**
 * Save all responses sequentially.
 *
 * @param {number} cmid Course module ID
 * @param {number} pageid Page ID
 * @param {Array} questionsToSave Array of {questionid, questiontype, response} objects
 * @param {jQuery} button Save button element
 * @param {jQuery} statusDiv Status div element
 */
const saveAllResponses = (cmid, pageid, questionsToSave, button, statusDiv) => {
    const originalText = button.text();
    button.prop('disabled', true);
    statusDiv.show().html('<div class="text-info"><i class="fa fa-spinner fa-spin"></i> Saving responses...</div>');

    // Check if this is a turns/continuous mode page and get current iteration.
    // The button might be outside the ai-conversation-container, so search from document.
    const container = $('.ai-conversation-container[data-pageid="' + pageid + '"]');
    let turnId = null;
    if (container.length > 0) {
        const behavior = container.data('behavior');
        if (behavior === 'turns') {
            // Get current viewing turn from the container.
            const viewingTurn = container.attr('data-viewing-turn');
            if (viewingTurn) {
                turnId = parseInt(viewingTurn, 10);
            } else {
                // If no viewing turn is set, get the current turn (highest turn with messages).
                const messagesContainer = container.find(`#chat-messages-page-${pageid}`);
                if (messagesContainer.length > 0) {
                    const messages = messagesContainer.find('.message[data-turn-id]');
                    let maxTurn = 0;
                    messages.each(function() {
                        const msgTurnId = parseInt($(this).attr('data-turn-id'), 10);
                        if (msgTurnId > maxTurn) {
                            maxTurn = msgTurnId;
                        }
                    });
                    if (maxTurn > 0) {
                        turnId = maxTurn;
                    } else {
                        // If no messages yet, default to turn 1 (first turn).
                        turnId = 1;
                    }
                } else {
                    // If no messages container found, default to turn 1 (first turn).
                    turnId = 1;
                }
            }
        } else if (behavior === 'continuous') {
            const viewingConversation = container.data('viewing-conversation') ||
                parseInt(container.attr('data-viewing-conversation'), 10);
            if (viewingConversation && !isNaN(viewingConversation)) {
                turnId = parseInt(viewingConversation, 10);
            }
        }
    }

    let savedCount = 0;
    let failedCount = 0;
    const total = questionsToSave.length;

    // Save responses sequentially using Promise chain.
    let promiseChain = Promise.resolve();

    questionsToSave.forEach((questionData, index) => {
        promiseChain = promiseChain.then(() => {
            return new Promise((resolve) => {
                // Update status.
                const savingHtml = '<div class="text-info"><i class="fa fa-spinner fa-spin"></i> ' +
                    `Saving ${index + 1} of ${total}...` +
                    '</div>';
                statusDiv.html(savingHtml);

                const params = new URLSearchParams({
                    action: 'save_response',
                    cmid: cmid,
                    pageid: pageid,
                    questionid: questionData.questionid,
                    response: questionData.response,
                    sesskey: Config.sesskey
                });

                // Add turn_id if this is a turns mode page.
                if (turnId !== null && turnId > 0) {
                    params.append('turn_id', turnId);
                }

                fetch(Config.wwwroot + '/mod/harpiasurvey/ajax.php?' + params.toString(), {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        savedCount++;
                        // Update saved message for this question.
                        const now = new Date();
                        const datetimeStr = now.toLocaleString();
                        getString('saved', 'mod_harpiasurvey').then((savedText) => {
                            getString('on', 'mod_harpiasurvey').then((onText) => {
                                const questionItem = questionData.questionItem ? $(questionData.questionItem) :
                                    $(`.question-item[data-questionid="${questionData.questionid}"]`);
                                const savedMessage = questionItem.find(`.saved-response-message[data-questionid="${questionData.questionid}"]`);
                                const icon = '<i class="fa fa-check-circle" aria-hidden="true"></i>';
                                if (savedMessage.length === 0) {
                                    // Create new message element.
                                    const messageHtml = '<div class="mt-2 small text-muted saved-response-message" ' +
                                        `data-questionid="${questionData.questionid}">` +
                                        `${icon} ${savedText} ${onText} ${datetimeStr}</div>`;
                                    questionItem.append(messageHtml);
                                } else {
                                    // Update existing message timestamp.
                                    savedMessage.html(`${icon} ${savedText} ${onText} ${datetimeStr}`);
                                }
                                lockQuestionItem(questionItem);
                                ensureEditButton(questionItem);

                                // Update history timeline.
                                getString('answerhistory', 'mod_harpiasurvey').then((historyText) => {
                                    let history = questionItem.find(`.answer-history[data-questionid="${questionData.questionid}"]`);
                                    if (history.length === 0) {
                                        const details = $('<details class="answer-history mt-1 d-none"></details>');
                                        details.attr('data-questionid', questionData.questionid);
                                        details.append(`<summary>${historyText} (1)</summary>`);
                                        details.append('<ul class="list-unstyled mt-2 mb-0"></ul>');
                                        questionItem.append(details);
                                        history = details;
                                    }
                                    const list = history.find('ul');
                                    const responseDisplay = getResponseDisplay(questionItem, questionData.questiontype);
                                    list.prepend(`<li class="mb-1"><span class="text-muted">${datetimeStr}</span> — ${$('<div>').text(responseDisplay).html()}</li>`);

                                    const count = list.find('li').length;
                                    history.find('summary').text(`${historyText} (${count})`);
                                    if (count > 1) {
                                        history.removeClass('d-none');
                                    }
                                });
                            });
                        });
                    } else {
                        failedCount++;
                    }
                    resolve();
                })
                .catch(() => {
                    failedCount++;
                    resolve(); // Continue with next question even on error.
                });
            });
        });
    });

    // After all saves complete.
    promiseChain.then(() => {
        button.prop('disabled', false);
        button.text(originalText);

        if (failedCount === 0) {
            // All saved successfully.
            button.removeClass('btn-primary').addClass('btn-success');
            const successHtml = '<div class="text-success"><i class="fa fa-check-circle"></i> ' +
                `All ${savedCount} response(s) saved successfully!</div>`;
            statusDiv.html(successHtml);
            setTimeout(() => {
                button.removeClass('btn-success').addClass('btn-primary');
                statusDiv.fadeOut();
            }, 3000);
        } else {
            // Some failed.
            button.removeClass('btn-primary').addClass('btn-warning');
            const warningHtml = '<div class="text-warning"><i class="fa fa-exclamation-triangle"></i> ' +
                `${savedCount} saved, ${failedCount} failed. Please try again.</div>`;
            statusDiv.html(warningHtml);
            setTimeout(() => {
                button.removeClass('btn-warning').addClass('btn-primary');
            }, 5000);
        }
    });
};
