/**
 * CMC Admin Panel — Core JavaScript
 *
 * Responsibilities:
 *  1. Sidebar active state (already set server-side, JS for SPA feel)
 *  2. Toast notification utility
 *  3. Modal open/close utility
 *  4. Dropdown toggle
 *  5. AJAX helper (wraps fetch + WP nonce)
 *
 * All identifiers prefixed with CMC_ or cmc- to avoid conflicts.
 */

"use strict";

const CMC = (() => {

    // ----------------------------------------------------------
    // 1. TOAST NOTIFICATIONS
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

        // Trigger CSS transition
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
    // 2. MODAL UTILITY
    // Usage: CMC.modal.open("#cmc-modal-id")
    //        CMC.modal.close("#cmc-modal-id")
    // ----------------------------------------------------------
    const modal = {
        open(selector) {
            document.querySelector(selector)?.classList.add("is-open");
        },
        close(selector) {
            document.querySelector(selector)?.classList.remove("is-open");
        },
        init() {
            // Close on overlay backdrop click
            document.querySelectorAll(".cmc-modal-overlay").forEach(overlay => {
                overlay.addEventListener("click", e => {
                    if (e.target === overlay) overlay.classList.remove("is-open");
                });
            });

            // Close button
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
    // 3. DROPDOWN TOGGLE
    // Attach data-cmc-dropdown-trigger to the button inside .cmc-dropdown
    // ----------------------------------------------------------
    function initDropdowns() {
        document.querySelectorAll(".cmc-dropdown").forEach(wrapper => {
            const trigger = wrapper.querySelector("[data-cmc-dropdown-trigger]");
            const menu    = wrapper.querySelector(".cmc-dropdown__menu");
            if (!trigger || !menu) return;

            trigger.addEventListener("click", e => {
                e.stopPropagation();
                const open = menu.classList.contains("is-open");

                // Close all other open dropdowns first
                document.querySelectorAll(".cmc-dropdown__menu.is-open")
                    .forEach(m => m.classList.remove("is-open"));

                if (!open) menu.classList.add("is-open");
            });
        });

        // Close on outside click
        document.addEventListener("click", () => {
            document.querySelectorAll(".cmc-dropdown__menu.is-open")
                .forEach(m => m.classList.remove("is-open"));
        });
    }


    // ----------------------------------------------------------
    // 4. AJAX HELPER
    // Wraps fetch() with WP nonce and JSON handling.
    // Usage: CMC.ajax("cmc_get_campaigns", { status: "active" })
    // ----------------------------------------------------------
    async function ajax(action, data = {}) {
        const body = new FormData();
        body.append("action", action);
        body.append("nonce",  window.CMC_DATA?.nonce ?? "");

        for (const [key, val] of Object.entries(data)) {
            body.append(key, val);
        }

        const response = await fetch(window.CMC_DATA?.ajaxUrl ?? "/wp-admin/admin-ajax.php", {
            method: "POST",
            body,
            credentials: "same-origin",
        });

        if (!response.ok) {
            throw new Error(`CMC AJAX error: ${response.status}`);
        }

        return response.json();
    }


    // ----------------------------------------------------------
    // 5. TABS
    // Auto-initialise .cmc-tabs by adding click handlers.
    // ----------------------------------------------------------
    function initTabs() {
        document.querySelectorAll(".cmc-tabs").forEach(tabGroup => {
            tabGroup.querySelectorAll(".cmc-tab").forEach(tab => {
                tab.addEventListener("click", () => {
                    tabGroup.querySelectorAll(".cmc-tab")
                        .forEach(t => t.classList.remove("is-active"));
                    tab.classList.add("is-active");

                    // If data-target attribute set, show that panel
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
    // INIT
    // ----------------------------------------------------------
    function init() {
        modal.init();
        initDropdowns();
        initTabs();

        // Confirm current page from server-injected data
        if (window.CMC_DATA?.page) {
            document.title = document.title; // page title already set server-side
        }
    }

    // Boot on DOM ready
    document.addEventListener("DOMContentLoaded", init);

    // Public API
    return { toast, modal, ajax };

})();
