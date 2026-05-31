/**
 * CMC Campaigns Module — JavaScript
 * Version: 1.0.0
 *
 * Handles:
 *  1. Campaign list: delete with confirm
 *  2. Form: type/discount type toggles
 *  3. Product picker: search (debounced), load more
 *  4. Group picker: category / tag / attribute chips
 *  5. Selected products management
 *  6. Form summary live update
 *  7. Save (create/update) via AJAX
 */

"use strict";

(function () {

    // ============================================================
    // STATE
    // ============================================================

    const state = {
        selectionMode   : "manual",
        selectedProducts: [],        // [{ id, name, price, thumb }]
        selectedCategories: [],      // [termId, ...]
        selectedTags    : [],
        selectedAttributes: [],      // [{taxonomy, term_id}]
        pickerData      : null,      // loaded once from server
        searchPage      : 1,
        searchQuery     : "",
        isSearching     : false,
    };

    // ============================================================
    // HELPERS
    // ============================================================

    const $ = id => document.getElementById(id);
    const ajaxUrl = window.CMC_DATA?.ajaxUrl ?? "/wp-admin/admin-ajax.php";
    const nonce   = window.CMC_DATA?.nonce   ?? "";

    async function apiFetch(action, params = {}, method = "GET") {
        const url = new URL(ajaxUrl);

        if (method === "GET") {
            url.searchParams.set("action", action);
            url.searchParams.set("nonce",  nonce);
            for (const [k, v] of Object.entries(params)) {
                url.searchParams.set(k, v);
            }
            const res = await fetch(url.toString(), { credentials: "same-origin" });
            return res.json();
        }

        const body = new FormData();
        body.append("action", action);
        body.append("nonce",  nonce);
        for (const [k, v] of Object.entries(params)) {
            body.append(k, typeof v === "object" ? JSON.stringify(v) : String(v));
        }
        const res = await fetch(ajaxUrl, { method: "POST", body, credentials: "same-origin" });
        return res.json();
    }

    function debounce(fn, ms) {
        let t;
        return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
    }

    function showToast(msg, type = "success") {
        if (window.CMC?.toast) {
            CMC.toast(msg, type);
        }
    }

    // ============================================================
    // 1. CAMPAIGN LIST — DELETE
    // ============================================================

    function initList() {
        document.querySelectorAll(".cmc-delete-campaign").forEach(btn => {
            btn.addEventListener("click", async () => {
                const id    = btn.dataset.id;
                const title = btn.dataset.title;

                if (!confirm(`کمپین "${title}" حذف شود؟\nاین عمل قابل بازگشت نیست.`)) return;

                btn.disabled = true;

                try {
                    const res = await apiFetch("cmc_delete_campaign", { id }, "POST");
                    if (res.success) {
                        // Remove row from table with fade
                        const row = btn.closest("tr");
                        row.style.transition = "opacity 300ms";
                        row.style.opacity    = "0";
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
            });
        });
    }

    // ============================================================
    // 2. FORM TOGGLES
    // ============================================================

    function initFormToggles() {
        // Campaign type toggle
        document.querySelectorAll(".cmc-type-btn").forEach(btn => {
            btn.addEventListener("click", () => {
                document.querySelectorAll(".cmc-type-btn").forEach(b => b.classList.remove("is-active"));
                btn.classList.add("is-active");
                $("cmc-field-type").value = btn.dataset.value;
                updateSummary();
            });
        });

        // Discount type toggle
        document.querySelectorAll(".cmc-dt-btn").forEach(btn => {
            btn.addEventListener("click", () => {
                document.querySelectorAll(".cmc-dt-btn").forEach(b => b.classList.remove("is-active"));
                btn.classList.add("is-active");
                $("cmc-field-discount-type").value = btn.dataset.value;
                updateSummary();
            });
        });

        // Live discount input → update summary
        $("cmc-field-discount")?.addEventListener("input", updateSummary);
    }

    // ============================================================
    // 3. PICKER TABS
    // ============================================================

    function initPickerTabs() {
        document.querySelectorAll("#cmc-picker-tabs .cmc-tab").forEach(tab => {
            tab.addEventListener("click", () => {
                // Activate tab
                document.querySelectorAll("#cmc-picker-tabs .cmc-tab")
                    .forEach(t => t.classList.remove("is-active"));
                tab.classList.add("is-active");

                // Hide all panels
                document.querySelectorAll(".cmc-picker-panel")
                    .forEach(p => p.style.display = "none");

                // Show target panel
                const mode = tab.dataset.mode;
                state.selectionMode = mode;
                $(`cmc-panel-${mode}`).style.display = "block";
                $("cmc-field-selection-mode").value = mode;

                // Load picker data if needed
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
    // 4. PRODUCT SEARCH (debounced, paginated)
    // ============================================================

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
            // Show results if we already have them
            if ($("cmc-search-results").children.length) {
                $("cmc-search-results").style.display = "block";
            }
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
                search : state.searchQuery,
                page   : state.searchPage,
            });

            if (!data.success) throw new Error(data.message);

            if (reset) {
                resultsEl.innerHTML = "";
            } else {
                // Remove existing "load more" button before appending
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
                products.forEach(p => {
                    if (!resultsEl.querySelector(`[data-id="${p.id}"]`)) {
                        resultsEl.appendChild(buildProductItem(p));
                    }
                });
            }

            // Load more button
            if (data.data?.has_more) {
                const btn = document.createElement("button");
                btn.className   = "cmc-load-more";
                btn.textContent = "نمایش بیشتر...";
                btn.addEventListener("click", () => {
                    state.searchPage++;
                    runSearch(false);
                });
                resultsEl.appendChild(btn);
            }

            resultsEl.style.display = products.length || !reset ? "block" : "none";

        } catch (err) {
            showToast("خطا در جستجو", "danger");
        } finally {
            loadingEl.style.display = "none";
            state.isSearching = false;
        }
    }

    function buildProductItem(product) {
        const isSelected = state.selectedProducts.some(p => p.id === product.id);
        const item       = document.createElement("div");

        item.className       = `cmc-product-result-item${isSelected ? " is-selected" : ""}`;
        item.dataset.id      = product.id;
        item.innerHTML = `
            <img src="${product.thumb}" alt="${product.name}" loading="lazy">
            <div style="flex:1;min-width:0">
                <div class="cmc-product-result-item__name">${product.name}</div>
                <div class="cmc-product-result-item__meta">${product.sku ? "SKU: " + product.sku : product.type}</div>
            </div>
            <span class="cmc-product-result-item__price">${product.price}</span>
            <span class="cmc-product-result-item__check">
                ${isSelected ? '<i class="ti ti-check" style="font-size:11px"></i>' : ""}
            </span>
        `;

        item.addEventListener("click", () => toggleProduct(product, item));
        return item;
    }

    function toggleProduct(product, itemEl) {
        const idx = state.selectedProducts.findIndex(p => p.id === product.id);

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
    // 5. GROUP PICKER (categories / tags / attributes)
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

        if (mode === "category") {
            renderTermChips(
                "cmc-category-list",
                state.pickerData.categories,
                state.selectedCategories,
                (id, selected) => toggleTermSelection(id, state.selectedCategories, selected)
            );
        }

        if (mode === "tag") {
            renderTermChips(
                "cmc-tag-list",
                state.pickerData.tags,
                state.selectedTags,
                (id, selected) => toggleTermSelection(id, state.selectedTags, selected)
            );
        }

        if (mode === "attribute") {
            renderAttributeChips();
        }
    }

    function renderTermChips(containerId, terms, selectedIds, onToggle) {
        const container = $(containerId);
        if (!container) return;

        container.innerHTML = "";

        if (terms.length === 0) {
            container.innerHTML = `<span style="font-size:13px;color:var(--cmc-text-muted)">موردی یافت نشد</span>`;
            return;
        }

        terms.forEach(term => {
            const chip      = document.createElement("div");
            const isSelected = selectedIds.includes(term.id);
            chip.className  = `cmc-term-chip${isSelected ? " is-selected" : ""}`;
            chip.dataset.id = term.id;
            chip.innerHTML  = `
                ${term.name}
                ${term.count !== undefined ? `<span class="cmc-term-chip__count">(${term.count})</span>` : ""}
            `;
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

        state.pickerData.attributes.forEach(attr => {
            const group = document.createElement("div");
            group.className = "cmc-attr-group";
            group.innerHTML = `<div class="cmc-attr-group__label">${attr.label}</div>`;

            const chips = document.createElement("div");
            chips.className = "cmc-term-list";

            attr.terms.forEach(term => {
                const isSelected = state.selectedAttributes.some(
                    a => a.taxonomy === attr.taxonomy && a.term_id === term.id
                );
                const chip = document.createElement("div");
                chip.className = `cmc-term-chip${isSelected ? " is-selected" : ""}`;
                chip.textContent = term.name;

                chip.addEventListener("click", () => {
                    const nowSelected = chip.classList.toggle("is-selected");
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

    function toggleTermSelection(id, arr, selected) {
        const idx = arr.indexOf(id);
        if (selected && idx === -1) arr.push(id);
        if (!selected && idx !== -1) arr.splice(idx, 1);
    }

    // ============================================================
    // 6. SELECTED PRODUCTS PANEL
    // ============================================================

    function renderSelectedProducts() {
        const wrap    = $("cmc-selected-wrap");
        const list    = $("cmc-selected-list");
        const counter = $("cmc-selected-count");

        const count = state.selectedProducts.length;
        counter.textContent = count;
        wrap.style.display  = count > 0 ? "block" : "none";
        list.innerHTML      = "";

        state.selectedProducts.forEach(p => {
            const item = document.createElement("div");
            item.className = "cmc-selected-product";
            item.innerHTML = `
                <img src="${p.thumb}" alt="${p.name}" loading="lazy">
                <span class="cmc-selected-product__name">${p.name}</span>
                <span class="cmc-selected-product__price" style="font-size:11px;color:var(--cmc-primary-500);font-weight:600">${p.price}</span>
                <button class="cmc-selected-product__remove" data-id="${p.id}" title="حذف">
                    <i class="ti ti-x"></i>
                </button>
            `;
            item.querySelector(".cmc-selected-product__remove").addEventListener("click", () => {
                const idx = state.selectedProducts.findIndex(x => x.id === p.id);
                if (idx !== -1) state.selectedProducts.splice(idx, 1);

                // Also uncheck in results list if visible
                const resultItem = document.querySelector(`#cmc-search-results [data-id="${p.id}"]`);
                if (resultItem) {
                    resultItem.classList.remove("is-selected");
                    resultItem.querySelector(".cmc-product-result-item__check").innerHTML = "";
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
        const typeVal     = $("cmc-field-type")?.value;
        const discountVal = $("cmc-field-discount")?.value;
        const discType    = $("cmc-field-discount-type")?.value;

        const typeLabels = { flash_sale: "فلش سیل", amazing_offer: "پیشنهاد شگفت‌انگیز" };

        const sumType      = $("cmc-sum-type");
        const sumDiscount  = $("cmc-sum-discount");
        const sumProducts  = $("cmc-sum-products");

        if (sumType)     sumType.textContent     = typeLabels[typeVal] ?? "—";
        if (sumDiscount) sumDiscount.textContent  = discountVal
            ? discountVal + (discType === "percent" ? "٪" : " تومان")
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
            case "all":
                productSummary = "همه محصولات";
                break;
        }
        if (sumProducts) sumProducts.textContent = productSummary;
    }

    // ============================================================
    // 8. SAVE CAMPAIGN (AJAX)
    // ============================================================

    function initSaveButton() {
        const btn = $("cmc-btn-save");
        if (!btn) return;

        btn.addEventListener("click", async () => {
            const editId = parseInt(btn.dataset.editId, 10) || 0;
            const isEdit = editId > 0;

            // Collect form values
            const payload = {
                title          : $("cmc-field-title")?.value?.trim()    ?? "",
                description    : $("cmc-field-desc")?.value?.trim()     ?? "",
                type           : $("cmc-field-type")?.value             ?? "flash_sale",
                discount       : $("cmc-field-discount")?.value         ?? "0",
                discount_type  : $("cmc-field-discount-type")?.value    ?? "percent",
                starts_at      : $("cmc-field-starts-at")?.value        ?? "",
                ends_at        : $("cmc-field-ends-at")?.value          ?? "",
                status         : $("cmc-field-status")?.value           ?? "draft",
                selection_mode : state.selectionMode,
                product_ids    : JSON.stringify(state.selectedProducts.map(p => p.id)),
                category_ids   : JSON.stringify(state.selectedCategories),
                tag_ids        : JSON.stringify(state.selectedTags),
                attribute_rules: JSON.stringify(state.selectedAttributes),
            };

            if (isEdit) {
                payload.id = editId;
            }

            // UI: loading state
            btn.classList.add("is-loading");
            btn.disabled = true;
            hideFormError();

            try {
                const action = isEdit ? "cmc_update_campaign" : "cmc_create_campaign";
                const res    = await apiFetch(action, payload, "POST");

                if (res.success) {
                    showToast(res.message ?? "ذخیره شد", "success");
                    // Redirect to list after short delay
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
    // 9. DELETE FROM FORM
    // ============================================================

    function initDeleteButton() {
        const btn = $("cmc-btn-delete");
        if (!btn) return;

        btn.addEventListener("click", async () => {
            const id = parseInt(btn.dataset.id, 10);
            if (!confirm("این کمپین حذف شود؟ این عمل قابل بازگشت نیست.")) return;

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
        });
    }

    // ============================================================
    // 10. LOAD EDIT DATA
    // ============================================================

    async function loadEditData() {
        if (!window.CMC_FORM?.isEdit || !window.CMC_FORM?.editId) return;

        try {
            const res = await apiFetch("cmc_get_campaign", { id: window.CMC_FORM.editId });
            if (!res.success) return;

            const c = res.campaign;

            // Fill fields
            if ($("cmc-field-title"))         $("cmc-field-title").value         = c.title         ?? "";
            if ($("cmc-field-desc"))          $("cmc-field-desc").value          = c.description   ?? "";
            if ($("cmc-field-discount"))      $("cmc-field-discount").value      = c.discount       ?? "";
            if ($("cmc-field-starts-at"))     $("cmc-field-starts-at").value     = c.starts_at?.replace(" ", "T") ?? "";
            if ($("cmc-field-ends-at"))       $("cmc-field-ends-at").value       = c.ends_at?.replace(" ", "T")   ?? "";
            if ($("cmc-field-status"))        $("cmc-field-status").value        = c.status         ?? "draft";
            if ($("cmc-field-type"))          $("cmc-field-type").value          = c.type           ?? "flash_sale";
            if ($("cmc-field-discount-type")) $("cmc-field-discount-type").value = c.discount_type  ?? "percent";

            // Activate type button
            document.querySelectorAll(".cmc-type-btn").forEach(b => {
                b.classList.toggle("is-active", b.dataset.value === c.type);
            });

            // Activate discount type button
            document.querySelectorAll(".cmc-dt-btn").forEach(b => {
                b.classList.toggle("is-active", b.dataset.value === c.discount_type);
            });

            // Load selected products
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
        const isList = !document.getElementById("cmc-btn-save");
        const isForm = !!document.getElementById("cmc-btn-save");

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
        }
    });

})();