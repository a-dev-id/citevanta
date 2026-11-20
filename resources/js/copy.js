/**
 * Buttons with data-cv-copy="#target" copy the target's value (or text) to
 * the clipboard and say "Copied" for a moment.
 */
export function initCopy() {
    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-cv-copy]');
        const target = button && document.querySelector(button.dataset.cvCopy);

        if (!target) return;

        const text = 'value' in target ? target.value : target.textContent;
        const label = button.querySelector('span') ?? button;
        const original = label.textContent;

        try {
            await navigator.clipboard.writeText(text);
            label.textContent = 'Copied';
        } catch {
            target.select?.();
            label.textContent = 'Press Ctrl+C to copy';
        }

        setTimeout(() => { label.textContent = original; }, 2000);
    });
}
