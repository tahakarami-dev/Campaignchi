/**
 * CMCDatePicker — Persian (Jalali) Date & Time Picker
 * Version: 2.0.0
 * No external dependencies — pure vanilla JS
 *
 * HTML pattern:
 *   <input type="text"   id="starts-display" data-cmc-datepicker="cmc-field-starts-at" readonly>
 *   <input type="hidden" id="cmc-field-starts-at">
 *
 * JS:
 *   CMCDatePicker.init();
 *   CMCDatePicker.setValue("cmc-field-starts-at", "2025-03-21T10:30");
 */

"use strict";

const CMCDatePicker = (() => {

  /* ============================================================
   * JALALI ↔ GREGORIAN CONVERSION
   * Based on the algorithm used by jalaali-js (MIT)
   * ============================================================ */

  function g2j(gy, gm, gd) {
    const sal_a = [
      0, 31,
      (gy % 4 === 0 && gy % 100 !== 0) || gy % 400 === 0 ? 29 : 28,
      31, 30, 31, 30, 31, 31, 30, 31, 30, 31
    ];
    gy -= 1600; gm--; gd--;
    let g_day_no = 365 * gy + Math.floor((gy + 3) / 4) - Math.floor((gy + 99) / 100) + Math.floor((gy + 399) / 400);
    for (let i = 0; i < gm; i++) g_day_no += sal_a[i + 1];
    g_day_no += gd;

    let j_day_no = g_day_no - 79;
    const j_np = Math.floor(j_day_no / 12053);
    j_day_no %= 12053;
    let jy = 979 + 33 * j_np + 4 * Math.floor(j_day_no / 1461);
    j_day_no %= 1461;
    if (j_day_no >= 366) { jy += Math.floor((j_day_no - 1) / 365); j_day_no = (j_day_no - 1) % 365; }
    const j_mi = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
    let jm = 0;
    for (jm = 0; jm < 11 && j_day_no >= j_mi[jm]; jm++) j_day_no -= j_mi[jm];
    return [jy, jm + 1, j_day_no + 1];
  }

  function j2g(jy, jm, jd) {
    jy -= 979; jm--; jd--;
    let j_day_no = 365 * jy + Math.floor(jy / 33) * 8 + Math.floor((jy % 33 + 3) / 4);
    const j_mi = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
    for (let i = 0; i < jm; i++) j_day_no += j_mi[i];
    j_day_no += jd;
    let g_day_no = j_day_no + 79;
    let gy = 1600 + 400 * Math.floor(g_day_no / 146097);
    g_day_no %= 146097;
    let leap = true;
    if (g_day_no >= 36525) {
      g_day_no--;
      gy += 100 * Math.floor(g_day_no / 36524);
      g_day_no %= 36524;
      if (g_day_no >= 365) g_day_no++; else leap = false;
    }
    gy += 4 * Math.floor(g_day_no / 1461);
    g_day_no %= 1461;
    if (g_day_no >= 366) { leap = false; g_day_no--; gy += Math.floor(g_day_no / 365); g_day_no %= 365; }
    const g_mi = [31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    let gm = 0;
    for (gm = 0; gm < 12 && g_day_no >= g_mi[gm]; gm++) g_day_no -= g_mi[gm];
    return [gy, gm + 1, g_day_no + 1];
  }

  function daysInJMonth(jy, jm) {
    if (jm <= 6) return 31;
    if (jm <= 11) return 30;
    // اسفند — سال کبیسه
    const rem = ((jy - 474) % 2820 + 474 + 38) * 682 % 2816;
    return rem < 682 ? 30 : 29;
  }

  // روز هفته شمسی: 0=شنبه ... 6=جمعه
  function jDayOfWeek(jy, jm, jd) {
    const [gy, gm, gd] = j2g(jy, jm, jd);
    const dow = new Date(gy, gm - 1, gd).getDay(); // 0=Sun
    return (dow + 1) % 7; // شنبه=0
  }

  /* ============================================================
   * UTILS
   * ============================================================ */

  const P = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
  const toPer  = n  => String(n).replace(/\d/g, d => P[+d]);
  const pad2   = n  => String(n).padStart(2, '0');

  const MONTHS   = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
  const WDAYS    = ['ش','ی','د','س','چ','پ','ج']; // شنبه اول

  /* ============================================================
   * STATE
   * ============================================================ */

  let popup  = null;  // DOM element
  let anchor = null;  // input display element
  let ps     = {};    // picker state

  /* ============================================================
   * PUBLIC: init
   * ============================================================ */

  function init() {
    document.querySelectorAll('[data-cmc-datepicker]').forEach(el => _attach(el));
    document.addEventListener('click', e => {
      if (popup && !popup.contains(e.target) && e.target !== anchor) _close();
    });
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && popup) _close();
    });
  }

  function _attach(displayEl) {
    displayEl.readOnly = true;
    displayEl.style.cursor = 'pointer';
    displayEl.addEventListener('click', e => { e.stopPropagation(); _open(displayEl); });
  }

  /* ============================================================
   * OPEN
   * ============================================================ */

  function _open(displayEl) {
    if (popup) _close();
    anchor = displayEl;

    const hiddenId = displayEl.dataset.cmcDatepicker;
    const hiddenEl = document.getElementById(hiddenId);
    const iso      = hiddenEl?.value ?? '';

    let jy, jm, jd, h = 0, min = 0;
    if (iso) {
      const d = new Date(iso);
      [jy, jm, jd] = g2j(d.getFullYear(), d.getMonth() + 1, d.getDate());
      h = d.getHours(); min = d.getMinutes();
    } else {
      const now = new Date();
      [jy, jm, jd] = g2j(now.getFullYear(), now.getMonth() + 1, now.getDate());
      h = now.getHours(); min = now.getMinutes();
    }

    ps = { jy, jm, jd, h, min, hiddenId, displayEl };

    popup = document.createElement('div');
    popup.className = 'cmc-dp';
    popup.id        = 'cmc-dp-popup';
    document.body.appendChild(popup);

    _render();
    _position();
  }

  /* ============================================================
   * CLOSE
   * ============================================================ */

  function _close() {
    if (popup) { popup.remove(); popup = null; }
    anchor = null;
  }

  /* ============================================================
   * RENDER
   * ============================================================ */

  function _render() {
    if (!popup) return;
    const { jy, jm, jd, h, min } = ps;
    const dim = daysInJMonth(jy, jm);
    const fwd = jDayOfWeek(jy, jm, 1); // اولین روز ماه

    /* ---- تقویم ---- */
    let rows = '';
    let d = 1 - fwd;
    while (d <= dim) {
      rows += '<tr>';
      for (let col = 0; col < 7; col++) {
        if (d < 1 || d > dim) {
          rows += `<td class="cmc-dp__day cmc-dp__day--out"></td>`;
        } else {
          const isSel   = d === jd;
          const isToday = (() => {
            const n = new Date();
            const [ty, tm, td] = g2j(n.getFullYear(), n.getMonth() + 1, n.getDate());
            return ty === jy && tm === jm && td === d;
          })();
          const cls = ['cmc-dp__day'];
          if (isSel)   cls.push('is-sel');
          if (isToday && !isSel) cls.push('is-today');
          rows += `<td class="${cls.join(' ')}" data-d="${d}">${toPer(d)}</td>`;
        }
        d++;
      }
      rows += '</tr>';
      if (d > dim) break;
    }

    popup.innerHTML = `
      <div class="cmc-dp__head">
        <button class="cmc-dp__nav" data-a="py" title="سال قبل">«</button>
        <button class="cmc-dp__nav" data-a="pm" title="ماه قبل">‹</button>
        <div class="cmc-dp__htitle">
          <b>${MONTHS[jm - 1]}</b>
          <span>${toPer(jy)}</span>
        </div>
        <button class="cmc-dp__nav" data-a="nm" title="ماه بعد">›</button>
        <button class="cmc-dp__nav" data-a="ny" title="سال بعد">»</button>
      </div>

      <table class="cmc-dp__cal">
        <thead>
          <tr>${WDAYS.map(w => `<th>${w}</th>`).join('')}</tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>

      <div class="cmc-dp__time">
        <i class="ti ti-clock" style="color:var(--cmc-primary-500);font-size:16px;flex-shrink:0"></i>
        <span style="font-size:12px;color:var(--cmc-text-muted);margin-left:4px">ساعت</span>
        <div style="flex:1"></div>
        <div class="cmc-dp__tfield">
          <button class="cmc-dp__tbtn" data-t="hu">▲</button>
          <span class="cmc-dp__tval" id="cmc-dp-h">${toPer(pad2(h))}</span>
          <button class="cmc-dp__tbtn" data-t="hd">▼</button>
        </div>
        <span class="cmc-dp__tsep">:</span>
        <div class="cmc-dp__tfield">
          <button class="cmc-dp__tbtn" data-t="mu">▲</button>
          <span class="cmc-dp__tval" id="cmc-dp-m">${toPer(pad2(min))}</span>
          <button class="cmc-dp__tbtn" data-t="md">▼</button>
        </div>
      </div>

      <div class="cmc-dp__foot">
        <button class="cmc-dp__ok cmc-btn cmc-btn--primary cmc-btn--sm">
          <i class="ti ti-check"></i> تأیید
        </button>
        <button class="cmc-dp__clear cmc-btn cmc-btn--ghost cmc-btn--sm">
          <i class="ti ti-eraser"></i> پاک کردن
        </button>
      </div>
    `;

    /* ---- Events: navigation ---- */
    popup.querySelectorAll('[data-a]').forEach(btn => {
      btn.addEventListener('click', e => {
        e.stopPropagation();
        const a = btn.dataset.a;
        if (a === 'pm') { ps.jm--; if (ps.jm < 1)  { ps.jm = 12; ps.jy--; } }
        if (a === 'nm') { ps.jm++; if (ps.jm > 12) { ps.jm = 1;  ps.jy++; } }
        if (a === 'py') ps.jy--;
        if (a === 'ny') ps.jy++;
        // Clamp day to new month's max
        ps.jd = Math.min(ps.jd, daysInJMonth(ps.jy, ps.jm));
        _render();
      });
    });

    /* ---- Events: day click ---- */
    popup.querySelectorAll('.cmc-dp__day:not(.cmc-dp__day--out)').forEach(cell => {
      cell.addEventListener('click', e => {
        e.stopPropagation();
        ps.jd = parseInt(cell.dataset.d, 10);
        _render();
      });
    });

    /* ---- Events: time ---- */
    popup.querySelectorAll('[data-t]').forEach(btn => {
      btn.addEventListener('click', e => {
        e.stopPropagation();
        const t = btn.dataset.t;
        if (t === 'hu') ps.h   = (ps.h   + 1)  % 24;
        if (t === 'hd') ps.h   = (ps.h   - 1 + 24) % 24;
        if (t === 'mu') ps.min = (ps.min  + 5)  % 60;
        if (t === 'md') ps.min = (ps.min  - 5 + 60) % 60;
        const hEl = document.getElementById('cmc-dp-h');
        const mEl = document.getElementById('cmc-dp-m');
        if (hEl) hEl.textContent = toPer(pad2(ps.h));
        if (mEl) mEl.textContent = toPer(pad2(ps.min));
      });
    });

    /* ---- Events: confirm ---- */
    popup.querySelector('.cmc-dp__ok')?.addEventListener('click', e => {
      e.stopPropagation();
      const { jy, jm, jd, h, min, hiddenId, displayEl } = ps;
      const [gy, gm, gd] = j2g(jy, jm, jd);

      const iso     = `${gy}-${pad2(gm)}-${pad2(gd)}T${pad2(h)}:${pad2(min)}`;
      const persian = `${toPer(jy)}/${toPer(pad2(jm))}/${toPer(pad2(jd))}  ${toPer(pad2(h))}:${toPer(pad2(min))}`;

      displayEl.value = persian;
      const hEl = document.getElementById(hiddenId);
      if (hEl) hEl.value = iso;
      _close();
    });

    /* ---- Events: clear ---- */
    popup.querySelector('.cmc-dp__clear')?.addEventListener('click', e => {
      e.stopPropagation();
      const { hiddenId, displayEl } = ps;
      displayEl.value = '';
      const hEl = document.getElementById(hiddenId);
      if (hEl) hEl.value = '';
      _close();
    });
  }

  /* ============================================================
   * POSITION — هوشمند، بالا یا پایین ورودی
   * ============================================================ */

  function _position() {
    if (!popup || !anchor) return;
    const rect   = anchor.getBoundingClientRect();
    const popH   = 400;
    const spaceB = window.innerHeight - rect.bottom;
    const top    = spaceB > popH
      ? rect.bottom + window.scrollY + 6
      : rect.top + window.scrollY - popH - 6;
    // راست‌چین: لبه راست پاپ‌آپ با لبه راست input هم‌راستا باشه
    const left = Math.max(10, rect.right - 300 + window.scrollX);
    popup.style.top  = `${top}px`;
    popup.style.left = `${left}px`;
  }

  /* ============================================================
   * PUBLIC: setValue — set value programmatically (برای حالت ویرایش)
   * hiddenId: id of the hidden input
   * iso: "2025-03-21T10:30"
   * ============================================================ */

  function setValue(hiddenId, iso) {
    const hEl = document.getElementById(hiddenId);
    if (!hEl) return;
    hEl.value = iso || '';

    const dEl = document.querySelector(`[data-cmc-datepicker="${hiddenId}"]`);
    if (!dEl) return;

    if (!iso) { dEl.value = ''; return; }

    const d = new Date(iso);
    const [jy, jm, jd] = g2j(d.getFullYear(), d.getMonth() + 1, d.getDate());
    const h = d.getHours(), min = d.getMinutes();
    dEl.value = `${toPer(jy)}/${toPer(pad2(jm))}/${toPer(pad2(jd))}  ${toPer(pad2(h))}:${toPer(pad2(min))}`;
  }

  /* ============================================================
   * PUBLIC API
   * ============================================================ */

  return { init, setValue };

})();