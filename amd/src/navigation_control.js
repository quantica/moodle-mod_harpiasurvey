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
 * Navigation control module for harpiasurvey.
 *
 * @module     mod_harpiasurvey/navigation_control
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {get_string as getString} from 'core/str';
import $ from 'jquery';

let initialized = false;

/**
 * Check if all questions on the current page are answered.
 *
 * @return {boolean} True if all questions are answered
 */
const areAllQuestionsAnswered = () => {
    let allAnswered = true;
    const unansweredQuestions = [];

    // Check all question inputs on the page.
    $('.question-item').each(function() {
        const questionItem = $(this);
        const questionType = questionItem.data('questiontype');

        // Skip AI conversation questions as they don't need to be answered before navigation.
        if (questionType === 'aiconversation') {
            return;
        }

        let hasAnswer = false;

        // Check based on question type.
        if (questionType === 'singlechoice' || questionType === 'likert') {
            // Radio buttons - check if any is checked.
            const checked = questionItem.find('input[type="radio"]:checked');
            hasAnswer = checked.length > 0;
        } else if (questionType === 'multiplechoice') {
            // Checkboxes - check if any is checked.
            const checked = questionItem.find('input[type="checkbox"]:checked');
            hasAnswer = checked.length > 0;
        } else if (questionType === 'select') {
            // Select dropdown - check if value is selected.
            const select = questionItem.find('select');
            hasAnswer = select.val() && select.val() !== '';
        } else if (questionType === 'number') {
            // Number input - check if value is not empty.
            const input = questionItem.find('input[type="number"]');
            const value = input.val();
            hasAnswer = value !== '' && value !== null && value !== undefined;
        } else if (questionType === 'shorttext') {
            // Text input - check if value is not empty.
            const input = questionItem.find('input[type="text"]');
            hasAnswer = input.val() && input.val().trim() !== '';
        } else if (questionType === 'longtext') {
            // Textarea - check if value is not empty.
            const textarea = questionItem.find('textarea');
            hasAnswer = textarea.val() && textarea.val().trim() !== '';
        }

        if (!hasAnswer) {
            allAnswered = false;
            const questionName = questionItem.find('h5').text() || 'Question';
            unansweredQuestions.push(questionName);
        }
    });

    return {
        allAnswered: allAnswered,
        unansweredQuestions: unansweredQuestions
    };
};

/**
 * Initialize navigation control.
 *
 * @param {boolean} canmanage Whether user can manage (admin) - if true, no restrictions apply
 */
export const init = (canmanage = false) => {
    if (initialized) {
        return;
    }

    // If user can manage (admin), don't apply any restrictions.
    if (canmanage) {
        initialized = true;
        return;
    }

    // Check if we're in only_forward mode.
    const navElement = $('nav[data-navigation-mode]');
    const navigationMode = navElement.data('navigation-mode');
    const canManageNav = navElement.data('canmanage') === true || navElement.data('canmanage') === 1;

    // If admin, no restrictions.
    if (canManageNav) {
        initialized = true;
        return;
    }

    if (navigationMode !== 'only_forward') {
        // Free navigation mode - no restrictions.
        initialized = true;
        return;
    }

    // Only forward mode - add validation.
    $(document).on('click', '.navigation-next-link', function(e) {
        e.preventDefault();
        const link = $(this);
        const pageid = link.data('pageid');

        // Check if all questions are answered.
        const result = areAllQuestionsAnswered(pageid);

        if (!result.allAnswered) {
            // Show alert.
            getString('questionsmustbeanswered', 'mod_harpiasurvey').then((message) => {
                alert(message);
            });
            return false;
        }

        // All questions answered - proceed to next page.
        window.location.href = link.attr('href');
    });

    // Prevent clicking on page tabs for previous pages in only_forward mode.
    $(document).on('click', '.pages-tabs .page-tab-link', function(e) {
        // Check if user can manage (admin) - if so, allow all navigation.
        const navBar = $('.pages-navigation-bar[data-navigation-mode]');
        const canManage = navBar.data('canmanage') === true || navBar.data('canmanage') === 1;

        if (canManage) {
            // Admin can navigate freely.
            return;
        }

        // Only apply restrictions in only_forward mode.
        const navMode = navBar.data('navigation-mode');

        if (navMode !== 'only_forward') {
            // Free navigation - allow all tab clicks.
            return;
        }

        const link = $(this);
        const pageid = link.data('pageid');
        const currentPageId = $('.pages-tabs .page-tab-link.active').data('pageid');

        // Find the index of the clicked page and current page.
        let clickedIndex = -1;
        let currentIndex = -1;
        $('.pages-tabs .page-tab-link').each(function(index) {
            const tabPageId = $(this).data('pageid');
            if (tabPageId == pageid) {
                clickedIndex = index;
            }
            if (tabPageId == currentPageId) {
                currentIndex = index;
            }
        });

        // If clicking on a previous page, prevent navigation.
        if (clickedIndex >= 0 && currentIndex >= 0 && clickedIndex < currentIndex) {
            e.preventDefault();
            return false;
        }

        // If clicking on a future page, check if all questions are answered on current page.
        if (clickedIndex >= 0 && currentIndex >= 0 && clickedIndex > currentIndex) {
            // Check if all questions are answered on current page.
            const result = areAllQuestionsAnswered(currentPageId);

            if (!result.allAnswered) {
                e.preventDefault();
                getString('questionsmustbeanswered', 'mod_harpiasurvey').then((message) => {
                    alert(message);
                });
                return false;
            }
        }
    });

    // Also prevent clicking on page numbers for previous pages.
    $(document).on('click', '.pagination .page-item.disabled .page-link', function(e) {
        e.preventDefault();
        return false;
    });

    initialized = true;
};
