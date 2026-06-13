/**
 * CMC Campaigns Module — JavaScript
 * Version: 2.1.0
 *
 * Fixes:
 *  - product_ids و brand_ids دیگه double-stringify نمیشن
 *  - CMCDatePicker.init() فقط یکبار و بعد از DOM ready صدا زده میشه
 *  - هیچ alert/confirm مرورگری استفاده نمیشه
 */

"use strict";

(function () {

  // ============================================================
  // STATE
  // ============================================================

  const state = {
    selectionMode: 'manual',
    selectedProducts: [],
    selectedCategories: [],
    selectedTags: [],
    selectedAttributes: [],
    selectedBrands: [],
    pickerData: null,
    searchPage: 1,
    searchQuery: '',
    isSearching: false,
  };

  // ============================================================
  // HELPERS
  // ============================================================

  const $ = id => document.getElementById(id);

  /**
   * AJAX helper
   * نکته مهم: برای POST، آرایه‌ها و objectها رو مستقیم append میکنیم
   * تا double-stringify نشن
   */
  async function apiFetch(action, params = {}, method = 'GET') {
    const ajaxUrl = window.CMC_DATA?.ajaxUrl ?? '/wp-admin/admin-ajax.php';
    const nonce   = window.CMC_DATA?.nonce   ?? '';

    if (method === 'GET') {
      const url = new URL(ajaxUrl, location.href);
      url.searchParams.set('action', action);
      url.searchParams.set('nonce', nonce);
      for (const [k, v] of Object.entries(params)) {
        url.searchParams.set(k, String(v));
      }
      const res = await fetch(url.toString(), { credentials: 'same-origin' });
      return res.json();
    }

    // POST — مقادیر رو مستقیم append کن (بدون تبدیل اضافی)
    const body = new FormData();
    body.append('action', action);
    body.append('nonce', nonce);
    for (const [k, v] of Object.entries(params)) {
      // اگر string هست مستقیم، اگر غیرstring (number, bool) رو به string تبدیل کن
      // JSON stringها رو هم مستقیم pass کن چون خودشون قبلاً stringify شدن
      body.append(k, String(v));
    }
    const res = await fetch(ajaxUrl, { method: 'POST', body, credentials: 'same-origin' });
    return res.json();
  }

  function debounce(fn, ms) {
    let t;
    return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
  }

  function showToast(msg, type = 'success') {
    if (typeof CMC !== 'undefined' && typeof CMC.toast === 'function') {
      CMC.toast(msg, type);
    } else {
      setTimeout(() => {
        if (typeof CMC !== 'undefined' && typeof CMC.toast === 'function') {
          CMC.toast(msg, type);
        }
      }, 500);
    }
  }

  function showConfirm(opts) {
    if (typeof CMC !== 'undefined' && typeof CMC.confirm === 'function') {
      CMC.confirm(opts);
    }
  }

  // ============================================================
  // 1. CAMPAIGN LIST — DELETE
  // ============================================================

  function initList() {
    document.querySelectorAll('.cmc-delete-campaign').forEach(btn => {
      btn.addEventListener('click', () => {
        const id    = btn.dataset.id;
        const title = btn.dataset.title;

        showConfirm({
          title:   'حذف کمپین',
          body:    `کمپین «${title}» حذف شود؟`,
          sub:     'تمام داده‌ها، محصولات و آمار این کمپین پاک می‌شوند.',
          okLabel: 'بله، حذف کن',
          okClass: 'cmc-btn--danger',
          onConfirm: async () => {
            btn.disabled = true;
            try {
              const res = await apiFetch('cmc_delete_campaign', { id }, 'POST');
              if (res.success) {
                const row = btn.closest('tr');
                row.style.transition = 'opacity 300ms, transform 300ms';
                row.style.opacity    = '0';
                row.style.transform  = 'translateX(20px)';
                setTimeout(() => row.remove(), 310);
                showToast(res.message ?? 'کمپین حذف شد', 'success');
              } else {
                showToast(res.message ?? 'خطا در حذف', 'danger');
                btn.disabled = false;
              }
            } catch {
              showToast('خطا در اتصال به سرور', 'danger');
              btn.disabled = false;
            }
          },
        });
      });
    });
  }

  // ============================================================
  // 2. FORM TOGGLES
  // ============================================================

  function initFormToggles() {
    document.querySelectorAll('.cmc-type-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('.cmc-type-btn').forEach(b => b.classList.remove('is-active'));
        btn.classList.add('is-active');
        const f = $('cmc-field-type');
        if (f) f.value = btn.dataset.value;
        updateSummary();
      });
    });

    document.querySelectorAll('.cmc-dt-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('.cmc-dt-btn').forEach(b => b.classList.remove('is-active'));
        btn.classList.add('is-active');
        const f = $('cmc-field-discount-type');
        if (f) f.value = btn.dataset.value;
        updateSummary();
      });
    });

    $('cmc-field-discount')?.addEventListener('input', updateSummary);
  }

  // ============================================================
  // 3. PICKER TABS
  // ============================================================

  function initPickerTabs() {
    document.querySelectorAll('#cmc-picker-tabs .cmc-tab').forEach(tab => {
      tab.addEventListener('click', () => {
        document.querySelectorAll('#cmc-picker-tabs .cmc-tab').forEach(t => t.classList.remove('is-active'));
        tab.classList.add('is-active');
        document.querySelectorAll('.cmc-picker-panel').forEach(p => p.style.display = 'none');

        const mode = tab.dataset.mode;
        state.selectionMode = mode;

        const panel = $(`cmc-panel-${mode}`);
        if (panel) panel.style.display = 'block';
        const smf = $('cmc-field-selection-mode');
        if (smf) smf.value = mode;

        if (mode !== 'manual' && mode !== 'all' && !state.pickerData) {
          loadPickerData();
        } else if (state.pickerData) {
          renderPickerPanel(mode);
        }
        updateSummary();
      });
    });
  }

  // ============================================================
  // 4. PRODUCT SEARCH
  // ============================================================

  function initProductSearch() {
    const input = $('cmc-product-search');
    if (!input) return;

    const handleSearch = debounce(async query => {
      state.searchQuery = query;
      state.searchPage  = 1;
      await runSearch(true);
    }, 320);

    input.addEventListener('input', () => handleSearch(input.value.trim()));
    input.addEventListener('focus', () => {
      const r = $('cmc-search-results');
      if (r && r.children.length) r.style.display = 'block';
    });
  }

  async function runSearch(reset = false) {
    if (state.isSearching) return;
    state.isSearching = true;

    const resultsEl = $('cmc-search-results');
    const loadingEl = $('cmc-search-loading');
    if (loadingEl) loadingEl.style.display = 'flex';
    if (resultsEl) resultsEl.style.display = 'none';

    try {
      const data = await apiFetch('cmc_search_products', {
        search: state.searchQuery,
        page:   state.searchPage,
      });

      if (!data.success) throw new Error(data.message ?? 'خطا');

      if (reset) {
        resultsEl.innerHTML = '';
      } else {
        resultsEl.querySelector('.cmc-load-more')?.remove();
      }

      const products = data.data?.products ?? [];

      if (products.length === 0 && reset) {
        resultsEl.innerHTML = `
          <div style="padding:20px;text-align:center;color:var(--cmc-text-muted);font-size:13px">
            <i class="ti ti-search-off" style="font-size:24px;display:block;margin-bottom:8px"></i>
            محصولی یافت نشد
          </div>`;
      } else {
        products.forEach(p => {
          if (!resultsEl.querySelector(`[data-id="${p.id}"]`))
            resultsEl.appendChild(buildProductItem(p));
        });
      }

      if (data.data?.has_more) {
        const moreBtn = document.createElement('button');
        moreBtn.className   = 'cmc-load-more';
        moreBtn.textContent = 'نمایش بیشتر...';
        moreBtn.addEventListener('click', () => { state.searchPage++; runSearch(false); });
        resultsEl.appendChild(moreBtn);
      }

      if (resultsEl) resultsEl.style.display = (products.length || !reset) ? 'block' : 'none';

    } catch (err) {
      showToast('خطا در جستجو', 'danger');
    } finally {
      if (loadingEl) loadingEl.style.display = 'none';
      state.isSearching = false;
    }
  }

  function buildProductItem(product) {
    const isSelected = state.selectedProducts.some(p => p.id === product.id);
    const item = document.createElement('div');
    item.className  = `cmc-product-result-item${isSelected ? ' is-selected' : ''}`;
    item.dataset.id = product.id;
    item.innerHTML  = `
      <img src="${product.thumb}" alt="" loading="lazy">
      <div style="flex:1;min-width:0">
        <div class="cmc-product-result-item__name">${product.name}</div>
        <div class="cmc-product-result-item__meta">${product.sku ? 'SKU: ' + product.sku : product.type}</div>
      </div>
      <span class="cmc-product-result-item__price">${product.price}</span>
      <span class="cmc-product-result-item__check">
        ${isSelected ? '<i class="ti ti-check" style="font-size:11px"></i>' : ''}
      </span>`;
    item.addEventListener('click', () => toggleProduct(product, item));
    return item;
  }

  function toggleProduct(product, itemEl) {
    const idx = state.selectedProducts.findIndex(p => p.id === product.id);
    if (idx === -1) {
      state.selectedProducts.push(product);
      itemEl.classList.add('is-selected');
      itemEl.querySelector('.cmc-product-result-item__check').innerHTML =
        '<i class="ti ti-check" style="font-size:11px"></i>';
    } else {
      state.selectedProducts.splice(idx, 1);
      itemEl.classList.remove('is-selected');
      itemEl.querySelector('.cmc-product-result-item__check').innerHTML = '';
    }
    renderSelectedProducts();
    updateSummary();
  }

  // ============================================================
  // 5. GROUP PICKER
  // ============================================================

  async function loadPickerData() {
    try {
      const res = await apiFetch('cmc_get_picker_data');
      if (res.success) {
        state.pickerData = res.data;
        renderPickerPanel(state.selectionMode);
      } else {
        showToast('خطا در بارگذاری داده‌ها', 'danger');
      }
    } catch {
      showToast('خطا در اتصال به سرور', 'danger');
    }
  }

  function renderPickerPanel(mode) {
    if (!state.pickerData) return;
    if (mode === 'category') {
      renderTermChips('cmc-category-list', state.pickerData.categories ?? [], state.selectedCategories,
        (id, sel) => toggleTerm(id, state.selectedCategories, sel));
    }
    if (mode === 'tag') {
      renderTermChips('cmc-tag-list', state.pickerData.tags ?? [], state.selectedTags,
        (id, sel) => toggleTerm(id, state.selectedTags, sel));
    }
    if (mode === 'brand') {
      renderTermChips('cmc-brand-list', state.pickerData.brands ?? [], state.selectedBrands,
        (id, sel) => toggleTerm(id, state.selectedBrands, sel));
    }
    if (mode === 'attribute') {
      renderAttributeChips();
    }
  }

  function renderTermChips(containerId, terms, selectedIds, onToggle) {
    const container = $(containerId);
    if (!container) return;
    container.innerHTML = '';

    if (!terms || terms.length === 0) {
      container.innerHTML = `<span style="font-size:13px;color:var(--cmc-text-muted);padding:8px 0;display:block">موردی یافت نشد</span>`;
      return;
    }

    terms.forEach(term => {
      const isSelected = selectedIds.includes(term.id);
      const chip = document.createElement('div');
      chip.className  = `cmc-term-chip${isSelected ? ' is-selected' : ''}`;
      chip.dataset.id = term.id;
      chip.innerHTML  = `${term.name}${term.count !== undefined ? `<span class="cmc-term-chip__count">(${term.count})</span>` : ''}`;
      chip.addEventListener('click', () => {
        const nowSelected = chip.classList.toggle('is-selected');
        onToggle(term.id, nowSelected);
        updateSummary();
      });
      container.appendChild(chip);
    });
  }

  function renderAttributeChips() {
    const container = $('cmc-attribute-list');
    if (!container || !state.pickerData) return;
    container.innerHTML = '';

    (state.pickerData.attributes ?? []).forEach(attr => {
      const group = document.createElement('div');
      group.className = 'cmc-attr-group';
      group.innerHTML = `<div class="cmc-attr-group__label">${attr.label}</div>`;
      const chips = document.createElement('div');
      chips.className = 'cmc-term-list';

      attr.terms.forEach(term => {
        const isSelected = state.selectedAttributes.some(
          a => a.taxonomy === attr.taxonomy && a.term_id === term.id
        );
        const chip = document.createElement('div');
        chip.className   = `cmc-term-chip${isSelected ? ' is-selected' : ''}`;
        chip.textContent = term.name;
        chip.addEventListener('click', () => {
          const nowSelected = chip.classList.toggle('is-selected');
          const idx = state.selectedAttributes.findIndex(
            a => a.taxonomy === attr.taxonomy && a.term_id === term.id
          );
          if (nowSelected && idx === -1) {
            state.selectedAttributes.push({ taxonomy: attr.taxonomy, term_id: term.id });
          } else if (!nowSelected && idx !== -1) {
            state.selectedAttributes.splice(idx, 1);
          }
          updateSummary();
        });
        chips.appendChild(chip);
      });

      group.appendChild(chips);
      container.appendChild(group);
    });
  }

  function toggleTerm(id, arr, selected) {
    const idx = arr.indexOf(id);
    if (selected && idx === -1) arr.push(id);
    if (!selected && idx !== -1) arr.splice(idx, 1);
  }

  // ============================================================
  // 6. SELECTED PRODUCTS
  // ============================================================

  function renderSelectedProducts() {
    const wrap    = $('cmc-selected-wrap');
    const list    = $('cmc-selected-list');
    const counter = $('cmc-selected-count');
    const count   = state.selectedProducts.length;

    if (counter) counter.textContent = count;
    if (wrap)    wrap.style.display  = count > 0 ? 'block' : 'none';
    if (!list)   return;
    list.innerHTML = '';

    state.selectedProducts.forEach(p => {
      const item = document.createElement('div');
      item.className = 'cmc-selected-product';
      item.innerHTML = `
        <img src="${p.thumb}" alt="" loading="lazy">
        <span class="cmc-selected-product__name">${p.name}</span>
        <span style="font-size:11px;color:var(--cmc-primary-500);font-weight:600">${p.price}</span>
        <button class="cmc-selected-product__remove" data-id="${p.id}" title="حذف">
          <i class="ti ti-x"></i>
        </button>`;

      item.querySelector('.cmc-selected-product__remove').addEventListener('click', () => {
        const idx = state.selectedProducts.findIndex(x => x.id === p.id);
        if (idx !== -1) state.selectedProducts.splice(idx, 1);
        const resultItem = document.querySelector(`#cmc-search-results [data-id="${p.id}"]`);
        if (resultItem) {
          resultItem.classList.remove('is-selected');
          const check = resultItem.querySelector('.cmc-product-result-item__check');
          if (check) check.innerHTML = '';
        }
        renderSelectedProducts();
        updateSummary();
      });

      list.appendChild(item);
    });
  }

  // ============================================================
  // 7. SUMMARY
  // ============================================================

  function updateSummary() {
    const typeVal    = $('cmc-field-type')?.value;
    const discVal    = $('cmc-field-discount')?.value;
    const discType   = $('cmc-field-discount-type')?.value;
    const typeLabels = { flash_sale: 'فلش سیل', amazing_offer: 'پیشنهاد شگفت‌انگیز' };

    const sumType     = $('cmc-sum-type');
    const sumDiscount = $('cmc-sum-discount');
    const sumProducts = $('cmc-sum-products');

    if (sumType)     sumType.textContent     = typeLabels[typeVal] ?? '—';
    if (sumDiscount) sumDiscount.textContent = discVal
      ? discVal + (discType === 'percent' ? '٪' : ' تومان')
      : '—';

    let ps = '—';
    switch (state.selectionMode) {
      case 'manual':    ps = state.selectedProducts.length   ? `${state.selectedProducts.length} محصول`       : 'انتخاب نشده'; break;
      case 'category':  ps = state.selectedCategories.length ? `${state.selectedCategories.length} دسته‌بندی` : 'انتخاب نشده'; break;
      case 'tag':       ps = state.selectedTags.length       ? `${state.selectedTags.length} برچسب`           : 'انتخاب نشده'; break;
      case 'attribute': ps = state.selectedAttributes.length ? `${state.selectedAttributes.length} ویژگی`     : 'انتخاب نشده'; break;
      case 'brand':     ps = state.selectedBrands.length     ? `${state.selectedBrands.length} برند`           : 'انتخاب نشده'; break;
      case 'all':       ps = 'همه محصولات'; break;
    }
    if (sumProducts) sumProducts.textContent = ps;
  }

  // ============================================================
  // 8. SAVE CAMPAIGN
  // BUG FIX: آرایه‌ها قبل از pass شدن به apiFetch باید JSON string باشن
  // apiFetch داخلش String() میزنه که روی JSON string بی‌تاثیره
  // ============================================================

  function initSaveButton() {
    const btn = $('cmc-btn-save');
    if (!btn) return;

    btn.addEventListener('click', async () => {
      const editId = parseInt(btn.dataset.editId, 10) || 0;
      const isEdit = editId > 0;

      // JSON stringify کردن آرایه‌ها — این string نهایی‌ه که به server میره
      const payload = {
        title:           ($('cmc-field-title')?.value ?? '').trim(),
        description:     ($('cmc-field-desc')?.value ?? '').trim(),
        type:            $('cmc-field-type')?.value            ?? 'flash_sale',
        discount:        $('cmc-field-discount')?.value        ?? '0',
        discount_type:   $('cmc-field-discount-type')?.value   ?? 'percent',
        starts_at:       $('cmc-field-starts-at')?.value       ?? '',
        ends_at:         $('cmc-field-ends-at')?.value         ?? '',
        status:          $('cmc-field-status')?.value          ?? 'draft',
        selection_mode:  state.selectionMode,
        // ← اینجا JSON.stringify میکنیم — apiFetch فقط String() میزنه که بی‌تاثیره
        product_ids:     JSON.stringify(state.selectedProducts.map(p => p.id)),
        category_ids:    JSON.stringify(state.selectedCategories),
        tag_ids:         JSON.stringify(state.selectedTags),
        attribute_rules: JSON.stringify(state.selectedAttributes),
        brand_ids:       JSON.stringify(state.selectedBrands),
      };

      // validation ساده
      if (!payload.title) {
        showFormError('عنوان کمپین الزامی است');
        return;
      }
      if (!payload.discount || parseFloat(payload.discount) <= 0) {
        showFormError('مقدار تخفیف باید بزرگتر از صفر باشد');
        return;
      }

      if (isEdit) payload.id = editId;

      btn.classList.add('is-loading');
      btn.disabled = true;
      hideFormError();

      try {
        const action = isEdit ? 'cmc_update_campaign' : 'cmc_create_campaign';
        const res    = await apiFetch(action, payload, 'POST');

        if (res.success) {
          showToast(res.message ?? 'ذخیره شد', 'success');
          setTimeout(() => {
            window.location.href = window.CMC_FORM?.backUrl ?? '#';
          }, 900);
        } else {
          showFormError(res.message ?? 'خطایی رخ داد');
        }
      } catch {
        showFormError('خطا در اتصال به سرور');
      } finally {
        btn.classList.remove('is-loading');
        btn.disabled = false;
      }
    });
  }

  // ============================================================
  // 9. DELETE FROM FORM
  // ============================================================

  function initDeleteButton() {
    const btn = $('cmc-btn-delete');
    if (!btn) return;

    btn.addEventListener('click', () => {
      const id = parseInt(btn.dataset.id, 10);
      showConfirm({
        title:   'حذف کمپین',
        body:    'این کمپین به طور کامل حذف شود؟',
        sub:     'تمام داده‌ها، محصولات و آمار پاک می‌شوند. این عمل قابل بازگشت نیست.',
        okLabel: 'بله، حذف کن',
        okClass: 'cmc-btn--danger',
        onConfirm: async () => {
          btn.disabled = true;
          try {
            const res = await apiFetch('cmc_delete_campaign', { id }, 'POST');
            if (res.success) {
              showToast('کمپین حذف شد', 'success');
              setTimeout(() => { window.location.href = window.CMC_FORM?.backUrl ?? '#'; }, 900);
            } else {
              showToast(res.message ?? 'خطا', 'danger');
              btn.disabled = false;
            }
          } catch {
            showToast('خطا در اتصال', 'danger');
            btn.disabled = false;
          }
        },
      });
    });
  }

  // ============================================================
  // 10. LOAD EDIT DATA
  // ============================================================

  async function loadEditData() {
    if (!window.CMC_FORM?.isEdit || !window.CMC_FORM?.editId) return;

    try {
      const res = await apiFetch('cmc_get_campaign', { id: window.CMC_FORM.editId });
      if (!res.success) { showToast('خطا در بارگذاری', 'danger'); return; }

      const c = res.campaign;

      if ($('cmc-field-title'))         $('cmc-field-title').value         = c.title         ?? '';
      if ($('cmc-field-desc'))          $('cmc-field-desc').value          = c.description    ?? '';
      if ($('cmc-field-discount'))      $('cmc-field-discount').value      = c.discount       ?? '';
      if ($('cmc-field-status'))        $('cmc-field-status').value        = c.status         ?? 'draft';
      if ($('cmc-field-type'))          $('cmc-field-type').value          = c.type           ?? 'flash_sale';
      if ($('cmc-field-discount-type')) $('cmc-field-discount-type').value = c.discount_type  ?? 'percent';

      // تاریخ‌گیر شمسی
      if (c.starts_at && typeof CMCDatePicker !== 'undefined') {
        CMCDatePicker.setValue('cmc-field-starts-at', c.starts_at.replace(' ', 'T'));
      }
      if (c.ends_at && typeof CMCDatePicker !== 'undefined') {
        CMCDatePicker.setValue('cmc-field-ends-at', c.ends_at.replace(' ', 'T'));
      }

      // sync toggle buttons
      document.querySelectorAll('.cmc-type-btn').forEach(b =>
        b.classList.toggle('is-active', b.dataset.value === c.type));
      document.querySelectorAll('.cmc-dt-btn').forEach(b =>
        b.classList.toggle('is-active', b.dataset.value === c.discount_type));

      // محصولات انتخابی
      if (res.products?.length) {
        state.selectedProducts = res.products;
        renderSelectedProducts();
      }

      updateSummary();
    } catch {
      showToast('خطا در بارگذاری اطلاعات', 'danger');
    }
  }

  // ============================================================
  // FORM ERROR HELPERS
  // ============================================================

  function showFormError(msg) {
    const el = $('cmc-form-error');
    const tx = $('cmc-form-error-text');
    if (el) el.style.display = 'flex';
    if (tx) tx.textContent   = msg;
    el?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function hideFormError() {
    const el = $('cmc-form-error');
    if (el) el.style.display = 'none';
  }

  // ============================================================
  // INIT
  // ============================================================

  document.addEventListener('DOMContentLoaded', () => {
    const isForm = !!$('cmc-btn-save');

    if (!isForm) {
      initList();
    } else {
      initFormToggles();
      initPickerTabs();
      initProductSearch();
      initSaveButton();
      initDeleteButton();
      updateSummary();

      // تاریخ‌گیر شمسی — فقط یکبار init
      if (typeof CMCDatePicker !== 'undefined') {
        CMCDatePicker.init();
      } else {
        console.warn('[CMC] CMCDatePicker not found — make sure datepicker.js loads before campaigns.js');
      }

      // بارگذاری داده ویرایش بعد از init تاریخ‌گیر
      loadEditData();
    }
  });

})();
