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
        const sidebar   = document.getElementById("cmc-sidebar");
        const backdrop  = document.getElementById("cmc-backdrop");

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
        sidebar.querySelectorAll(".cmc-nav__item").forEach(item => {
            item.addEventListener("click", () => {
                if (window.innerWidth <= 768) {
                    sidebar.classList.remove("is-open");
                    backdrop?.classList.remove("is-visible");
                    hamburger.setAttribute("aria-expanded", "false");
                }
            });
        });

        // Close on Escape key
        document.addEventListener("keydown", e => {
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
            success : "ti-check",
            danger  : "ti-alert-circle",
            warning : "ti-alert-triangle",
        };

        const el = document.createElement("div");
        el.className = `cmc-toast cmc-toast--${type}`;
        el.innerHTML = `
            <i class="ti ${icons[type] ?? "ti-info-circle"} cmc-toast__icon"></i>
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
            document.querySelectorAll(".cmc-modal-overlay").forEach(overlay => {
                overlay.addEventListener("click", e => {
                    if (e.target === overlay) overlay.classList.remove("is-open");
                });
            });

            // Close button inside modal
            document.querySelectorAll(".cmc-modal__close").forEach(btn => {
                btn.addEventListener("click", () => {
                    btn.closest(".cmc-modal-overlay")?.classList.remove("is-open");
                });
            });

            // Escape key
            document.addEventListener("keydown", e => {
                if (e.key !== "Escape") return;
                document.querySelectorAll(".cmc-modal-overlay.is-open")
                    .forEach(o => o.classList.remove("is-open"));
            });
        }
    };


    // ----------------------------------------------------------
    // 4. DROPDOWN TOGGLE
    // Add data-cmc-dropdown-trigger to the trigger button
    // inside any .cmc-dropdown wrapper.
    // ----------------------------------------------------------
    function initDropdowns() {
        document.querySelectorAll(".cmc-dropdown").forEach(wrapper => {
            const trigger = wrapper.querySelector("[data-cmc-dropdown-trigger]");
            const menu    = wrapper.querySelector(".cmc-dropdown__menu");
            if (!trigger || !menu) return;

            trigger.addEventListener("click", e => {
                e.stopPropagation();
                const isOpen = menu.classList.contains("is-open");

                // Close all other open dropdowns
                document.querySelectorAll(".cmc-dropdown__menu.is-open")
                    .forEach(m => m.classList.remove("is-open"));

                menu.classList.toggle("is-open", !isOpen);
            });
        });

        // Close all dropdowns on outside click
        document.addEventListener("click", () => {
            document.querySelectorAll(".cmc-dropdown__menu.is-open")
                .forEach(m => m.classList.remove("is-open"));
        });
    }


    // ----------------------------------------------------------
    // 5. TABS
    // Auto-init all .cmc-tabs groups.
    // Optional: data-target="panel-id" on each .cmc-tab
    // to show/hide corresponding .cmc-tab-panel elements.
    // ----------------------------------------------------------
    function initTabs() {
        document.querySelectorAll(".cmc-tabs").forEach(tabGroup => {
            tabGroup.querySelectorAll(".cmc-tab").forEach(tab => {
                tab.addEventListener("click", () => {
                    // Deactivate siblings
                    tabGroup.querySelectorAll(".cmc-tab")
                        .forEach(t => t.classList.remove("is-active"));

                    // Activate clicked tab
                    tab.classList.add("is-active");

                    // Show target panel if data-target is set
                    const targetId = tab.dataset.target;
                    if (targetId) {
                        document.querySelectorAll(".cmc-tab-panel")
                            .forEach(p => p.hidden = true);
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
        body.append("nonce",  window.CMC_DATA?.nonce ?? "");

        for (const [key, val] of Object.entries(data)) {
            body.append(key, String(val));
        }

        const response = await fetch(
            window.CMC_DATA?.ajaxUrl ?? "/wp-admin/admin-ajax.php",
            {
                method      : "POST",
                body,
                credentials : "same-origin",
            }
        );

        if (!response.ok) {
            throw new Error(`[CMC] AJAX request failed: HTTP ${response.status}`);
        }

        return response.json();
    }


    // ----------------------------------------------------------
    // INIT — boot all modules on DOM ready
    // ----------------------------------------------------------
    function init() {
        initSidebar();
        modal.init();
        initDropdowns();
        initTabs();
    }

    document.addEventListener("DOMContentLoaded", init);

    // Public API
    return { toast, modal, ajax };

})();
