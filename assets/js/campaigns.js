/**
 * Campaignchi — campaigns.js
 *
 * Handles the campaign list page (delete) and the campaign create/edit form.
 *
 * Section 10 — Scheduling UX:
 *  - amazing_offer: the "زمان‌بندی شده" option is hidden/disabled in the
 *    status dropdown because amazing_offer campaigns have no date concept.
 *    Enforced server-side in CampaignService::applyStatusRules() as well.
 *
 *  - flash_sale + future starts_at: when the admin picks a starts_at that
 *    has not yet arrived, a visual hint appears showing that the server will
 *    automatically save the status as "scheduled" rather than "active".
 *    Actual enforcement happens server-side; this is UI feedback only.
 *
 *  - Cron auto-transition: processAutoTransitions() on the server runs every
 *    5 minutes and flips scheduled → active and active/scheduled → ended|draft.
 *    No client-side polling is needed.
 */

"use strict";

(function () {
  // ============================================================
  // STATE
  // ============================================================

  /** Shared mutable state for the create/edit form. */
  const state = {
    selectionMode:      "manual",
    selectedProducts:   [],
    selectedCategories: [],
    selectedTags:       [],
    selectedAttributes: [],
    selectedBrands:     [],
    pickerData:         null,
    searchPage:         1,
    searchQuery:        "",
    isSearching:        false,
  };

  // ============================================================
  // HELPERS
  // ============================================================

  /** Shorthand for document.getElementById. */
  const $ = (id) => document.getElementById(id);

  /**
   * Perform an AJAX request to WordPress admin-ajax.php.
   *
   * @param {string} action  WP AJAX action name.
   * @param {Object} params  Extra key/value parameters.
   * @param {string} method  'GET' or 'POST'.
   * @returns {Promise<Object>} Parsed JSON response.
   */
  async function apiFetch(action, params = {}, method = "GET") {
    const ajaxUrl = window.CMC_DATA?.ajaxUrl ?? "/wp-admin/admin-ajax.php";
    const nonce   = window.CMC_DATA?.nonce   ?? "";

    if (method === "GET") {
      const url = new URL(ajaxUrl, location.href);
      url.searchParams.set("action", action);
      url.searchParams.set("nonce",  nonce);
      for (const [k, v] of Object.entries(params)) {
        url.searchParams.set(k, String(v));
      }
      const res = await fetch(url.toString(), { credentials: "same-origin" });
      return res.json();
    }

    // POST — use FormData for compatibility with wp_verify_nonce.
    const body = new FormData();
    body.append("action", action);
    body.append("nonce",  nonce);
    for (const [k, v] of Object.entries(params)) {
      body.append(k, String(v));
    }
    const res = await fetch(ajaxUrl, { method: "POST", body, credentials: "same-origin" });
    return res.json();
  }

  /**
   * Return a debounced version of fn that fires after `ms` milliseconds
   * of inactivity.
   *
   * @param {Function} fn  Function to debounce.
   * @param {number}   ms  Delay in milliseconds.
   * @returns {Function}
   */
  function debounce(fn, ms) {
    let timer;
    return (...args) => {
      clearTimeout(timer);
      timer = setTimeout(() => fn(...args), ms);
    };
  }

  /** Persian labels for each selection mode (used in the summary widget). */
  const modeLabels = {
    manual:    "انتخاب دستی",
    category:  "دسته‌بندی",
    tag:       "برچسب",
    attribute: "ویژگی",
    brand:     "برند",
    all:       "همه محصولات",
  };

  /**
   * Show a toast notification via the global CMC.toast helper.
   * Falls back to a deferred call if CMC is not yet ready.
   *
   * @param {string} msg   Message to display.
   * @param {string} type  Toast type: 'success' | 'danger' | 'info'.
   */
  function showToast(msg, type = "success") {
    if (typeof CMC !== "undefined" && typeof CMC.toast === "function") {
      CMC.toast(msg, type);
    } else {
      setTimeout(() => { if (typeof CMC !== "undefined") CMC.toast(msg, type); }, 500);
    }
  }

  /**
   * Show a confirmation modal via the global CMC.confirm helper.
   *
   * @param {Object} opts  Options passed directly to CMC.confirm.
   */
  function showConfirm(opts) {
    if (typeof CMC !== "undefined" && typeof CMC.confirm === "function") {
      CMC.confirm(opts);
    }
  }

  // ============================================================
  // 1. CAMPAIGN LIST — DELETE
  // ============================================================

  /**
   * Attach click handlers to all delete buttons in the campaign table.
   * Shows a confirmation modal before sending the delete request.
   */
  function initList() {
    document.querySelectorAll(".cmc-delete-campaign").forEach((btn) => {
      btn.addEventListener("click", () => {
        const id    = btn.dataset.id;
        const title = btn.dataset.title;

        showConfirm({
          title:     "حذف کمپین",
          body:      `کمپین «${title}» حذف شود؟`,
          sub:       "تمام داده‌ها، محصولات و آمار این کمپین پاک می‌شوند.",
          okLabel:   "بله، حذف کن",
          okClass:   "cmc-btn--danger",
          onConfirm: async () => {
            btn.disabled = true;
            try {
              const res = await apiFetch("cmc_delete_campaign", { id }, "POST");
              if (res.success) {
                // Animate row out before removing it from the DOM.
                const row = btn.closest("tr");
                row.style.transition = "opacity 300ms, transform 300ms";
                row.style.opacity    = "0";
                row.style.transform  = "translateX(20px)";
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

  /**
   * Show/hide the schedule card and manage the status dropdown based on
   * the currently selected campaign type.
   *
   * Rules:
   *   amazing_offer:
   *     - Hides the schedule date card (no dates needed).
   *     - Clears any existing date values.
   *     - Disables and hides the "زمان‌بندی شده" status option.
   *     - If "زمان‌بندی شده" was selected, falls back to "active".
   *     - Hides the scheduled-status hint banner.
   *
   *   flash_sale:
   *     - Shows the schedule date card.
   *     - Re-enables the "زمان‌بندی شده" status option.
   *     - Re-evaluates the scheduling hint (in case starts_at is set).
   *
   * @param {string} type  'flash_sale' | 'amazing_offer'
   */
  function updateScheduleVisibility(type) {
    const card = $("cmc-schedule-card");
    if (card) {
      card.style.display = type === "amazing_offer" ? "none" : "";

      if (type === "amazing_offer") {
        // Clear date picker values so they are not submitted.
        const startsHidden  = $("cmc-field-starts-at");
        const endsHidden    = $("cmc-field-ends-at");
        const startsDisplay = $("cmc-field-starts-at-display");
        const endsDisplay   = $("cmc-field-ends-at-display");
        if (startsHidden)  startsHidden.value  = "";
        if (endsHidden)    endsHidden.value    = "";
        if (startsDisplay) startsDisplay.value = "";
        if (endsDisplay)   endsDisplay.value   = "";
      }
    }

    const statusSelect = $("cmc-field-status");
    if (!statusSelect) return;

    const scheduledOption = statusSelect.querySelector('option[value="scheduled"]');

    if (type === "amazing_offer") {
      // Disable + visually hide the scheduled option.
      if (scheduledOption) {
        scheduledOption.style.display = "none";
        scheduledOption.disabled      = true;
      }
      // If scheduled was previously selected, switch to active.
      if (statusSelect.value === "scheduled") {
        statusSelect.value = "active";
      }
      // Hide the scheduling hint — not relevant for amazing_offer.
      setScheduledHintVisible(false);
    } else {
      // flash_sale — restore the scheduled option.
      if (scheduledOption) {
        scheduledOption.style.display = "";
        scheduledOption.disabled      = false;
      }
      // Re-evaluate whether the hint should be visible based on current dates.
      updateScheduledHint();
    }
  }

  /**
   * Show or hide the "this campaign will be saved as scheduled" hint banner.
   *
   * The hint is shown when:
   *   - Campaign type is flash_sale.
   *   - Status dropdown shows "active".
   *   - starts_at is set and is in the future.
   *
   * This is purely a UI hint. The server enforces the actual rule in
   * CampaignService::applyStatusRules().
   *
   * The hint element must have id="cmc-scheduled-hint" in the PHP template.
   * If it does not exist, this function is a no-op.
   */
  function updateScheduledHint() {
    const hint        = $("cmc-scheduled-hint");
    const statusVal   = $("cmc-field-status")?.value;
    const typeVal     = $("cmc-field-type")?.value;
    const startsAtVal = $("cmc-field-starts-at")?.value;

    if (!hint) return;

    // Hint only applies to flash_sale campaigns that the admin wants to "activate".
    const shouldShow =
      typeVal     === "flash_sale" &&
      statusVal   === "active"    &&
      startsAtVal &&
      new Date(startsAtVal.replace(" ", "T")) > new Date();

    setScheduledHintVisible(shouldShow);
  }

  /**
   * Toggle the visibility of the scheduled-status hint banner.
   *
   * @param {boolean} visible  True to show, false to hide.
   */
  function setScheduledHintVisible(visible) {
    const hint = $("cmc-scheduled-hint");
    if (hint) {
      hint.style.display = visible ? "flex" : "none";
    }
  }

  /**
   * Wire up all type-toggle, discount-type, and discount-input interactions.
   * Also runs the initial state sync based on the current field values.
   */
  function initFormToggles() {
    // Campaign type toggle buttons.
    document.querySelectorAll(".cmc-type-btn").forEach((btn) => {
      btn.addEventListener("click", () => {
        document.querySelectorAll(".cmc-type-btn").forEach((b) => b.classList.remove("is-active"));
        btn.classList.add("is-active");
        const f = $("cmc-field-type");
        if (f) f.value = btn.dataset.value;
        updateScheduleVisibility(btn.dataset.value);
        updateSummary();
      });
    });

    // Discount type toggle buttons (percent / fixed).
    document.querySelectorAll(".cmc-dt-btn").forEach((btn) => {
      btn.addEventListener("click", () => {
        document.querySelectorAll(".cmc-dt-btn").forEach((b) => b.classList.remove("is-active"));
        btn.classList.add("is-active");
        const f = $("cmc-field-discount-type");
        if (f) f.value = btn.dataset.value;
        updateSummary();
      });
    });

    // Discount value input.
    $("cmc-field-discount")?.addEventListener("input", updateSummary);

    // Status select — update the hint when the admin changes status manually.
    $("cmc-field-status")?.addEventListener("change", updateScheduledHint);

    // starts_at change — re-evaluate the scheduling hint.
    $("cmc-field-starts-at")?.addEventListener("change", updateScheduledHint);

    // Apply the correct initial state for the pre-selected type.
    updateScheduleVisibility($("cmc-field-type")?.value ?? "flash_sale");
  }

  // ============================================================
  // 3. PICKER TABS
  // ============================================================

  /**
   * Wire up the product selection mode tabs (manual, category, tag, …).
   * Switches the visible panel and loads remote data if not yet cached.
   */
  function initPickerTabs() {
    document.querySelectorAll("#cmc-picker-tabs .cmc-tab").forEach((tab) => {
      tab.addEventListener("click", () => {
        document.querySelectorAll("#cmc-picker-tabs .cmc-tab").forEach((t) => t.classList.remove("is-active"));
        tab.classList.add("is-active");
        document.querySelectorAll(".cmc-picker-panel").forEach((p) => (p.style.display = "none"));

        const mode = tab.dataset.mode;
        state.selectionMode = mode;

        const panel = $(`cmc-panel-${mode}`);
        if (panel) panel.style.display = "block";

        const smf = $("cmc-field-selection-mode");
        if (smf) smf.value = mode;

        // Load remote taxonomy data on first switch (cached after that).
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

  /**
   * Attach an input listener to the manual product search box.
   * Searches are debounced to reduce AJAX calls.
   */
  function initProductSearch() {
    const input = $("cmc-product-search");
    if (!input) return;

    const handleSearch = debounce(async (query) => {
      state.searchQuery = query;
      state.searchPage  = 1;
      await runSearch(true);
    }, 320);

    input.addEventListener("input", () => handleSearch(input.value.trim()));
    input.addEventListener("focus", () => {
      const r = $("cmc-search-results");
      if (r && r.children.length) r.style.display = "block";
    });
  }

  /**
   * Execute a product search request and render the results.
   *
   * @param {boolean} reset  True to clear existing results before rendering.
   */
  async function runSearch(reset = false) {
    if (state.isSearching) return;
    state.isSearching = true;

    const resultsEl = $("cmc-search-results");
    const loadingEl = $("cmc-search-loading");

    if (loadingEl) loadingEl.style.display = "flex";
    if (resultsEl) resultsEl.style.display = "none";

    try {
      const data = await apiFetch("cmc_search_products", {
        search: state.searchQuery,
        page:   state.searchPage,
      });

      if (!data.success) throw new Error(data.message ?? "خطا");

      if (reset) {
        resultsEl.innerHTML = "";
      } else {
        resultsEl.querySelector(".cmc-load-more")?.remove();
      }

      const products = data.data?.products ?? [];

      if (products.length === 0 && reset) {
        resultsEl.innerHTML = `
          <div style="padding:20px;text-align:center;color:var(--cmc-text-muted);font-size:13px">
            <i class="ti ti-search-off" style="font-size:24px;display:block;margin-bottom:8px"></i>
            محصولی یافت نشد
          </div>`;
      } else {
        products.forEach((p) => {
          if (!resultsEl.querySelector(`[data-id="${p.id}"]`)) {
            resultsEl.appendChild(buildProductItem(p));
          }
        });
      }

      if (data.data?.has_more) {
        const moreBtn = document.createElement("button");
        moreBtn.className   = "cmc-load-more";
        moreBtn.textContent = "نمایش بیشتر...";
        moreBtn.addEventListener("click", () => {
          state.searchPage++;
          runSearch(false);
        });
        resultsEl.appendChild(moreBtn);
      }

      if (resultsEl) {
        resultsEl.style.display = products.length || !reset ? "block" : "none";
      }
    } catch {
      showToast("خطا در جستجو", "danger");
    } finally {
      if (loadingEl) loadingEl.style.display = "none";
      state.isSearching = false;
    }
  }

  /**
   * Build a product list item element for the search results panel.
   *
   * @param {Object} product  Product data from the server.
   * @returns {HTMLElement}
   */
  function buildProductItem(product) {
    const isSelected = state.selectedProducts.some((p) => p.id === product.id);
    const item       = document.createElement("div");

    item.className  = `cmc-product-result-item${isSelected ? " is-selected" : ""}`;
    item.dataset.id = product.id;
    item.innerHTML  = `
      <img src="${product.thumb}" alt="" loading="lazy">
      <div style="flex:1;min-width:0">
        <div class="cmc-product-result-item__name">${product.name}</div>
        <div class="cmc-product-result-item__meta">${product.sku ? "SKU: " + product.sku : product.type}</div>
      </div>
      <span class="cmc-product-result-item__price">${product.price}</span>
      <span class="cmc-product-result-item__check">
        ${isSelected ? '<i class="ti ti-check" style="font-size:11px"></i>' : ""}
      </span>`;

    item.addEventListener("click", () => toggleProduct(product, item));
    return item;
  }

  /**
   * Toggle a product's selection state in the manual picker.
   *
   * @param {Object}      product  Product data object.
   * @param {HTMLElement} itemEl   The list item DOM element.
   */
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
  // 5. GROUP PICKER (category / tag / brand / attribute)
  // ============================================================

  /**
   * Fetch taxonomy/attribute data from the server (once per page load).
   * Results are stored in state.pickerData and the current panel is re-rendered.
   */
  async function loadPickerData() {
    try {
      const res = await apiFetch("cmc_get_picker_data");
      if (res.success) {
        state.pickerData = res.data;
        renderPickerPanel(state.selectionMode);
      } else {
        showToast("خطا در بارگذاری داده‌ها", "danger");
      }
    } catch {
      showToast("خطا در اتصال به سرور", "danger");
    }
  }

  /**
   * Render the appropriate chip list for the active selection mode panel.
   *
   * @param {string} mode  The active selection mode key.
   */
  function renderPickerPanel(mode) {
    if (!state.pickerData) return;

    if (mode === "category") {
      renderTermChips(
        "cmc-category-list",
        state.pickerData.categories ?? [],
        state.selectedCategories,
        (id, sel) => toggleTerm(id, state.selectedCategories, sel)
      );
    }
    if (mode === "tag") {
      renderTermChips(
        "cmc-tag-list",
        state.pickerData.tags ?? [],
        state.selectedTags,
        (id, sel) => toggleTerm(id, state.selectedTags, sel)
      );
    }
    if (mode === "brand") {
      renderTermChips(
        "cmc-brand-list",
        state.pickerData.brands ?? [],
        state.selectedBrands,
        (id, sel) => toggleTerm(id, state.selectedBrands, sel)
      );
    }
    if (mode === "attribute") {
      renderAttributeChips();
    }
  }

  /**
   * Render selectable term chips inside a container element.
   *
   * @param {string}   containerId  ID of the container element.
   * @param {Array}    terms        Array of {id, name, count?} objects.
   * @param {number[]} selectedIds  Currently selected term IDs.
   * @param {Function} onToggle     Callback(termId, isSelected).
   */
  function renderTermChips(containerId, terms, selectedIds, onToggle) {
    const container = $(containerId);
    if (!container) return;
    container.innerHTML = "";

    if (!terms || terms.length === 0) {
      container.innerHTML = `<span style="font-size:13px;color:var(--cmc-text-muted);padding:8px 0;display:block">موردی یافت نشد</span>`;
      return;
    }

    terms.forEach((term) => {
      const isSelected = selectedIds.includes(term.id);
      const chip       = document.createElement("div");
      chip.className   = `cmc-term-chip${isSelected ? " is-selected" : ""}`;
      chip.dataset.id  = term.id;
      chip.innerHTML   = `${term.name}${term.count !== undefined ? `<span class="cmc-term-chip__count">(${term.count})</span>` : ""}`;

      chip.addEventListener("click", () => {
        const nowSelected = chip.classList.toggle("is-selected");
        onToggle(term.id, nowSelected);
        updateSummary();
      });

      container.appendChild(chip);
    });
  }

  /**
   * Render attribute term chips grouped by attribute taxonomy.
   */
  function renderAttributeChips() {
    const container = $("cmc-attribute-list");
    if (!container || !state.pickerData) return;
    container.innerHTML = "";

    (state.pickerData.attributes ?? []).forEach((attr) => {
      const group    = document.createElement("div");
      group.className = "cmc-attr-group";
      group.innerHTML = `<div class="cmc-attr-group__label">${attr.label}</div>`;

      const chips     = document.createElement("div");
      chips.className = "cmc-term-list";

      attr.terms.forEach((term) => {
        const isSelected = state.selectedAttributes.some(
          (a) => a.taxonomy === attr.taxonomy && a.term_id === term.id
        );
        const chip       = document.createElement("div");
        chip.className   = `cmc-term-chip${isSelected ? " is-selected" : ""}`;
        chip.textContent = term.name;

        chip.addEventListener("click", () => {
          const nowSelected = chip.classList.toggle("is-selected");
          const idx = state.selectedAttributes.findIndex(
            (a) => a.taxonomy === attr.taxonomy && a.term_id === term.id
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

  /**
   * Add or remove a term ID from a selection array.
   *
   * @param {number}   id        Term ID to toggle.
   * @param {number[]} arr       The array to modify in place.
   * @param {boolean}  selected  True to add, false to remove.
   */
  function toggleTerm(id, arr, selected) {
    const idx = arr.indexOf(id);
    if (selected && idx === -1)  arr.push(id);
    if (!selected && idx !== -1) arr.splice(idx, 1);
  }

  // ============================================================
  // 6. SELECTED PRODUCTS
  // ============================================================

  /**
   * Re-render the list of currently selected products in the sidebar.
   * Shows/hides the wrapper div based on selection count.
   */
  function renderSelectedProducts() {
    const wrap    = $("cmc-selected-wrap");
    const list    = $("cmc-selected-list");
    const counter = $("cmc-selected-count");
    const count   = state.selectedProducts.length;

    if (counter) counter.textContent = count;
    if (wrap)    wrap.style.display  = count > 0 ? "block" : "none";
    if (!list)   return;

    list.innerHTML = "";

    state.selectedProducts.forEach((p) => {
      const item      = document.createElement("div");
      item.className  = "cmc-selected-product";
      item.innerHTML  = `
        <img src="${p.thumb}" alt="" loading="lazy">
        <span class="cmc-selected-product__name">${p.name}</span>
        <span style="font-size:11px;color:var(--cmc-primary-500);font-weight:600">${p.price}</span>
        <button class="cmc-selected-product__remove" data-id="${p.id}" title="حذف">
          <i class="ti ti-x"></i>
        </button>`;

      // Remove button deselects the product from both lists.
      item.querySelector(".cmc-selected-product__remove").addEventListener("click", () => {
        const idx = state.selectedProducts.findIndex((x) => x.id === p.id);
        if (idx !== -1) state.selectedProducts.splice(idx, 1);

        // Uncheck the item in the search results panel if it is visible.
        const resultItem = document.querySelector(`#cmc-search-results [data-id="${p.id}"]`);
        if (resultItem) {
          resultItem.classList.remove("is-selected");
          const check = resultItem.querySelector(".cmc-product-result-item__check");
          if (check) check.innerHTML = "";
        }

        renderSelectedProducts();
        updateSummary();
      });

      list.appendChild(item);
    });
  }

  // ============================================================
  // 7. SUMMARY WIDGET
  // ============================================================

  /**
   * Refresh the sidebar "summary" widget with the current field values.
   * Also re-evaluates the scheduled-status hint whenever called.
   */
  function updateSummary() {
    const typeVal  = $("cmc-field-type")?.value;
    const discVal  = $("cmc-field-discount")?.value;
    const discType = $("cmc-field-discount-type")?.value;

    const typeLabels = {
      flash_sale:    "فلش سیل",
      amazing_offer: "پیشنهاد شگفت‌انگیز",
    };

    const sumType     = $("cmc-sum-type");
    const sumDiscount = $("cmc-sum-discount");
    const sumProducts = $("cmc-sum-products");

    if (sumType)     sumType.textContent     = typeLabels[typeVal] ?? "—";
    if (sumDiscount) sumDiscount.textContent = discVal
      ? discVal + (discType === "percent" ? "٪" : " تومان")
      : "—";

    let ps = "—";
    switch (state.selectionMode) {
      case "manual":    ps = state.selectedProducts.length   ? `${state.selectedProducts.length} محصول`     : "انتخاب نشده"; break;
      case "category":  ps = state.selectedCategories.length ? `${state.selectedCategories.length} دسته‌بندی` : "انتخاب نشده"; break;
      case "tag":       ps = state.selectedTags.length       ? `${state.selectedTags.length} برچسب`          : "انتخاب نشده"; break;
      case "attribute": ps = state.selectedAttributes.length ? `${state.selectedAttributes.length} ویژگی`    : "انتخاب نشده"; break;
      case "brand":     ps = state.selectedBrands.length     ? `${state.selectedBrands.length} برند`          : "انتخاب نشده"; break;
      case "all":       ps = "همه محصولات";                                                                                    break;
    }
    if (sumProducts) sumProducts.textContent = ps;

    // Re-evaluate the scheduling hint on every summary update.
    updateScheduledHint();
  }

  // ============================================================
  // 8. SAVE CAMPAIGN
  // ============================================================

  /**
   * Attach the click handler to the Save button.
   * Collects all field values, runs client-side validation, and POSTs to AJAX.
   */
  function initSaveButton() {
    const btn = $("cmc-btn-save");
    if (!btn) return;

    btn.addEventListener("click", async () => {
      const editId = parseInt(btn.dataset.editId, 10) || 0;
      const isEdit = editId > 0;

      const payload = {
        title:           ($("cmc-field-title")?.value  ?? "").trim(),
        description:     ($("cmc-field-desc")?.value   ?? "").trim(),
        type:            $("cmc-field-type")?.value     ?? "flash_sale",
        discount:        $("cmc-field-discount")?.value ?? "0",
        discount_type:   $("cmc-field-discount-type")?.value ?? "percent",
        starts_at:       $("cmc-field-starts-at")?.value ?? "",
        ends_at:         $("cmc-field-ends-at")?.value   ?? "",
        status:          $("cmc-field-status")?.value    ?? "draft",
        selection_mode:  state.selectionMode,
        product_ids:     JSON.stringify(state.selectedProducts.map((p) => p.id)),
        category_ids:    JSON.stringify(state.selectedCategories),
        tag_ids:         JSON.stringify(state.selectedTags),
        attribute_rules: JSON.stringify(state.selectedAttributes),
        brand_ids:       JSON.stringify(state.selectedBrands),
      };

      // Client-side validation — mirrors server-side rules for fast feedback.
      if (!payload.title) {
        showFormError("عنوان کمپین الزامی است");
        return;
      }
      if (!payload.discount || parseFloat(payload.discount) <= 0) {
        showFormError("مقدار تخفیف باید بزرگتر از صفر باشد");
        return;
      }

      if (isEdit) payload.id = editId;

      btn.classList.add("is-loading");
      btn.disabled = true;
      hideFormError();

      try {
        const action = isEdit ? "cmc_update_campaign" : "cmc_create_campaign";
        const res    = await apiFetch(action, payload, "POST");

        if (res.success) {
          showToast(res.message ?? "ذخیره شد", "success");
          // Short delay so the user can read the toast before redirect.
          setTimeout(() => { window.location.href = window.CMC_FORM?.backUrl ?? "#"; }, 900);
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
  // 9. DELETE FROM FORM
  // ============================================================

  /**
   * Attach the click handler to the Delete button inside the edit form.
   * Shows a confirmation modal before sending the delete request.
   */
  function initDeleteButton() {
    const btn = $("cmc-btn-delete");
    if (!btn) return;

    btn.addEventListener("click", () => {
      const id = parseInt(btn.dataset.id, 10);

      showConfirm({
        title:     "حذف کمپین",
        body:      "این کمپین به طور کامل حذف شود؟",
        sub:       "تمام داده‌ها، محصولات و آمار پاک می‌شوند. این عمل قابل بازگشت نیست.",
        okLabel:   "بله، حذف کن",
        okClass:   "cmc-btn--danger",
        onConfirm: async () => {
          btn.disabled = true;
          try {
            const res = await apiFetch("cmc_delete_campaign", { id }, "POST");
            if (res.success) {
              showToast("کمپین حذف شد", "success");
              setTimeout(() => { window.location.href = window.CMC_FORM?.backUrl ?? "#"; }, 900);
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

  /**
   * When the form is in edit mode, load the existing campaign data via AJAX
   * and populate all form fields, pickers, and toggles.
   *
   * Section 10 fix: updateScheduleVisibility() is called AFTER all field
   * values (including status) have been set, so the scheduling rules and
   * hint are evaluated against the correct loaded state.
   */
  async function loadEditData() {
    if (!window.CMC_FORM?.isEdit || !window.CMC_FORM?.editId) return;

    try {
      const res = await apiFetch("cmc_get_campaign", { id: window.CMC_FORM.editId });
      if (!res.success) { showToast("خطا در بارگذاری", "danger"); return; }

      const c = res.campaign;

      // Populate text/number fields.
      if ($("cmc-field-title"))         $("cmc-field-title").value         = c.title         ?? "";
      if ($("cmc-field-desc"))          $("cmc-field-desc").value          = c.description   ?? "";
      if ($("cmc-field-discount"))      $("cmc-field-discount").value      = c.discount       ?? "";
      if ($("cmc-field-type"))          $("cmc-field-type").value          = c.type           ?? "flash_sale";
      if ($("cmc-field-discount-type")) $("cmc-field-discount-type").value = c.discount_type  ?? "percent";

      // Set status BEFORE calling updateScheduleVisibility so the
      // visibility rules (and the scheduled-hint check) see the real value.
      if ($("cmc-field-status"))        $("cmc-field-status").value        = c.status         ?? "draft";

      // Populate date pickers.
      if (c.starts_at && typeof CMCDatePicker !== "undefined") {
        CMCDatePicker.setValue("cmc-field-starts-at", c.starts_at.replace(" ", "T"));
      }
      if (c.ends_at && typeof CMCDatePicker !== "undefined") {
        CMCDatePicker.setValue("cmc-field-ends-at", c.ends_at.replace(" ", "T"));
      }

      // Sync visual toggle buttons to the loaded values.
      document.querySelectorAll(".cmc-type-btn").forEach(
        (b) => b.classList.toggle("is-active", b.dataset.value === c.type)
      );
      document.querySelectorAll(".cmc-dt-btn").forEach(
        (b) => b.classList.toggle("is-active", b.dataset.value === c.discount_type)
      );

      // Apply visibility + scheduling hint rules for the loaded type.
      // Called AFTER status and starts_at are already set.
      updateScheduleVisibility(c.type ?? "flash_sale");

      // Restore selected products (manual mode).
      if (res.products?.length) {
        state.selectedProducts = res.products;
        renderSelectedProducts();
      }

      // Restore selection mode and rule sets.
      const rules = res.rules ?? {};
      state.selectedCategories = (rules.category_ids   ?? []).map(Number);
      state.selectedTags       = (rules.tag_ids         ?? []).map(Number);
      state.selectedBrands     = (rules.brand_ids       ?? []).map(Number);
      state.selectedAttributes = rules.attribute_rules  ?? [];

      const mode = c.selection_mode || "manual";
      state.selectionMode = mode;

      const smf = $("cmc-field-selection-mode");
      if (smf) smf.value = mode;

      // Update the "current mode" badge.
      const modeBadge = $("cmc-products-mode-badge");
      if (modeBadge) {
        modeBadge.textContent   = `فعلی: ${modeLabels[mode] ?? mode}`;
        modeBadge.style.display = "inline-flex";
      }

      // Activate the correct picker tab.
      document.querySelectorAll("#cmc-picker-tabs .cmc-tab").forEach(
        (tab) => tab.classList.toggle("is-active", tab.dataset.mode === mode)
      );
      document.querySelectorAll(".cmc-picker-panel").forEach(
        (p) => (p.style.display = p.id === `cmc-panel-${mode}` ? "block" : "none")
      );

      // Load taxonomy data if the restored mode needs it.
      if (mode !== "manual" && mode !== "all") {
        await loadPickerData();
      }

      updateSummary();
    } catch {
      showToast("خطا در بارگذاری اطلاعات", "danger");
    }
  }

  // ============================================================
  // FORM ERROR HELPERS
  // ============================================================

  /**
   * Display an inline error message inside the form error banner.
   *
   * @param {string} msg  Error message to display.
   */
  function showFormError(msg) {
    const el = $("cmc-form-error");
    const tx = $("cmc-form-error-text");
    if (el) el.style.display = "flex";
    if (tx) tx.textContent   = msg;
    el?.scrollIntoView({ behavior: "smooth", block: "nearest" });
  }

  /**
   * Hide the inline form error banner.
   */
  function hideFormError() {
    const el = $("cmc-form-error");
    if (el) el.style.display = "none";
  }

  // ============================================================
  // INIT
  // ============================================================

  document.addEventListener("DOMContentLoaded", () => {
    // Detect which page we are on based on the presence of the save button.
    const isForm = !!$("cmc-btn-save");

    if (!isForm) {
      // Campaign list page.
      initList();
    } else {
      // Campaign create/edit form.
      initFormToggles();
      initPickerTabs();
      initProductSearch();
      initSaveButton();
      initDeleteButton();
      updateSummary();

      if (typeof CMCDatePicker !== "undefined") {
        CMCDatePicker.init();
      } else {
        console.warn("[CMC] CMCDatePicker not found — make sure datepicker.js loads before campaigns.js");
      }

      // Load existing campaign data if in edit mode.
      loadEditData();
    }
  });

})();