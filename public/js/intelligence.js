document.querySelectorAll('form[data-run-test]').forEach(form=>{form.addEventListener('submit',()=>{const button=form.querySelector('button[type="submit"]');button.disabled=true;button.setAttribute('aria-busy','true');button.innerHTML='<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Running test…';form.querySelector('[data-progress]').hidden=false;});});


document.querySelectorAll('form[data-batch-preview]').forEach(form => {
    const updateCount = () => {
        const prompts = form.querySelectorAll('input[name="prompt_ids[]"]:checked').length;
        const markets = form.querySelectorAll('input[name="market_ids[]"]:checked').length;
        const repetitions = Number(form.querySelector('[name="repetitions"]').value) || 0;
        const output = form.querySelector('[data-request-count]');
        const count = String(prompts * markets * repetitions);
        const credits = form.querySelector('[data-credit-count]');
        if (credits) credits.textContent = String(Number(count) * Number(form.dataset.creditWeight || 1));
        if (output.textContent !== count) {
            output.textContent = count;
        }
    };
    form.addEventListener('change', updateCount);
    form.addEventListener('input', updateCount);
    updateCount();
});


document.querySelectorAll('form[data-campaign-confirmations]').forEach(form => {
    const button = form.querySelector('button[type="submit"]');
    const update = () => {
        button.disabled = form.dataset.canStart !== '1' || [...form.querySelectorAll('input[type="checkbox"][required]')].some(input => !input.checked);
    };
    form.addEventListener('change', update);
    update();
});
