const modeSelect = document.querySelector('select[name="mode"]');
const capitalFields = document.querySelectorAll('.capital-only');

const updateCapitalFields = () => {
    const isCapital = modeSelect && modeSelect.value === 'capital';
    capitalFields.forEach((field) => {
        field.style.display = isCapital ? '' : 'none';
    });
};

if (modeSelect) {
    modeSelect.addEventListener('change', updateCapitalFields);
    updateCapitalFields();
}

const rangeInput = document.querySelector('[data-range]');
const rangeValue = document.querySelector('[data-range-value]');
if (rangeInput && rangeValue) {
    const updateValue = () => {
        rangeValue.textContent = `${rangeInput.value}%`;
    };
    rangeInput.addEventListener('input', updateValue);
    updateValue();
}

const form = document.querySelector('[data-route-form]');
if (form) {
    form.addEventListener('submit', () => {
        const button = form.querySelector('[data-submit]');
        if (button) {
            button.disabled = true;
            button.classList.add('loading');
            const text = button.querySelector('.button-text');
            if (text) {
                text.textContent = 'Calculating…';
            }
        }
    });
}

const debounce = (fn, delay = 150) => {
    let timer;
    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), delay);
    };
};

const createAutocomplete = (input, hidden) => {
    if (!input) return;
    const wrapper = input.closest('label') || input.parentElement;
    if (!wrapper) return;

    wrapper.classList.add('autocomplete');
    const menu = document.createElement('div');
    menu.className = 'autocomplete-menu';
    menu.setAttribute('role', 'listbox');
    menu.hidden = true;
    wrapper.appendChild(menu);

    let activeIndex = -1;
    let items = [];
    let controller;

    const closeMenu = () => {
        menu.hidden = true;
        menu.innerHTML = '';
        activeIndex = -1;
        items = [];
        input.setAttribute('aria-expanded', 'false');
    };

    const openMenu = () => {
        if (items.length === 0) return;
        menu.hidden = false;
        input.setAttribute('aria-expanded', 'true');
    };

    const render = () => {
        menu.innerHTML = '';
        items.forEach((item, index) => {
            const option = document.createElement('button');
            option.type = 'button';
            option.className = 'autocomplete-item';
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', index === activeIndex ? 'true' : 'false');
            option.innerHTML = `<span>${item.name}</span><span class="meta">${item.sec_nav.toFixed(1)} · ${item.region ?? 'Unknown'}</span>`;
            option.addEventListener('mousedown', (event) => {
                event.preventDefault();
                selectItem(index);
            });
            menu.appendChild(option);
        });
        openMenu();
    };

    const selectItem = (index) => {
        const item = items[index];
        if (!item) return;
        input.value = item.name;
        if (hidden) {
            hidden.value = item.id;
        }
        closeMenu();
    };

    const fetchResults = debounce(async () => {
        const query = input.value.trim();
        if (hidden) {
            hidden.value = '';
        }
        if (query.length < 2) {
            closeMenu();
            return;
        }

        if (controller) {
            controller.abort();
        }
        controller = new AbortController();

        try {
            const response = await fetch(`/api/v1/systems?q=${encodeURIComponent(query)}&limit=10`, {
                signal: controller.signal,
            });
            if (!response.ok) {
                closeMenu();
                return;
            }
            const data = await response.json();
            items = Array.isArray(data) ? data : [];
            activeIndex = items.length > 0 ? 0 : -1;
            render();
        } catch (error) {
            if (error.name !== 'AbortError') {
                closeMenu();
            }
        }
    }, 150);

    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');

    input.addEventListener('input', fetchResults);
    input.addEventListener('focus', fetchResults);
    input.addEventListener('keydown', (event) => {
        if (menu.hidden) return;
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            activeIndex = (activeIndex + 1) % items.length;
            render();
        }
        if (event.key === 'ArrowUp') {
            event.preventDefault();
            activeIndex = (activeIndex - 1 + items.length) % items.length;
            render();
        }
        if (event.key === 'Enter') {
            if (activeIndex >= 0) {
                event.preventDefault();
                selectItem(activeIndex);
            }
        }
        if (event.key === 'Escape') {
            closeMenu();
        }
    });

    input.addEventListener('blur', () => {
        setTimeout(closeMenu, 100);
    });
};

createAutocomplete(
    document.querySelector('[data-autocomplete="from"]'),
    document.querySelector('[data-system-id="from"]')
);
createAutocomplete(
    document.querySelector('[data-autocomplete="to"]'),
    document.querySelector('[data-system-id="to"]')
);
