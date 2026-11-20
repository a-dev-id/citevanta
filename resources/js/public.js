// Public website: only the Bootstrap collapse plugin (mobile menu, FAQ) and
// the pricing toggles. No icon font, no other plugins.
import 'bootstrap/js/dist/collapse';

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-pricing-set]');

    if (!button) return;

    const pricing = button.closest('[data-pricing]');
    const [key, value] = button.dataset.pricingSet.split(':');

    pricing.dataset[key] = value;
    button.parentElement.querySelectorAll('[data-pricing-set]').forEach((b) => {
        b.setAttribute('aria-pressed', b === button ? 'true' : 'false');
    });
});
