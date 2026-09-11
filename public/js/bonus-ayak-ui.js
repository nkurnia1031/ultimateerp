(function (window, document) {
    'use strict';

    const numberFormatter = new Intl.NumberFormat('id-ID', {
        maximumFractionDigits: 0,
    });

    function pad(value) {
        return String(value).padStart(2, '0');
    }

    function parseIsoDate(value) {
        const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!match) return null;

        const year = Number(match[1]);
        const month = Number(match[2]);
        const day = Number(match[3]);
        const date = new Date(year, month - 1, day);

        if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) {
            return null;
        }

        return { year, month, day };
    }

    function parseDisplayDate(value) {
        const parts = String(value || '').trim().replace(/[.-]/g, '/').split('/');
        if (parts.length !== 3 || parts.some((part) => !/^\d+$/.test(part))) return null;

        const day = Number(parts[0]);
        const month = Number(parts[1]);
        const year = Number(parts[2]);
        const parsed = parseIsoDate(`${year}-${pad(month)}-${pad(day)}`);

        return parsed ? { ...parsed, iso: `${year}-${pad(month)}-${pad(day)}` } : null;
    }

    function displayDate(value) {
        const parsed = parseIsoDate(value);
        return parsed ? `${pad(parsed.day)}/${pad(parsed.month)}/${parsed.year}` : '';
    }

    function errorElement(input) {
        const id = `${input.id || 'bonus-date'}-error`;
        let element = document.getElementById(id);

        if (!element) {
            element = document.createElement('small');
            element.id = id;
            element.className = 'bonus-field-error';
            element.setAttribute('role', 'alert');
            element.textContent = 'Gunakan tanggal yang valid dengan format DD/MM/YYYY.';
            input.insertAdjacentElement('afterend', element);
        }

        return element;
    }

    function setDateError(input, invalid) {
        input.setAttribute('aria-invalid', invalid ? 'true' : 'false');
        errorElement(input).hidden = !invalid;
    }

    function initDates() {
        document.querySelectorAll('[data-date-display]').forEach((input) => {
            const hidden = document.getElementById(input.dataset.dateValueId);
            if (!hidden) return;

            const form = input.closest('form');
            const sync = (showError) => {
                const parsed = parseDisplayDate(input.value);

                if (!input.value.trim()) {
                    hidden.value = '';
                    setDateError(input, Boolean(input.required && showError));
                    return !input.required;
                }

                if (!parsed) {
                    hidden.value = '';
                    setDateError(input, showError);
                    return false;
                }

                hidden.value = parsed.iso;
                input.value = `${pad(parsed.day)}/${pad(parsed.month)}/${parsed.year}`;
                setDateError(input, false);
                return true;
            };

            const initial = parseIsoDate(hidden.value);
            if (initial) input.value = displayDate(hidden.value);

            input.addEventListener('input', () => {
                hidden.value = '';
                setDateError(input, false);
            });
            input.addEventListener('blur', () => sync(true));
            form?.addEventListener('submit', (event) => {
                if (!sync(true)) event.preventDefault();
            });
        });
    }

    function parseMoney(value) {
        const digits = String(value || '').replace(/\D/g, '');
        return digits ? String(Number(digits)) : '0';
    }

    function initCurrencyFields() {
        document.querySelectorAll('[data-currency-display]').forEach((input) => {
            const hidden = document.getElementById(input.dataset.currencyValueId);
            if (!hidden) return;

            const format = () => {
                hidden.value = parseMoney(input.value);
                input.value = `Rp ${numberFormatter.format(Number(hidden.value || 0))}`;
            };

            input.value = `Rp ${numberFormatter.format(Number(hidden.value || 0))}`;
            input.addEventListener('focus', () => {
                input.value = hidden.value || '0';
                input.select();
            });
            input.addEventListener('input', () => {
                hidden.value = parseMoney(input.value);
            });
            input.addEventListener('blur', format);
            input.closest('form')?.addEventListener('submit', format);
        });
    }

    function initFilePickers() {
        document.querySelectorAll('[data-file-picker]').forEach((input) => {
            const name = document.querySelector(`[data-file-name-for="${input.id}"]`);
            if (!name) return;

            input.addEventListener('change', () => {
                name.textContent = input.files?.[0]?.name || 'Belum ada file dipilih';
            });
        });
    }

    function init() {
        initDates();
        initCurrencyFields();
        initFilePickers();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
