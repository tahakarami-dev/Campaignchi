/**
 * CMC Admin Panel — Core JavaScript
 * Campaignchi Plugin v1.0.0
 *
 * Responsibilities:
 *   1. Topbar date (Shamsi/Jalali)
 *   2. Toast notification utility
 *   3. Modal open/close utility
 *   4. Dropdown toggle utility
 *   5. Dashboard button bindings
 */

"use strict";

const CMC = (() => {

    // ----------------------------------------------------------
    // 1. TOPBAR DATE — Jalali via Intl API
    // ----------------------------------------------------------
    function initDate() {
        const el = document.getElementById("cmc-page-date");
        if (!el) return;

        try {
            const formatted = new Intl.DateTimeFormat("fa-IR-u-ca-persian", {
                calendar: "persian",
                weekday: "long",
                year:    "numeric",
                month:   "long",
                day:     "numeric",
            }).format(new Date());

            el.textContent = formatted;
        } catch {
            el.textContent = new Date().toLocaleDateString("fa-IR");
        }
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
            danger:  "ti-alert-circle",
            warning: "ti-alert-triangle",
        };

        const el = document.createElement("div");
        el.className = `cmc-toast cmc-toast--${type}`;
        el.innerHTML = `
            <i class="ti ${icons[type] || "ti-info-circle"} cmc-toast__icon"></i>
            <span class="cmc-toast__text">${message}</span>
        `;

        container.appendChild(el);

        // Trigger enter animation
        requestAnimationFrame(() => requestAnimationFrame(() => el.classList.add("is-visible")));

        // Auto remove after 3.5s
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

            // Close button
            document.querySelectorAll(".cmc-modal__close").forEach(btn => {
                btn.addEventListener("click", () => {
                    btn.closest(".cmc-modal-overlay")?.classList.remove("is-open");
                });
            });

            // Escape key
            document.addEventListener("keydown", e => {
                if (e.key === "Escape") {
                    document.querySelectorAll(".cmc-modal-overlay.is-open").forEach(o => {
                        o.classList.remove("is-open");
                    });
                }
            });
        },
    };


    // ----------------------------------------------------------
    // 4. DROPDOWN UTILITY
    // Auto-attaches to .cmc-dropdown wrappers
    // ----------------------------------------------------------
    function initDropdowns() {
        document.querySelectorAll(".cmc-dropdown").forEach(dropdown => {
            const trigger = dropdown.querySelector("[data-cmc-dropdown-trigger]");
            const menu    = dropdown.querySelector(".cmc-dropdown__menu");
            if (!trigger || !menu) return;

            trigger.addEventListener("click", e => {
                e.stopPropagation();
                const isOpen = menu.classList.contains("is-open");

                // Close all open dropdowns first
                document.querySelectorAll(".cmc-dropdown__menu.is-open").forEach(m => {
                    m.classList.remove("is-open");
                });

                if (!isOpen) menu.classList.add("is-open");
            });
        });

        // Click outside closes all dropdowns
        document.addEventListener("click", () => {
            document.querySelectorAll(".cmc-dropdown__menu.is-open").forEach(m => {
                m.classList.remove("is-open");
            });
        });
    }


    // ----------------------------------------------------------
    // 5. TABS — auto-activate on click
    // ----------------------------------------------------------
    function initTabs() {
        document.querySelectorAll(".cmc-tabs").forEach(tabs => {
            tabs.querySelectorAll(".cmc-tab").forEach(tab => {
                tab.addEventListener("click", () => {
                    tabs.querySelectorAll(".cmc-tab").forEach(t => t.classList.remove("is-active"));
                    tab.classList.add("is-active");
                });
            });
        });
    }


    // ----------------------------------------------------------
    // 6. PAGE-SPECIFIC BINDINGS (dashboard buttons)
    // ----------------------------------------------------------
    function initPageBindings() {
        const btnNewCampaign = document.getElementById("cmc-btn-new-campaign");
        const btnFlash       = document.getElementById("cmc-btn-flash");
        const btnSaveSettings = document.getElementById("cmc-btn-save-settings");

        btnNewCampaign?.addEventListener("click", () => {
            toast("به‌زودی: ایجاد کمپین جدید", "success");
        });

        btnFlash?.addEventListener("click", () => {
            toast("فلش سیل در حال راه‌اندازی...", "warning");
        });

        btnSaveSettings?.addEventListener("click", () => {
            toast("تنظیمات ذخیره شد", "success");
        });
    }


    // ----------------------------------------------------------
    // INIT — run after DOM ready
    // ----------------------------------------------------------
    function init() {
        initDate();
        modal.init();
        initDropdowns();
        initTabs();
        initPageBindings();
    }

    return { init, toast, modal };

})();

document.addEventListener("DOMContentLoaded", CMC.init);
