/**
 * Sidebar behaviour:
 *  - desktop (>= 992px): toggle an icon-only rail, remembered in localStorage
 *  - mobile: off-canvas drawer with a backdrop, closed by Esc or backdrop click
 *  - nested sub-menus open/close with aria-expanded kept in sync
 */
const STORAGE_KEY = 'cv.sidebar.collapsed';
const DESKTOP = window.matchMedia('(min-width: 992px)');
const root = document.documentElement;

export function readCollapsed() {
    try {
        return window.localStorage.getItem(STORAGE_KEY) === '1';
    } catch {
        return false;
    }
}

function writeCollapsed(collapsed) {
    try {
        window.localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
    } catch {
        // Storage can be unavailable (private mode, blocked site data); the toggle still works.
    }
}

function setToggleState(expanded) {
    document.querySelectorAll('[data-cv-sidebar-toggle]').forEach((button) => {
        button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    });
}

function closeDrawer() {
    root.classList.remove('sidebar-open');
    setToggleState(false);
}

function toggleSidebar() {
    if (DESKTOP.matches) {
        const collapsed = !root.classList.contains('sidebar-collapsed');
        root.classList.toggle('sidebar-collapsed', collapsed);
        writeCollapsed(collapsed);
        setToggleState(!collapsed);

        return;
    }

    const open = !root.classList.contains('sidebar-open');
    root.classList.toggle('sidebar-open', open);
    setToggleState(open);
}

function toggleSubmenu(button) {
    const item = button.closest('.cv-nav__item');
    const open = !item.classList.contains('is-open');

    item.classList.toggle('is-open', open);
    button.setAttribute('aria-expanded', open ? 'true' : 'false');

    const submenu = item.querySelector(':scope > .cv-subnav');
    if (submenu) {
        submenu.hidden = !open;
    }
}

export function initSidebar() {
    setToggleState(DESKTOP.matches ? !root.classList.contains('sidebar-collapsed') : false);

    document.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-cv-sidebar-toggle]');
        if (toggle) {
            event.preventDefault();
            toggleSidebar();

            return;
        }

        const submenu = event.target.closest('[data-cv-submenu-toggle]');
        if (submenu) {
            event.preventDefault();
            toggleSubmenu(submenu);

            return;
        }

        if (event.target.closest('[data-cv-backdrop]')) {
            closeDrawer();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && root.classList.contains('sidebar-open')) {
            closeDrawer();
        }
    });

    DESKTOP.addEventListener('change', () => {
        closeDrawer();
        setToggleState(DESKTOP.matches ? !root.classList.contains('sidebar-collapsed') : false);
    });
}
