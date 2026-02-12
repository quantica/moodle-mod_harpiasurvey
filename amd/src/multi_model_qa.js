// Q&A logic for multi-model tabs.
import {
    Templates,
    Notification,
    Config,
    getString,
    $,
    addError
} from './common_chat';

let initialized = false;
let handlersRegistered = false;
const qaEvaluationSaved = {};
let editLabel = 'Edit';

const lockQuestionItem = (questionItem) => {
    questionItem.attr('data-response-locked', '1');
    questionItem.removeAttr('data-response-editing');
    questionItem.find('input, select, textarea').prop('disabled', true);
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

export const init = () => initialize();

const stateKey = (pageid, modelid) => `${pageid}_${modelid}`;

const initialize = () => {
    if (initialized) {
        return;
    }
    getString('edit', 'moodle').then((str) => {
        editLabel = str;
    }).catch(() => {
        editLabel = 'Edit';
    });

    const containers = $('.multi-model-chat-container[data-behavior="qa"]');
    if (containers.length === 0) {
        return;
    }

    containers.each(function() {
        const pageid = parseInt($(this).data('pageid'), 10);
        if (!pageid) {
            return;
        }

        $(this).find('.tab-pane[data-model-id]').each(function() {
            const pane = $(this);
            const modelid = parseInt(pane.data('model-id'), 10);
            if (!modelid) {
                return;
            }

            const messagesContainer = $(`#chat-messages-model-${modelid}-${pageid}`);
            if (messagesContainer.find('.message[data-role="assistant"]').length > 0) {
                lockQaInput(pageid, modelid);
            }

            const activeId = parseInt(pane.data('qa-active-id'), 10);
            if (activeId) {
                selectQaQuestion(pageid, modelid, activeId);
            }
        });
    });

    registerHandlers();
    initialized = true;
};

function registerHandlers() {
    if (handlersRegistered) {
        return;
    }

    $(document).on('click', '.multi-model-chat-container[data-behavior="qa"] .chat-send-btn', function(e) {
        e.preventDefault();
        const button = $(this);
        const cmid = parseInt(button.data('cmid'), 10);
        const pageid = parseInt(button.data('pageid'), 10);
        const modelid = parseInt(button.data('model-id') || button.data('modelId'), 10);

        if (!cmid || !pageid || !modelid) {
            addError('Missing cmid, pageid or modelid');
            return;
        }

        if (hasUnsavedQaEvaluation(pageid, modelid)) {
            getString('qarequiresave', 'mod_harpiasurvey').then((message) => {
                Notification.addNotification({
                    message: message,
                    type: 'warning'
                });
            }).catch(() => {
                Notification.addNotification({
                    message: 'Please save the evaluation answers before starting a new question.',
                    type: 'warning'
                });
            });
            return;
        }

        const input = $(`#chat-input-model-${modelid}-${pageid}`);
        if (input.length === 0) {
            addError('Chat input not found');
            return;
        }

        const inputValue = input.val();
        if (!inputValue) {
            return;
        }

        const message = inputValue.trim();
        if (!message) {
            return;
        }

        input.prop('disabled', true);
        button.prop('disabled', true);
        input.val('');

        const tempId = 'temp-user-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
        displayUserMessage(pageid, modelid, message, tempId);
        addQaQuestionItem(pageid, modelid, tempId, message);
        selectQaQuestion(pageid, modelid, tempId);

        const loading = $(`#chat-loading-model-${modelid}-${pageid}`);
        loading.show();
        sendMessage(cmid, pageid, modelid, message, button, input, loading);
    });

    $(document).on('keydown', '.multi-model-chat-container[data-behavior="qa"] .chat-input', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            const input = $(this);
            const pageid = parseInt(input.data('pageid'), 10);
            const modelid = parseInt(input.data('model-id') || input.data('modelId'), 10);
            $(`#send-message-btn-model-${modelid}-${pageid}`).click();
        }
    });

    $(document).on('click', '.multi-model-chat-container[data-behavior="qa"] .new-question-btn', function(e) {
        e.preventDefault();
        const pageid = parseInt($(this).data('pageid'), 10);
        const modelid = parseInt($(this).data('model-id') || $(this).data('modelId'), 10);
        if (!pageid || !modelid) {
            return;
        }

        if (hasUnsavedQaEvaluation(pageid, modelid)) {
            getString('qarequiresave', 'mod_harpiasurvey').then((message) => {
                Notification.addNotification({
                    message: message,
                    type: 'warning'
                });
            }).catch(() => {
                Notification.addNotification({
                    message: 'Please save the evaluation answers before starting a new question.',
                    type: 'warning'
                });
            });
            return;
        }

        resetQaView(pageid, modelid);
    });

    $(document).on('click', '.multi-model-chat-container[data-behavior="qa"] .qa-question-item', function(e) {
        e.preventDefault();
        const button = $(this);
        const pageid = parseInt(button.data('pageid'), 10);
        const modelid = parseInt(button.data('model-id') || button.data('modelId'), 10);
        const questionId = button.data('question-id');
        if (!pageid || !modelid || !questionId) {
            return;
        }
        selectQaQuestion(pageid, modelid, questionId);
    });

    $(document).on('click', '.multi-model-chat-container[data-behavior="qa"] .qa-evaluation-questions-container .save-turn-evaluation-questions-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();

        const button = $(this);
        const turnId = parseInt(button.data('turn-id'), 10);
        const pageid = parseInt(button.data('pageid'), 10);
        const cmid = parseInt(button.data('cmid'), 10);
        const modelid = parseInt(button.closest('.tab-pane[data-model-id]').data('model-id'), 10);

        if (!turnId || !pageid || !cmid || !modelid) {
            Notification.addNotification({
                message: 'Missing required data to save responses.',
                type: 'error'
            });
            return;
        }

        const evaluationContainer = button.closest('.turn-evaluation-questions');
        if (evaluationContainer.length === 0) {
            return;
        }

        const responses = {};
        evaluationContainer.find('[data-questionid]').each(function() {
            const item = $(this);
            const isLocked = String(item.attr('data-response-locked')) === '1';
            const isEditing = String(item.attr('data-response-editing')) === '1';
            if (isLocked && !isEditing) {
                return;
            }
            const questionId = $(this).data('questionid');
            const questionType = $(this).data('questiontype');
            let responseValue = null;

            if (questionType === 'multiplechoice') {
                const checked = evaluationContainer.find(`input[name="question_${questionId}_turn[]"]:checked`);
                const values = [];
                checked.each(function() {
                    values.push($(this).val());
                });
                responseValue = values.length > 0 ? JSON.stringify(values) : null;
            } else if (questionType === 'select' || questionType === 'singlechoice' || questionType === 'likert') {
                const selectSelector = `select[name="question_${questionId}_turn"]`;
                const inputSelector = `input[name="question_${questionId}_turn"]:checked`;
                const selected = evaluationContainer.find(`${selectSelector}, ${inputSelector}`);
                responseValue = selected.length > 0 ? selected.val() : null;
            } else if (questionType === 'number' || questionType === 'shorttext') {
                const input = evaluationContainer.find(`input[name="question_${questionId}_turn"]`);
                responseValue = input.length > 0 ? input.val() : null;
            } else if (questionType === 'longtext') {
                const textarea = evaluationContainer.find(`textarea[name="question_${questionId}_turn"]`);
                responseValue = textarea.length > 0 ? textarea.val() : null;
            }

            if (responseValue !== null && responseValue !== '') {
                responses[questionId] = responseValue;
            }
        });

        if (Object.keys(responses).length === 0) {
            Notification.addNotification({
                message: 'No responses to save.',
                type: 'info'
            });
            return;
        }

        const originalText = button.text();
        button.prop('disabled', true);
        button.text('Saving...');
        saveQaEvaluationResponses(cmid, pageid, modelid, turnId, responses, button, originalText);
    });

    handlersRegistered = true;
}

const resetQaView = (pageid, modelid) => {
    const messagesContainer = $(`#chat-messages-model-${modelid}-${pageid}`);
    if (messagesContainer.length === 0) {
        return;
    }

    messagesContainer.find('.message').hide();
    const placeholder = messagesContainer.find('.qa-placeholder');
    if (placeholder.length > 0) {
        placeholder.show();
    } else {
        getString('qaplaceholder', 'mod_harpiasurvey').then((message) => {
            messagesContainer.append('<div class="qa-placeholder text-muted text-center small py-3">' + message + '</div>');
        }).catch(() => {
            messagesContainer.append('<div class="qa-placeholder text-muted text-center small py-3">Ask a new question to get an answer.</div>');
        });
    }

    const input = $(`#chat-input-model-${modelid}-${pageid}`);
    if (input.length > 0) {
        input.val('');
        input.prop('disabled', false);
        const pane = $(`#model-pane-${modelid}-${pageid}`);
        pane.find('.input-group').show();
        const sendButton = $(`#send-message-btn-model-${modelid}-${pageid}`);
        sendButton.prop('disabled', false);
        pane.find('.qa-question-item').removeClass('active');
        input.focus();
    }

    const evalContainer = $(`#qa-evaluation-questions-container-model-${modelid}-${pageid}`);
    if (evalContainer.length > 0) {
        evalContainer.empty();
    }
};

const sendMessage = (cmid, pageid, modelid, message, button, input, loading) => {
    const params = new URLSearchParams({
        action: 'send_ai_message',
        cmid: cmid,
        pageid: pageid,
        message: message,
        modelid: modelid,
        sesskey: Config.sesskey
    });

    fetch(Config.wwwroot + '/mod/harpiasurvey/ajax.php?' + params.toString(), {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json'
        }
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('HTTP error! status: ' + response.status);
        }
        return response.json();
    })
    .then(data => {
        loading.hide();
        if (data.success) {
            if (data.messageid && data.content) {
                const parentid = data.parentid || null;
                if (parentid) {
                    const pendingUser = $(`#chat-messages-model-${modelid}-${pageid} .message[data-role="user"]`).last();
                    if (pendingUser.length > 0) {
                        pendingUser.attr('data-messageid', parentid);
                    }
                    updateQaQuestionItemId(pageid, modelid, parentid);
                }
                displayAIMessage(pageid, modelid, data.content, data.messageid, parentid).then(() => {
                    lockQaInput(pageid, modelid);
                    if (parentid) {
                        selectQaQuestion(pageid, modelid, parentid);
                    }
                });
            } else {
                addError('Received response but missing message ID or content');
            }
        } else {
            addError(data.message || 'Error sending message');
        }

        input.prop('disabled', false);
        button.prop('disabled', false);
        input.focus();
    })
    .catch(error => {
        loading.hide();
        // eslint-disable-next-line no-console
        console.error('Error sending message:', error);
        addError('Error sending message: ' + error.message);
        input.prop('disabled', false);
        button.prop('disabled', false);
        input.focus();
    });
};

const displayUserMessage = (pageid, modelid, message, messageid) => {
    const messagesContainer = $(`#chat-messages-model-${modelid}-${pageid}`);
    if (messagesContainer.length === 0) {
        return;
    }
    messagesContainer.find('.text-center.text-muted').remove();
    messagesContainer.find('.qa-placeholder').remove();
    Templates.render('mod_harpiasurvey/chat_user_message', {
        id: messageid,
        content: message,
        timecreated: Math.floor(Date.now() / 1000)
    }).then((html) => {
        messagesContainer.append(html);
        scrollToBottom(pageid, modelid);
    }).catch(Notification.exception);
};

const displayAIMessage = (pageid, modelid, content, messageid, parentid = null) => {
    const messagesContainer = $(`#chat-messages-model-${modelid}-${pageid}`);
    if (messagesContainer.length === 0) {
        return Promise.resolve();
    }
    return Templates.render('mod_harpiasurvey/chat_ai_message', {
        id: messageid,
        parentid: parentid,
        content: content,
        timecreated: Math.floor(Date.now() / 1000)
    }).then((html) => {
        messagesContainer.append(html);
        scrollToBottom(pageid, modelid);
        return html;
    }).catch(Notification.exception);
};

const selectQaQuestion = (pageid, modelid, questionId) => {
    const messagesContainer = $(`#chat-messages-model-${modelid}-${pageid}`);
    if (messagesContainer.length === 0) {
        return;
    }

    const pane = $(`#model-pane-${modelid}-${pageid}`);
    pane.attr('data-qa-active-id', questionId);
    pane.data('qa-active-id', questionId);

    messagesContainer.find('.qa-placeholder').hide();
    messagesContainer.find('.message').hide();
    messagesContainer.find(`.message[data-messageid="${questionId}"], .message[data-parentid="${questionId}"]`).show();

    const list = $(`.qa-questions-list[data-pageid="${pageid}"][data-model-id="${modelid}"]`);
    list.find('.qa-question-item').removeClass('active');
    list.find(`.qa-question-item[data-question-id="${questionId}"]`).addClass('active');

    lockQaInput(pageid, modelid);
    const evalId = parseInt(questionId, 10);
    if (!isNaN(evalId)) {
        renderQaEvaluationQuestions(pageid, modelid, evalId).then(() => {
            loadQaEvaluationResponses(pageid, modelid, evalId);
        });
    }
};

const addQaQuestionItem = (pageid, modelid, questionId, message) => {
    const list = $(`.qa-questions-list[data-pageid="${pageid}"][data-model-id="${modelid}"]`);
    if (list.length === 0) {
        return;
    }
    const preview = (message || '').trim().slice(0, 60);
    const label = message && message.length > 60 ? preview + '...' : preview || ('Question #' + questionId);
    const button = $('<button>', {
        type: 'button',
        class: 'btn btn-sm btn-outline-secondary qa-question-item',
        'data-pageid': pageid,
        'data-model-id': modelid,
        'data-question-id': questionId,
        title: label,
        text: label
    });
    list.append(button);
    if (list[0]) {
        list.scrollTop(list[0].scrollHeight);
    }
};

const updateQaQuestionItemId = (pageid, modelid, newId) => {
    const list = $(`.qa-questions-list[data-pageid="${pageid}"][data-model-id="${modelid}"]`);
    const lastItem = list.find('.qa-question-item').last();
    if (lastItem.length > 0) {
        lastItem.attr('data-question-id', newId);
    }
};

const lockQaInput = (pageid, modelid) => {
    const pane = $(`#model-pane-${modelid}-${pageid}`);
    const inputGroup = pane.find('.input-group');
    if (inputGroup.length > 0) {
        inputGroup.hide();
    }
    const input = $(`#chat-input-model-${modelid}-${pageid}`);
    const sendButton = $(`#send-message-btn-model-${modelid}-${pageid}`);
    input.prop('disabled', true);
    sendButton.prop('disabled', true);
};

const hasUnsavedQaEvaluation = (pageid, modelid) => {
    const key = stateKey(pageid, modelid);
    const pane = $(`#model-pane-${modelid}-${pageid}`);
    const activeId = pane.data('qa-active-id');
    if (!activeId) {
        return false;
    }
    if (!qaEvaluationSaved[key]) {
        qaEvaluationSaved[key] = {};
    }
    if (qaEvaluationSaved[key][activeId] === true) {
        return false;
    }
    const container = $(`#qa-evaluation-questions-container-model-${modelid}-${pageid}`);
    if (container.length === 0) {
        return false;
    }
    const requiredQuestions = container.find('.question-item[data-required="1"]');
    if (requiredQuestions.length === 0) {
        qaEvaluationSaved[key][activeId] = true;
        return false;
    }
    let missing = false;
    requiredQuestions.each(function() {
        const item = $(this);
        if (item.find('.saved-response-message').length === 0) {
            missing = true;
            return false;
        }
        return undefined;
    });
    qaEvaluationSaved[key][activeId] = !missing;
    return missing;
};

const renderQaEvaluationQuestions = (pageid, modelid, questionId) => {
    const containerWrapper = $(`#qa-evaluation-questions-container-model-${modelid}-${pageid}`);
    if (containerWrapper.length === 0) {
        return Promise.resolve();
    }

    const key = stateKey(pageid, modelid);
    if (!qaEvaluationSaved[key]) {
        qaEvaluationSaved[key] = {};
    }
    qaEvaluationSaved[key][questionId] = false;

    return getString('loading', 'moodle').then((loadingStr) => {
        containerWrapper.html('<div class="text-center py-3 text-muted"><i class="fa fa-spinner fa-spin"></i> ' +
            loadingStr + '</div>');

        const container = $(`.multi-model-chat-container[data-pageid="${pageid}"]`);
        const cmid = parseInt(container.data('cmid') || container.attr('data-cmid'), 10);
        const params = new URLSearchParams({
            action: 'get_turn_questions',
            pageid: pageid,
            turn_id: questionId,
            cmid: cmid,
            sesskey: Config.sesskey
        });

        return fetch(Config.wwwroot + '/mod/harpiasurvey/ajax.php?' + params.toString(), {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json'
            }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('HTTP error! status: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            if (data.success && data.html) {
                containerWrapper.html(data.html);
            } else {
                containerWrapper.html('<div class="text-center text-muted py-3">' +
                    (data.message || 'No questions available for this entry.') + '</div>');
            }
        })
        .catch((error) => {
            // eslint-disable-next-line no-console
            console.error('Error loading evaluation questions:', error);
            containerWrapper.html('<div class="text-danger text-center py-3">' +
                'Error loading questions: ' + error.message + '</div>');
        });
    });
};

const loadQaEvaluationResponses = (pageid, modelid, questionId) => {
    const containerWrapper = $(`#qa-evaluation-questions-container-model-${modelid}-${pageid}`);
    if (containerWrapper.length === 0) {
        return;
    }
    let container = containerWrapper.find(`.turn-evaluation-questions[data-turn-id="${questionId}"]`);
    if (container.length === 0) {
        container = containerWrapper;
    }

    const chatContainer = $(`.multi-model-chat-container[data-pageid="${pageid}"]`);
    const cmid = parseInt(chatContainer.data('cmid') || chatContainer.attr('data-cmid'), 10);
    const params = new URLSearchParams({
        action: 'get_turn_responses',
        cmid: cmid,
        pageid: pageid,
        turn_id: questionId,
        sesskey: Config.sesskey
    });

    fetch(Config.wwwroot + '/mod/harpiasurvey/ajax.php?' + params.toString(), {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json'
        }
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('HTTP error! status: ' + response.status);
        }
        return response.json();
    })
    .then(data => {
        if (!data.success || !data.responses) {
            return;
        }
        Object.keys(data.responses).forEach((qid) => {
            const responseData = data.responses[qid];
            const questionItem = container.find(`.question-item[data-questionid="${qid}"]`);
            if (questionItem.length === 0) {
                return;
            }
            const questionType = questionItem.data('questiontype');
            const value = responseData.response;

            if (questionType === 'singlechoice' || questionType === 'likert') {
                questionItem.find('input[type="radio"]').prop('checked', false);
                questionItem.find(`input[type="radio"][value="${value}"]`).prop('checked', true);
            } else if (questionType === 'multiplechoice') {
                questionItem.find('input[type="checkbox"]').prop('checked', false);
                try {
                    const values = JSON.parse(value);
                    if (Array.isArray(values)) {
                        values.forEach((val) => {
                            questionItem.find(`input[type="checkbox"][value="${val}"]`).prop('checked', true);
                        });
                    }
                } catch (e) {
                    // ignore invalid JSON
                }
            } else if (questionType === 'select') {
                questionItem.find('select').val(value);
            } else if (questionType === 'number') {
                questionItem.find('input[type="number"]').val(value);
            } else if (questionType === 'shorttext') {
                questionItem.find('input[type="text"]').val(value);
            } else if (questionType === 'longtext') {
                questionItem.find('textarea').val(value);
            }

            if (responseData.saved_datetime) {
                getString('saved', 'mod_harpiasurvey').then((savedText) => {
                    getString('on', 'mod_harpiasurvey').then((onText) => {
                        const icon = '<i class="fa fa-check-circle" aria-hidden="true"></i>';
                        let savedMessage = questionItem.find('.saved-response-message');
                        if (savedMessage.length === 0) {
                            const messageHtml = '<div class="mt-2 small text-muted saved-response-message">' +
                                `${icon} ${savedText} ${onText} ${responseData.saved_datetime}</div>`;
                            questionItem.append(messageHtml);
                        } else {
                            savedMessage.html(`${icon} ${savedText} ${onText} ${responseData.saved_datetime}`);
                        }
                        lockQuestionItem(questionItem);
                        ensureEditButton(questionItem);
                    });
                });
            }

            getString('answerhistory', 'mod_harpiasurvey').then((historyText) => {
                let history = questionItem.find('.answer-history');
                if (!history.length && responseData.history_count > 1) {
                    const details = $('<details class="answer-history mt-1"></details>');
                    details.append(`<summary>${historyText} (${responseData.history_count})</summary>`);
                    details.append('<ul class="list-unstyled mt-2 mb-0"></ul>');
                    questionItem.append(details);
                    history = details;
                }
                if (history.length) {
                    const list = history.find('ul');
                    list.empty();
                    (responseData.history_items || []).forEach((item) => {
                        list.append(`<li class="mb-1"><span class="text-muted">${item.time}</span> — ${item.response}</li>`);
                    });
                    history.find('summary').text(`${historyText} (${responseData.history_count || 0})`);
                    if ((responseData.history_count || 0) > 1) {
                        history.show();
                    } else {
                        history.hide();
                    }
                }
            });
        });

        const key = stateKey(pageid, modelid);
        if (!qaEvaluationSaved[key]) {
            qaEvaluationSaved[key] = {};
        }
        const requiredQuestions = container.find('.question-item[data-required="1"]');
        if (requiredQuestions.length === 0) {
            qaEvaluationSaved[key][questionId] = true;
            return;
        }
        const requiredCount = requiredQuestions.length;
        const savedRequired = requiredQuestions.filter(function() {
            return $(this).find('.saved-response-message').length > 0;
        }).length;
        qaEvaluationSaved[key][questionId] = (requiredCount > 0 && savedRequired === requiredCount);
    })
    .catch((error) => {
        // eslint-disable-next-line no-console
        console.error('Error loading evaluation responses:', error);
    });
};

const saveQaEvaluationResponses = (cmid, pageid, modelid, questionId, responses, button, originalText) => {
    const entries = Object.entries(responses);
    let failedCount = 0;

    let promiseChain = Promise.resolve();
    entries.forEach(([qid, response]) => {
        promiseChain = promiseChain.then(() => {
            const params = new URLSearchParams({
                action: 'save_response',
                cmid: cmid,
                pageid: pageid,
                questionid: qid,
                response: response,
                turn_id: questionId,
                sesskey: Config.sesskey
            });

            return fetch(Config.wwwroot + '/mod/harpiasurvey/ajax.php?' + params.toString(), {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json'
                }
            })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    failedCount++;
                }
            })
            .catch(() => {
                failedCount++;
            });
        });
    });

    promiseChain.then(() => {
        button.prop('disabled', false);
        button.text(originalText);
        if (failedCount === 0) {
            Notification.addNotification({
                message: 'Responses saved.',
                type: 'success'
            });
        } else {
            Notification.addNotification({
                message: 'Some responses failed to save.',
                type: 'warning'
            });
        }
        const key = stateKey(pageid, modelid);
        if (!qaEvaluationSaved[key]) {
            qaEvaluationSaved[key] = {};
        }
        qaEvaluationSaved[key][questionId] = false;
        loadQaEvaluationResponses(pageid, modelid, questionId);
    });
};

const scrollToBottom = (pageid, modelid) => {
    const messagesContainer = $(`#chat-messages-model-${modelid}-${pageid}`);
    if (messagesContainer.length > 0) {
        messagesContainer.scrollTop(messagesContainer[0].scrollHeight);
    }
};
