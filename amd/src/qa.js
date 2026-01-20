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

export const init = () => initialize();

const initialize = () => {
    if (initialized) {
        return;
    }
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
        resetQaView(pageid);
    });

    $(document).on('click', '.qa-question-item', function(e) {
        e.preventDefault();
        const button = $(this);
        const pageid = parseInt(button.data('pageid'), 10);
        const questionId = parseInt(button.data('question-id'), 10);
        if (!pageid || !questionId) {
            return;
        }
        selectQaQuestion(pageid, questionId);
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
        input.prop('disabled', false);
        const container = $(`.ai-conversation-container[data-pageid="${pageid}"]`);
        const inputGroup = container.find('.input-group');
        if (inputGroup.length > 0) {
            inputGroup.show();
        }
        const sendButton = container.find('.chat-send-btn');
        sendButton.prop('disabled', false);
        container.find('.qa-question-item').removeClass('active');
        input.focus();
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
    messagesContainer.find('.qa-placeholder').hide();
    messagesContainer.find('.message').hide();
    messagesContainer.find(`.message[data-messageid="${questionId}"], .message[data-parentid="${questionId}"]`).show();
    const list = $(`.qa-questions-list[data-pageid="${pageid}"]`);
    list.find('.qa-question-item').removeClass('active');
    list.find(`.qa-question-item[data-question-id="${questionId}"]`).addClass('active');
    lockQaInput(pageid);
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
};

const updateQaQuestionItemId = (pageid, newId) => {
    const list = $(`.qa-questions-list[data-pageid="${pageid}"]`);
    const lastItem = list.find('.qa-question-item').last();
    if (lastItem.length > 0) {
        lastItem.attr('data-question-id', newId);
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
