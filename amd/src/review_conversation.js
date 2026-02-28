// Review conversation mode for Harpia Survey.
import Config from 'core/config';
import Notification from 'core/notification';
import $ from 'jquery';

let initialized = false;

const ajaxPost = (params) => {
    return fetch(Config.wwwroot + '/mod/harpiasurvey/ajax.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: params.toString(),
    }).then((response) => response.json());
};

const clearQuestionInputs = (pageid) => {
    const root = $(`.page-view .page-questions .question-item`);
    root.each(function() {
        const item = $(this);
        const questiontype = item.data('questiontype');
        if (questiontype === 'singlechoice' || questiontype === 'likert') {
            item.find('input[type="radio"]').prop('checked', false);
        } else if (questiontype === 'multiplechoice') {
            item.find('input[type="checkbox"]').prop('checked', false);
        } else if (questiontype === 'select') {
            item.find('select').val('');
        } else if (questiontype === 'number' || questiontype === 'shorttext') {
            item.find('input').val('');
        } else if (questiontype === 'longtext') {
            item.find('textarea').val('');
        }
        item.attr('data-response-locked', '0');
        item.attr('data-response-editing', '1');
        item.find('.saved-response-message').remove();
        item.find('.answer-history').remove();
        item.find('.question-edit-btn').hide();
        item.find('input, select, textarea').prop('disabled', false);
    });

    const statusDiv = $(`.page-view .page-questions .save-all-status`);
    statusDiv.hide().empty();

    // Keep save button style consistent after target switch.
    const saveBtn = $(`.save-all-responses-btn[data-pageid="${pageid}"]`);
    saveBtn.removeClass('btn-success btn-warning').addClass('btn-primary');
};

const setQuestionValue = (questionItem, questionType, responseValue) => {
    if (responseValue === null || responseValue === undefined || responseValue === '') {
        return;
    }
    if (questionType === 'singlechoice' || questionType === 'likert') {
        questionItem.find(`input[type="radio"][value="${responseValue}"]`).prop('checked', true);
        return;
    }
    if (questionType === 'multiplechoice') {
        let values = [];
        try {
            values = JSON.parse(responseValue);
        } catch (e) {
            values = [];
        }
        if (!Array.isArray(values)) {
            values = [];
        }
        values.forEach((value) => {
            questionItem.find(`input[type="checkbox"][value="${value}"]`).prop('checked', true);
        });
        return;
    }
    if (questionType === 'select') {
        questionItem.find('select').val(String(responseValue));
        return;
    }
    if (questionType === 'number' || questionType === 'shorttext') {
        questionItem.find('input').val(responseValue);
        return;
    }
    if (questionType === 'longtext') {
        questionItem.find('textarea').val(responseValue);
    }
};

const renderMessages = (pageid, messages) => {
    const container = $(`#review-thread-messages-${pageid}`);
    container.empty();

    if (!messages || messages.length === 0) {
        container.html('<div class="text-muted text-center small py-3">No messages in this conversation.</div>');
        return;
    }

    messages.forEach((msg) => {
        const roleClass = msg.role === 'assistant' ? 'border-primary' : 'border-secondary';
        const roleLabel = msg.role ? msg.role.charAt(0).toUpperCase() + msg.role.slice(1) : 'Message';
        const row = $('<div class="review-message-item border rounded p-2 mb-2 bg-white"></div>');
        row.addClass(roleClass);
        row.append(`<div class="small text-muted mb-1"><strong>${roleLabel}</strong>${msg.timecreated ? ` • ${msg.timecreated}` : ''}</div>`);
        row.append(`<div class="review-message-content">${msg.content || ''}</div>`);
        container.append(row);
    });
    container.scrollTop(0);
};

const loadEvaluationResponses = (cmid, pageid, threadid) => {
    const responseParams = new URLSearchParams({
        action: 'get_review_evaluation_responses',
        cmid: String(cmid),
        pageid: String(pageid),
        threadid: String(threadid),
        sesskey: Config.sesskey,
    });
    return ajaxPost(responseParams).then((response) => {
        if (!response.success) {
            throw new Error(response.message || 'Failed to load review evaluation responses.');
        }

        clearQuestionInputs(pageid);

        const reviewContainer = $(`.review-conversation-container[data-pageid="${pageid}"]`);
        reviewContainer.attr('data-review-target-id', String(response.targetid || ''));
        const aiContainer = $(`.ai-conversation-container[data-pageid="${pageid}"]`);
        aiContainer.attr('data-review-target-id', String(response.targetid || ''));

        const responses = response.responses || {};
        Object.keys(responses).forEach((questionid) => {
            const questionItem = $(`.page-view .page-questions .question-item[data-questionid="${questionid}"]`);
            if (!questionItem.length) {
                return;
            }
            setQuestionValue(questionItem, questionItem.data('questiontype'), responses[questionid].response);
        });
    });
};

const loadThread = (cmid, pageid, threadid) => {
    const params = new URLSearchParams({
        action: 'get_review_thread_messages',
        cmid: String(cmid),
        pageid: String(pageid),
        threadid: String(threadid),
        sesskey: Config.sesskey,
    });
    return ajaxPost(params).then((response) => {
        if (!response.success) {
            throw new Error(response.message || 'Failed to load review conversation.');
        }
        renderMessages(pageid, response.messages || []);
        return loadEvaluationResponses(cmid, pageid, threadid);
    }).catch((error) => {
        Notification.addNotification({
            message: error.message || 'Failed to load review conversation.',
            type: 'error',
        });
    });
};

const bindHandlers = () => {
    $(document).on('click', '.review-thread-item', function(e) {
        e.preventDefault();
        const button = $(this);
        const pageid = parseInt(button.data('pageid'), 10);
        const threadid = parseInt(button.data('thread-id'), 10);
        const container = $(`.review-conversation-container[data-pageid="${pageid}"]`);
        const cmid = parseInt(container.data('cmid'), 10);

        if (!pageid || !threadid || !cmid) {
            return;
        }

        $(`.review-thread-item[data-pageid="${pageid}"]`).removeClass('active');
        button.addClass('active');
        loadThread(cmid, pageid, threadid);
    });
};

export const init = () => {
    if (!initialized) {
        bindHandlers();
        initialized = true;
    }

    $('.review-conversation-container').each(function() {
        const container = $(this);
        const pageid = parseInt(container.data('pageid'), 10);
        const cmid = parseInt(container.data('cmid'), 10);
        const defaultthread = parseInt(container.data('review-default-thread-id'), 10);
        if (!pageid || !cmid || !defaultthread) {
            return;
        }
        const defaultButton = container.find(`.review-thread-item[data-thread-id="${defaultthread}"]`);
        if (defaultButton.length) {
            defaultButton.trigger('click');
        }
    });
};

