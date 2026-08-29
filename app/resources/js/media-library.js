document.querySelectorAll('[data-media-library]').forEach((library) => {
    const selectAll = library.querySelector('[data-media-select-all]');
    const selectionCount = library.querySelector('[data-media-selection-count]');
    const action = library.querySelector('[data-media-bulk-action]');
    const form = library.querySelector('[data-media-bulk-form]');
    const asyncTrash = library.hasAttribute('data-media-async-trash');
    const items = () => [...library.querySelectorAll('[data-media-item]')];

    const refreshSelection = () => {
        const currentItems = items();
        const count = currentItems.filter((item) => item.checked).length;
        if (selectAll) {
            selectAll.checked = count > 0 && count === currentItems.length;
            selectAll.indeterminate = count > 0 && count < currentItems.length;
            selectAll.disabled = currentItems.length === 0;
        }
        if (selectionCount) {
            selectionCount.textContent = selectionCount.dataset.template.replace(':count', String(count));
        }
        if (action) {
            action.disabled = count === 0;
        }
    };

    selectAll?.addEventListener('change', () => {
        items().forEach((item) => {
            item.checked = selectAll.checked;
        });
        refreshSelection();
    });
    items().forEach((item) => item.addEventListener('change', refreshSelection));

    const refreshSectionCount = (section) => {
        const count = section.querySelectorAll('[data-media-card]').length;
        const badge = section.querySelector('[data-media-section-count]');
        if (badge) {
            badge.textContent = badge.dataset.template.replace(':count', String(count));
        }
    };

    const removeCards = (cards) => {
        const sections = new Set(cards.map((card) => card.closest('[data-media-section]')).filter(Boolean));
        cards.forEach((card) => card.remove());
        sections.forEach(refreshSectionCount);
        refreshSelection();
    };

    const errorMessage = async (response) => {
        try {
            const payload = await response.json();
            return Object.values(payload.errors || {}).flat()[0] || payload.message;
        } catch {
            return '';
        }
    };

    const submitAsync = async (targetForm) => {
        const response = await fetch(targetForm.action, {
            method: 'POST',
            body: new FormData(targetForm),
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        if (!response.ok) {
            throw new Error((await errorMessage(response)) || library.dataset.requestError);
        }
    };

    library.querySelectorAll('[data-media-delete-form]').forEach((deleteForm) => {
        deleteForm.addEventListener('submit', async (event) => {
            if (!asyncTrash) {
                return;
            }
            event.preventDefault();
            const card = deleteForm.closest('[data-media-card]');
            const button = deleteForm.querySelector('button[type="submit"]');
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            try {
                await submitAsync(deleteForm);
                removeCards([card]);
            } catch (error) {
                window.alert(error.message || library.dataset.requestError);
                button.disabled = false;
                button.removeAttribute('aria-busy');
            }
        });
    });

    form?.addEventListener('submit', (event) => {
        const selectedItems = items().filter((item) => item.checked);
        if (selectedItems.length === 0 || !window.confirm(form.dataset.confirm)) {
            event.preventDefault();
            return;
        }
        if (!asyncTrash) {
            return;
        }
        event.preventDefault();
        action.disabled = true;
        action.setAttribute('aria-busy', 'true');
        submitAsync(form)
            .then(() => removeCards(selectedItems.map((item) => item.closest('[data-media-card]'))))
            .catch((error) => window.alert(error.message || library.dataset.requestError))
            .finally(() => {
                action.removeAttribute('aria-busy');
                refreshSelection();
            });
    });
    refreshSelection();
});
