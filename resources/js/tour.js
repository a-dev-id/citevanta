/**
 * Guided tours (onboarding) for the customer app. No library: one step highlights one element (a dimmed page
 * with a clear outline around it) and a popover next to it has the title, the text, a step counter and the
 * buttons Previous, Next (Done on the last step), Skip and the checkbox "Don't show this again".
 *
 * The server decides which tour a page has and writes its text in the user's language (see
 * App\Services\Tours\TourService); it puts the tour in <script type="application/json" id="cv-tour-data"> and
 * remembers what the user did. This file only shows the tour and reports Done / Skip. Nothing else is sent:
 * no clicks, no timing.
 *
 *  - Steps whose element is not on the page, or is hidden, are skipped (also when a Livewire re-render removes it).
 *  - A step in the sidebar opens the drawer on a narrow screen and closes it again afterwards.
 *  - Auto-start never happens on top of a modal or a form with unsaved input.
 *  - Keyboard: Esc closes (like Skip), the left and right arrows move, Tab stays inside the popover.
 *  - prefers-reduced-motion is respected (no animation, no smooth scrolling).
 */
const NARROW = window.matchMedia('(max-width: 991.98px)');
const MOTION = window.matchMedia('(prefers-reduced-motion: reduce)');
const root = document.documentElement;
const GAP = 12;
const PAD = 6;

let active = null;

function readData() {
    const node = document.getElementById('cv-tour-data');

    if (!node) {
        return null;
    }

    try {
        return JSON.parse(node.textContent);
    } catch {
        return null;
    }
}

function post(url, body) {
    try {
        const token = document.querySelector('meta[name="csrf-token"]')?.content || '';

        return fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body || {}),
            credentials: 'same-origin',
            keepalive: true,
        }).catch(() => {});
    } catch {
        return Promise.resolve();
    }
}

function query(selector) {
    try {
        return document.querySelector(selector);
    } catch {
        return null; // a selector this browser does not understand: the step is skipped
    }
}

function isVisible(el) {
    if (!el || !el.isConnected) {
        return false;
    }

    const rect = el.getBoundingClientRect();
    const style = getComputedStyle(el);

    return (rect.width > 0 || rect.height > 0) && style.visibility !== 'hidden' && style.display !== 'none';
}

/** Whether a step can be shown now. A sidebar step on a narrow screen counts when it exists: the drawer is opened for it. */
function available(step) {
    const el = query(step.target);

    if (!el) {
        return false;
    }

    if (step.sidebar && NARROW.matches) {
        return !el.closest('[hidden]');
    }

    return isVisible(el);
}

function make(tag, className, attributes = {}) {
    const el = document.createElement(tag);

    if (className) {
        el.className = className;
    }

    Object.entries(attributes).forEach(([name, value]) => el.setAttribute(name, value));

    return el;
}

/** A modal, drawer or dialog is open on the page. */
function overlayOpen() {
    return Array.from(document.querySelectorAll('.modal.show, .offcanvas.show, dialog[open], [role="dialog"][aria-modal="true"]'))
        .some((el) => !el.closest('.cv-tour') && isVisible(el));
}

function fieldChanged(el) {
    if (el.disabled || ['hidden', 'submit', 'button', 'reset', 'image', 'file'].includes(el.type)) {
        return false;
    }

    if (el.type === 'checkbox' || el.type === 'radio') {
        return el.checked !== el.defaultChecked;
    }

    if (el.tagName === 'SELECT') {
        return Array.from(el.options).some((option) => option.selected !== option.defaultSelected);
    }

    return el.value !== el.defaultValue;
}

/** The user is typing, or a form has input that is not saved yet. */
function unsavedInput() {
    const focused = document.activeElement;

    if (focused && focused !== document.body && /^(INPUT|TEXTAREA)$/.test(focused.tagName) && !['checkbox', 'radio', 'button', 'submit'].includes(focused.type)) {
        return true;
    }

    return Array.from(document.forms).some((form) => Array.from(form.elements).some(fieldChanged));
}

function toast(text) {
    const node = make('div', 'cv-tour-toast', { role: 'status' });
    node.textContent = text;
    document.body.appendChild(node);
    window.setTimeout(() => node.remove(), 4000);
}

function start(data, force = false) {
    if (active || !data || !Array.isArray(data.steps)) {
        return false;
    }

    if (!force && (overlayOpen() || unsavedInput())) {
        return false;
    }

    const steps = data.steps.filter(available);

    if (steps.length === 0) {
        if (force) {
            toast(data.ui.nothing);
        }

        return false;
    }

    active = new Tour(data, steps);
    active.show(0, 1);

    return true;
}

class Tour {
    constructor(data, steps) {
        this.data = data;
        this.steps = steps;
        this.index = 0;
        this.openedDrawer = false;
        this.returnFocus = document.activeElement;
        this.frame = null;

        // The setup checklist hides while a tour runs (CSS) and comes back afterwards.
        root.classList.add('cv-tour-active');

        const ui = data.ui;

        this.layer = make('div', 'cv-tour');
        this.click = make('div', 'cv-tour__click', { 'aria-hidden': 'true' });
        this.spot = make('div', 'cv-tour__spot', { 'aria-hidden': 'true' });
        this.pop = make('div', 'cv-tour__pop', { role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'cv-tour-title', 'aria-describedby': 'cv-tour-text', tabindex: '-1' });

        const header = make('div', 'cv-tour__header');
        this.title = make('h2', 'cv-tour__title', { id: 'cv-tour-title' });
        this.closeButton = make('button', 'cv-tour__close', { type: 'button', 'aria-label': ui.close });
        this.closeButton.appendChild(make('i', 'fa-solid fa-xmark', { 'aria-hidden': 'true' }));
        header.append(this.title, this.closeButton);

        this.text = make('p', 'cv-tour__text', { id: 'cv-tour-text' });

        const remember = make('label', 'cv-tour__remember');
        this.dontShow = make('input', 'form-check-input', { type: 'checkbox' });
        remember.append(this.dontShow, document.createTextNode(' ' + ui.dont_show));

        const footer = make('div', 'cv-tour__footer');
        this.counter = make('span', 'cv-tour__counter', { 'aria-live': 'polite' });
        this.skipButton = make('button', 'btn btn-link btn-sm cv-tour__skip', { type: 'button' });
        this.skipButton.textContent = ui.skip;
        this.prevButton = make('button', 'btn btn-outline-secondary btn-sm', { type: 'button' });
        this.prevButton.textContent = ui.previous;
        this.nextButton = make('button', 'btn btn-primary btn-sm', { type: 'button' });
        const buttons = make('div', 'cv-tour__buttons');
        buttons.append(this.skipButton, this.prevButton, this.nextButton);
        footer.append(this.counter, buttons);

        this.pop.append(header, this.text, remember, footer);
        this.layer.append(this.click, this.spot, this.pop);
        document.body.appendChild(this.layer);

        this.closeButton.addEventListener('click', () => this.end('skip'));
        this.skipButton.addEventListener('click', () => this.end('skip'));
        this.prevButton.addEventListener('click', () => this.move(-1));
        this.nextButton.addEventListener('click', () => this.move(1));

        this.onKey = (event) => this.key(event);
        this.onLayout = () => this.schedule();
        document.addEventListener('keydown', this.onKey, true);
        window.addEventListener('resize', this.onLayout);
        window.addEventListener('scroll', this.onLayout, true);

        // Livewire re-renders and Alpine changes: keep the outline on the element, find it again if it was replaced.
        this.observer = new MutationObserver((records) => {
            if (records.some((record) => !this.layer.contains(record.target))) {
                this.schedule();
            }
        });
        this.observer.observe(document.body, { childList: true, subtree: true, attributes: true });
    }

    get step() {
        return this.steps[this.index];
    }

    /** Show step i. direction (1 or -1) says which way to look for the next available step if this one is gone. */
    show(i, direction) {
        let at = i;

        while (this.steps[at] && !available(this.steps[at])) {
            at += direction;
        }

        if (!this.steps[at]) {
            this.end('done');

            return;
        }

        this.index = at;
        const step = this.step;
        const total = this.steps.length;

        this.title.textContent = step.title;
        this.text.textContent = step.text;
        this.counter.textContent = this.data.ui.counter.replace(':current', String(at + 1)).replace(':total', String(total));
        this.prevButton.hidden = at === 0;
        this.nextButton.textContent = at === total - 1 ? this.data.ui.done : this.data.ui.next;
        this.pop.setAttribute('aria-label', this.data.ui.dialog + ': ' + this.counter.textContent);

        this.drawer(step.sidebar === true);
        const el = query(step.target);

        if (el) {
            el.scrollIntoView({ block: 'center', inline: 'nearest', behavior: MOTION.matches ? 'auto' : 'smooth' });
        }

        this.place();
        window.setTimeout(() => this.place(), MOTION.matches ? 0 : 380);
        this.nextButton.focus({ preventScroll: true });
    }

    move(direction) {
        if (direction > 0 && this.index === this.steps.length - 1) {
            this.end('done');

            return;
        }

        this.show(this.index + direction, direction);
    }

    /** The sidebar is a drawer on a narrow screen: open it for a sidebar step and close it again afterwards. */
    drawer(needed) {
        const toggle = document.querySelector('[data-cv-sidebar-toggle]');

        if (needed && NARROW.matches && !root.classList.contains('sidebar-open') && toggle) {
            toggle.click();
            this.openedDrawer = true;
        } else if (!needed && this.openedDrawer && root.classList.contains('sidebar-open') && toggle) {
            toggle.click();
            this.openedDrawer = false;
        }
    }

    schedule() {
        if (this.frame !== null) {
            return;
        }

        this.frame = window.requestAnimationFrame(() => {
            this.frame = null;
            this.place();
        });
    }

    /** Put the outline on the element and the popover next to it. */
    place() {
        const step = this.step;

        if (!step) {
            return;
        }

        if (!available(step)) {
            // The element went away (a re-render): carry on with the next step that is there.
            this.show(this.index + 1, 1);

            return;
        }

        const el = query(step.target);
        const rect = el.getBoundingClientRect();
        const vw = window.innerWidth;
        const vh = window.innerHeight;

        // A very tall element (the sidebar) is outlined only as far as it is visible.
        const top = Math.max(rect.top - PAD, 4);
        const left = Math.max(rect.left - PAD, 4);
        const bottom = Math.min(rect.bottom + PAD, vh - 4);
        const right = Math.min(rect.right + PAD, vw - 4);

        Object.assign(this.spot.style, { top: top + 'px', left: left + 'px', width: Math.max(right - left, 0) + 'px', height: Math.max(bottom - top, 0) + 'px' });

        const width = Math.min(340, vw - 2 * GAP);
        this.pop.style.width = width + 'px';
        const height = this.pop.offsetHeight;
        const centerX = Math.min(Math.max(left + (right - left) / 2 - width / 2, GAP), vw - width - GAP);
        let x = centerX;
        let y;

        if (bottom + GAP + height <= vh - GAP) {
            y = bottom + GAP;
        } else if (top - GAP - height >= GAP) {
            y = top - GAP - height;
        } else if (right + GAP + width <= vw - GAP) {
            x = right + GAP;
            y = Math.min(Math.max(top, GAP), vh - height - GAP);
        } else if (left - GAP - width >= GAP) {
            x = left - GAP - width;
            y = Math.min(Math.max(top, GAP), vh - height - GAP);
        } else {
            y = Math.max(vh - height - GAP, GAP);
        }

        Object.assign(this.pop.style, { left: x + 'px', top: Math.max(y, GAP) + 'px' });
    }

    key(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            this.end('skip');
        } else if (event.key === 'ArrowRight') {
            event.preventDefault();
            this.move(1);
        } else if (event.key === 'ArrowLeft') {
            event.preventDefault();
            this.move(-1);
        } else if (event.key === 'Tab') {
            const items = Array.from(this.pop.querySelectorAll('button, input, [href]')).filter((el) => !el.disabled && !el.hidden && el.offsetParent !== null);

            if (items.length === 0) {
                return;
            }

            const first = items[0];
            const last = items[items.length - 1];

            if (!this.pop.contains(document.activeElement)) {
                event.preventDefault();
                first.focus();
            } else if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    }

    /** outcome: "done" (the last step's Done button) or "skip" (Skip, the X, Esc). */
    end(outcome) {
        const dontShow = this.dontShow.checked;

        document.removeEventListener('keydown', this.onKey, true);
        window.removeEventListener('resize', this.onLayout);
        window.removeEventListener('scroll', this.onLayout, true);
        this.observer.disconnect();

        if (this.frame !== null) {
            window.cancelAnimationFrame(this.frame);
        }

        this.drawer(false);
        this.layer.remove();
        root.classList.remove('cv-tour-active');
        active = null;

        if (this.returnFocus && this.returnFocus.isConnected && typeof this.returnFocus.focus === 'function') {
            this.returnFocus.focus({ preventScroll: true });
        }

        if (outcome === 'done') {
            post(this.data.urls.finish, { version: this.data.version });
        } else {
            post(this.data.urls.skip, { version: this.data.version, dont_show: dontShow });
        }
    }
}

export function initTour() {
    const data = readData();
    const urls = document.getElementById('cv-tour-urls');

    // "Take the tour of this page" in the help menu.
    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-cv-tour-start]')) {
            event.preventDefault();
            start(data, true);
        }

        if (event.target.closest('[data-cv-tour-restart]')) {
            event.preventDefault();
            const done = urls ? post(urls.dataset.reset) : Promise.resolve();

            done.then(() => {
                if (urls?.dataset.restarted) {
                    toast(urls.dataset.restarted);
                }

                if (data) {
                    window.setTimeout(() => start(data, true), 600);
                }
            });
        }
    });

    window.cvTour = { start: () => start(data, true) };

    if (!data || !data.autostart) {
        return;
    }

    // The first visit: wait for the page (and Livewire) to settle, and never start on top of a modal or unsaved input.
    let tries = 0;
    const attempt = () => {
        if (active) {
            return;
        }

        if (!start(data, false) && tries++ < 3) {
            window.setTimeout(attempt, 1500);
        }
    };

    window.setTimeout(attempt, 900);
}
