/**
 * JS for handling AJAX-based form step loading.
 *
 * @module local_taskflow/dashboard
 * @copyright 2025
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// import Ajax from 'core/ajax';
import * as notification from 'core/notification';
import Ajax from 'core/ajax';
import Templates from 'core/templates';
import DynamicForm from 'core_form/dynamicform';

const SELECTORS = {
    DASHBOARDWRAPPER: '[data-region="dashboard-wrapper"]',
    USERSELECTORFORM: '[data-region="tf-selectuserformcontainer"]',
    LISTBOX: '.form-autocomplete-selection',
    CLOSEBTN: '.btn-close-tab',
};

var container;
var uniqueid;
var dynamicForm;

/**
 *
 * @param {*} id
 */
export const init = (id) => {
    // eslint-disable-next-line no-console
    console.log('Initializing dashboard with uniqueid:', uniqueid);
    const body = document.body;
    uniqueid = id;
    container = document.querySelector(SELECTORS.DASHBOARDWRAPPER + '[data-uniqueid="' + uniqueid + '"]');
    attachCloseListenerOnce();
    if (!body.classList.contains('dashboard-init')) {
        loadDashboard(uniqueid)
        .then((status) => {
            if (status === 'redirected') {
                return;
            }
            initUserSelectorForm();
            return;
        })
        .catch((err) => {
            // eslint-disable-next-line no-console
            console.error('Dashboard failed:', err);
        });
    }
    body.classList.add('dashboard-init');
};

/**
 * Init the user selector form.
 *
 */
function initUserSelectorForm() {

    const element = document.querySelector(SELECTORS.USERSELECTORFORM);
    if (!element || element.dataset.init) {
        // eslint-disable-next-line no-console
        console.warn('No user selector form found for uniqueid:', uniqueid);
        return;
    }
    // Initialize the form.
    dynamicForm = new DynamicForm(
        element,
        'local_taskflow\\form\\dynamic_select_users'
    );

    dynamicForm.load()
        // Wait until the autocomplete list-box exists.
        .then(() => waitForElement(container, SELECTORS.LISTBOX))
        .then(listBox => {
            reactToChange(listBox);
            const observer = new MutationObserver(() =>
                reactToChange(listBox, {dynamicForm, uniqueid})
            );
            observer.observe(listBox, {childList: true, subtree: true});

            dynamicForm.addEventListener(dynamicForm.events.FORM_SUBMITTED, (e) => {
                // Prevent default form submission.
                e.preventDefault();
                e.stopPropagation();
                loadDashboard(uniqueid)
                .then((status) => {
                    if (status === 'redirected') {
                        return;
                    }
                    initUserSelectorForm();
                    return;
                })
                .catch((err) => {
                    // eslint-disable-next-line no-console
                    console.error('Dashboard failed:', err);
                });
             });
        })
        .catch(notification.exception);

    element.dataset.init = '1';

}

/**
 * Bind exactly once to the <ul class="nav nav-tabs"> inside the wrapper.
 * Everything else happens only if the click was on .btn-close-tab.
 */
function attachCloseListenerOnce() {
    const wrapper = document.querySelector(
        `${SELECTORS.DASHBOARDWRAPPER}[data-uniqueid="${uniqueid}"]`
    );
    const navTabs = wrapper?.querySelector('.nav.nav-tabs');
    if (!navTabs || navTabs.dataset.closeInit) {
        return;
    }

    navTabs.addEventListener('click', (e) => {
        const btn = e.target.closest('.btn-close-tab');
        if (!btn) {
            return;
        }

        e.preventDefault();
        e.stopPropagation();

        const userid = Number(btn.dataset.userid);
        if (!userid) {
            return;
        }

        // 1. Purge cache via AJAX.
        Ajax.call([{
            methodname: 'local_taskflow_clear_dashboard_cache',
            args: {userid},
        }])[0]
        .done(() => {
            // 2. Remove tab + pane.
            const tab = document.querySelector(btn.dataset.tab);
            const pane = document.querySelector(btn.dataset.pane);
            if (pane) {
                pane.remove();
            }
            if (tab) {
                const li = tab.parentElement;
                const wasActive = tab.classList.contains('active');
                li.remove();

                if (wasActive) {
                    const first = wrapper.querySelector('.nav-link');
                    if (first) {
                        first.click();
                    }
                }
            }
        })
        .fail(notification.exception);
    });

    navTabs.dataset.closeInit = '1';
}

/**
 * Wait for an element to be added to the DOM.
 * @param {*} root
 * @param {*} selector
 * @param {*} timeout
 * @returns
 */
function waitForElement(root, selector, timeout = 1000) {
    return new Promise((resolve, reject) => {
        const el = root.querySelector(selector);
        if (el) {
            return resolve(el);
        }

        const obs = new MutationObserver(() => {
            const candidate = root.querySelector(selector);
            if (candidate) {
                obs.disconnect();
                resolve(candidate);
            }
        });

        obs.observe(root, {childList: true, subtree: true});

        // Safety-net timeout
        setTimeout(() => {
            obs.disconnect();
            reject(new Error(`Element ${selector} not found within ${timeout} ms`));
        }, timeout);
    });
}

/**
 * Check if a selection exists in the listbox.
 * @returns {boolean}
 */
function selectionExists() {
    const selBox = container.querySelector('.form-autocomplete-selection');
    // A real selection produces a <span … class="badge …"> inside the listbox
    return !!selBox && selBox.querySelector('.badge');
}

/**
 * React to changes in the listbox selection.
 * @param {*} listBox
 */
function reactToChange(listBox) {
    if (selectionExists(listBox)) {
        if (dynamicForm) {
            dynamicForm.submitFormAjax()
                .catch(notification.exception);
        }
    }
}


/**
 * Load a step of the form via AJAX.
 *
 * @param {mixed} uniqueid
 *
 * @return void *
 */
function loadDashboard(uniqueid) {
  const multiformcontainer = document.querySelector(
    SELECTORS.DASHBOARDWRAPPER + '[data-uniqueid="' + uniqueid + '"]'
  );
  if (!multiformcontainer) {
    return Promise.resolve(false);
  }

  return new Promise((resolve, reject) => {
    Ajax.call([
      {
        methodname: 'local_taskflow_load_dashboard',
        args: {},
        done: (response) => {
            if (response.returnurl?.length) {
                window.location.href = response.returnurl;
                resolve('redirected');
                return;
            }

            Templates.renderForPromise(
            response.template,
            JSON.parse(response.data)
            )
            .then(({ html, js }) => {
                html += response.js;
                Templates.replaceNode(
                    `${SELECTORS.DASHBOARDWRAPPER}[data-uniqueid="${uniqueid}"]`,
                    html,
                    js
              );
                document.body.classList.add('dashboard-init');
                resolve(true);
                return true;
            })
            .catch((err) => {
                // eslint-disable-next-line no-console
                console.error(err);
                reject(err);
            });
        },

        fail: (xhrError) => {
          notification.exception(xhrError);
          reject(xhrError);
        },
      },
    ]);
  });
}
