const recoveryManager = document.querySelector('[data-recovery-manager]');

if (recoveryManager) {
    const selectAll = recoveryManager.querySelector('[data-recovery-select-all]');
    const items = [...recoveryManager.querySelectorAll('[data-recovery-item]')];
    const actionButtons = [...recoveryManager.querySelectorAll('[data-recovery-action]')];
    const purgeButton = recoveryManager.querySelector('[data-recovery-purge-action]');
    const purgeConfirmation = recoveryManager.querySelector('[data-recovery-purge-confirmation]');
    const selectedCount = recoveryManager.querySelector('[data-recovery-selection-count]');

    const refreshSelection = () => {
        const count = items.filter((item) => item.checked).length;
        if (selectAll) {
            selectAll.checked = count > 0 && count === items.length;
            selectAll.indeterminate = count > 0 && count < items.length;
        }
        actionButtons.forEach((button) => {
            button.disabled = count === 0;
        });
        if (purgeButton) {
            purgeButton.disabled = count === 0 || purgeConfirmation?.value !== 'delete';
        }
        if (selectedCount) {
            selectedCount.textContent = selectedCount.dataset.template.replace(':count', String(count));
        }
    };

    selectAll?.addEventListener('change', () => {
        items.forEach((item) => {
            item.checked = selectAll.checked;
        });
        refreshSelection();
    });
    items.forEach((item) => item.addEventListener('change', refreshSelection));
    purgeConfirmation?.addEventListener('input', refreshSelection);
    refreshSelection();
}
