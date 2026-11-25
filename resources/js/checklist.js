/**
 * The setup checklist (a Livewire component, see app/Livewire/SetupChecklist.php) ticks its items from real data.
 * This file does two small things:
 *  - after every Livewire action on the page it asks the checklist to recompute (a moment later, once);
 *  - the "Setup checklist" entry in the help menu brings a hidden checklist back.
 */
export function initChecklist() {
    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-cv-checklist-restore]')) {
            event.preventDefault();

            try {
                window.Livewire.dispatch('setup-checklist-restore');
            } catch {
                // Livewire is not ready: nothing to bring back.
            }
        }
    });

    document.addEventListener('livewire:init', () => {
        let timer = null;

        try {
            window.Livewire.hook('commit', ({ component, succeed }) => {
                succeed(() => {
                    if (component.name === 'setup-checklist') {
                        return;
                    }

                    window.clearTimeout(timer);
                    timer = window.setTimeout(() => window.Livewire.dispatch('setup-checklist-refresh'), 600);
                });
            });
        } catch {
            // An older Livewire without this hook: the items still update on every page load.
        }
    });
}
