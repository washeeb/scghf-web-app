/**
 * The footer's currency picker: submit on change, so the "Apply" button is
 * only for the visitor without script (it is hidden here). No inline
 * handler — the public site's CSP has no 'unsafe-inline'.
 */
export function initCurrencyPicker() {
    const form = document.querySelector('[data-currency-picker]');

    if (!form) return;

    form.querySelector('[data-currency-apply]')?.setAttribute('hidden', '');
    form.querySelector('select')?.addEventListener('change', () => form.requestSubmit());
}
