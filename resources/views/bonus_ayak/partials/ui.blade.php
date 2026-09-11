<style>
    .bonus-ui {
        --bonus-ink: #253b49;
        --bonus-muted: #60717b;
        --bonus-border: #b8cbd0;
        --bonus-teal: #0f8b8d;
        --bonus-focus: #f0b85b;
    }

    .bonus-ui .btn {
        min-height: 40px;
    }

    .bonus-ui .bonus-btn-secondary {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        min-height: 40px;
        padding: 8px 14px;
        color: #334e5b !important;
        background: #f7fafb !important;
        border: 1px solid var(--bonus-border) !important;
        border-radius: 6px;
        box-shadow: 0 1px 1px rgba(22, 43, 58, .04);
        text-decoration: none !important;
    }

    .bonus-ui .bonus-btn-secondary:hover,
    .bonus-ui .bonus-btn-secondary:focus {
        color: #1d3b48 !important;
        background: #e8f0f1 !important;
        border-color: #8faeb5 !important;
    }

    .bonus-ui .bonus-btn-secondary:focus-visible,
    .bonus-ui .bonus-action-group .btn:focus-visible,
    .bonus-ui .bonus-row-actions .btn:focus-visible,
    .bonus-ui .bonus-file-picker__label:focus-visible {
        outline: 3px solid var(--bonus-focus) !important;
        outline-offset: 3px;
    }

    .bonus-ui .bonus-action-group {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 9px;
        width: 100%;
        margin-top: 18px;
        padding-top: 16px;
        border-top: 1px solid #e6edef;
    }

    .bonus-ui .bonus-form-grid > .bonus-action-group {
        grid-column: 1 / -1;
    }

    .bonus-ui .bonus-action-group .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        min-height: 40px;
        height: 40px;
        }

    .bonus-ui .btn .fa {
        font-size: 1em !important;
        line-height: 1 !important;
    }

    .bonus-ui .bonus-row-actions {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        flex-wrap: wrap;
        min-width: 150px;
    }

    .bonus-ui .bonus-row-actions .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        min-width: 68px;
        min-height: 40px;
        white-space: nowrap;
    }

    .bonus-ui .bonus-row-actions form {
        display: inline-flex;
        margin: 0;
    }

    .bonus-ui .bonus-locked-state {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        min-height: 32px;
        padding: 5px 9px;
        color: #53616a;
        background: #eef1f2;
        border: 1px solid #d5dde0;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
        white-space: nowrap;
    }

    .bonus-ui .bonus-field-error {
        display: block;
        margin-top: 6px;
        color: #b42318;
        font-size: 12px;
        line-height: 1.4;
    }

    .bonus-ui .bonus-date-display,
    .bonus-ui .bonus-currency-display {
        min-height: 40px;
    }

    .bonus-ui .bonus-file-picker {
        display: flex;
        align-items: center;
        gap: 10px;
        min-width: 0;
    }

    .bonus-ui .bonus-file-picker__input {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip: rect(0 0 0 0);
        clip-path: inset(50%);
        white-space: nowrap;
    }

    .bonus-ui .bonus-file-picker__label {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        min-height: 40px;
        padding: 8px 13px;
        color: #334e5b;
        background: #f7fafb;
        border: 1px solid var(--bonus-border);
        border-radius: 6px;
        cursor: pointer;
        white-space: nowrap;
    }

    .bonus-ui .bonus-file-picker__name {
        min-width: 0;
        overflow: hidden;
        color: var(--bonus-muted);
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .bonus-ui .bonus-mobile-list {
        display: none;
    }

    .bonus-ui .bonus-mobile-card {
        padding: 15px;
        border: 1px solid #dce6e8;
        border-radius: 10px;
        background: #fff;
    }

    .bonus-ui .bonus-mobile-card + .bonus-mobile-card {
        margin-top: 10px;
    }

    .bonus-ui .bonus-mobile-card__grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .bonus-ui .bonus-mobile-card__label {
        display: block;
        margin-bottom: 3px;
        color: #73858d;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
    }

    .bonus-ui .bonus-mobile-card__value {
        color: var(--bonus-ink);
        line-height: 1.45;
    }

    .bonus-ui .bonus-mobile-card__actions {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: 14px;
        padding-top: 12px;
        border-top: 1px solid #e6edef;
    }

    .bonus-ui .bonus-mobile-card__actions .btn {
        flex: 1;
        min-height: 40px;
    }

    @media (max-width: 640px) {
        .bonus-ui .bonus-desktop-table {
            display: none;
        }

        .bonus-ui .bonus-mobile-list {
            display: block;
        }

        .bonus-ui .bonus-action-group {
            flex-direction: column;
            align-items: stretch;
        }

        .bonus-ui .bonus-action-group .btn,
        .bonus-ui .bonus-file-picker__label {
            width: 100%;
        }

        .bonus-ui .bonus-file-picker {
            display: block;
        }

        .bonus-ui .bonus-file-picker__name {
            display: block;
            margin-top: 8px;
        }

        .bonus-ui .bonus-mobile-card__grid {
            grid-template-columns: 1fr;
            gap: 10px;
        }

        .bonus-ui .bonus-mobile-card__actions {
            flex-direction: column;
            align-items: stretch;
        }
    }
</style>
