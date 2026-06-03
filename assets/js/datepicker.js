/**
 * CMC Persian (Jalali) Date-Time Picker
 * Version: 1.0.0
 * No external dependencies — pure vanilla JS
 *
 * Usage in HTML:
 *   <!-- visible display input -->
 *   <input type="text" id="starts-at-display" data-cmc-datepicker="cmc-field-starts-at" readonly>
 *   <!-- hidden ISO value (used for form submission) -->
 *   <input type="hidden" id="cmc-field-starts-at">
 *
 * JS init:
 *   CMCDatePicker.init();
 *
 * JS set value (e.g. when editing):
 *   CMCDatePicker.setValue("cmc-field-starts-at", "2025-03-21T10:30");
 */

const CMCDatePicker = (() => {

  /* -------------------------------------------------------
   * JALALI ↔ GREGORIAN CONVERSION
   * ------------------------------------------------------- */

  function g2j(gy, gm, gd) {
    let jy, jm, jd;
    const sal_a = [0,31,59 + (gy%4===0&&(gy%100!==0||gy%400===0)?1:0),90,120,151,181,212,243,273,304,334];
    gy -= 1600; gm--; gd--;
    let g_day_no = 365*gy + Math.floor((gy+3)/4) - Math.floor((gy+99)/100) + Math.floor((gy+399)/400);
    g_day_no += sal_a[gm] + gd;
    let j_day_no = g_day_no - 79;
    const j_np = Math.floor(j_day_no/12053); j_day_no %= 12053;
    jy = 979 + 33*j_np + 4*Math.floor(j_day_no/1461);
    j_day_no %= 1461;
    if (j_day_no >= 366) { jy += Math.floor((j_day_no-1)/365); j_day_no = (j_day_no-1)%365; }
    for (let i=0; i<11 && j_day_no>=[31,31,31,31,31,31,30,30,30,30,30,29][i]; i++) { j_day_no -= [31,31,31,31,31,31,30,30,30,30,30,29][i]; }
    jm = i + 1; jd = j_day_no + 1;
    return [jy, jm, jd];
  }

  function j2g(jy, jm, jd) {
    let gy, gm, gd;
    jy -= 979; jm--; jd--;
    let j_day_no = 365*jy + Math.floor(jy/33)*8 + Math.floor((jy%33+3)/4);
    for (let i=0;i<jm;i++) j_day_no += [31,31,31,31,31,31,30,30,30,30,30,29][i];
    j_day_no += jd;
    let g_day_no = j_day_no + 79;
    gy = 1600 + 400*Math.floor(g_day_no/146097); g_day_no %= 146097;
    let leap = true;
    if (g_day_no >= 36525) { g_day_no--; gy += 100*Math.floor(g_day_no/36524); g_day_no %= 36524; if (g_day_no >= 365) g_day_no++; else leap = false; }
    gy += 4*Math.floor(g_day_no/1461); g_day_no %= 1461;
    if (g_day_no >= 366) { leap = false; g_day_no--; gy += Math.floor(g_day_no/365); g_day_no %= 365; }
    const sal_a = [0,31,28+(leap?1:0),31,30,31,30,31,31,30,31,30,31];
    gm = 0; while (gm<12 && g_day_no>=sal_a[gm]) { g_day_no -= sal_a[gm]; gm++; }
    gd = g_day_no + 1;
    return [gy, gm, gd];
  }

  function daysInMonth(jy, jm) {
    if (jm <= 6) return 31;
    if (jm <= 11) return 30;
    // Leap check simplified
    const rem = ((jy - 474) % 2820 + 474 + 38) * 682 % 2816;
    return rem < 682 ? 30 : 29;
  }

  function dayOfWeek(jy, jm, jd) {
    const [gy, gm, gd] = j2g(jy, jm, jd);
    const dow = new Date(gy, gm - 1, gd).getDay(); // 0=Sun..6=Sat
    // Convert to Sat=0..Fri=6
    return (dow + 1) % 7;
  }

  const P = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
  const toPer  = n  => String(n).replace(/\d/g, d => P[d]);
  const pad2   = n  => String(n).padStart(2, "0");
  const MONTHS = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
  const WDAYS  = ['ش','ی','د','س','چ','پ','ج'];

  /* -------------------------------------------------------
   * STATE
   * ------------------------------------------------------- */
  let popup   = null;
  let anchor  = null;
  let ps      = {}; // picker state: {jy, jm, jd, h, min, hiddenId, displayEl}

  /* -------------------------------------------------------
   * PUBLIC: init — attach to all [data-cmc-datepicker] inputs
   * ------------------------------------------------------- */
  function init() {
    document.querySelectorAll("[data-cmc-datepicker]").forEach(el => attach(el));
    document.addEventListener("click", e => {
      if (popup && !popup.contains(e.target) && e.target !== anchor) close();
    });
  }

  function attach(displayEl) {
    displayEl.readOnly = true;
    displayEl.style.cursor = "pointer";
    displayEl.addEventListener("click", e => { e.stopPropagation(); open(displayEl); });
  }

  /* -------------------------------------------------------
   * OPEN
   * ------------------------------------------------------- */
  function open(displayEl) {
    if (popup) close();
    anchor = displayEl;

    const hiddenId = displayEl.dataset.cmcDatepicker;
    const hiddenEl = document.getElementById(hiddenId);
    const iso      = hiddenEl?.value ?? "";

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
    popup = document.createElement("div");
    popup.className = "cmc-dp";
    popup.id        = "cmc-dp-popup";
    document.body.appendChild(popup);
    render();
    position();
  }

  /* -------------------------------------------------------
   * CLOSE
   * ------------------------------------------------------- */
  function close() {
    popup?.remove();
    popup  = null;
    anchor = null;
  }

  /* -------------------------------------------------------
   * RENDER
   * ------------------------------------------------------- */
  function render() {
    if (!popup) return;
    const { jy, jm, jd, h, min } = ps;
    const dim = daysInMonth(jy, jm);
    const fwd = dayOfWeek(jy, jm, 1); // first day of month, 0=Sat

    // Build calendar grid
    let grid = "<tr>";
    let d = 1 - fwd;
    let rows = 0;
    while (d <= dim) {
      for (let col = 0; col < 7; col++) {
        if (d < 1 || d > dim) {
          grid += `<td class="cmc-dp__day cmc-dp__day--out"></td>`;
        } else {
          const isToday = (() => {
            const n = new Date();
            const [ty,tm,td] = g2j(n.getFullYear(), n.getMonth()+1, n.getDate());
            return ty===jy && tm===jm && td===d;
          })();
          grid += `<td class="cmc-dp__day${d===jd?" is-sel":""}${isToday&&d!==jd?" is-today":""}" data-d="${d}">${toPer(d)}</td>`;
        }
        d++;
      }
      grid += "</tr>";
      rows++;
      if (d > dim && rows >= 4) break;
      if (d > dim) break;
      grid += "<tr>";
    }

    popup.innerHTML = `
      <div class="cmc-dp__head">
        <button class="cmc-dp__nav" data-a="py">«</button>
        <button class="cmc-dp__nav" data-a="pm">‹</button>
        <div class="cmc-dp__htitle">
          <b>${MONTHS[jm-1]}</b>
          <span>${toPer(jy)}</span>
        </div>
        <button class="cmc-dp__nav" data-a="nm">›</button>
        <button class="cmc-dp__nav" data-a="ny">»</button>
      </div>
      <table class="cmc-dp__cal">
        <thead><tr>${WDAYS.map(w=>`<th>${w}</th>`).join("")}</tr></thead>
        <tbody>${grid}</tbody>
      </table>
      <div class="cmc-dp__time">
        <i class="ti ti-clock" style="color:var(--cmc-primary-500);font-size:15px"></i>
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
        <button class="cmc-btn cmc-btn--primary cmc-btn--sm cmc-dp__ok">تأیید</button>
        <button class="cmc-btn cmc-btn--ghost cmc-btn--sm cmc-dp__clear">پاک کردن</button>
      </div>
    `;

    // Navigation events
    popup.querySelectorAll("[data-a]").forEach(b => b.addEventListener("click", e => {
      e.stopPropagation();
      const a = b.dataset.a;
      if (a==="pm") { ps.jm--; if (ps.jm<1) { ps.jm=12; ps.jy--; } }
      if (a==="nm") { ps.jm++; if (ps.jm>12) { ps.jm=1; ps.jy++; } }
      if (a==="py") ps.jy--;
      if (a==="ny") ps.jy++;
      render();
    }));

    // Day click
    popup.querySelectorAll(".cmc-dp__day:not(.cmc-dp__day--out)").forEach(cell => {
      cell.addEventListener("click", e => {
        e.stopPropagation();
        ps.jd = parseInt(cell.dataset.d);
        render();
      });
    });

    // Time buttons
    popup.querySelectorAll("[data-t]").forEach(b => b.addEventListener("click", e => {
      e.stopPropagation();
      const t = b.dataset.t;
      if (t==="hu") ps.h  = (ps.h + 1) % 24;
      if (t==="hd") ps.h  = (ps.h - 1 + 24) % 24;
      if (t==="mu") ps.min = (ps.min + 5) % 60;
      if (t==="md") ps.min = (ps.min - 5 + 60) % 60;
      document.getElementById("cmc-dp-h").textContent = toPer(pad2(ps.h));
      document.getElementById("cmc-dp-m").textContent = toPer(pad2(ps.min));
    }));

    // Confirm
    popup.querySelector(".cmc-dp__ok")?.addEventListener("click", e => {
      e.stopPropagation();
      const { jy, jm, jd, h, min, hiddenId, displayEl } = ps;
      const [gy, gm, gd] = j2g(jy, jm, jd);
      const iso     = `${gy}-${pad2(gm)}-${pad2(gd)}T${pad2(h)}:${pad2(min)}`;
      const persian = `${toPer(jy)}/${toPer(pad2(jm))}/${toPer(pad2(jd))}  ${toPer(pad2(h))}:${toPer(pad2(min))}`;
      displayEl.value = persian;
      const hEl = document.getElementById(hiddenId);
      if (hEl) hEl.value = iso;
      close();
    });

    // Clear
    popup.querySelector(".cmc-dp__clear")?.addEventListener("click", e => {
      e.stopPropagation();
      const { hiddenId, displayEl } = ps;
      displayEl.value = "";
      const hEl = document.getElementById(hiddenId);
      if (hEl) hEl.value = "";
      close();
    });
  }

  /* -------------------------------------------------------
   * POSITION — smart positioning near the anchor
   * ------------------------------------------------------- */
  function position() {
    const rect   = anchor.getBoundingClientRect();
    const popH   = 380;
    const spaceB = window.innerHeight - rect.bottom;
    const top    = spaceB > popH ? rect.bottom + window.scrollY + 6 : rect.top + window.scrollY - popH - 6;
    const left   = Math.max(10, rect.right - 300 + window.scrollX);
    popup.style.top  = `${top}px`;
    popup.style.left = `${left}px`;
  }

  /* -------------------------------------------------------
   * PUBLIC: setValue — set value programmatically
   * hiddenId: id of the hidden input
   * iso: ISO string like "2025-03-21T10:30"
   * ------------------------------------------------------- */
  function setValue(hiddenId, iso) {
    const hEl = document.getElementById(hiddenId);
    if (!hEl) return;
    hEl.value = iso;

    // Find display input: it has data-cmc-datepicker="hiddenId"
    const dEl = document.querySelector(`[data-cmc-datepicker="${hiddenId}"]`);
    if (dEl && iso) {
      const d = new Date(iso);
      const [jy, jm, jd] = g2j(d.getFullYear(), d.getMonth()+1, d.getDate());
      const h = d.getHours(), min = d.getMinutes();
      dEl.value = `${toPer(jy)}/${toPer(pad2(jm))}/${toPer(pad2(jd))}  ${toPer(pad2(h))}:${toPer(pad2(min))}`;
    } else if (dEl) {
      dEl.value = "";
    }
  }

  return { init, setValue };
})();