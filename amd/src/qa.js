// Q&A-only logic for harpiasurvey chat (single question/answer).
import {
    Templates,
    Notification,
    Config,
    getString,
    $,
    scrollToBottom,
    addError
} from './common_chat';

let initialized = false;
let handlersRegistered = false;
const qaEvaluationSaved = {};
let editLabel = 'Edit';

const getQaContainer = (pageid) => $(`.ai-conversation-container[data-pageid="${pageid}"]`);

const getQaQuestionCount = (pageid) => {
    const list = $(`.qa-questions-list[data-pageid="${pageid}"]`);
    if (list.length === 0) {
        return 0;
    }
    return list.find('.qa-question-item').length;
};

const getQaMaxQuestions = (pageid) => {
    const container = getQaContainer(pageid);
    if (container.length === 0) {
        return null;
    }
    const value = parseInt(container.data('max-qa-questions') || container.attr('data-max-qa-questions'), 10);
    return Number.isFinite(value) && value > 0 ? value : null;
};

const isQaQuestionLimitReached = (pageid) => {
    const maxQuestions = getQaMaxQuestions(pageid);
    if (!maxQuestions) {
        return false;
    }
    return getQaQuestionCount(pageid) >= maxQuestions;
};

const notifyQaMaxQuestionsReached = () => {
    getString('maxqaquestionsreached', 'mod_harpiasurvey').then((message) => {
        Notification.addNotification({
            message: message,
            type: 'warning'
        });
    }).catch(() => {
        Notification.addNotification({
            message: 'Maximum number of questions reached. You cannot add more questions.',
            type: 'warning'
        });
    });
};

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

const resetQuestionItemToNeutral = (questionItem) => {
    if (!questionItem || questionItem.length === 0) {
        return;
    }

    questionItem.removeAttr('data-response-locked');
    questionItem.removeAttr('data-response-editing');
    questionItem.find('.saved-response-message').remove();
    questionItem.find('.answer-history').remove();
    questionItem.find('.question-edit-controls').remove();

    questionItem.find('input, select, textarea').prop('disabled', false);

    questionItem.find('input[type="radio"], input[type="checkbox"]').each(function() {
        $(this).prop('checked', Boolean(this.defaultChecked));
    });

    questionItem.find('select').each(function() {
        const select = $(this);
        const defaultOption = select.find('option').filter(function() {
            return this.defaultSelected;
        }).first();
        if (defaultOption.length > 0) {
            select.val(defaultOption.val());
        } else {
            select.prop('selectedIndex', 0);
        }
    });

    questionItem.find('input[type="number"], input[type="text"], textarea').each(function() {
        $(this).val(this.defaultValue || '');
    });
};

export const init = () => initialize();

const initialize = () => {
    if (initialized) {
        return;
    }
    getString('edit', 'moodle').then((str) => {
        editLabel = str;
    }).catch(() => {
        editLabel = 'Edit';
    });
    const containers = $('.ai-conversation-container[data-behavior="qa"]');
    if (containers.length === 0) {
        return;
    }
    containers.each(function() {
        const pageid = parseInt($(this).data('pageid'), 10);
        if (!pageid) {
            return;
        }
        const activeId = parseInt($(this).data('qa-active-id'), 10);
        const messagesContainer = $(`#chat-messages-page-${pageid}`);
        if (messagesContainer.find('.message[data-role="assistant"]').length > 0) {
            lockQaInput(pageid);
        }
        if (isQaQuestionLimitReached(pageid)) {
            lockQaInput(pageid);
        }
        if (activeId) {
            selectQaQuestion(pageid, activeId);
        }
    });
    registerHandlers();
    initialized = true;
};

function registerHandlers() {
    if (handlersRegistered) {
        return;
    }

    $(document).on('click', '.ai-conversation-container[data-behavior="qa"] .chat-send-btn', function(e) {
        e.preventDefault();
        const button = $(this);
        const cmid = parseInt(button.data('cmid'), 10);
        const pageid = parseInt(button.data('pageid'), 10);
        if (!cmid || !pageid) {
            addError('Missing cmid or pageid');
            return;
        }
        if (isQaQuestionLimitReached(pageid)) {
            notifyQaMaxQuestionsReached();
            return;
        }
        if (hasUnsavedQaEvaluation(pageid)) {
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
        const input = $(`#chat-input-page-${pageid}`);
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
        const container = button.closest('.ai-conversation-container');
        const modelsdata = container.data('models');
        let modelid = null;
        if (modelsdata) {
            const modelids = modelsdata.split(',');
            if (modelids.length > 0) {
                modelid = parseInt(modelids[0], 10);
            }
        }
        if (!modelid) {
            addError('No model available');
            return;
        }
        input.prop('disabled', true);
        button.prop('disabled', true);
        input.val('');
        const tempId = 'temp-user-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
        displayUserMessage(pageid, message, tempId);
        addQaQuestionItem(pageid, tempId, message);
        selectQaQuestion(pageid, tempId);
        const loading = $(`#chat-loading-page-${pageid}`);
        loading.show();
        sendMessage(cmid, pageid, message, modelid, button, input, loading);
    });

    $(document).on('keydown', '.ai-conversation-container[data-behavior="qa"] .chat-input', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            $(this).closest('.ai-conversation-container').find('.chat-send-btn').click();
        }
    });

    $(document).on('click', '.ai-conversation-container[data-behavior="qa"] .new-question-btn', function(e) {
        e.preventDefault();
        const pageid = parseInt($(this).data('pageid'), 10);
        if (!pageid) {
            return;
        }
        if (isQaQuestionLimitReached(pageid)) {
            notifyQaMaxQuestionsReached();
            return;
        }
        if (hasUnsavedQaEvaluation(pageid)) {
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
        resetQaView(pageid);
    });

    $(document).on('click', '.ai-conversation-container[data-behavior="qa"] .qa-question-item', function(e) {
        e.preventDefault();
        const button = $(this);
        const pageid = parseInt(button.data('pageid'), 10);
        const questionId = button.data('question-id');
        if (!pageid || !questionId) {
            return;
        }
        selectQaQuestion(pageid, questionId);
    });

    $(document).on('click', '.ai-conversation-container[data-behavior="qa"] .export-conversation-btn', function(e) {
        e.preventDefault();
        const button = $(this);
        const pageid = parseInt(button.data('pageid'), 10);
        const cmid = parseInt(button.data('cmid'), 10);
        if (!pageid || !cmid) {
            addError('Missing required data to export conversation');
            return;
        }

        const container = getQaContainer(pageid);
        const activeId = parseInt(container.data('qa-active-id') || container.attr('data-qa-active-id'), 10);

        const params = new URLSearchParams();
        params.append('action', 'export_conversation');
        params.append('cmid', cmid);
        params.append('pageid', pageid);
        params.append('sesskey', Config.sesskey);
        if (activeId && !isNaN(activeId)) {
            params.append('turn_id', activeId);
        }

        window.open(Config.wwwroot + '/mod/harpiasurvey/ajax.php?' + params.toString(), '_blank');
    });

    $(document).on('click', '.ai-conversation-container[data-behavior="qa"] .qa-evaluation-questions-container .save-turn-evaluation-questions-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const button = $(this);
        const turnId = parseInt(button.data('turn-id'), 10);
        const pageid = parseInt(button.data('pageid'), 10);
        const cmid = parseInt(button.data('cmid'), 10);
        if (!turnId || !pageid || !cmid) {
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
        saveQaEvaluationResponses(cmid, pageid, turnId, responses, button, originalText);
    });

    handlersRegistered = true;
}

const resetQaView = (pageid) => {
    const messagesContainer = $(`#chat-messages-page-${pageid}`);
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
    const input = $(`#chat-input-page-${pageid}`);
    if (input.length > 0) {
        input.val('');
        const limitReached = isQaQuestionLimitReached(pageid);
        input.prop('disabled', limitReached);
        const container = $(`.ai-conversation-container[data-pageid="${pageid}"]`);
        const inputGroup = container.find('.input-group');
        if (inputGroup.length > 0) {
            if (limitReached) {
                inputGroup.hide();
            } else {
                inputGroup.show();
            }
        }
        const sendButton = container.find('.chat-send-btn');
        sendButton.prop('disabled', limitReached);
        container.find('.qa-question-item').removeClass('active');
        if (!limitReached) {
            input.focus();
        }
    }
    const evalContainer = $(`#qa-evaluation-questions-container-${pageid}`);
    if (evalContainer.length > 0) {
        evalContainer.empty();
    }
};

const sendMessage = (cmid, pageid, message, modelid, button, input, loading) => {
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
                    const pendingUser = $(`#chat-messages-page-${pageid} .message[data-role="user"]`).last();
                    if (pendingUser.length > 0) {
                        pendingUser.attr('data-messageid', parentid);
                    }
                    updateQaQuestionItemId(pageid, parentid);
                }
                displayAIMessage(pageid, data.content, data.messageid, parentid).then(() => {
                    scrollToBottom(pageid);
                    lockQaInput(pageid);
                    if (parentid) {
                        selectQaQuestion(pageid, parentid);
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

const displayUserMessage = (pageid, message, messageid) => {
    const messagesContainer = $(`#chat-messages-page-${pageid}`);
    if (messagesContainer.length === 0) {
        return;
    }
    messagesContainer.find('.text-center.text-muted').remove();
    Templates.render('mod_harpiasurvey/chat_user_message', {
        id: messageid,
        content: message,
        timecreated: Math.floor(Date.now() / 1000)
    }).then((html) => {
        messagesContainer.append(html);
        scrollToBottom(pageid);
    }).catch(Notification.exception);
};

const displayAIMessage = (pageid, content, messageid, parentid = null) => {
    const messagesContainer = $(`#chat-messages-page-${pageid}`);
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
        scrollToBottom(pageid);
        return html;
    }).catch(Notification.exception);
};

const selectQaQuestion = (pageid, questionId) => {
    const messagesContainer = $(`#chat-messages-page-${pageid}`);
    if (messagesContainer.length === 0) {
        return;
    }
    const container = $(`.ai-conversation-container[data-pageid="${pageid}"]`);
    container.attr('data-qa-active-id', questionId);
    container.data('qa-active-id', questionId);
    messagesContainer.find('.qa-placeholder').hide();
    messagesContainer.find('.message').hide();
    messagesContainer.find(`.message[data-messageid="${questionId}"], .message[data-parentid="${questionId}"]`).show();
    const list = $(`.qa-questions-list[data-pageid="${pageid}"]`);
    list.find('.qa-question-item').removeClass('active');
    list.find(`.qa-question-item[data-question-id="${questionId}"]`).addClass('active');
    lockQaInput(pageid);
    const evalId = parseInt(questionId, 10);
    if (!isNaN(evalId)) {
        renderQaEvaluationQuestions(pageid, evalId).then(() => {
            loadQaEvaluationResponses(pageid, evalId);
        });
    } else {
        const evalContainer = $(`#qa-evaluation-questions-container-${pageid}`);
        if (evalContainer.length > 0) {
            evalContainer.html('<div class="text-muted small py-2">Evaluation will be available after the model response is received.</div>');
        }
    }
};

const addQaQuestionItem = (pageid, questionId, message) => {
    const list = $(`.qa-questions-list[data-pageid="${pageid}"]`);
    if (list.length === 0) {
        return;
    }
    const preview = (message || '').trim().slice(0, 60);
    const label = message && message.length > 60 ? preview + '...' : preview || ('Question #' + questionId);
    const button = $('<button>', {
        type: 'button',
        class: 'btn btn-sm btn-outline-secondary qa-question-item',
        'data-pageid': pageid,
        'data-question-id': questionId,
        title: label,
        text: label
    });
    list.append(button);
    if (list[0]) {
        list.scrollTop(list[0].scrollHeight);
    }
};

const updateQaQuestionItemId = (pageid, newId) => {
    const list = $(`.qa-questions-list[data-pageid="${pageid}"]`);
    const lastItem = list.find('.qa-question-item').last();
    if (lastItem.length > 0) {
        lastItem.attr('data-question-id', newId);
        lastItem.data('question-id', newId);
    }
};

const lockQaInput = (pageid) => {
    const container = $(`.ai-conversation-container[data-pageid="${pageid}"]`);
    const inputGroup = container.find('.input-group');
    if (inputGroup.length > 0) {
        inputGroup.hide();
    }
    const input = $(`#chat-input-page-${pageid}`);
    const sendButton = container.find('.chat-send-btn');
    input.prop('disabled', true);
    sendButton.prop('disabled', true);
};

const hasUnsavedQaEvaluation = (pageid) => {
    const qaContainer = $(`.ai-conversation-container[data-pageid="${pageid}"]`);
    const activeId = qaContainer.data('qa-active-id');
    if (!activeId) {
        return false;
    }
    if (!qaEvaluationSaved[pageid]) {
        qaEvaluationSaved[pageid] = {};
    }
    if (qaEvaluationSaved[pageid][activeId] === true) {
        return false;
    }
    const container = $(`#qa-evaluation-questions-container-${pageid}`);
    if (container.length === 0) {
        return false;
    }
    const requiredQuestions = container.find('.question-item[data-required="1"]');
    if (requiredQuestions.length === 0) {
        qaEvaluationSaved[pageid][activeId] = true;
        return false;
    }
    const requiredSet = requiredQuestions;
    let missing = false;
    requiredSet.each(function() {
        const item = $(this);
        if (item.find('.saved-response-message').length === 0) {
            missing = true;
            return false;
        }
        return undefined;
    });
    qaEvaluationSaved[pageid][activeId] = !missing;
    return missing;
};

const renderQaEvaluationQuestions = (pageid, questionId) => {
    const containerWrapper = $(`#qa-evaluation-questions-container-${pageid}`);
    if (containerWrapper.length === 0) {
        return Promise.resolve();
    }
    if (!qaEvaluationSaved[pageid]) {
        qaEvaluationSaved[pageid] = {};
    }
    qaEvaluationSaved[pageid][questionId] = false;
    return getString('loading', 'moodle').then((loadingStr) => {
        containerWrapper.html('<div class="text-center py-3 text-muted"><i class="fa fa-spinner fa-spin"></i> ' +
            loadingStr + '</div>');

        const params = new URLSearchParams({
            action: 'get_turn_questions',
            pageid: pageid,
            turn_id: questionId,
            cmid: $(`#chat-messages-page-${pageid}`).closest('.ai-conversation-container').data('cmid'),
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

const loadQaEvaluationResponses = (pageid, questionId) => {
    const containerWrapper = $(`#qa-evaluation-questions-container-${pageid}`);
    if (containerWrapper.length === 0) {
        return;
    }
    let container = containerWrapper.find(`.turn-evaluation-questions[data-turn-id="${questionId}"]`);
    if (container.length === 0) {
        container = containerWrapper;
    }
    container.find('.question-item').each(function() {
        resetQuestionItemToNeutral($(this));
    });

    const params = new URLSearchParams({
        action: 'get_turn_responses',
        cmid: $(`#chat-messages-page-${pageid}`).closest('.ai-conversation-container').data('cmid'),
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
        if (!data.success || !data.responses || Object.keys(data.responses).length === 0) {
            if (!qaEvaluationSaved[pageid]) {
                qaEvaluationSaved[pageid] = {};
            }
            qaEvaluationSaved[pageid][questionId] = false;
            return;
        }
        Object.keys(data.responses).forEach((questionId) => {
            const responseData = data.responses[questionId];
            const questionItem = container.find(`.question-item[data-questionid="${questionId}"]`);
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
        if (!qaEvaluationSaved[pageid]) {
            qaEvaluationSaved[pageid] = {};
        }
        const requiredQuestions = container.find('.question-item[data-required="1"]');
        if (requiredQuestions.length === 0) {
            qaEvaluationSaved[pageid][questionId] = true;
            return;
        }
        const requiredSet = requiredQuestions;
        const requiredCount = requiredSet.length;
        const savedRequired = requiredSet.filter(function() {
            return $(this).find('.saved-response-message').length > 0;
        }).length;
        qaEvaluationSaved[pageid][questionId] = (requiredCount > 0 && savedRequired === requiredCount);
    })
    .catch((error) => {
        // eslint-disable-next-line no-console
        console.error('Error loading evaluation responses:', error);
    });
};

const saveQaEvaluationResponses = (cmid, pageid, questionId, responses, button, originalText) => {
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
        if (!qaEvaluationSaved[pageid]) {
            qaEvaluationSaved[pageid] = {};
        }
        qaEvaluationSaved[pageid][questionId] = false;
        loadQaEvaluationResponses(pageid, questionId);
    });
};
