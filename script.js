const form = document.querySelector('#tariff-form');
const exampleButton = document.querySelector('#load-example');
const editor = document.querySelector('#tier-editor');
const template = document.querySelector('#tier-row-template');
const addTierButton = document.querySelector('#add-tier');

function usageFields() {
  return [...document.querySelectorAll('[name="usage[]"]')];
}

exampleButton?.addEventListener('click', () => {
  const example = [200, 100, 300, 180, ''];
  usageFields().forEach((field, index) => {
    window.setTimeout(() => {
      field.value = example[index] ?? '';
      field.dispatchEvent(new Event('input', { bubbles: true }));
    }, index * 55);
  });
  window.setTimeout(() => usageFields()[3]?.focus(), 280);
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

  submitter.firstChild.textContent = 'Calculating… ';
  submitter.setAttribute('aria-busy', 'true');
});

document.querySelector('#validation-summary')?.focus({ preventScroll: true });
