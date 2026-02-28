import Notification from 'core/notification';
import Config from 'core/config';
import {get_string as getString} from 'core/str';
import $ from 'jquery';

let initialized = false;
let state = {
    cmid: 0,
    experimentid: 0,
    finalized: false,
};

const ajaxUrl = () => Config.wwwroot + '/mod/harpiasurvey/ajax.php';

const applyReadOnlyMode = () => {
    if (!state.finalized) {
        return;
    }

    if ($('.harpiasurvey-finalized-banner').length === 0) {
        const banner = $('<div class="alert alert-secondary harpiasurvey-finalized-banner mb-3"></div>');
        getString('experimentreadonlybanner', 'mod_harpiasurvey').then((txt) => {
            banner.text(txt);
        }).catch(() => {
            banner.text('This experiment has been finalized and is now read-only.');
        });
        $('.card-body').first().prepend(banner);
    }

    $('.question-item input, .question-item textarea, .question-item select').prop('disabled', true);
    $('.chat-input, .chat-send-btn').prop('disabled', true);

    [
        '.save-all-responses-btn',
        '.save-turn-evaluation-questions-btn',
        '.question-edit-btn',
        '.new-question-btn',
        '.create-direct-branch-btn',
        '.create-branch-from-tree-btn',
        '.new-conversation-btn',
        '.create-root-btn',
        '.branch-btn',
        '.chat-send-btn',
    ].forEach((selector) => {
        $(selector).prop('disabled', true).addClass('disabled');
    });
};

const updateConfirmState = (container, summary, finalized) => {
    const confirmBtn = container.find('[data-role="confirm-finalize-btn"]');
    const certify = container.find('[data-role="certify-checkbox"]');
    const requiredWarning = container.find('[data-role="required-warning"]');
    const requiredUnanswered = parseInt(summary?.requiredunanswered || 0, 10);
    const canFinalize = !!summary?.canfinalize && requiredUnanswered === 0;

    if (finalized) {
        certify.prop('checked', false).prop('disabled', true);
        confirmBtn.prop('disabled', true);
        requiredWarning.addClass('d-none');
        return;
    }

    certify.prop('disabled', false);
    requiredWarning.toggleClass('d-none', requiredUnanswered === 0);
    const certified = certify.is(':checked');
    confirmBtn.prop('disabled', !(certified && canFinalize));
};

const renderSummary = (container, payload) => {
    const summary = payload.summary || {};
    const content = container.find('.finalize-summary-content');
    const loading = container.find('.finalize-summary-loading');

    container.find('[data-field="answered"]').text(summary.answered || 0);
    container.find('[data-field="unanswered"]').text(summary.unanswered || 0);
    container.find('[data-field="requiredunanswered"]').text(summary.requiredunanswered || 0);

    const details = container.find('[data-role="page-details"]');
    details.empty();
    (summary.pages || []).forEach((page) => {
        const row = $('<div class="d-flex justify-content-between small py-1 border-bottom"></div>');
        const left = $('<div></div>');
        const right = $('<div class="text-nowrap ml-2"></div>');
        left.text(`${page.title}`);
        right.text(`${page.unanswered || 0}`);
        row.append(left).append(right);
        details.append(row);
    });
    if (!summary.pages || summary.pages.length === 0) {
        details.append('<div class="text-muted small">-</div>');
    }

    loading.addClass('d-none');
    content.removeClass('d-none');
    updateConfirmState(container, summary, !!payload.finalized);
};

const loadSummary = (wrapper) => {
    const modal = wrapper.find('.harpiasurvey-finalize-modal');
    modal.find('.finalize-summary-loading').removeClass('d-none');
    modal.find('.finalize-summary-content').addClass('d-none');
    modal.find('[data-role="confirm-finalize-btn"]').prop('disabled', true);

    const params = new URLSearchParams({
        action: 'get_finalize_summary',
        cmid: String(state.cmid),
        experimentid: String(state.experimentid),
        sesskey: Config.sesskey,
        _: String(Date.now()),
    });

    fetch(ajaxUrl(), {
        method: 'POST',
        cache: 'no-store',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: params.toString(),
    }).then((r) => r.json()).then((data) => {
        if (!data.success) {
            throw new Error(data.message || 'Failed to load summary');
        }
        state.finalized = !!data.finalized;
        wrapper.attr('data-finalized', state.finalized ? '1' : '0');
        renderSummary(modal, data);
        if (state.finalized) {
            applyReadOnlyMode();
        }
    }).catch((e) => {
        modal.find('.finalize-summary-loading').addClass('d-none');
        Notification.addNotification({
            message: e.message || 'Failed to load finalization summary.',
            type: 'error',
        });
    });
};

const bindFinalizeUI = () => {
    $(document).on('click', '.harpiasurvey-finalize-modal [data-role="finalize-cancel-btn"]', function(e) {
        e.preventDefault();
        const modal = $(this).closest('.harpiasurvey-finalize-modal');
        modal.modal('hide');
    });

    $(document).on('click', '.save-all-responses-btn, .save-turn-evaluation-questions-btn, .chat-send-btn, .new-question-btn, .create-direct-branch-btn, .create-branch-from-tree-btn, .new-conversation-btn, .create-root-btn, .question-edit-btn', function(e) {
        if (!state.finalized) {
            return;
        }
        e.preventDefault();
        e.stopPropagation();
        getString('experimentfinalizedreadonly', 'mod_harpiasurvey').then((msg) => {
            Notification.addNotification({message: msg, type: 'info'});
        }).catch(() => {
            Notification.addNotification({message: 'This experiment is read-only.', type: 'info'});
        });
    });

    $(document).on('click', '[data-action="open-finalize-modal"]', function(e) {
        e.preventDefault();
        const wrapper = $(this).closest('.harpiasurvey-finalize-page');
        const modal = wrapper.find('.harpiasurvey-finalize-modal');
        modal.find('[data-role="certify-checkbox"]').prop('checked', false);
        modal.modal('show');
        loadSummary(wrapper);
    });

    $(document).on('change', '.harpiasurvey-finalize-modal [data-role="certify-checkbox"]', function() {
        const modal = $(this).closest('.harpiasurvey-finalize-modal');
        const summary = {
            canfinalize: parseInt(modal.find('[data-field="requiredunanswered"]').text(), 10) === 0,
            requiredunanswered: parseInt(modal.find('[data-field="requiredunanswered"]').text(), 10) || 0,
        };
        updateConfirmState(modal, summary, state.finalized);
    });

    $(document).on('click', '.harpiasurvey-finalize-modal [data-role="confirm-finalize-btn"]', function() {
        const btn = $(this);
        const modal = btn.closest('.harpiasurvey-finalize-modal');
        const certified = modal.find('[data-role="certify-checkbox"]').is(':checked') ? 1 : 0;
        btn.prop('disabled', true);

        const params = new URLSearchParams({
            action: 'confirm_finalize_experiment',
            cmid: String(state.cmid),
            experimentid: String(state.experimentid),
            certified: String(certified),
            sesskey: Config.sesskey,
        });

        fetch(ajaxUrl(), {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: params.toString(),
        }).then((r) => r.json()).then((data) => {
            if (!data.success) {
                Notification.addNotification({
                    message: data.message || 'Could not finalize experiment.',
                    type: 'warning',
                });
                renderSummary(modal, data);
                return;
            }
            state.finalized = true;
            Notification.addNotification({
                message: data.message || 'Experiment finalized successfully.',
                type: 'success',
            });
            modal.modal('hide');
            // Reload keeps URL/page context and guarantees all modules reload in read-only mode.
            window.location.reload();
        }).catch((e) => {
            Notification.addNotification({
                message: e.message || 'Could not finalize experiment.',
                type: 'error',
            });
            btn.prop('disabled', false);
        });
    });
};

export const init = (cmid, experimentid, finalized = false) => {
    state.cmid = parseInt(cmid, 10) || 0;
    state.experimentid = parseInt(experimentid, 10) || 0;
    state.finalized = finalized === true || finalized === 1 || finalized === '1';

    if (!initialized) {
        bindFinalizeUI();
        initialized = true;
    }

    applyReadOnlyMode();
};
