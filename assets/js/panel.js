/**
 * CMC Admin Panel — Core JavaScript
 * Version: 1.0.0
 *
 * Responsibilities:
 *  1. Mobile sidebar toggle (hamburger + backdrop)
 *  2. Toast notification utility
 *  3. Modal open/close utility
 *  4. Dropdown toggle
 *  5. Tabs
 *  6. AJAX helper (fetch + WP nonce)
 *  7. Confirm modal utility
 *  8. Color field sync (swatch + hex input)
 *
 * All identifiers prefixed with CMC to avoid conflicts with WP/WooCommerce.
 */

"use strict";

const CMC = (() => {
  // ----------------------------------------------------------
  // 1. MOBILE SIDEBAR TOGGLE
  // Hamburger button ↔ sidebar .is-open + backdrop .is-visible
  // ----------------------------------------------------------
  function initSidebar() {
    const hamburger = document.getElementById("cmc-hamburger");
    const sidebar = document.getElementById("cmc-sidebar");
    const backdrop = document.getElementById("cmc-backdrop");

    if (!hamburger || !sidebar) return;

    // Open / close sidebar
    hamburger.addEventListener("click", () => {
      const isOpen = sidebar.classList.contains("is-open");
      sidebar.classList.toggle("is-open", !isOpen);
      backdrop?.classList.toggle("is-visible", !isOpen);
      hamburger.setAttribute("aria-expanded", String(!isOpen));
    });

    // Close on backdrop click
    backdrop?.addEventListener("click", () => {
      sidebar.classList.remove("is-open");
      backdrop.classList.remove("is-visible");
      hamburger.setAttribute("aria-expanded", "false");
    });

    // Close sidebar on nav item click (mobile UX)
    sidebar.querySelectorAll(".cmc-nav__item").forEach((item) => {
      item.addEventListener("click", () => {
        if (window.innerWidth <= 768) {
          sidebar.classList.remove("is-open");
          backdrop?.classList.remove("is-visible");
          hamburger.setAttribute("aria-expanded", "false");
        }
      });
    });

    // Close on Escape key
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && sidebar.classList.contains("is-open")) {
        sidebar.classList.remove("is-open");
        backdrop?.classList.remove("is-visible");
        hamburger.setAttribute("aria-expanded", "false");
      }
    });
  }

  // ----------------------------------------------------------
  // 2. TOAST NOTIFICATIONS
  // Usage: CMC.toast("پیام", "success" | "danger" | "warning")
  // ----------------------------------------------------------
  function toast(message, type = "success") {
    const container = document.getElementById("cmc-toasts");
    if (!container) return;

    const icons = {
      success: "ti-check",
      danger: "ti-alert-circle",
      warning: "ti-alert-triangle",
    };

    const el = document.createElement("div");
    el.className = `cmc-toast cmc-toast--${type}`;
    el.innerHTML = `
            <i class="ti ${
              icons[type] ?? "ti-info-circle"
            } cmc-toast__icon"></i>
            <span class="cmc-toast__text">${message}</span>
        `;

    container.appendChild(el);

    // Trigger CSS entrance transition
    requestAnimationFrame(() => {
      requestAnimationFrame(() => el.classList.add("is-visible"));
    });

    // Auto dismiss after 3.5s
    setTimeout(() => {
      el.classList.remove("is-visible");
      setTimeout(() => el.remove(), 300);
    }, 3500);
  }

  // ----------------------------------------------------------
  // 3. MODAL UTILITY
  // Usage: CMC.modal.open("#cmc-my-modal")
  //        CMC.modal.close("#cmc-my-modal")
  // ----------------------------------------------------------
  const modal = {
    open(selector) {
      document.querySelector(selector)?.classList.add("is-open");
    },
    close(selector) {
      document.querySelector(selector)?.classList.remove("is-open");
    },
    init() {
      // Close on backdrop click
      document.querySelectorAll(".cmc-modal-overlay").forEach((overlay) => {
        overlay.addEventListener("click", (e) => {
          if (e.target === overlay) overlay.classList.remove("is-open");
        });
      });

      // Close button inside modal
      document.querySelectorAll(".cmc-modal__close").forEach((btn) => {
        btn.addEventListener("click", () => {
          btn.closest(".cmc-modal-overlay")?.classList.remove("is-open");
        });
      });

      // Escape key
      document.addEventListener("keydown", (e) => {
        if (e.key !== "Escape") return;
        document
          .querySelectorAll(".cmc-modal-overlay.is-open")
          .forEach((o) => o.classList.remove("is-open"));
      });
    },
  };

  // ----------------------------------------------------------
  // 4. DROPDOWN TOGGLE
  // Add data-cmc-dropdown-trigger to the trigger button
  // inside any .cmc-dropdown wrapper.
  // ----------------------------------------------------------
  function initDropdowns() {
    document.querySelectorAll(".cmc-dropdown").forEach((wrapper) => {
      const trigger = wrapper.querySelector("[data-cmc-dropdown-trigger]");
      const menu = wrapper.querySelector(".cmc-dropdown__menu");
      if (!trigger || !menu) return;

      trigger.addEventListener("click", (e) => {
        e.stopPropagation();
        const isOpen = menu.classList.contains("is-open");

        // Close all other open dropdowns
        document
          .querySelectorAll(".cmc-dropdown__menu.is-open")
          .forEach((m) => m.classList.remove("is-open"));

        menu.classList.toggle("is-open", !isOpen);
      });
    });

    // Close all dropdowns on outside click
    document.addEventListener("click", () => {
      document
        .querySelectorAll(".cmc-dropdown__menu.is-open")
        .forEach((m) => m.classList.remove("is-open"));
    });
  }

  // ----------------------------------------------------------
  // 5. TABS
  // Auto-init all .cmc-tabs groups.
  // Optional: data-target="panel-id" on each .cmc-tab
  // to show/hide corresponding .cmc-tab-panel elements.
  // ----------------------------------------------------------
  function initTabs() {
    document.querySelectorAll(".cmc-tabs").forEach((tabGroup) => {
      tabGroup.querySelectorAll(".cmc-tab").forEach((tab) => {
        tab.addEventListener("click", () => {
          // Deactivate siblings
          tabGroup
            .querySelectorAll(".cmc-tab")
            .forEach((t) => t.classList.remove("is-active"));

          // Activate clicked tab
          tab.classList.add("is-active");

          // Show target panel if data-target is set
          const targetId = tab.dataset.target;
          if (targetId) {
            document
              .querySelectorAll(".cmc-tab-panel")
              .forEach((p) => (p.hidden = true));
            const target = document.getElementById(targetId);
            if (target) target.hidden = false;
          }
        });
      });
    });
  }

  // ----------------------------------------------------------
  // 6. AJAX HELPER
  // Wraps fetch() with WP nonce + FormData.
  // Usage: await CMC.ajax("cmc_action_name", { key: "value" })
  // Returns parsed JSON response.
  // ----------------------------------------------------------
  async function ajax(action, data = {}) {
    const body = new FormData();
    body.append("action", action);
    body.append("nonce", window.CMC_DATA?.nonce ?? "");

    for (const [key, val] of Object.entries(data)) {
      body.append(key, String(val));
    }

    const response = await fetch(
      window.CMC_DATA?.ajaxUrl ?? "/wp-admin/admin-ajax.php",
      {
        method: "POST",
        body,
        credentials: "same-origin",
      }
    );

    if (!response.ok) {
      throw new Error(`[CMC] AJAX request failed: HTTP ${response.status}`);
    }

    return response.json();
  }

  // ----------------------------------------------------------
  // 7. CONFIRM MODAL UTILITY
  // Usage: CMC.confirm({ title, body, sub, okLabel, okClass, onConfirm })
  // ----------------------------------------------------------
  function confirm({
    title = "تأیید",
    body = "",
    sub = "",
    okLabel = "تأیید",
    okClass = "cmc-btn--primary",
    onConfirm,
  } = {}) {
    let overlay = document.getElementById("cmc-confirm-modal");

    if (!overlay) {
      overlay = document.createElement("div");
      overlay.className = "cmc-modal-overlay";
      overlay.id = "cmc-confirm-modal";
      overlay.innerHTML = `
            <div class="cmc-modal" style="max-width:440px">
                <div class="cmc-modal__header">
                    <span class="cmc-modal__title" id="cmc-cm-title"></span>
                    <button class="cmc-modal__close" id="cmc-cm-close"><i class="ti ti-x"></i></button>
                </div>
                <div class="cmc-modal__body">
                    <div style="display:flex;align-items:flex-start;gap:var(--cmc-space-4)">
                        <div id="cmc-cm-icon-wrap" style="width:44px;height:44px;border-radius:var(--cmc-radius-md);display:flex;align-items:center;justify-content:center;flex-shrink:0;background:var(--cmc-danger-light)">
                            <i class="ti ti-alert-triangle" style="font-size:22px;color:var(--cmc-danger)"></i>
                        </div>
                        <div>
                            <div id="cmc-cm-body"  style="font-size:14px;font-weight:600;color:var(--cmc-text-heading);margin-bottom:6px"></div>
                            <div id="cmc-cm-sub"   style="font-size:12px;color:var(--cmc-text-muted)"></div>
                        </div>
                    </div>
                </div>
                <div class="cmc-modal__footer">
                    <button class="cmc-btn" id="cmc-cm-ok">
                        <i class="ti ti-check" id="cmc-cm-ok-icon"></i>
                        <span id="cmc-cm-ok-label"></span>
                    </button>
                    <button class="cmc-btn cmc-btn--secondary" id="cmc-cm-cancel">انصراف</button>
                </div>
            </div>
        `;
      document.body.appendChild(overlay);

      document
        .getElementById("cmc-cm-close")
        ?.addEventListener("click", () => overlay.classList.remove("is-open"));
      document
        .getElementById("cmc-cm-cancel")
        ?.addEventListener("click", () => overlay.classList.remove("is-open"));
      overlay.addEventListener("click", (e) => {
        if (e.target === overlay) overlay.classList.remove("is-open");
      });
      document.addEventListener("keydown", (e) => {
        if (e.key === "Escape") overlay.classList.remove("is-open");
      });
    }

    // Populate
    document.getElementById("cmc-cm-title").textContent = title;
    document.getElementById("cmc-cm-body").textContent = body;
    document.getElementById("cmc-cm-sub").textContent = sub;
    document.getElementById("cmc-cm-ok-label").textContent = okLabel;

    // Update OK button style
    const okBtn = document.getElementById("cmc-cm-ok");
    okBtn.className = `cmc-btn ${okClass}`;

    // Replace ok button to clear old listener
    const newOk = okBtn.cloneNode(true);
    okBtn.parentNode.replaceChild(newOk, okBtn);
    document.getElementById("cmc-cm-ok").addEventListener("click", () => {
      overlay.classList.remove("is-open");
      onConfirm?.();
    });

    overlay.classList.add("is-open");
  }

  // ----------------------------------------------------------
  // 8. COLOR FIELD SYNC
  // Keeps a native <input type="color"> in sync with its sibling hex
  // text input and swatch preview inside every .cmc-color-field
  // (see components.css). Used by the Appearance and Templates pages.
  // ----------------------------------------------------------
  function initColorFields() {
    document.querySelectorAll(".cmc-color-field").forEach((field) => {
      const colorInput = field.querySelector(".cmc-color-field__input");
      const hexInput = field.querySelector(".cmc-color-field__hex");
      const swatch = field.querySelector(".cmc-color-field__swatch");

      if (!colorInput || !hexInput || !swatch) return;

      const applySwatch = (hex) => {
        swatch.style.background = hex;
      };

      // Native color picker changed → reflect into the hex field + swatch
      colorInput.addEventListener("input", () => {
        const hex = colorInput.value.toUpperCase();
        hexInput.value = hex;
        applySwatch(hex);
      });

      // Hex field typed → validate and reflect into the color picker + swatch
      hexInput.addEventListener("input", () => {
        const value = hexInput.value.trim();
        if (/^#[0-9a-fA-F]{6}$/.test(value)) {
          colorInput.value = value;
          applySwatch(value);
        }
      });

      // On blur, snap back to the last valid color if the typed value was invalid
      hexInput.addEventListener("blur", () => {
        if (!/^#[0-9a-fA-F]{6}$/.test(hexInput.value.trim())) {
          hexInput.value = colorInput.value.toUpperCase();
        }
      });
    });
  }

  // ----------------------------------------------------------
  // INIT — boot all modules on DOM ready
  // ----------------------------------------------------------
  function init() {
    initSidebar();
    modal.init();
    initDropdowns();
    initTabs();
    initColorFields();
  }

  document.addEventListener("DOMContentLoaded", init);

  // Public API
  return { toast, modal, ajax, confirm };
})();