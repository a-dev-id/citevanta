/**
 * Bootstrap tooltips for [data-bs-toggle="tooltip"], created on first hover or
 * focus. Delegated from document, so elements added later by Livewire work too.
 */
export function initTooltips(bootstrap) {
    const show = (event) => {
        const el = event.target.closest?.('[data-bs-toggle="tooltip"]');

        if (el && !bootstrap.Tooltip.getInstance(el)) {
            bootstrap.Tooltip.getOrCreateInstance(el).show();
        }
    };

    document.addEventListener('mouseover', show);
    document.addEventListener('focusin', show);
}
