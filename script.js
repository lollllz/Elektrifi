const form = document.querySelector('#tariff-form');
const exampleButton = document.querySelector('#load-example');
const quickTotal = document.querySelector('#quick-total');
const quickFillStatus = document.querySelector('#quick-fill-status');
const calculationStatus = document.querySelector('#calculation-status');
const editor = document.querySelector('#tier-editor');
const template = document.querySelector('#tier-row-template');
const addTierButton = document.querySelector('#add-tier');
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
let calculationInProgress = false;

function usageFields() {
  return [...document.querySelectorAll('[name="usage[]"]')];
}

function formatUsage(value) {
  return Number.isInteger(value) ? String(value) : value.toFixed(3).replace(/0+$/, '').replace(/\.$/, '');
}

function distributeTotalUsage() {
  const rawTotal = quickTotal?.value.trim() ?? '';
  if (!/^\d+(?:\.\d{1,3})?$/.test(rawTotal)) {
    quickTotal?.classList.add('invalid');
    quickTotal?.setAttribute('aria-invalid', 'true');
    if (quickFillStatus) quickFillStatus.textContent = 'Enter a valid total using up to three decimal places.';
    quickTotal?.focus();
    return;
  }

  const total = Number(rawTotal);
  let remaining = total;
  let lastUsedField = null;
  const fields = usageFields();

  fields.forEach((field, index) => {
    const row = field.closest('[data-usage-row]');
    const rawLimit = row?.dataset.tierLimit ?? '';
    const limit = rawLimit === '' ? null : Number(rawLimit);
    const amount = limit === null ? remaining : Math.min(remaining, limit);
    remaining = Math.max(0, remaining - amount);

    window.setTimeout(() => {
      field.value = amount > 0 ? formatUsage(amount) : '';
      field.dispatchEvent(new Event('input', { bubbles: true }));
    }, reducedMotion.matches ? 0 : index * 55);

    if (amount > 0) lastUsedField = field;
  });

  if (remaining > 0.0005) {
    quickTotal?.classList.add('invalid');
    quickTotal?.setAttribute('aria-invalid', 'true');
    if (quickFillStatus) quickFillStatus.textContent = `${formatUsage(remaining)} kWh exceeds the available tariff blocks. Add an unlimited final block.`;
    return;
  }

  quickTotal?.classList.remove('invalid');
  quickTotal?.removeAttribute('aria-invalid');
  if (quickFillStatus) quickFillStatus.textContent = `${formatUsage(total)} kWh distributed across ${fields.length} tariff blocks.`;
  window.setTimeout(() => lastUsedField?.focus(), reducedMotion.matches ? 0 : fields.length * 55 + 30);
}

exampleButton?.addEventListener('click', distributeTotalUsage);
quickTotal?.addEventListener('keydown', (event) => {
  if (event.key !== 'Enter') return;
  event.preventDefault();
  distributeTotalUsage();
});

form?.addEventListener('input', (event) => {
  const field = event.target;
  if (!(field instanceof HTMLInputElement)) return;
  if (field.inputMode === 'decimal') {
    field.value = field.value.replace(/[^0-9.]/g, '').replace(/(\..*)\./g, '$1');
  }
  field.classList.remove('invalid');
  field.closest('.tier-field')?.classList.remove('field-error');
  field.removeAttribute('aria-invalid');
  if (field === quickTotal && quickFillStatus) {
    quickFillStatus.textContent = 'Press Enter or choose Distribute to fill the tariff blocks.';
  }
});

addTierButton?.addEventListener('click', () => {
  const rows = editor.querySelectorAll('[data-editor-row]');
  if (rows.length >= 10) {
    addTierButton.textContent = 'Maximum 10 tiers';
    addTierButton.disabled = true;
    return;
  }
  const row = template.content.firstElementChild.cloneNode(true);
  const lastRow = rows[rows.length - 1];
  if (lastRow) {
    const lastLimit = lastRow.querySelector('[name="tier_limit[]"]');
    if (lastLimit && lastLimit.value === '') lastLimit.value = '100';
  }
  editor.append(row);
  row.querySelector('input')?.focus();
});

editor?.addEventListener('click', (event) => {
  const removeButton = event.target.closest('.remove-tier');
  if (!removeButton) return;
  const rows = editor.querySelectorAll('[data-editor-row]');
  if (rows.length <= 1) return;
  removeButton.closest('[data-editor-row]').remove();
  addTierButton.disabled = false;
  addTierButton.textContent = '+ Add a tier';
  const updatedRows = editor.querySelectorAll('[data-editor-row]');
  updatedRows[updatedRows.length - 1]?.querySelector('[name="tier_limit[]"]')?.setAttribute('placeholder', 'No limit');
});

form?.addEventListener('submit', (event) => {
  const submitter = event.submitter;
  if (submitter?.value !== 'calculate') return;
  if (calculationInProgress) return;

  let firstInvalid = null;
  usageFields().forEach((field) => {
    const value = field.value.trim();
    if (value !== '' && !/^\d+(?:\.\d{1,3})?$/.test(value)) {
      field.setAttribute('aria-invalid', 'true');
      field.closest('.tier-field')?.classList.add('field-error');
      firstInvalid ??= field;
    }
  });

  if (firstInvalid) {
    event.preventDefault();
    firstInvalid.focus();
    return;
  }

  event.preventDefault();
  calculationInProgress = true;
  submitter.querySelector('span').textContent = 'Powering estimate…';
  submitter.setAttribute('aria-busy', 'true');
  document.body.classList.add('is-calculating');
  if (calculationStatus) calculationStatus.textContent = 'Calculating your electricity bill.';

  window.setTimeout(() => {
    form.requestSubmit(submitter);
  }, reducedMotion.matches ? 0 : 720);
});

document.querySelector('#validation-summary')?.focus({ preventScroll: true });

document.querySelector('#database-form')?.addEventListener('submit', (event) => {
  const databaseForm = event.currentTarget;
  databaseForm.querySelectorAll('[data-draft]').forEach((field) => field.remove());
  if (form) {
    for (const [name, value] of new FormData(form)) {
      if (name === 'csrf_token' || name === 'action' || typeof value !== 'string') continue;
      const hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = name;
      hidden.value = value;
      hidden.dataset.draft = '';
      databaseForm.append(hidden);
    }
  }
  if (event.submitter?.value === 'connect_database') {
    event.submitter.textContent = 'Testing connection…';
    event.submitter.setAttribute('aria-busy', 'true');
  }
});

document.querySelector('#copy-database-sql')?.addEventListener('click', async () => {
  const status = document.querySelector('#database-copy-status');
  try {
    const provider = document.querySelector('[name="database_provider"]:checked')?.value ?? 'supabase';
    await navigator.clipboard.writeText(document.querySelector(`[data-provider-sql="${provider}"]`).textContent);
    status.textContent = ' SQL copied.';
  } catch {
    status.textContent = ' Select and copy the SQL below.';
  }
});

function updateDatabaseProvider() {
  const provider = document.querySelector('[name="database_provider"]:checked')?.value ?? 'supabase';
  document.querySelectorAll('[data-provider-fields]').forEach((group) => {
    const selected = group.dataset.providerFields === provider;
    group.hidden = !selected;
    group.querySelectorAll('input').forEach((input) => {
      input.disabled = !selected;
      input.required = selected;
      if (!selected && input.type === 'password') input.value = '';
    });
  });
  document.querySelectorAll('[data-provider-sql]').forEach((block) => {
    block.hidden = block.dataset.providerSql !== provider;
  });
  const status = document.querySelector('#database-copy-status');
  if (status) status.textContent = '';
}
document.querySelectorAll('[name="database_provider"]').forEach((input) => input.addEventListener('change', updateDatabaseProvider));
updateDatabaseProvider();
