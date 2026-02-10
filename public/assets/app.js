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
    const button = form.querySelector('[data-submit]');
    const statusEl = document.querySelector('[data-job-status]');
    const progressEl = document.querySelector('[data-job-progress]');
    const summaryEl = document.querySelector('[data-job-summary]');
    const resultEl = document.querySelector('[data-job-result]');

    const setButtonLoading = (loading, label = 'Plan Route') => {
        if (!button) return;
        button.disabled = loading;
        button.classList.toggle('loading', loading);
        const text = button.querySelector('.button-text');
        if (text) text.textContent = label;
    };

    const collectPayload = () => {
        const data = new FormData(form);
        return Object.fromEntries(data.entries());
    };

    const describeRequest = (payload) => {
        const toggles = [
            payload.avoid_lowsec ? 'Avoid lowsec' : null,
            payload.avoid_nullsec ? 'Avoid nullsec' : null,
            payload.prefer_npc_stations ? 'Prefer NPC stations' : null,
            payload.require_station_midpoints ? 'Require station midpoints' : null,
        ].filter(Boolean);
        return `Mode: ${payload.mode || 'subcap'} · Range bias: ${payload.safety_vs_speed || 50}%${toggles.length ? ` · ${toggles.join(', ')}` : ''}`;
    };

    const pollJob = async (jobId) => {
        const startedAt = Date.now();
        while (true) {
            const response = await fetch(`/api/v1/route-jobs/${encodeURIComponent(jobId)}`);
            if (!response.ok) {
                throw new Error('Unable to poll route job.');
            }
            const data = await response.json();
            const progress = data.progress || {};
            if (progressEl) {
                const pct = Number.isFinite(progress.pct) ? `${progress.pct}%` : '';
                progressEl.textContent = [progress.message, pct].filter(Boolean).join(' · ') || 'Working...';
            }
            if (Date.now() - startedAt > 15000 && statusEl) {
                statusEl.textContent = 'Still working…';
            }

            if (data.status === 'done') {
                if (statusEl) statusEl.textContent = 'Done';
                if (resultEl) {
                    resultEl.hidden = false;
                    resultEl.textContent = JSON.stringify(data.result || {}, null, 2);
                }
                return;
            }
            if (data.status === 'failed') {
                throw new Error(data.error || 'Route calculation failed.');
            }
            if (data.status === 'canceled') {
                throw new Error('Route calculation canceled.');
            }

            await new Promise((resolve) => setTimeout(resolve, 800));
        }
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const payload = collectPayload();
        if (statusEl) statusEl.textContent = 'Calculating route…';
        if (summaryEl) summaryEl.textContent = describeRequest(payload);
        if (progressEl) progressEl.textContent = 'Queueing job...';
        if (resultEl) {
            resultEl.hidden = true;
            resultEl.textContent = '';
        }
        setButtonLoading(true, 'Calculating…');

        try {
            const createResponse = await fetch('/api/v1/route-jobs', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            if (!createResponse.ok) {
                throw new Error('Unable to create route job.');
            }
            const created = await createResponse.json();
            if (statusEl) statusEl.textContent = created.status || 'queued';
            await pollJob(created.job_id);
        } catch (error) {
            if (statusEl) statusEl.textContent = 'Failed';
            if (progressEl) progressEl.textContent = error.message || 'Unknown error';
        } finally {
            setButtonLoading(false, 'Plan Route');
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
