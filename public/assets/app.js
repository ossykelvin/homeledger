(() => {
  const root = document.documentElement;
  const themeButton = document.querySelector('.theme-toggle');
  const themeMeta = document.querySelector('meta[name="theme-color"]');

  const setTheme = (theme) => {
    root.dataset.theme = theme;
    localStorage.setItem('homeledger-theme', theme);
    themeMeta?.setAttribute('content', theme === 'light' ? '#f1f0eb' : '#080b0f');
    const logo = document.getElementById('brand-logo-img');
    if (logo) {
      logo.setAttribute('src', theme === 'light' ? 'assets/brand/logo-light.png' : 'assets/brand/logo-dark.png');
    }
  };

  themeButton?.addEventListener('click', () => setTheme(root.dataset.theme === 'dark' ? 'light' : 'dark'));

  const sidebar = document.querySelector('#sidebar');
  const menuButton = document.querySelector('.menu-toggle');
  menuButton?.addEventListener('click', () => {
    const open = sidebar?.classList.toggle('open') ?? false;
    menuButton.setAttribute('aria-expanded', String(open));
  });
  document.addEventListener('click', (event) => {
    if (window.innerWidth > 860 || !sidebar?.classList.contains('open')) return;
    if (!sidebar.contains(event.target) && !menuButton?.contains(event.target)) {
      sidebar.classList.remove('open');
      menuButton?.setAttribute('aria-expanded', 'false');
    }
  });

  const openDialog = (dialog, reset = true) => {
    if (!dialog) return;
    if (reset) dialog.querySelector('form')?.reset();
    document.body.classList.add('dialog-open');
    dialog.showModal();
    const profileButton = document.querySelector('.profile-toggle');
    if (dialog.id === 'profile-dialog') {
      profileButton?.setAttribute('aria-expanded', 'true');
      requestAnimationFrame(() => dialog.querySelector('[name="display_name"]')?.focus());
      return;
    }
    requestAnimationFrame(() => dialog.querySelector('input:not([type="hidden"]):not([readonly]), select, button')?.focus());
  };

  const closeDialog = (dialog) => {
    if (!dialog?.open) return;
    dialog.close();
    document.body.classList.remove('dialog-open');
    if (dialog.id === 'profile-dialog') {
      document.querySelector('.profile-toggle')?.setAttribute('aria-expanded', 'false');
    }
  };

  document.querySelectorAll('[data-open-dialog]').forEach((button) => {
    button.addEventListener('click', () => {
      const dialog = document.getElementById(button.dataset.openDialog);
      if (dialog?.id === 'profile-dialog') {
        openDialog(dialog, false);
        return;
      }
      if (dialog?.id === 'delete-account-dialog') {
        closeDialog(document.getElementById('profile-dialog'));
        openDialog(dialog);
        syncDeleteAccountForm(dialog);
        return;
      }
      if (dialog?.id === 'transaction-dialog') {
        const title = dialog.querySelector('#transaction-dialog-title');
        if (title) title.textContent = 'Add transaction';
        dialog.querySelector('[name="id"]').value = '';
        dialog.querySelector('[name="transaction_date"]').value = new Date().toISOString().slice(0, 10);
      }
      if (dialog?.id === 'recurring-dialog') {
        const title = dialog.querySelector('#recurring-dialog-title');
        if (title) title.textContent = 'Add recurring entry';
        dialog.querySelector('[name="id"]').value = '';
        dialog.querySelector('[name="start_date"]').value = new Date().toISOString().slice(0, 10);
      }
      openDialog(dialog);
      filterCategoryOptions(dialog?.querySelector('form'));
    });
  });

  document.querySelectorAll('.close-dialog').forEach((button) => button.addEventListener('click', () => closeDialog(button.closest('dialog'))));
  document.querySelectorAll('dialog').forEach((dialog) => {
    dialog.addEventListener('click', (event) => {
      const rect = dialog.getBoundingClientRect();
      if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) closeDialog(dialog);
    });
    dialog.addEventListener('close', () => {
      document.body.classList.remove('dialog-open');
      if (dialog.id === 'profile-dialog') {
        document.querySelector('.profile-toggle')?.setAttribute('aria-expanded', 'false');
      }
    });
  });

  const filterCategoryOptions = (form) => {
    if (!form) return;
    const type = form.querySelector('[name="type"]:checked')?.value;
    const select = form.querySelector('[name="category_id"]');
    if (!select) return;
    [...select.options].forEach((option) => {
      if (!option.dataset.type) return;
      option.hidden = option.dataset.type !== type;
      option.disabled = option.dataset.type !== type;
    });
    if (select.selectedOptions[0]?.disabled) select.value = '';
  };

  document.querySelectorAll('.entry-form').forEach((form) => {
    form.querySelectorAll('[name="type"]').forEach((radio) => radio.addEventListener('change', () => filterCategoryOptions(form)));
    filterCategoryOptions(form);
  });

  const fillForm = (form, data) => {
    Object.entries(data).forEach(([name, value]) => {
      const field = form.elements.namedItem(name);
      if (!field) return;
      if (field instanceof RadioNodeList) {
        field.value = value ?? '';
      } else {
        field.value = value ?? '';
      }
    });
    filterCategoryOptions(form);
  };

  document.querySelectorAll('.edit-transaction').forEach((button) => {
    button.addEventListener('click', () => {
      const dialog = document.getElementById('transaction-dialog');
      if (!dialog) return;
      fillForm(dialog.querySelector('form'), JSON.parse(button.dataset.transaction));
      dialog.querySelector('#transaction-dialog-title').textContent = 'Edit transaction';
      openDialog(dialog, false);
    });
  });

  document.querySelectorAll('.edit-recurring').forEach((button) => {
    button.addEventListener('click', () => {
      const dialog = document.getElementById('recurring-dialog');
      if (!dialog) return;
      fillForm(dialog.querySelector('form'), JSON.parse(button.dataset.recurring));
      dialog.querySelector('#recurring-dialog-title').textContent = 'Edit recurring entry';
      openDialog(dialog, false);
    });
  });

  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    });
  });

  const syncDeleteAccountForm = (dialog) => {
    const form = dialog?.querySelector('form');
    if (!form) return;
    const expected = (dialog.dataset.householdCode || '').replace(/[\s-]/g, '').toUpperCase();
    const typed = (form.querySelector('[name="confirm_household_id"]')?.value || '').replace(/[\s-]/g, '').toUpperCase();
    const needsTransfer = form.dataset.needsTransfer === '1';
    const transfer = form.querySelector('[name="transfer_user_id"]:checked');
    const submit = form.querySelector('[type="submit"]');
    if (submit) submit.disabled = !(expected !== '' && typed === expected && (!needsTransfer || !!transfer));
  };

  const deleteDialog = document.getElementById('delete-account-dialog');
  if (deleteDialog) {
    deleteDialog.querySelector('[name="confirm_household_id"]')?.addEventListener('input', () => syncDeleteAccountForm(deleteDialog));
    deleteDialog.querySelectorAll('[name="transfer_user_id"]').forEach((input) => {
      input.addEventListener('change', () => syncDeleteAccountForm(deleteDialog));
    });
    deleteDialog.querySelector('form')?.addEventListener('reset', () => requestAnimationFrame(() => syncDeleteAccountForm(deleteDialog)));
    syncDeleteAccountForm(deleteDialog);
  }

  const toast = document.querySelector('[data-toast]');
  toast?.querySelector('button')?.addEventListener('click', () => toast.remove());
  if (toast) window.setTimeout(() => toast.remove(), 5000);

  if (new URLSearchParams(window.location.search).get('delete') === '1') {
    const dialog = document.getElementById('delete-account-dialog');
    openDialog(dialog, false);
    syncDeleteAccountForm(dialog);
  } else if (new URLSearchParams(window.location.search).get('profile') === '1') {
    openDialog(document.getElementById('profile-dialog'), false);
  }

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('service-worker.js').catch(() => {}));
  }

  const householdVersion = document.body?.dataset.householdVersion;
  if (householdVersion) {
    const pollMs = 6000;
    let seenVersion = householdVersion;
    let inFlight = false;
    let reloading = false;

    const pollHousehold = () => {
      if (reloading || inFlight || document.hidden) return;
      inFlight = true;
      fetch('?page=household_sync', {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
      })
        .then((response) => {
          if (response.status === 401) {
            reloading = true;
            return null;
          }
          return response.ok ? response.json() : null;
        })
        .then((payload) => {
          if (!payload || payload.version == null) return;
          const nextVersion = String(payload.version);
          if (nextVersion !== seenVersion) {
            reloading = true;
            seenVersion = nextVersion;
            window.location.reload();
          }
        })
        .catch(() => {})
        .finally(() => {
          inFlight = false;
        });
    };

    window.setInterval(pollHousehold, pollMs);
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) pollHousehold();
    });
  }
})();
