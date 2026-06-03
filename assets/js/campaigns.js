/**
 * CMC Campaigns Module — JavaScript
 * Version: 1.2.0
 *
 * Changes in 1.2.0:
 *  - Replaced all browser confirm() / alert() with custom CMC modals
 *  - Added fully custom Persian (Shamsi) date/time picker
 *  - Added WooCommerce brand picker tab (product_brand taxonomy)
 */

"use strict";

(function () {
  // ============================================================
  // STATE
  // ============================================================

  const state = {
    selectionMode: "manual",
    selectedProducts: [],
    selectedCategories: [],
    selectedTags: [],
    selectedAttributes: [],
    selectedBrands: [], // NEW: brand selection
    pickerData: null,
    searchPage: 1,
    searchQuery: "",
    isSearching: false,

    // Pending delete context (for modal confirm)
    pendingDelete: null, // { id, title, rowEl, btnEl }
    pendingDeleteForm: null, // { id }
  };

  // ============================================================
  // HELPERS
  // ============================================================

  const $ = (id) => document.getElementById(id);

  async function apiFetch(action, params = {}, method = "GET") {
    const ajaxUrl = window.CMC_DATA?.ajaxUrl ?? "/wp-admin/admin-ajax.php";
    const nonce = window.CMC_DATA?.nonce ?? "";

    if (method === "GET") {
      const url = new URL(ajaxUrl);
      url.searchParams.set("action", action);
      url.searchParams.set("nonce", nonce);
      for (const [k, v] of Object.entries(params))
        url.searchParams.set(k, String(v));
      const res = await fetch(url.toString(), { credentials: "same-origin" });
      return res.json();
    }

    const body = new FormData();
    body.append("action", action);
    body.append("nonce", nonce);
    for (const [k, v] of Object.entries(params)) {
      body.append(k, typeof v === "object" ? JSON.stringify(v) : String(v));
    }
    const res = await fetch(ajaxUrl, {
      method: "POST",
      body,
      credentials: "same-origin",
    });
    return res.json();
  }

  function debounce(fn, ms) {
    let t;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...args), ms);
    };
  }

  function showToast(msg, type = "success") {
    if (typeof CMC !== "undefined" && typeof CMC.toast === "function") {
      CMC.toast(msg, type);
    } else {
      setTimeout(() => {
        if (typeof CMC !== "undefined" && typeof CMC.toast === "function")
          CMC.toast(msg, type);
      }, 600);
    }
  }

  // ============================================================
  // CUSTOM CONFIRM MODAL
  // Creates a reusable danger-confirmation modal in the DOM.
  // ============================================================

  function ensureConfirmModal() {
    if ($("cmc-confirm-modal")) return;

    const overlay = document.createElement("div");
    overlay.className = "cmc-modal-overlay";
    overlay.id = "cmc-confirm-modal";
    overlay.innerHTML = `
      <div class="cmc-modal" style="max-width:420px">
        <div class="cmc-modal__header">
          <span class="cmc-modal__title" id="cmc-confirm-title">تأیید عملیات</span>
          <button class="cmc-modal__close" id="cmc-confirm-close"><i class="ti ti-x"></i></button>
        </div>
        <div class="cmc-modal__body" style="padding:var(--cmc-space-5) var(--cmc-space-6)">
          <div style="display:flex;align-items:flex-start;gap:var(--cmc-space-4)">
            <div style="width:44px;height:44px;border-radius:var(--cmc-radius-md);background:var(--cmc-danger-light);display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <i class="ti ti-alert-triangle" style="font-size:22px;color:var(--cmc-danger)"></i>
            </div>
            <div>
              <div id="cmc-confirm-body" style="font-size:14px;color:var(--cmc-text-heading);font-weight:600;margin-bottom:6px"></div>
              <div id="cmc-confirm-sub" style="font-size:12px;color:var(--cmc-text-muted)">این عمل قابل بازگشت نیست.</div>
            </div>
          </div>
        </div>
        <div class="cmc-modal__footer">
          <button class="cmc-btn cmc-btn--danger" id="cmc-confirm-ok">
            <i class="ti ti-trash"></i>
            <span id="cmc-confirm-ok-label">بله، حذف کن</span>
          </button>
          <button class="cmc-btn cmc-btn--secondary" id="cmc-confirm-cancel">انصراف</button>
        </div>
      </div>
    `;
    document.body.appendChild(overlay);

    // Close handlers
    $("cmc-confirm-close")?.addEventListener("click", () =>
      closeConfirmModal()
    );
    $("cmc-confirm-cancel")?.addEventListener("click", () =>
      closeConfirmModal()
    );
    overlay.addEventListener("click", (e) => {
      if (e.target === overlay) closeConfirmModal();
    });
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape") closeConfirmModal();
    });
  }

  function openConfirmModal({
    title,
    body,
    sub,
    okLabel = "بله، حذف کن",
    onConfirm,
  }) {
    ensureConfirmModal();
    const overlay = $("cmc-confirm-modal");
    if ($("cmc-confirm-title"))
      $("cmc-confirm-title").textContent = title ?? "تأیید";
    if ($("cmc-confirm-body")) $("cmc-confirm-body").textContent = body ?? "";
    if ($("cmc-confirm-sub"))
      $("cmc-confirm-sub").textContent = sub ?? "این عمل قابل بازگشت نیست.";
    if ($("cmc-confirm-ok-label"))
      $("cmc-confirm-ok-label").textContent = okLabel;

    // Remove old listener and attach new one
    const okBtn = $("cmc-confirm-ok");
    const newOk = okBtn.cloneNode(true);
    okBtn.parentNode.replaceChild(newOk, okBtn);
    $("cmc-confirm-ok").addEventListener("click", () => {
      closeConfirmModal();
      onConfirm?.();
    });

    overlay.classList.add("is-open");
  }

  function closeConfirmModal() {
    $("cmc-confirm-modal")?.classList.remove("is-open");
  }

  // ============================================================
  // 1. CAMPAIGN LIST — DELETE (custom modal instead of confirm())
  // ============================================================

  function initList() {
    document.querySelectorAll(".cmc-delete-campaign").forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = btn.dataset.id;
        const title = btn.dataset.title;

        CMC.confirm({
          title: "حذف کمپین",
          body: `کمپین «${title}» حذف شود؟`,
          sub: "تمام داده‌ها، محصولات و آمار این کمپین پاک می‌شوند.",
          okLabel: "بله، حذف کن",
          okClass: "cmc-btn--danger",
          onConfirm: async () => {
            btn.disabled = true;
            try {
              const res = await apiFetch("cmc_delete_campaign", { id }, "POST");
              if (res.success) {
                const row = btn.closest("tr");
                row.style.transition = "opacity 300ms";
                row.style.opacity = "0";
                setTimeout(() => row.remove(), 310);
                showToast(res.message ?? "کمپین حذف شد", "success");
              } else {
                showToast(res.message ?? "خطا در حذف", "danger");
                btn.disabled = false;
              }
            } catch {
              showToast("خطا در اتصال به سرور", "danger");
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
    document.querySelectorAll(".cmc-type-btn").forEach((btn) => {
      btn.addEventListener("click", () => {
        document
          .querySelectorAll(".cmc-type-btn")
          .forEach((b) => b.classList.remove("is-active"));
        btn.classList.add("is-active");
        $("cmc-field-type").value = btn.dataset.value;
        updateSummary();
      });
    });

    document.querySelectorAll(".cmc-dt-btn").forEach((btn) => {
      btn.addEventListener("click", () => {
        document
          .querySelectorAll(".cmc-dt-btn")
          .forEach((b) => b.classList.remove("is-active"));
        btn.classList.add("is-active");
        $("cmc-field-discount-type").value = btn.dataset.value;
        updateSummary();
      });
    });

    $("cmc-field-discount")?.addEventListener("input", updateSummary);
  }

  // ============================================================
  // 3. PICKER TABS (now includes brand tab)
  // ============================================================

  function initPickerTabs() {
    document.querySelectorAll("#cmc-picker-tabs .cmc-tab").forEach((tab) => {
      tab.addEventListener("click", () => {
        document
          .querySelectorAll("#cmc-picker-tabs .cmc-tab")
          .forEach((t) => t.classList.remove("is-active"));
        tab.classList.add("is-active");
        document
          .querySelectorAll(".cmc-picker-panel")
          .forEach((p) => (p.style.display = "none"));

        const mode = tab.dataset.mode;
        state.selectionMode = mode;
        $(`cmc-panel-${mode}`).style.display = "block";
        $("cmc-field-selection-mode").value = mode;

        if (mode !== "manual" && mode !== "all" && !state.pickerData) {
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
    const input = $("cmc-product-search");
    if (!input) return;

    const handleSearch = debounce(async (query) => {
      state.searchQuery = query;
      state.searchPage = 1;
      await runSearch(true);
    }, 320);

    input.addEventListener("input", () => handleSearch(input.value.trim()));
    input.addEventListener("focus", () => {
      if ($("cmc-search-results").children.length)
        $("cmc-search-results").style.display = "block";
    });
  }

  async function runSearch(reset = false) {
    if (state.isSearching) return;
    state.isSearching = true;

    const resultsEl = $("cmc-search-results");
    const loadingEl = $("cmc-search-loading");
    loadingEl.style.display = "flex";
    resultsEl.style.display = "none";

    try {
      const data = await apiFetch("cmc_search_products", {
        search: state.searchQuery,
        page: state.searchPage,
      });
      if (!data.success) throw new Error(data.message);

      if (reset) {
        resultsEl.innerHTML = "";
      } else {
        resultsEl.querySelector(".cmc-load-more")?.remove();
      }

      const products = data.data?.products ?? [];

      if (products.length === 0 && reset) {
        resultsEl.innerHTML = `
          <div style="padding:16px;text-align:center;color:var(--cmc-text-muted);font-size:13px">
            <i class="ti ti-search-off" style="font-size:20px;display:block;margin-bottom:6px"></i>
            محصولی یافت نشد
          </div>`;
      } else {
        products.forEach((p) => {
          if (!resultsEl.querySelector(`[data-id="${p.id}"]`))
            resultsEl.appendChild(buildProductItem(p));
        });
      }

      if (data.data?.has_more) {
        const btn = document.createElement("button");
        btn.className = "cmc-load-more";
        btn.textContent = "نمایش بیشتر...";
        btn.addEventListener("click", () => {
          state.searchPage++;
          runSearch(false);
        });
        resultsEl.appendChild(btn);
      }

      resultsEl.style.display = products.length || !reset ? "block" : "none";
    } catch {
      showToast("خطا در جستجو", "danger");
    } finally {
      loadingEl.style.display = "none";
      state.isSearching = false;
    }
  }

  function buildProductItem(product) {
    const isSelected = state.selectedProducts.some((p) => p.id === product.id);
    const item = document.createElement("div");
    item.className = `cmc-product-result-item${
      isSelected ? " is-selected" : ""
    }`;
    item.dataset.id = product.id;
    item.innerHTML = `
      <img src="${product.thumb}" alt="${product.name}" loading="lazy">
      <div style="flex:1;min-width:0">
        <div class="cmc-product-result-item__name">${product.name}</div>
        <div class="cmc-product-result-item__meta">${
          product.sku ? "SKU: " + product.sku : product.type
        }</div>
      </div>
      <span class="cmc-product-result-item__price">${product.price}</span>
      <span class="cmc-product-result-item__check">
        ${
          isSelected ? '<i class="ti ti-check" style="font-size:11px"></i>' : ""
        }
      </span>`;
    item.addEventListener("click", () => toggleProduct(product, item));
    return item;
  }

  function toggleProduct(product, itemEl) {
    const idx = state.selectedProducts.findIndex((p) => p.id === product.id);
    if (idx === -1) {
      state.selectedProducts.push(product);
      itemEl.classList.add("is-selected");
      itemEl.querySelector(".cmc-product-result-item__check").innerHTML =
        '<i class="ti ti-check" style="font-size:11px"></i>';
    } else {
      state.selectedProducts.splice(idx, 1);
      itemEl.classList.remove("is-selected");
      itemEl.querySelector(".cmc-product-result-item__check").innerHTML = "";
    }
    renderSelectedProducts();
    updateSummary();
  }

  // ============================================================
  // 5. GROUP PICKER (categories / tags / attributes / brands)
  // ============================================================

  async function loadPickerData() {
    try {
      const res = await apiFetch("cmc_get_picker_data");
      if (res.success) {
        state.pickerData = res.data;
        renderPickerPanel(state.selectionMode);
      }
    } catch {
      showToast("خطا در بارگذاری داده‌ها", "danger");
    }
  }

  function renderPickerPanel(mode) {
    if (!state.pickerData) return;
    if (mode === "category")
      renderTermChips(
        "cmc-category-list",
        state.pickerData.categories,
        state.selectedCategories,
        (id, sel) => toggleTermSelection(id, state.selectedCategories, sel)
      );
    if (mode === "tag")
      renderTermChips(
        "cmc-tag-list",
        state.pickerData.tags,
        state.selectedTags,
        (id, sel) => toggleTermSelection(id, state.selectedTags, sel)
      );
    if (mode === "brand")
      renderTermChips(
        "cmc-brand-list",
        state.pickerData.brands ?? [],
        state.selectedBrands,
        (id, sel) => toggleTermSelection(id, state.selectedBrands, sel)
      );
    if (mode === "attribute") renderAttributeChips();
  }

  function renderTermChips(containerId, terms, selectedIds, onToggle) {
    const container = $(containerId);
    if (!container) return;
    container.innerHTML = "";

    if (!terms || terms.length === 0) {
      container.innerHTML = `<span style="font-size:13px;color:var(--cmc-text-muted)">موردی یافت نشد</span>`;
      return;
    }

    terms.forEach((term) => {
      const chip = document.createElement("div");
      const isSelected = selectedIds.includes(term.id);
      chip.className = `cmc-term-chip${isSelected ? " is-selected" : ""}`;
      chip.dataset.id = term.id;
      chip.innerHTML = `
        ${term.name}
        ${
          term.count !== undefined
            ? `<span class="cmc-term-chip__count">(${term.count})</span>`
            : ""
        }`;
      chip.addEventListener("click", () => {
        const nowSelected = chip.classList.toggle("is-selected");
        onToggle(term.id, nowSelected);
        updateSummary();
      });
      container.appendChild(chip);
    });
  }

  function renderAttributeChips() {
    const container = $("cmc-attribute-list");
    if (!container || !state.pickerData) return;
    container.innerHTML = "";

    state.pickerData.attributes.forEach((attr) => {
      const group = document.createElement("div");
      group.className = "cmc-attr-group";
      group.innerHTML = `<div class="cmc-attr-group__label">${attr.label}</div>`;
      const chips = document.createElement("div");
      chips.className = "cmc-term-list";

      attr.terms.forEach((term) => {
        const isSelected = state.selectedAttributes.some(
          (a) => a.taxonomy === attr.taxonomy && a.term_id === term.id
        );
        const chip = document.createElement("div");
        chip.className = `cmc-term-chip${isSelected ? " is-selected" : ""}`;
        chip.textContent = term.name;
        chip.addEventListener("click", () => {
          const nowSelected = chip.classList.toggle("is-selected");
          const idx = state.selectedAttributes.findIndex(
            (a) => a.taxonomy === attr.taxonomy && a.term_id === term.id
          );
          if (nowSelected && idx === -1)
            state.selectedAttributes.push({
              taxonomy: attr.taxonomy,
              term_id: term.id,
            });
          else if (!nowSelected && idx !== -1)
            state.selectedAttributes.splice(idx, 1);
          updateSummary();
        });
        chips.appendChild(chip);
      });

      group.appendChild(chips);
      container.appendChild(group);
    });
  }

  function toggleTermSelection(id, arr, selected) {
    const idx = arr.indexOf(id);
    if (selected && idx === -1) arr.push(id);
    if (!selected && idx !== -1) arr.splice(idx, 1);
  }

  // ============================================================
  // 6. SELECTED PRODUCTS PANEL
  // ============================================================

  function renderSelectedProducts() {
    const wrap = $("cmc-selected-wrap");
    const list = $("cmc-selected-list");
    const counter = $("cmc-selected-count");
    const count = state.selectedProducts.length;
    counter.textContent = count;
    wrap.style.display = count > 0 ? "block" : "none";
    list.innerHTML = "";

    state.selectedProducts.forEach((p) => {
      const item = document.createElement("div");
      item.className = "cmc-selected-product";
      item.innerHTML = `
        <img src="${p.thumb}" alt="${p.name}" loading="lazy">
        <span class="cmc-selected-product__name">${p.name}</span>
        <span style="font-size:11px;color:var(--cmc-primary-500);font-weight:600">${p.price}</span>
        <button class="cmc-selected-product__remove" data-id="${p.id}" title="حذف"><i class="ti ti-x"></i></button>`;
      item
        .querySelector(".cmc-selected-product__remove")
        .addEventListener("click", () => {
          const idx = state.selectedProducts.findIndex((x) => x.id === p.id);
          if (idx !== -1) state.selectedProducts.splice(idx, 1);
          const resultItem = document.querySelector(
            `#cmc-search-results [data-id="${p.id}"]`
          );
          if (resultItem) {
            resultItem.classList.remove("is-selected");
            resultItem.querySelector(
              ".cmc-product-result-item__check"
            ).innerHTML = "";
          }
          renderSelectedProducts();
          updateSummary();
        });
      list.appendChild(item);
    });
  }

  // ============================================================
  // 7. SUMMARY LIVE UPDATE
  // ============================================================

  function updateSummary() {
    const typeVal = $("cmc-field-type")?.value;
    const discVal = $("cmc-field-discount")?.value;
    const discType = $("cmc-field-discount-type")?.value;
    const typeLabels = {
      flash_sale: "فلش سیل",
      amazing_offer: "پیشنهاد شگفت‌انگیز",
    };

    const sumType = $("cmc-sum-type");
    const sumDiscount = $("cmc-sum-discount");
    const sumProducts = $("cmc-sum-products");

    if (sumType) sumType.textContent = typeLabels[typeVal] ?? "—";
    if (sumDiscount)
      sumDiscount.textContent = discVal
        ? discVal + (discType === "percent" ? "٪" : " تومان")
        : "—";

    let productSummary = "—";
    switch (state.selectionMode) {
      case "manual":
        productSummary = state.selectedProducts.length
          ? `${state.selectedProducts.length} محصول`
          : "انتخاب نشده";
        break;
      case "category":
        productSummary = state.selectedCategories.length
          ? `${state.selectedCategories.length} دسته‌بندی`
          : "انتخاب نشده";
        break;
      case "tag":
        productSummary = state.selectedTags.length
          ? `${state.selectedTags.length} برچسب`
          : "انتخاب نشده";
        break;
      case "attribute":
        productSummary = state.selectedAttributes.length
          ? `${state.selectedAttributes.length} ویژگی`
          : "انتخاب نشده";
        break;
      case "brand":
        productSummary = state.selectedBrands.length
          ? `${state.selectedBrands.length} برند`
          : "انتخاب نشده";
        break;
      case "all":
        productSummary = "همه محصولات";
        break;
    }
    if (sumProducts) sumProducts.textContent = productSummary;
  }

  // ============================================================
  // 8. SAVE CAMPAIGN
  // ============================================================

  function initSaveButton() {
    const btn = $("cmc-btn-save");
    if (!btn) return;

    btn.addEventListener("click", async () => {
      const editId = parseInt(btn.dataset.editId, 10) || 0;
      const isEdit = editId > 0;

      const payload = {
        title: $("cmc-field-title")?.value?.trim() ?? "",
        description: $("cmc-field-desc")?.value?.trim() ?? "",
        type: $("cmc-field-type")?.value ?? "flash_sale",
        discount: $("cmc-field-discount")?.value ?? "0",
        discount_type: $("cmc-field-discount-type")?.value ?? "percent",
        starts_at: $("cmc-field-starts-at")?.value ?? "",
        ends_at: $("cmc-field-ends-at")?.value ?? "",
        status: $("cmc-field-status")?.value ?? "draft",
        selection_mode: state.selectionMode,
        product_ids: JSON.stringify(state.selectedProducts.map((p) => p.id)),
        category_ids: JSON.stringify(state.selectedCategories),
        tag_ids: JSON.stringify(state.selectedTags),
        attribute_rules: JSON.stringify(state.selectedAttributes),
        brand_ids: JSON.stringify(state.selectedBrands), // NEW
      };

      if (isEdit) payload.id = editId;

      btn.classList.add("is-loading");
      btn.disabled = true;
      hideFormError();

      try {
        const action = isEdit ? "cmc_update_campaign" : "cmc_create_campaign";
        const res = await apiFetch(action, payload, "POST");
        if (res.success) {
          showToast(res.message ?? "ذخیره شد", "success");
          setTimeout(() => {
            window.location.href = window.CMC_FORM?.backUrl ?? "#";
          }, 900);
        } else {
          showFormError(res.message ?? "خطایی رخ داد");
        }
      } catch {
        showFormError("خطا در اتصال به سرور");
      } finally {
        btn.classList.remove("is-loading");
        btn.disabled = false;
      }
    });
  }

  // ============================================================
  // 9. DELETE FROM FORM (custom modal instead of confirm())
  // ============================================================

  function initDeleteButton() {
    const btn = $("cmc-btn-delete");
    if (!btn) return;

    btn.addEventListener("click", () => {
      const id = parseInt(btn.dataset.id, 10);

      CMC.confirm({
        title: "حذف کمپین",
        body: "این کمپین به طور کامل حذف شود؟",
        sub: "تمام داده‌ها، محصولات و آمار این کمپین پاک می‌شوند. این عمل قابل بازگشت نیست.",
        okLabel: "بله، حذف کن",
        okClass: "cmc-btn--danger",
        onConfirm: async () => {
          btn.disabled = true;
          try {
            const res = await apiFetch("cmc_delete_campaign", { id }, "POST");
            if (res.success) {
              showToast("کمپین حذف شد", "success");
              setTimeout(() => {
                window.location.href = window.CMC_FORM?.backUrl ?? "#";
              }, 900);
            } else {
              showToast(res.message ?? "خطا", "danger");
              btn.disabled = false;
            }
          } catch {
            showToast("خطا در اتصال", "danger");
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
      const res = await apiFetch("cmc_get_campaign", {
        id: window.CMC_FORM.editId,
      });
      if (!res.success) return;
      const c = res.campaign;

      if ($("cmc-field-title")) $("cmc-field-title").value = c.title ?? "";
      if ($("cmc-field-desc")) $("cmc-field-desc").value = c.description ?? "";
      if ($("cmc-field-discount"))
        $("cmc-field-discount").value = c.discount ?? "";
      if ($("cmc-field-status"))
        $("cmc-field-status").value = c.status ?? "draft";
      if ($("cmc-field-type"))
        $("cmc-field-type").value = c.type ?? "flash_sale";
      if ($("cmc-field-discount-type"))
        $("cmc-field-discount-type").value = c.discount_type ?? "percent";

      // Set date pickers
      if (c.starts_at)
        CMCDatePicker.setValue(
          "cmc-field-starts-at",
          c.starts_at.replace(" ", "T")
        );
      if (c.ends_at)
        CMCDatePicker.setValue(
          "cmc-field-ends-at",
          c.ends_at.replace(" ", "T")
        );

      document
        .querySelectorAll(".cmc-type-btn")
        .forEach((b) =>
          b.classList.toggle("is-active", b.dataset.value === c.type)
        );
      document
        .querySelectorAll(".cmc-dt-btn")
        .forEach((b) =>
          b.classList.toggle("is-active", b.dataset.value === c.discount_type)
        );

      if (res.products?.length) {
        state.selectedProducts = res.products;
        renderSelectedProducts();
      }
      updateSummary();
    } catch {
      showToast("خطا در بارگذاری اطلاعات", "danger");
    }
  }

  // ============================================================
  // ERROR HELPERS
  // ============================================================

  function showFormError(msg) {
    const el = $("cmc-form-error");
    const tx = $("cmc-form-error-text");
    if (el) el.style.display = "flex";
    if (tx) tx.textContent = msg;
    el?.scrollIntoView({ behavior: "smooth", block: "nearest" });
  }

  function hideFormError() {
    const el = $("cmc-form-error");
    if (el) el.style.display = "none";
  }

  // ============================================================
  // INIT
  // ============================================================

  document.addEventListener("DOMContentLoaded", () => {
    const isForm = !!document.getElementById("cmc-btn-save");
    const isList = !isForm;

    ensureConfirmModal(); // Always inject confirm modal

    if (isList) {
      initList();
    }

    if (isForm) {
      initFormToggles();
      initPickerTabs();
      initProductSearch();
      initSaveButton();
      initDeleteButton();
      updateSummary();
      loadEditData();
      CMCDatePicker.init(); // Init Persian date pickers
    }
  });
})();

/* ===================================================================
 * CMCDatePicker — Fully custom Persian (Shamsi) date/time picker
 * No external dependencies — pure vanilla JS + CSS
 * Attaches to elements with data-cmc-datepicker attribute
 * =================================================================== */

const CMCDatePicker = (() => {
  /* -------------------------------------------------------
   * JALALI CONVERSION UTILITIES
   * Based on the standard Jalali ↔ Gregorian algorithm
   * ------------------------------------------------------- */

  function gregorianToJalali(gy, gm, gd) {
    const g_d_no =
      365 * gy +
      Math.floor((gy + 3) / 4) -
      Math.floor((gy + 99) / 100) +
      Math.floor((gy + 399) / 400);
    let jy = gy - 621;
    const j_d_no = jy * 365 + Math.floor((jy + 3) / 4) + 79 + 1;
    const jnp = g_d_no - j_d_no;
    let j_month_start, i;

    for (i = 0; i < 11; i++) {
      j_month_start = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
      if (jnp < j_month_start.slice(0, i + 1).reduce((a, b) => a + b, 0)) break;
    }

    const jm = i + 1;
    const jd =
      jnp -
      (i === 0 ? 0 : j_month_start.slice(0, i).reduce((a, b) => a + b, 0));

    // Recalculate properly
    const g2j = gregorianToJalaliAccurate(gy, gm, gd);
    return g2j;
  }

  function gregorianToJalaliAccurate(gy, gm, gd) {
    const breaks = [
      -61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097,
      2192, 2262, 2324, 2394, 2456, 3178,
    ];
    const gy2 = gy + 1600;
    const gd2 = gd;
    let days =
      365 * gy2 +
      Math.floor((gy2 + 3) / 4) -
      Math.floor((gy2 + 99) / 100) +
      Math.floor((gy2 + 399) / 400);
    for (let i = 0; i < gm - 1; i++)
      days += [
        31,
        28 + (gy % 4 === 0 && (gy % 100 !== 0 || gy % 400 === 0) ? 1 : 0),
        31,
        30,
        31,
        30,
        31,
        31,
        30,
        31,
        30,
        31,
      ][i];
    days += gd2;

    let jy = -1,
      jm,
      jd,
      jp = 474;
    const jy2 = 474 + (Math.floor((days - 948) / 365.24219879) % 2820);
    let y2 = jy2 + 474 + 38;
    for (let i = 0; i < breaks.length; i++) {
      if (jy2 >= breaks[i]) {
        jp = breaks[i];
        jy = jp;
      }
    }
    jy +=
      474 +
      Math.floor(
        (days - (365 * jp + Math.floor((jp * 682) / 2816) + 1948440 - 10)) /
          365.24219879
      );
    const jdy =
      days - (365 * jy + Math.floor((jy * 682) / 2816) + 1948440 - 10);
    if (jdy > 0) {
      if (jdy <= 186) {
        jm = Math.ceil(jdy / 31);
        jd = jdy - 31 * (jm - 1);
      } else {
        jm = Math.ceil((jdy - 6) / 30);
        jd = jdy - 30 * jm + 6;
      }
    }
    return [jy, jm, jd];
  }

  function jalaliToGregorian(jy, jm, jd) {
    jy += 1595;
    const days =
      -355779 +
      (365 + Math.floor(jy / 33)) * Math.floor(jy / 4) +
      Math.floor(((jy % 33) + 3) / 8) +
      Math.floor(((jy % 33) + 1) / 4) +
      (jm < 7 ? (jm - 1) * 31 : (jm - 7) * 30 + 186) +
      jd +
      1;
    let gy = Math.floor((days + 365 * 400 + 97 * 4 + 4) / 365.2425) - 400;
    let gm, gd2;
    let diff =
      days -
      (365 * gy +
        Math.floor(gy / 4) -
        Math.floor(gy / 100) +
        Math.floor(gy / 400));
    const gdm = [
      31,
      28 +
        (((gy + 1) % 4 === 0 && (gy + 1) % 100 !== 0) || (gy + 1) % 400 === 0
          ? 1
          : 0),
      31,
      30,
      31,
      30,
      31,
      31,
      30,
      31,
      30,
      31,
    ];
    if (diff < 0) {
      gy--;
      diff +=
        365 + (gy % 4 === 0 && (gy % 100 !== 0 || gy % 400 === 0) ? 1 : 0);
    }
    gm = 0;
    while (gm < 11 && diff >= gdm[gm]) {
      diff -= gdm[gm];
      gm++;
    }
    gd2 = diff + 1;
    return [gy, gm + 1, gd2];
  }

  function jalaliDaysInMonth(jy, jm) {
    if (jm <= 6) return 31;
    if (jm <= 11) return 30;
    return isJalaliLeap(jy) ? 30 : 29;
  }

  function isJalaliLeap(jy) {
    const leaps = [1, 5, 9, 13, 17, 22, 26, 30];
    return leaps.includes(((((jy - 474) % 2820) + 474 + 38) * 682) % 2816);
  }

  function jalaliDayOfWeek(jy, jm, jd) {
    const [gy, gm, gd] = jalaliToGregorian(jy, jm, jd);
    return new Date(gy, gm - 1, gd).getDay(); // 0=Sun
  }

  /* Persian numerals */
  const PERSIAN_NUMS = ["۰", "۱", "۲", "۳", "۴", "۵", "۶", "۷", "۸", "۹"];
  function toPersian(n) {
    return String(n)
      .split("")
      .map((c) => PERSIAN_NUMS[c] ?? c)
      .join("");
  }
  function fromPersian(s) {
    return s.replace(/[۰-۹]/g, (c) => PERSIAN_NUMS.indexOf(c));
  }

  const MONTHS = [
    "فروردین",
    "اردیبهشت",
    "خرداد",
    "تیر",
    "مرداد",
    "شهریور",
    "مهر",
    "آبان",
    "آذر",
    "دی",
    "بهمن",
    "اسفند",
  ];
  const WEEK_DAYS = ["ش", "ی", "د", "س", "چ", "پ", "ج"];

  /* -------------------------------------------------------
   * PICKER INSTANCE
   * ------------------------------------------------------- */

  let activePickerEl = null;
  let activePopup = null;
  let pickerState = {}; // { jy, jm, jd, hour, minute, targetInput }

  function init() {
    document.querySelectorAll("[data-cmc-datepicker]").forEach((input) => {
      input.readOnly = true;
      input.style.cursor = "pointer";
      input.addEventListener("click", (e) => {
        e.stopPropagation();
        openPicker(input);
      });
    });

    // Close on outside click
    document.addEventListener("click", (e) => {
      if (activePopup && !activePopup.contains(e.target)) closePicker();
    });
  }

  function openPicker(inputEl) {
    if (activePopup) closePicker();

    // Parse current value (if any) from hidden ISO field
    const hiddenId = inputEl.dataset.cmcDatepicker;
    const hiddenEl = document.getElementById(hiddenId);
    const isoVal = hiddenEl?.value ?? "";

    let jy,
      jm,
      jd,
      hour = 0,
      minute = 0;
    if (isoVal) {
      const d = new Date(isoVal);
      [jy, jm, jd] = gregorianToJalaliAccurate(
        d.getFullYear(),
        d.getMonth() + 1,
        d.getDate()
      );
      hour = d.getHours();
      minute = d.getMinutes();
    } else {
      const now = new Date();
      [jy, jm, jd] = gregorianToJalaliAccurate(
        now.getFullYear(),
        now.getMonth() + 1,
        now.getDate()
      );
      hour = now.getHours();
      minute = now.getMinutes();
    }

    pickerState = { jy, jm, jd, hour, minute, targetInput: inputEl, hiddenId };
    activePickerEl = inputEl;

    const popup = document.createElement("div");
    popup.className = "cmc-datepicker-popup";
    popup.id = "cmc-datepicker-popup";
    document.body.appendChild(popup);
    activePopup = popup;

    renderPickerPopup();
    positionPopup(inputEl, popup);
  }

  function closePicker() {
    activePopup?.remove();
    activePopup = null;
    activePickerEl = null;
  }

  function renderPickerPopup() {
    if (!activePopup) return;
    const { jy, jm, jd, hour, minute } = pickerState;

    const firstDayOfWeek = (jalaliDayOfWeek(jy, jm, 1) + 1) % 7; // Sat = 0
    const daysInMonth = jalaliDaysInMonth(jy, jm);
    const prevDays = jalaliDaysInMonth(jy, jm - 1 < 1 ? 12 : jm - 1);

    let calRows = "";
    let day = 1 - firstDayOfWeek;
    for (let row = 0; row < 6; row++) {
      calRows += "<tr>";
      for (let col = 0; col < 7; col++) {
        if (day < 1 || day > daysInMonth) {
          const greyDay = day < 1 ? prevDays + day : day - daysInMonth;
          calRows += `<td class="cmc-dp-day cmc-dp-day--other">${toPersian(
            greyDay
          )}</td>`;
        } else {
          const isSelected = day === jd;
          const isToday = (() => {
            const now = new Date();
            const [ty, tm, td] = gregorianToJalaliAccurate(
              now.getFullYear(),
              now.getMonth() + 1,
              now.getDate()
            );
            return ty === jy && tm === jm && td === day;
          })();
          calRows += `<td class="cmc-dp-day${isSelected ? " is-selected" : ""}${
            isToday && !isSelected ? " is-today" : ""
          }" data-day="${day}">${toPersian(day)}</td>`;
        }
        day++;
      }
      calRows += "</tr>";
      if (day > daysInMonth) break;
    }

    activePopup.innerHTML = `
      <div class="cmc-dp-header">
        <button class="cmc-dp-nav" data-nav="prev-year" title="سال قبل">«</button>
        <button class="cmc-dp-nav" data-nav="prev-month" title="ماه قبل">‹</button>
        <div class="cmc-dp-title">
          <span class="cmc-dp-month-name">${MONTHS[jm - 1]}</span>
          <span class="cmc-dp-year">${toPersian(jy)}</span>
        </div>
        <button class="cmc-dp-nav" data-nav="next-month" title="ماه بعد">›</button>
        <button class="cmc-dp-nav" data-nav="next-year" title="سال بعد">»</button>
      </div>

      <table class="cmc-dp-calendar">
        <thead>
          <tr>${WEEK_DAYS.map((d) => `<th>${d}</th>`).join("")}</tr>
        </thead>
        <tbody>${calRows}</tbody>
      </table>

      <div class="cmc-dp-time">
        <div class="cmc-dp-time-label"><i class="ti ti-clock"></i> ساعت</div>
        <div class="cmc-dp-time-controls">
          <div class="cmc-dp-time-field">
            <button class="cmc-dp-time-btn" data-time="hour-up">+</button>
            <span class="cmc-dp-time-val" id="cmc-dp-hour">${toPersian(
              String(hour).padStart(2, "0")
            )}</span>
            <button class="cmc-dp-time-btn" data-time="hour-down">−</button>
          </div>
          <span class="cmc-dp-time-sep">:</span>
          <div class="cmc-dp-time-field">
            <button class="cmc-dp-time-btn" data-time="min-up">+</button>
            <span class="cmc-dp-time-val" id="cmc-dp-minute">${toPersian(
              String(minute).padStart(2, "0")
            )}</span>
            <button class="cmc-dp-time-btn" data-time="min-down">−</button>
          </div>
        </div>
      </div>

      <div class="cmc-dp-footer">
        <button class="cmc-dp-btn-confirm cmc-btn cmc-btn--primary cmc-btn--sm">تأیید</button>
        <button class="cmc-dp-btn-clear cmc-btn cmc-btn--ghost cmc-btn--sm">پاک کردن</button>
      </div>
    `;

    // Event: navigation
    activePopup.querySelectorAll("[data-nav]").forEach((btn) => {
      btn.addEventListener("click", (e) => {
        e.stopPropagation();
        const nav = btn.dataset.nav;
        if (nav === "prev-month") {
          pickerState.jm--;
          if (pickerState.jm < 1) {
            pickerState.jm = 12;
            pickerState.jy--;
          }
        } else if (nav === "next-month") {
          pickerState.jm++;
          if (pickerState.jm > 12) {
            pickerState.jm = 1;
            pickerState.jy++;
          }
        } else if (nav === "prev-year") {
          pickerState.jy--;
        } else if (nav === "next-year") {
          pickerState.jy++;
        }
        renderPickerPopup();
      });
    });

    // Event: day click
    activePopup
      .querySelectorAll(".cmc-dp-day:not(.cmc-dp-day--other)")
      .forEach((cell) => {
        cell.addEventListener("click", (e) => {
          e.stopPropagation();
          pickerState.jd = parseInt(cell.dataset.day);
          renderPickerPopup();
        });
      });

    // Event: time buttons
    activePopup.querySelectorAll("[data-time]").forEach((btn) => {
      btn.addEventListener("click", (e) => {
        e.stopPropagation();
        const t = btn.dataset.time;
        if (t === "hour-up") pickerState.hour = (pickerState.hour + 1) % 24;
        if (t === "hour-down")
          pickerState.hour = (pickerState.hour - 1 + 24) % 24;
        if (t === "min-up") pickerState.minute = (pickerState.minute + 5) % 60;
        if (t === "min-down")
          pickerState.minute = (pickerState.minute - 5 + 60) % 60;
        document.getElementById("cmc-dp-hour").textContent = toPersian(
          String(pickerState.hour).padStart(2, "0")
        );
        document.getElementById("cmc-dp-minute").textContent = toPersian(
          String(pickerState.minute).padStart(2, "0")
        );
      });
    });

    // Event: confirm
    activePopup
      .querySelector(".cmc-dp-btn-confirm")
      ?.addEventListener("click", (e) => {
        e.stopPropagation();
        const { jy, jm, jd, hour, minute, targetInput, hiddenId } = pickerState;
        const [gy, gm, gd] = jalaliToGregorian(jy, jm, jd);

        // Formatted ISO for hidden input (used in form submission)
        const isoStr = `${gy}-${String(gm).padStart(2, "0")}-${String(
          gd
        ).padStart(2, "0")}T${String(hour).padStart(2, "0")}:${String(
          minute
        ).padStart(2, "0")}`;

        // Persian display string for visible input
        const persianStr = `${toPersian(jy)}/${toPersian(
          String(jm).padStart(2, "0")
        )}/${toPersian(String(jd).padStart(2, "0"))}  ${toPersian(
          String(hour).padStart(2, "0")
        )}:${toPersian(String(minute).padStart(2, "0"))}`;

        targetInput.value = persianStr;
        const hiddenEl = document.getElementById(hiddenId);
        if (hiddenEl) hiddenEl.value = isoStr;

        closePicker();
      });

    // Event: clear
    activePopup
      .querySelector(".cmc-dp-btn-clear")
      ?.addEventListener("click", (e) => {
        e.stopPropagation();
        const { targetInput, hiddenId } = pickerState;
        targetInput.value = "";
        const hiddenEl = document.getElementById(hiddenId);
        if (hiddenEl) hiddenEl.value = "";
        closePicker();
      });
  }

  function positionPopup(inputEl, popup) {
    const rect = inputEl.getBoundingClientRect();
    const top = rect.bottom + window.scrollY + 6;
    const left = rect.right - 300 + window.scrollX; // align right edge
    popup.style.top = `${Math.max(top, 10)}px`;
    popup.style.left = `${Math.max(left, 10)}px`;
  }

  // Set value programmatically (used by loadEditData)
  function setValue(hiddenId, isoVal) {
    const hiddenEl = document.getElementById(hiddenId);
    if (!hiddenEl) return;

    const displayId = hiddenEl.dataset.cmcDatepickerDisplay;
    const displayEl = displayId ? document.getElementById(displayId) : null;

    if (isoVal) {
      hiddenEl.value = isoVal;
      if (displayEl) {
        const d = new Date(isoVal);
        const [jy, jm, jd] = gregorianToJalaliAccurate(
          d.getFullYear(),
          d.getMonth() + 1,
          d.getDate()
        );
        const h = d.getHours();
        const min = d.getMinutes();
        displayEl.value = `${toPersian(jy)}/${toPersian(
          String(jm).padStart(2, "0")
        )}/${toPersian(String(jd).padStart(2, "0"))}  ${toPersian(
          String(h).padStart(2, "0")
        )}:${toPersian(String(min).padStart(2, "0"))}`;
      }
    } else {
      hiddenEl.value = "";
      if (displayEl) displayEl.value = "";
    }
  }

  return { init, setValue };
})();
