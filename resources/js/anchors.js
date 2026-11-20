/**
 * Links like /hotels/1/scans/5#answer-42 point into a collapsed accordion:
 * open the panel that holds the target, then scroll to it and highlight it.
 */
export function initAnchors(bootstrap) {
    const reveal = () => {
        const id = window.location.hash.slice(1);
        const target = id && document.getElementById(id);

        if (!target) return;

        const panel = target.closest('.collapse');

        const focus = () => {
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            target.classList.add('is-targeted');
        };

        if (panel && !panel.classList.contains('show')) {
            panel.addEventListener('shown.bs.collapse', focus, { once: true });
            bootstrap.Collapse.getOrCreateInstance(panel).show();
        } else {
            focus();
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', reveal);
    } else {
        reveal();
    }
    window.addEventListener('hashchange', reveal);
}
