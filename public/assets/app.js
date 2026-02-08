document.querySelectorAll('[data-tabs]').forEach((tabGroup) => {
    const buttons = tabGroup.querySelectorAll('.tab-button');
    const panels = tabGroup.querySelectorAll('.tab-panel');

    buttons.forEach((button) => {
        button.addEventListener('click', () => {
            const target = button.getAttribute('data-tab');
            buttons.forEach((btn) => btn.classList.toggle('active', btn === button));
            panels.forEach((panel) => {
                panel.classList.toggle('active', panel.getAttribute('data-tab-panel') === target);
            });
        });
    });
});
