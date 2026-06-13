/**
 * CMCDatePicker — Persian (Jalali) Date & Time Picker
 * Version: 3.0.0 — Production Ready
 *
 * Bug fixes:
 *  - ISO parse بدون timezone-shift (دستی parse میکنیم)
 *  - grid: همه روزها درست نمایش داده میشن
 *  - positioning: هوشمند بالا/پایین/چپ/راست
 *  - z-index بالا
 *  - جلوگیری از init دوباره
 */

"use strict";

const CMCDatePicker = (() => {

  /* ============================================================
   * JALALI ↔ GREGORIAN (الگوریتم تأیید شده)
   * ============================================================ */

  function g2j(gy, gm, gd) {
    const isLeap = y => (y % 4 === 0 && y % 100 !== 0) || y % 400 === 0;
    const sal_a  = [0, 31, isLeap(gy) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    let _gy = gy - 1600, _gm = gm - 1, _gd = gd - 1;
    let g_day_no = 365*_gy + Math.floor((_gy+3)/4) - Math.floor((_gy+99)/100) + Math.floor((_gy+399)/400);
    for (let i = 0; i < _gm; i++) g_day_no += sal_a[i + 1];
    g_day_no += _gd;
    let j_day_no = g_day_no - 79;
    const j_np   = Math.floor(j_day_no / 12053);
    j_day_no    %= 12053;
    let jy        = 979 + 33*j_np + 4*Math.floor(j_day_no/1461);
    j_day_no     %= 1461;
    if (j_day_no >= 366) { jy += Math.floor((j_day_no-1)/365); j_day_no = (j_day_no-1)%365; }
    const j_mi = [31,31,31,31,31,31,30,30,30,30,30,29];
    let jm = 0;
    for (jm = 0; jm < 11 && j_day_no >= j_mi[jm]; jm++) j_day_no -= j_mi[jm];
    return [jy, jm+1, j_day_no+1];
  }

  function j2g(jy, jm, jd) {
    jy -= 979; jm--; jd--;
    let j_day_no = 365*jy + Math.floor(jy/33)*8 + Math.floor((jy%33+3)/4);
    const j_mi   = [31,31,31,31,31,31,30,30,30,30,30,29];
    for (let i = 0; i < jm; i++) j_day_no += j_mi[i];
    j_day_no += jd;
    let g_day_no = j_day_no + 79;
    let gy       = 1600 + 400*Math.floor(g_day_no/146097);
    g_day_no    %= 146097;
    let leap     = true;
    if (g_day_no >= 36525) {
      g_day_no--;
      gy       += 100*Math.floor(g_day_no/36524);
      g_day_no %= 36524;
      if (g_day_no >= 365) g_day_no++; else leap = false;
    }
    gy        += 4*Math.floor(g_day_no/1461);
    g_day_no  %= 1461;
    if (g_day_no >= 366) { leap = false; g_day_no--; gy += Math.floor(g_day_no/365); g_day_no %= 365; }
    const g_mi = [31, leap?29:28, 31,30,31,30,31,31,30,31,30,31];
    let gm = 0;
    for (gm = 0; gm < 12 && g_day_no >= g_mi[gm]; gm++) g_day_no -= g_mi[gm];
    return [gy, gm+1, g_day_no+1];
  }

  function daysInJMonth(jy, jm) {
    if (jm <= 6)  return 31;
    if (jm <= 11) return 30;
    const rem = ((jy - 474) % 2820 + 474 + 38) * 682 % 2816;
    return rem < 682 ? 30 : 29;
  }

  function jDayOfWeek(jy, jm, jd) {
    const [gy, gm, gd] = j2g(jy, jm, jd);
    return (new Date(gy, gm-1, gd).getDay() + 1) % 7; // شنبه=0
  }

  /* ============================================================
   * PARSE ISO — بدون timezone-shift
   * 'YYYY-MM-DD HH:MM:SS' یا 'YYYY-MM-DDTHH:MM' هر دو OK
   * ============================================================ */

  function parseISO(str) {
    if (!str) return null;
    const m = String(str).trim().replace('T', ' ')
      .match(/^(\d{4})-(\d{2})-(\d{2})(?:\s+(\d{2}):(\d{2}))?/);
    if (!m) return null;
    return {
      gy:  +m[1], gm: +m[2], gd: +m[3],
      h:   +(m[4]||0), min: +(m[5]||0),
    };
  }

  /* ============================================================
   * UTILS
   * ============================================================ */

  const P     = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
  const toPer = n => String(n).replace(/\d/g, d => P[+d]);
  const pad2  = n => String(n).padStart(2, '0');

  const MONTHS = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
  const WDAYS  = ['ش','ی','د','س','چ','پ','ج'];

  /* ============================================================
   * STATE
   * ============================================================ */

  let popup  = null;
  let anchor = null;
  let ps     = {};

  /* ============================================================
   * PUBLIC: init
   * ============================================================ */

  function init() {
    if (document._cmcDpReady) return;
    document._cmcDpReady = true;

    document.querySelectorAll('[data-cmc-datepicker]').forEach(_attach);

    document.addEventListener('click', e => {
      if (!popup) return;
      if (popup.contains(e.target)) return;
      if (anchor && anchor.contains(e.target)) return;
      _close();
    }, true);

    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && popup) _close();
    });
  }

  function _attach(el) {
    if (el._cmcDpDone) return;
    el._cmcDpDone   = true;
    el.readOnly     = true;
    el.style.cursor = 'pointer';
    el.addEventListener('click', e => {
      e.preventDefault();
      e.stopPropagation();
      if (popup && anchor === el) { _close(); return; }
      _open(el);
    });
  }

  /* ============================================================
   * OPEN
   * ============================================================ */

  function _open(displayEl) {
    if (popup) _close();
    anchor = displayEl;

    const hiddenId = displayEl.dataset.cmcDatepicker;
    const hiddenEl = document.getElementById(hiddenId);
    const rawVal   = hiddenEl ? hiddenEl.value : '';

    let jy, jm, jd, h = 0, min = 0;
    const parsed = parseISO(rawVal);
    if (parsed) {
      [jy, jm, jd] = g2j(parsed.gy, parsed.gm, parsed.gd);
      h = parsed.h; min = parsed.min;
    } else {
      const now = new Date();
      [jy, jm, jd] = g2j(now.getFullYear(), now.getMonth()+1, now.getDate());
      h = now.getHours(); min = now.getMinutes();
    }

    ps = { jy, jm, jd, h, min, hiddenId, displayEl };

    popup = document.createElement('div');
    popup.className = 'cmc-dp';
    popup.id        = 'cmc-dp-popup';
    document.body.appendChild(popup);

    _render();
    requestAnimationFrame(_position);
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
    const fwd = jDayOfWeek(jy, jm, 1);

    const now = new Date();
    const [ty, tm, td] = g2j(now.getFullYear(), now.getMonth()+1, now.getDate());

    // ساخت grid — همه ردیف‌ها تا آخرین روز
    let gridHTML = '';
    let d = 1 - fwd;
    while (d <= dim) {
      gridHTML += '<tr>';
      for (let col = 0; col < 7; col++) {
        if (d < 1 || d > dim) {
          gridHTML += '<td class="cmc-dp__day cmc-dp__day--out"></td>';
        } else {
          const isSel   = (d === jd);
          const isToday = (ty === jy && tm === jm && td === d);
          let cls = 'cmc-dp__day';
          if (isSel)             cls += ' is-sel';
          if (isToday && !isSel) cls += ' is-today';
          gridHTML += `<td class="${cls}" data-d="${d}">${toPer(d)}</td>`;
        }
        d++;
      }
      gridHTML += '</tr>';
    }

    popup.innerHTML = `
      <div class="cmc-dp__head">
        <button class="cmc-dp__nav" data-a="py" type="button" title="سال قبل">«</button>
        <button class="cmc-dp__nav" data-a="pm" type="button" title="ماه قبل">‹</button>
        <div class="cmc-dp__htitle">
          <b>${MONTHS[jm-1]}</b>
          <span>${toPer(jy)}</span>
        </div>
        <button class="cmc-dp__nav" data-a="nm" type="button" title="ماه بعد">›</button>
        <button class="cmc-dp__nav" data-a="ny" type="button" title="سال بعد">»</button>
      </div>

      <table class="cmc-dp__cal">
        <thead><tr>${WDAYS.map(w=>`<th>${w}</th>`).join('')}</tr></thead>
        <tbody>${gridHTML}</tbody>
      </table>

      <div class="cmc-dp__time">
        <i class="ti ti-clock" style="color:var(--cmc-primary-500);font-size:15px;flex-shrink:0"></i>
        <span style="font-size:12px;color:var(--cmc-text-muted);margin-left:6px;flex-shrink:0">ساعت</span>
        <div style="flex:1"></div>
        <div class="cmc-dp__tfield">
          <button class="cmc-dp__tbtn" data-t="hu" type="button">▲</button>
          <span class="cmc-dp__tval" id="cmc-dp-h">${toPer(pad2(h))}</span>
          <button class="cmc-dp__tbtn" data-t="hd" type="button">▼</button>
        </div>
        <span class="cmc-dp__tsep">:</span>
        <div class="cmc-dp__tfield">
          <button class="cmc-dp__tbtn" data-t="mu" type="button">▲</button>
          <span class="cmc-dp__tval" id="cmc-dp-m">${toPer(pad2(min))}</span>
          <button class="cmc-dp__tbtn" data-t="md" type="button">▼</button>
        </div>
      </div>

      <div class="cmc-dp__foot">
        <button class="cmc-dp__ok    cmc-btn cmc-btn--primary cmc-btn--sm" type="button">
          <i class="ti ti-check"></i> تأیید
        </button>
        <button class="cmc-dp__clear cmc-btn cmc-btn--ghost   cmc-btn--sm" type="button">
          <i class="ti ti-eraser"></i> پاک کردن
        </button>
      </div>`;

    /* ناوبری */
    popup.querySelectorAll('[data-a]').forEach(btn => {
      btn.addEventListener('click', e => {
        e.preventDefault(); e.stopPropagation();
        const a = btn.dataset.a;
        if (a==='pm') { ps.jm--; if (ps.jm<1)  { ps.jm=12; ps.jy--; } }
        if (a==='nm') { ps.jm++; if (ps.jm>12) { ps.jm=1;  ps.jy++; } }
        if (a==='py') ps.jy--;
        if (a==='ny') ps.jy++;
        ps.jd = Math.min(ps.jd, daysInJMonth(ps.jy, ps.jm));
        _render();
        requestAnimationFrame(_position);
      });
    });

    /* انتخاب روز */
    popup.querySelectorAll('.cmc-dp__day:not(.cmc-dp__day--out)').forEach(cell => {
      cell.addEventListener('click', e => {
        e.preventDefault(); e.stopPropagation();
        ps.jd = parseInt(cell.dataset.d, 10);
        _render();
        requestAnimationFrame(_position);
      });
    });

    /* ساعت/دقیقه */
    popup.querySelectorAll('[data-t]').forEach(btn => {
      btn.addEventListener('click', e => {
        e.preventDefault(); e.stopPropagation();
        const t = btn.dataset.t;
        if (t==='hu') ps.h   = (ps.h   + 1)      % 24;
        if (t==='hd') ps.h   = (ps.h   - 1 + 24) % 24;
        if (t==='mu') ps.min = (ps.min  + 5)      % 60;
        if (t==='md') ps.min = (ps.min  - 5 + 60) % 60;
        const hEl = document.getElementById('cmc-dp-h');
        const mEl = document.getElementById('cmc-dp-m');
        if (hEl) hEl.textContent = toPer(pad2(ps.h));
        if (mEl) mEl.textContent = toPer(pad2(ps.min));
      });
    });

    /* تأیید */
    popup.querySelector('.cmc-dp__ok')?.addEventListener('click', e => {
      e.preventDefault(); e.stopPropagation();
      const { jy, jm, jd, h, min, hiddenId, displayEl } = ps;
      const [gy, gm, gd] = j2g(jy, jm, jd);
      const iso     = `${gy}-${pad2(gm)}-${pad2(gd)}T${pad2(h)}:${pad2(min)}`;
      const persian = `${toPer(jy)}/${toPer(pad2(jm))}/${toPer(pad2(jd))}  ${toPer(pad2(h))}:${toPer(pad2(min))}`;
      displayEl.value = persian;
      const hEl = document.getElementById(hiddenId);
      if (hEl) hEl.value = iso;
      _close();
    });

    /* پاک کردن */
    popup.querySelector('.cmc-dp__clear')?.addEventListener('click', e => {
      e.preventDefault(); e.stopPropagation();
      ps.displayEl.value = '';
      const hEl = document.getElementById(ps.hiddenId);
      if (hEl) hEl.value = '';
      _close();
    });
  }

  /* ============================================================
   * POSITION
   * ============================================================ */

  function _position() {
    if (!popup || !anchor) return;
    const rect = anchor.getBoundingClientRect();
    const popW = 300;
    const popH = popup.offsetHeight || 400;
    const winW = window.innerWidth;
    const winH = window.innerHeight;
    const sY   = window.scrollY  || document.documentElement.scrollTop  || 0;
    const sX   = window.scrollX  || document.documentElement.scrollLeft || 0;

    // عمودی
    let top;
    if (rect.bottom + popH + 10 <= winH) {
      top = rect.bottom + sY + 6;
    } else if (rect.top - popH - 10 >= 0) {
      top = rect.top + sY - popH - 6;
    } else {
      top = sY + Math.max(8, Math.floor((winH - popH) / 2));
    }

    // افقی (راست‌چین)
    let left = rect.right + sX - popW;
    if (left < sX + 8)                left = sX + 8;
    if (left + popW > sX + winW - 8)  left = sX + winW - popW - 8;

    Object.assign(popup.style, {
      position: 'absolute',
      top:      `${Math.round(top)}px`,
      left:     `${Math.round(left)}px`,
      zIndex:   '9999999',
      width:    `${popW}px`,
    });
  }

  /* ============================================================
   * PUBLIC: setValue — برای حالت ویرایش کمپین
   * ============================================================ */

  function setValue(hiddenId, isoStr) {
    const hEl = document.getElementById(hiddenId);
    if (!hEl) return;
    hEl.value = isoStr || '';

    const dEl = document.querySelector(`[data-cmc-datepicker="${hiddenId}"]`);
    if (!dEl) return;

    if (!isoStr) { dEl.value = ''; return; }

    const parsed = parseISO(isoStr);
    if (!parsed) { dEl.value = ''; return; }

    const [jy, jm, jd] = g2j(parsed.gy, parsed.gm, parsed.gd);
    dEl.value = `${toPer(jy)}/${toPer(pad2(jm))}/${toPer(pad2(jd))}  ${toPer(pad2(parsed.h))}:${toPer(pad2(parsed.min))}`;
  }

  return { init, setValue };

})();