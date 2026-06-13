# CMC Admin Panel — راهنمای کامل
WooCommerce Flash Sale Plugin · v1.0.0

---

## ساختار فایل‌ها

```
cmc-admin/
├── base.css          ← توکن‌های طراحی، ریست، لایوت، سایدبار، تاپبار
├── components.css    ← همه کامپوننت‌های تکرارشونده
└── dashboard.html    ← صفحه داشبورد (نمونه کامل)
```

ترتیب import اجباری:
```html
<link rel="stylesheet" href="base.css">
<link rel="stylesheet" href="components.css">
<link rel="stylesheet" href="dashboard.css">  <!-- اختیاری: CSS مختص هر صفحه -->
```

---

## پیشوند کلاس‌ها

همه کلاس‌ها و ID‌ها با `cmc-` شروع می‌شوند تا با WordPress و WooCommerce تداخل نداشته باشند.

```
cmc-panel         ← wrapper اصلی (همه CSS داخل این scope است)
cmc-sidebar       ← سایدبار
cmc-topbar        ← نوار بالا
cmc-content       ← محتوای اصلی
cmc-card          ← کارت
cmc-btn           ← دکمه
cmc-badge         ← برچسب وضعیت
...
```

---

## ۱. LAYOUT — لایوت اصلی

```html
<div class="cmc-panel">         <!-- wrapper اصلی، RTL، فونت فارسی -->
  <aside class="cmc-sidebar">   <!-- سایدبار ۲۶۰px -->
    ...
  </aside>
  <div class="cmc-main">
    <header class="cmc-topbar"> <!-- نوار بالا ۶۰px -->
      ...
    </header>
    <main class="cmc-content">  <!-- محتوای اصلی -->
      <div class="cmc-content-inner"> <!-- max-width: 1280px -->
        ...
      </div>
    </main>
  </div>
</div>
```

---

## ۲. SIDEBAR — سایدبار

### آیتم ناوبری
```html
<a href="#" class="cmc-nav__item is-active" data-page="dashboard">
  <span class="cmc-nav__item-icon"><i class="ti ti-bolt"></i></span>
  <span class="cmc-nav__item-label">داشبورد</span>
  <span class="cmc-nav__item-badge">۴</span>           <!-- اختیاری -->
  <span class="cmc-nav__item-badge cmc-nav__item-badge--accent">۱</span>  <!-- نارنجی -->
</a>
```

### بخش‌بندی منو
```html
<p class="cmc-nav__section">تنظیمات</p>
```

---

## ۳. TOPBAR — نوار بالا

```html
<header class="cmc-topbar">
  <div class="cmc-topbar__title">
    <span class="cmc-topbar__page">داشبورد</span>
    <span class="cmc-topbar__sub">زیرعنوان / تاریخ</span>
  </div>
  <div class="cmc-topbar__spacer"></div>
  <div class="cmc-topbar__actions">
    <button class="cmc-topbar__icon-btn"><i class="ti ti-bell"></i></button>
    <button class="cmc-btn cmc-btn--primary cmc-btn--sm">عملیات</button>
  </div>
</header>
```

---

## ۴. CARD — کارت

```html
<!-- کارت پایه -->
<div class="cmc-card">
  <div class="cmc-card__header">
    <div>
      <div class="cmc-card__title">عنوان</div>
      <div class="cmc-card__subtitle">زیرعنوان</div>    <!-- اختیاری -->
    </div>
    <a href="#" class="cmc-card__action">همه</a>        <!-- اختیاری -->
  </div>
  <!-- محتوا -->
</div>

<!-- بدون padding -->
<div class="cmc-card cmc-card--flush"> ... </div>

<!-- با border رنگی -->
<div class="cmc-card cmc-card--bordered"> ... </div>
```

---

## ۵. STAT CARD — کارت آماری

```html
<div class="cmc-stat-card">
  <div class="cmc-stat-card__header">
    <span class="cmc-stat-card__label">عنوان</span>
    <span class="cmc-stat-card__icon cmc-stat-card__icon--purple">
      <i class="ti ti-bolt"></i>
    </span>
  </div>
  <div class="cmc-stat-card__value">۱۲.۴M</div>
  <div class="cmc-stat-card__change cmc-stat-card__change--up">
    <i class="ti ti-arrow-up"></i> توضیح
  </div>
</div>
```

**آیکون رنگ‌ها:** `--purple` `--orange` `--green` `--blue` `--red` `--yellow`
**تغییر:** `--up` (سبز) · `--down` (قرمز) · `--flat` (خاکستری)

---

## ۶. BUTTON — دکمه

```html
<!-- سایزها -->
<button class="cmc-btn cmc-btn--primary cmc-btn--sm">کوچک ۳۴px</button>
<button class="cmc-btn cmc-btn--primary">متوسط ۴۴px</button>
<button class="cmc-btn cmc-btn--primary cmc-btn--lg">بزرگ ۵۲px</button>

<!-- نوع‌ها -->
<button class="cmc-btn cmc-btn--primary">اصلی</button>
<button class="cmc-btn cmc-btn--secondary">ثانویه</button>
<button class="cmc-btn cmc-btn--ghost">شفاف</button>
<button class="cmc-btn cmc-btn--danger">خطرناک</button>
<button class="cmc-btn cmc-btn--accent">فلش سیل</button>

<!-- آیکون‌دار -->
<button class="cmc-btn cmc-btn--primary">
  <i class="ti ti-plus"></i> افزودن
</button>

<!-- آیکون تنها -->
<button class="cmc-btn cmc-btn--secondary cmc-btn--icon">
  <i class="ti ti-edit"></i>
</button>

<!-- حالت‌ها -->
<button class="cmc-btn cmc-btn--primary is-loading">در حال اجرا</button>
<button class="cmc-btn cmc-btn--primary" disabled>غیرفعال</button>
```

---

## ۷. BADGE — برچسب

```html
<span class="cmc-badge cmc-badge--active">
  <span class="cmc-badge__dot"></span> فعال
</span>
<span class="cmc-badge cmc-badge--draft">پیش‌نویس</span>
<span class="cmc-badge cmc-badge--flash">فوری</span>
<span class="cmc-badge cmc-badge--primary">بنفش</span>
<span class="cmc-badge cmc-badge--danger">خطا</span>
<span class="cmc-badge cmc-badge--warning">هشدار</span>
<span class="cmc-badge cmc-badge--info">اطلاعات</span>
```

---

## ۸. FORM ELEMENTS — فرم

```html
<!-- Input -->
<div class="cmc-form-group">
  <label class="cmc-label cmc-label--required">عنوان کمپین</label>
  <div class="cmc-input-wrap">
    <i class="ti ti-bolt cmc-input-wrap__icon"></i>
    <input type="text" class="cmc-input" placeholder="مثلاً: فلش سیل یلدا">
  </div>
  <span class="cmc-form-hint">حداکثر ۶۰ کاراکتر</span>
</div>

<!-- Select -->
<select class="cmc-select">
  <option>انتخاب کنید</option>
</select>

<!-- Textarea -->
<textarea class="cmc-textarea" rows="4" placeholder="توضیحات..."></textarea>

<!-- Toggle -->
<label class="cmc-toggle">
  <input type="checkbox" class="cmc-toggle__input" checked>
  <div class="cmc-toggle__track"><div class="cmc-toggle__thumb"></div></div>
  <span class="cmc-toggle__label">فعال‌سازی کمپین</span>
</label>

<!-- Checkbox -->
<label class="cmc-checkbox">
  <input type="checkbox" class="cmc-checkbox__input">
  <span class="cmc-checkbox__label">انتخاب همه</span>
</label>
```

---

## ۹. TABLE — جدول

```html
<div class="cmc-table-wrap">
  <table class="cmc-table">
    <thead>
      <tr>
        <th>نام محصول</th>
        <th>وضعیت</th>
        <th>قیمت</th>
        <th>عملیات</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td class="cmc-table__cell--bold">قالب Nexus</td>
        <td><span class="cmc-badge cmc-badge--active">فعال</span></td>
        <td>
          <span class="cmc-table__cell--price">۱۸۰K</span>
          <span class="cmc-table__cell--strike">۳۵۰K</span>
        </td>
        <td>
          <div class="cmc-table__row-actions">
            <button class="cmc-btn cmc-btn--ghost cmc-btn--icon cmc-btn--sm">
              <i class="ti ti-edit"></i>
            </button>
            <button class="cmc-btn cmc-btn--ghost cmc-btn--icon cmc-btn--sm">
              <i class="ti ti-trash"></i>
            </button>
          </div>
        </td>
      </tr>
    </tbody>
  </table>
</div>
```

---

## ۱۰. PROGRESS BAR

```html
<div class="cmc-progress">
  <div class="cmc-progress__meta">
    <span>موجودی</span>
    <span class="cmc-progress__meta-value">۳۸٪</span>
  </div>
  <div class="cmc-progress__track">
    <div class="cmc-progress__fill cmc-progress__fill--accent" style="width:38%"></div>
  </div>
</div>
```

**رنگ fill:** (پیشفرض بنفش) · `--accent` · `--success` · `--warning` · `--danger`

---

## ۱۱. MODAL — مودال

```html
<!-- Trigger -->
<button onclick="CMC.modal.open('#cmc-modal-example')">باز کردن</button>

<!-- Modal markup -->
<div class="cmc-modal-overlay" id="cmc-modal-example">
  <div class="cmc-modal">
    <div class="cmc-modal__header">
      <span class="cmc-modal__title">عنوان مودال</span>
      <button class="cmc-modal__close"><i class="ti ti-x"></i></button>
    </div>
    <div class="cmc-modal__body">
      <!-- محتوا -->
    </div>
    <div class="cmc-modal__footer">
      <button class="cmc-btn cmc-btn--primary">ذخیره</button>
      <button class="cmc-btn cmc-btn--secondary" onclick="CMC.modal.close('#cmc-modal-example')">انصراف</button>
    </div>
  </div>
</div>
```

---

## ۱۲. TOAST — اعلان سریع

```js
// در JS استفاده کن:
CMC.toast("کمپین ذخیره شد", "success");
CMC.toast("خطا در اتصال", "danger");
CMC.toast("در حال پردازش...", "warning");
```

---

## ۱۳. DROPDOWN MENU

```html
<div class="cmc-dropdown">
  <button class="cmc-btn cmc-btn--secondary cmc-btn--sm" data-cmc-dropdown-trigger>
    عملیات <i class="ti ti-chevron-down"></i>
  </button>
  <div class="cmc-dropdown__menu">
    <div class="cmc-dropdown__item">
      <i class="ti ti-edit"></i> ویرایش
    </div>
    <div class="cmc-dropdown__item">
      <i class="ti ti-copy"></i> کپی
    </div>
    <div class="cmc-dropdown__divider"></div>
    <div class="cmc-dropdown__item cmc-dropdown__item--danger">
      <i class="ti ti-trash"></i> حذف
    </div>
  </div>
</div>
```

---

## ۱۴. TABS — تب‌ها

```html
<div class="cmc-tabs">
  <div class="cmc-tab is-active">همه</div>
  <div class="cmc-tab">فعال</div>
  <div class="cmc-tab">پیش‌نویس</div>
  <div class="cmc-tab">آرشیو</div>
</div>
```

---

## ۱۵. ALERT — پیام هشدار

```html
<div class="cmc-alert cmc-alert--warning">
  <i class="ti ti-alert-triangle cmc-alert__icon"></i>
  <div class="cmc-alert__body">
    <div class="cmc-alert__title">موجودی رو به اتمام است</div>
    محصولات این کمپین کمتر از ۴۰٪ موجودی دارند.
  </div>
</div>
```

**انواع:** `--info` · `--success` · `--warning` · `--danger`

---

## ۱۶. GRID HELPERS

```html
<!-- ۲ ستون -->
<div class="cmc-grid cmc-grid--2"> ... </div>

<!-- ۳ ستون -->
<div class="cmc-grid cmc-grid--3"> ... </div>

<!-- ۴ ستون -->
<div class="cmc-grid cmc-grid--4"> ... </div>

<!-- محتوا + سایدبار راست -->
<div class="cmc-grid cmc-grid--main"> ... </div>

<!-- فاصله‌دهی عمودی بین ردیف‌ها -->
<div class="cmc-mb-5"> ... </div>
```

---

## ۱۷. CSS VARIABLES — متغیرهای کلیدی

| متغیر | مقدار |
|---|---|
| `--cmc-primary-500` | `#6C47FF` |
| `--cmc-accent` | `#FF6B35` |
| `--cmc-bg` | `#F7F8FC` |
| `--cmc-surface` | `#FFFFFF` |
| `--cmc-border` | `#E5E7EB` |
| `--cmc-radius-md` | `14px` |
| `--cmc-radius-lg` | `20px` |
| `--cmc-sidebar-width` | `260px` |
| `--cmc-topbar-height` | `60px` |
| `--cmc-transition` | `200ms ease-out` |

---

## JS API

```js
CMC.toast(message, type)        // نمایش toast
CMC.modal.open(selector)        // باز کردن مودال
CMC.modal.close(selector)       // بستن مودال
```

---

## قوانین کلی

- همه کلاس‌ها با `cmc-` شروع می‌شوند
- state classes: `is-active`, `is-open`, `is-loading`, `is-disabled`, `is-error`
- هرگز CSS را مستقیم روی تگ‌های HTML ننویس — همیشه از کلاس استفاده کن
- انیمیشن فقط از طریق `--cmc-transition` (200ms ease-out)
- کامنت‌ها به انگلیسی

---

*CMC Flash Sale Plugin — Takix · takix.ir*
