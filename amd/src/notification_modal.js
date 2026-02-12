import {get_string as getString} from 'core/str';
import Notification from 'core/notification';
import $ from 'jquery';

const MODAL_ID = 'harpiasurvey-notification-modal';
let cachedStrings = null;
let observerStarted = false;
let notificationPatched = false;

const getType = (node) => {
    const el = $(node);
    if (el.hasClass('alert-danger') || el.hasClass('alert-error')) {
        return 'danger';
    }
    if (el.hasClass('alert-success')) {
        return 'success';
    }
    if (el.hasClass('alert-warning')) {
        return 'warning';
    }
    return 'info';
};

const getStrings = () => {
    if (cachedStrings) {
        return Promise.resolve(cachedStrings);
    }

    return Promise.all([
        getString('notifications', 'mod_harpiasurvey').catch(() => 'Notifications'),
        getString('close', 'moodle').catch(() => 'Close'),
        getString('success', 'moodle').catch(() => 'Success'),
        getString('error', 'moodle').catch(() => 'Error'),
        getString('warning', 'moodle').catch(() => 'Warning'),
        getString('info', 'moodle').catch(() => 'Info')
    ]).then((strings) => {
        cachedStrings = {
            title: strings[0],
            closeLabel: strings[1],
            labels: {
                success: strings[2],
                danger: strings[3],
                warning: strings[4],
                info: strings[5]
            }
        };
        return cachedStrings;
    });
};

const buildModal = (title, closeLabel, items) => {
    const bodyItems = items.map((item) => {
        const badgeClass = item.type === 'danger' ? 'danger' :
            (item.type === 'success' ? 'success' : (item.type === 'warning' ? 'warning' : 'info'));
        return `
            <div class="alert alert-${item.type} mb-2" role="alert">
                <span class="badge badge-${badgeClass} mr-2">${item.label}</span>
                <span>${item.html}</span>
            </div>
        `;
    }).join('');

    return `
        <div class="modal fade" id="${MODAL_ID}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">${title}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="${closeLabel}"></button>
                    </div>
                    <div class="modal-body">
                        ${bodyItems}
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">${closeLabel}</button>
                    </div>
                </div>
            </div>
        </div>
    `;
};

const collectAlerts = (root) => {
    const notices = root.find('.alert').filter(function() {
        const el = $(this);
        return el.text().trim().length > 0 && !el.attr('data-harpia-notification-handled');
    });
    return notices;
};

const showItemsInModal = (items) => {
    if (!items || !items.length) {
        return;
    }

    getStrings().then((strings) => {
        const normalized = items.map((item) => {
            const type = item.type || 'info';
            return {
                type: type,
                label: strings.labels[type] || strings.labels.info,
                html: item.html || ''
            };
        }).filter((item) => item.html.trim() !== '');

        if (!normalized.length) {
            return;
        }

        const existing = $(`#${MODAL_ID}`);
        if (existing.length) {
            existing.remove();
        }

        $('body').append(buildModal(strings.title, strings.closeLabel, normalized));
        const root = $('#user-notifications');
        if (root.length) {
            root.hide();
        }

        const modalElement = document.getElementById(MODAL_ID);
        if (!modalElement) {
            return;
        }
        const modal = new window.bootstrap.Modal(modalElement);
        modal.show();
    });
};

const showAlertsInModal = (root) => {
    const notices = collectAlerts(root);
    if (!notices.length) {
        return;
    }

    getStrings().then((strings) => {
        const items = [];
        notices.each(function() {
            const el = $(this);
            const type = getType(this);
            items.push({
                type: type,
                label: strings.labels[type] || strings.labels.info,
                html: el.html()
            });
            el.attr('data-harpia-notification-handled', '1');
            el.remove();
        });

        if (!items.length) {
            return;
        }

        showItemsInModal(items);
    });
};

const startObserver = (root) => {
    if (observerStarted) {
        return;
    }
    observerStarted = true;

    const observer = new MutationObserver(() => {
        showAlertsInModal(root);
    });

    if (root.length && root[0]) {
        observer.observe(root[0], {
            childList: true,
            subtree: true
        });
    }
};

const patchCoreNotifications = () => {
    if (notificationPatched || !Notification || typeof Notification.addNotification !== 'function') {
        return;
    }

    const originalAddNotification = Notification.addNotification.bind(Notification);
    Notification.addNotification = (data = {}) => {
        const message = data.message;
        const html = Array.isArray(message) ? message.join('<br>') : String(message || '');

        if (!html.trim()) {
            return originalAddNotification(data);
        }

        showItemsInModal([{
            type: data.type || 'info',
            html: html
        }]);

        // Prevent top-of-page duplicate notifications for Harpia pages.
        return Promise.resolve();
    };

    notificationPatched = true;
};

export const init = () => {
    if (!window.bootstrap || !window.bootstrap.Modal) {
        return;
    }
    const root = $('#user-notifications');
    if (!root.length) {
        return;
    }

    showAlertsInModal(root);
    startObserver(root);
    patchCoreNotifications();
};
