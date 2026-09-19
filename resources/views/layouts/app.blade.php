<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    {{-- Pages that set a title get "Page · Shop"; the rest just the shop name. --}}
    <title>@hasSection('title')@yield('title') · @endif{{ config('app.name') }}</title>
    <meta name="description" content="@yield('meta_description', 'Fresh groceries delivered across Cambodia. Pay by KHQR or cash on delivery.')">

    {{-- A 1200px PNG is not a tab icon: browsers want a small, opaque one. --}}
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Kantumruy+Pro:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        /* ---------------------------------------------------------------
           Storefront tokens. Colours are roles, so a tweak lands once.
        --------------------------------------------------------------- */
        :root {
            --bg:        #f7f9f8;
            --surface:   #ffffff;
            --surface-2: #f1f5f2;
            --line:      #e7ecea;
            --line-2:    #d8e0dc;

            --ink:       #0f1714;
            --ink-2:     #5c6b64;
            --ink-3:     #93a49b;

            --brand:     #0f9d5c;
            --brand-2:   #0b7d49;
            --brand-wash:#e8f6ef;
            --ring:      rgba(15, 157, 92, .22);

            --warn:      #c07f0a;
            --bad:       #cf3b4a;
            --bad-wash:  #fdecee;

            --r-sm: 12px;
            --r:    16px;
            --r-lg: 22px;

            --sh-1: 0 1px 2px rgba(12, 28, 20, .05);
            --sh-2: 0 6px 22px rgba(12, 28, 20, .08);
            --sh-3: 0 18px 44px rgba(12, 28, 20, .14);
        }

        * { -webkit-font-smoothing: antialiased; }

        body {
            background: var(--bg);
            color: var(--ink);
            /* Plus Jakarta Sans for Latin; Kantumruy Pro carries the Khmer
               script and takes over entirely when the page is in Khmer. */
            font-family: 'Plus Jakarta Sans', 'Kantumruy Pro', system-ui, sans-serif;
            font-size: 15px;
            letter-spacing: -.005em;
        }

        h1, h2, h3, h4, h5, h6 {
            letter-spacing: -.025em;
            font-weight: 700;
            color: var(--ink);
        }

        .display-hero {
            font-size: clamp(30px, 5vw, 50px);
            font-weight: 800;
            letter-spacing: -.035em;
            line-height: 1.08;
        }

        .text-muted { color: var(--ink-2) !important; }
        .lead-sm { color: var(--ink-2); font-size: 15px; }

        /* Category chips — the quick way into the catalogue. */
        .chip-row { display: flex; gap: 9px; overflow-x: auto; padding-bottom: 4px; }
        .chip-row::-webkit-scrollbar { height: 0; }

        .chip {
            flex: 0 0 auto;
            display: inline-flex; align-items: center; gap: 7px;
            padding: 8px 16px;
            border-radius: 999px;
            border: 1px solid var(--line-2);
            background: var(--surface);
            color: var(--ink);
            font-size: 14px; font-weight: 600;
            text-decoration: none;
            transition: .15s ease;
            white-space: nowrap;
        }

        .chip:hover { border-color: var(--brand); color: var(--brand); }
        .chip.on { background: var(--brand); border-color: var(--brand); color: #fff; }
        .chip .n { opacity: .65; font-weight: 500; }
        .chip.on .n { opacity: .85; }

        /* Category tiles */
        .cat-tile {
            display: block; position: relative;
            border-radius: var(--r-lg);
            overflow: hidden;
            aspect-ratio: 4 / 3;
            background: var(--surface-2);
            text-decoration: none;
            box-shadow: var(--sh-1);
            transition: transform .2s ease, box-shadow .2s ease;
        }

        .cat-tile:hover { transform: translateY(-4px); box-shadow: var(--sh-2); }
        .cat-tile img { width: 100%; height: 100%; object-fit: cover; }

        .cat-tile .veil {
            position: absolute; inset: 0;
            background: linear-gradient(to top, rgba(6, 20, 14, .82) 0%, rgba(6, 20, 14, .12) 55%, transparent 100%);
        }

        .cat-tile .cap {
            position: absolute; left: 0; right: 0; bottom: 0;
            padding: 16px 18px; color: #fff;
        }

        .cat-tile .cap b { font-size: 17px; font-weight: 700; letter-spacing: -.02em; }
        .cat-tile .cap span { display: block; font-size: 12.5px; opacity: .82; margin-top: 2px; }

        .cat-tile .emoji {
            position: absolute; inset: 0;
            display: grid; place-items: center;
            font-size: 62px;
        }

        /* Buttons */
        .btn { border-radius: 999px; font-weight: 600; }
        .btn-success { background: var(--brand); border-color: var(--brand); }
        .btn-success:hover { background: var(--brand-2); border-color: var(--brand-2); }
        .btn-outline-success { color: var(--brand); border-color: var(--brand); }
        .btn-outline-success:hover { background: var(--brand); border-color: var(--brand); }

        .form-control, .form-select { border-radius: var(--r-sm); border-color: var(--line-2); font-size: 15px; }
        .form-control:focus, .form-select:focus { border-color: var(--brand); box-shadow: 0 0 0 3px var(--ring); }

        /* An input-group prefix ("@", "$") sits flush with its field: same
           border radius on the outer corners, same muted tone. */
        .input-group-text {
            border-radius: var(--r-sm) 0 0 var(--r-sm);
            border-color: var(--line-2);
            background: var(--surface-2);
            color: var(--ink-2);
            font-size: 15px;
        }
        .input-group > .form-control { border-radius: 0 var(--r-sm) var(--r-sm) 0; }

        .section-head {
            display: flex; align-items: flex-end; justify-content: space-between;
            gap: 16px; margin-bottom: 18px;
        }

        .section-head h2 { font-size: 24px; margin: 0; }
        .section-head a { font-size: 14px; font-weight: 600; text-decoration: none; }

        /* ---------------------------------------------------- product card */
        .p-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--r-lg);
            overflow: hidden;
            display: flex; flex-direction: column;
            transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
        }

        .p-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--sh-2);
            border-color: var(--line-2);
        }

        .p-card-media {
            position: relative; display: block;
            aspect-ratio: 1 / 1;
            background: var(--surface-2);
            overflow: hidden;
        }

        .p-card-media img { width: 100%; height: 100%; object-fit: cover; transition: transform .3s ease; }
        .p-card:hover .p-card-media img { transform: scale(1.05); }
        .p-card-emoji { position: absolute; inset: 0; display: grid; place-items: center; font-size: 52px; }

        .p-card-flag {
            position: absolute; left: 10px; top: 10px;
            padding: 3px 10px; border-radius: 999px;
            font-size: 11px; font-weight: 700;
        }
        .flag-out { background: var(--bad); color: #fff; }
        .flag-low { background: #fff; color: var(--warn); box-shadow: var(--sh-1); }

        .p-card-body { padding: 13px 15px 15px; display: flex; flex-direction: column; gap: 3px; flex: 1; }

        .p-card-cat {
            font-size: 11.5px; font-weight: 700; letter-spacing: .04em;
            text-transform: uppercase; color: var(--brand);
            text-decoration: none;
        }

        .p-card-name {
            font-weight: 600; color: var(--ink); text-decoration: none;
            line-height: 1.35; font-size: 15px;
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
        }
        .p-card-name:hover { color: var(--brand); }

        .p-card-foot {
            display: flex; align-items: center; justify-content: space-between;
            gap: 10px; margin-top: auto; padding-top: 10px;
        }

        .p-card-price { font-size: 18px; font-weight: 800; color: var(--brand); letter-spacing: -.02em; }

        .p-card-add {
            width: 34px; height: 34px; flex: 0 0 34px;
            border-radius: 50%; border: 0;
            background: var(--brand-wash); color: var(--brand);
            font-size: 19px; font-weight: 700; line-height: 1;
            display: grid; place-items: center; text-decoration: none;
            transition: .15s ease;
        }
        .p-card-add:hover { background: var(--brand); color: #fff; }

        /* ---------------------------------------------------- touch screens */
        @media (max-width: 575.98px) {
            /* Safari zooms the page whenever a focused field is smaller than
               16px, and does not zoom back out — so every tap into a form
               left the site enlarged and scrolled off to one side.
               !important because page-level styles like `.cat-search input`
               outrank a bare element selector however this rule is ordered,
               and one stray 15px field is enough to trigger the zoom. */
            input, select, textarea,
            .form-control, .form-select { font-size: 16px !important; }

            /* The primary action on the catalogue. 34px is under the size a
               fingertip hits reliably, so add-to-cart was easy to miss. */
            .p-card-add {
                width: 44px; height: 44px; flex: 0 0 44px;
                font-size: 22px;
            }
        }

        /* A promotion: the price being charged, then what it was. */
        .p-sale { color: var(--brand-2); }
        .p-was, .pd-was {
            margin-left: 6px; font-weight: 600;
            color: var(--ink-3); font-size: .78em;
        }
        .pd-off {
            display: inline-block; margin-left: 8px;
            padding: 3px 10px; border-radius: 999px;
            background: var(--bad-wash); color: var(--bad);
            font-size: 13px; font-weight: 800; vertical-align: middle;
        }

        /* Corner flag on a card, so a deal is visible while scrolling. */
        /* The older card markup on the home and catalogue pages has no
           positioning context of its own. */
        .product-card, .product-image { position: relative; }

        .p-flag {
            position: absolute; top: 10px; left: 10px; z-index: 2;
            padding: 4px 10px; border-radius: 999px;
            background: var(--bad); color: #fff;
            font-size: 11.5px; font-weight: 800; letter-spacing: .01em;
        }
        .p-flag-soon { background: var(--ink); }

        /* Cart line thumbnail. */
        .cart-thumb {
            flex: 0 0 80px; width: 80px; height: 80px;
            border-radius: 14px; overflow: hidden;
            background: var(--surface-2); border: 1px solid var(--line);
            display: grid; place-items: center; text-decoration: none;
        }
        .cart-thumb img { width: 100%; height: 100%; object-fit: cover; }

        /* ------------------------------------------------------------ cart */
        .cart-title {
            display: flex; align-items: center; gap: 12px;
            margin: 0 0 4px;
            font-size: clamp(26px, 4vw, 34px);
            font-weight: 800; letter-spacing: -.03em;
            color: var(--ink);
        }

        /* A drawn trolley instead of the emoji: it takes the brand colour and
           sits on the text baseline the same way on every platform. */
        .cart-title-ico {
            display: grid; place-items: center;
            width: 44px; height: 44px; flex: 0 0 44px;
            border-radius: 14px;
            background: var(--brand-wash); color: var(--brand-2);
        }
        .cart-title-ico svg { width: 24px; height: 24px; }

        .cart-sub { color: var(--ink-2); font-size: 15px; }

        .cart-empty {
            text-align: center; padding: 64px 24px;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--r-lg);
            box-shadow: var(--sh-1);
        }
        .cart-empty-ico {
            display: grid; place-items: center;
            width: 76px; height: 76px; margin: 0 auto 16px;
            border-radius: 50%;
            background: var(--surface-2); color: var(--ink-3);
        }
        .cart-empty-ico svg { width: 36px; height: 36px; }

        .cart-empty-title {
            font-size: 19px; font-weight: 800; letter-spacing: -.02em;
            color: var(--ink); margin: 0 0 6px;
        }
        .cart-empty-body { color: var(--ink-2); font-size: 14.5px; margin: 0 0 18px; }

        /* One line per item: the product on the left, its controls on the right. */
        .cart-row {
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px;
        }
        .cart-row-main { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .cart-row-actions { display: flex; align-items: center; gap: 16px; }

        @media (max-width: 575.98px) {
            /* That single line needs about 540px. On a 375px screen it pushed
               the page sideways — on the last screen before checkout, of all
               places. Give the controls a line of their own instead. */
            .cart-row { flex-wrap: wrap; }
            .cart-row-main { flex: 1 1 100%; }

            .cart-row-actions {
                flex: 1 1 100%;
                justify-content: space-between;
                gap: 8px;
                margin-top: 10px;
            }

            /* Quantity, Update, price and Remove come to about 306px together,
               so nothing here can afford an indent or a wide field. */
            .cart-row-actions .btn-sm { padding: 7px 10px; }
            .cart-row-actions input[type="number"] { width: 58px !important; }
        }
        .cart-thumb-mark { font-size: 32px; line-height: 1; }

        /* ------------------------------------------------ search suggestions */
        .top-search { position: relative; }

        .sg-panel {
            position: absolute; top: calc(100% + 8px); left: 0; right: 0; z-index: 60;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--r);
            box-shadow: var(--sh-3);
            overflow: hidden auto;
            max-height: min(70vh, 460px);
        }

        .sg-head {
            padding: 9px 14px 5px;
            font-size: 11px; font-weight: 700; letter-spacing: .07em;
            text-transform: uppercase; color: var(--ink-3);
        }

        .sg-item {
            display: flex; align-items: center; gap: 11px;
            padding: 9px 14px;
            text-decoration: none; color: var(--ink);
        }
        .sg-item:hover, .sg-item.is-active { background: var(--surface-2); }

        .sg-thumb {
            flex: 0 0 40px; width: 40px; height: 40px;
            border-radius: 11px; overflow: hidden;
            background: var(--surface-2); border: 1px solid var(--line);
            display: grid; place-items: center; font-size: 16px;
        }
        .sg-thumb img { width: 100%; height: 100%; object-fit: cover; }

        .sg-name { display: block; font-size: 14px; font-weight: 600; line-height: 1.3; }
        .sg-meta { display: block; font-size: 12px; color: var(--ink-3); margin-top: 1px; }
        .sg-price { margin-left: auto; font-size: 14px; font-weight: 700; white-space: nowrap; }
        .sg-was { margin-left: 5px; font-size: 12px; font-weight: 600; color: var(--ink-3); }
        .sg-out { color: var(--ink-3); font-weight: 600; font-size: 12.5px; }

        .sg-all {
            display: block; padding: 11px 14px;
            border-top: 1px solid var(--line);
            font-size: 13px; font-weight: 700; color: var(--brand);
            text-decoration: none; text-align: center;
        }
        .sg-all:hover, .sg-all.is-active { background: var(--brand-wash); }

        .sg-empty { padding: 18px 14px; text-align: center; font-size: 13.5px; color: var(--ink-3); }

        /* ---------------------------------------------------------- footer */
        .site-footer {
            background: var(--surface);
            border-top: 1px solid var(--line);
            margin-top: 56px;
        }

        .footer-logo { width: 34px; height: 34px; object-fit: contain; border-radius: 9px; }
        .footer-brand { font-weight: 800; font-size: 17px; letter-spacing: -.03em; color: var(--ink); }
        .footer-text { color: var(--ink-2); font-size: 14px; line-height: 1.65; }

        .footer-head {
            font-size: 12px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .07em; color: var(--ink-3); margin-bottom: 12px;
        }

        .footer-list { list-style: none; padding: 0; margin: 0; }
        .footer-list li { margin-bottom: 9px; }

        .footer-list a {
            color: var(--ink-2); text-decoration: none; font-size: 14px;
            transition: color .14s ease;
        }
        .footer-list a:hover { color: var(--brand); }

        .footer-note {
            color: var(--ink-3); font-size: 13px; line-height: 1.55;
            margin-top: 12px;
        }

        .footer-zone {
            display: flex; justify-content: space-between; gap: 12px;
            font-size: 14px; color: var(--ink-2);
        }
        .footer-zone-meta { color: var(--ink-3); font-size: 13px; white-space: nowrap; }

        .pay-chip {
            display: inline-flex; align-items: center;
            padding: 5px 12px; border-radius: 999px;
            border: 1px solid var(--line-2);
            font-size: 12px; font-weight: 700; color: var(--ink-2);
        }

        .footer-base {
            display: flex; flex-wrap: wrap; gap: 10px;
            justify-content: space-between;
            padding: 18px 0;
            border-top: 1px solid var(--line);
            font-size: 13px; color: var(--ink-3);
        }

        /* ---------------------------------------------------------- navbar */
        .topbar {
            position: sticky; top: 0; z-index: 1030;
            background: rgba(255, 255, 255, .82);
            backdrop-filter: saturate(180%) blur(16px);
            border-bottom: 1px solid transparent;
            transition: border-color .2s ease, box-shadow .2s ease, background .2s ease;
        }

        /* Lifts off the page once you scroll, instead of sitting behind a
           hairline that is always there. */
        .topbar.is-scrolled {
            background: rgba(255, 255, 255, .92);
            border-bottom-color: var(--line);
            box-shadow: 0 6px 24px rgba(12, 28, 20, .07);
        }

        .topbar-inner {
            display: flex; align-items: center; gap: 14px;
            padding: 14px 0;
            transition: padding .2s ease;
        }
        .topbar.is-scrolled .topbar-inner { padding: 9px 0; }

        .brand {
            display: inline-flex; align-items: center; gap: 9px;
            font-weight: 800; font-size: 19px; letter-spacing: -.03em;
            color: var(--ink); text-decoration: none; white-space: nowrap;
        }
        .brand:hover { color: var(--ink); }

        .brand-logo {
            width: 38px; height: 38px;
            border-radius: 10px;
            object-fit: contain;
            flex: 0 0 38px;
        }

        .brand-mark {
            width: 34px; height: 34px; border-radius: 10px;
            background: var(--brand); color: #fff;
            display: grid; place-items: center; font-size: 17px;
            flex: 0 0 34px;
        }

        /* Search is the main way people find things, so it gets the middle. */
        .top-search { flex: 1 1 auto; max-width: 460px; position: relative; }

        .top-search input {
            width: 100%; height: 42px;
            padding: 0 15px 0 40px;
            border-radius: 999px;
            border: 1px solid var(--line-2);
            background: var(--surface-2);
            font-size: 14.5px;
            transition: .15s ease;
        }

        .top-search input:focus {
            outline: none; background: #fff;
            border-color: var(--brand);
            box-shadow: 0 0 0 3px var(--ring);
        }

        /* Hint for the keyboard shortcut, hidden once you are typing. */
        .top-search kbd {
            position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
            padding: 2px 7px; border-radius: 6px;
            border: 1px solid var(--line-2); background: var(--surface);
            color: var(--ink-3); font-size: 11px; font-weight: 700;
            font-family: inherit; line-height: 1.5; pointer-events: none;
            transition: opacity .14s ease;
        }
        .top-search input:focus ~ kbd,
        .top-search input:not(:placeholder-shown) ~ kbd { opacity: 0; }

        /* A drawn glyph rather than an emoji: it takes the colour of the field
           and renders the same on every platform. */
        .top-search .ico {
            position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
            color: var(--ink-3); pointer-events: none;
            display: grid; place-items: center;
            transition: color .14s ease;
        }
        .top-search .ico svg { width: 17px; height: 17px; display: block; }
        .top-search:focus-within .ico { color: var(--brand); }

        .top-links { display: flex; align-items: center; gap: 4px; }

        .top-link {
            position: relative;
            padding: 8px 14px; border-radius: 10px;
            color: var(--ink-2); text-decoration: none;
            font-size: 14.5px; font-weight: 600;
            white-space: nowrap;
            transition: color .14s ease, background .14s ease;
        }
        .top-link:hover { color: var(--ink); background: var(--surface-2); }

        /* An underline rather than a filled pill: it marks the page without
           competing with the cart button for attention. */
        .top-link.on { color: var(--ink); background: transparent; }
        .top-link.on::after {
            content: ''; position: absolute;
            left: 14px; right: 14px; bottom: 2px; height: 2px;
            border-radius: 2px; background: var(--brand);
        }

        .top-actions { display: flex; align-items: center; gap: 8px; margin-left: auto; }

        .icon-pill {
            height: 40px; min-width: 40px; padding: 0 12px;
            border-radius: 999px;
            border: 1px solid var(--line-2);
            background: var(--surface);
            color: var(--ink-2);
            display: inline-flex; align-items: center; justify-content: center; gap: 7px;
            font-size: 14px; font-weight: 700; text-decoration: none;
            transition: .14s ease;
        }
        .icon-pill:hover { border-color: var(--brand); color: var(--brand); }

        .cart-pill {
            height: 40px; padding: 0 17px;
            border-radius: 999px;
            background: var(--brand); color: #fff;
            display: inline-flex; align-items: center; gap: 8px;
            font-weight: 700; font-size: 14.5px; text-decoration: none;
            transition: .14s ease;
        }
        .cart-pill:hover { background: var(--brand-2); color: #fff; }

        .pill-ico { width: 18px; height: 18px; flex: 0 0 18px; }

        .cart-count {
            min-width: 21px; height: 21px; padding: 0 6px;
            border-radius: 999px;
            background: rgba(255,255,255,.26);
            display: grid; place-items: center;
            font-size: 12px; font-weight: 800;
        }

        .avatar-pill {
            display: inline-flex; align-items: center; gap: 9px;
            padding: 4px 13px 4px 4px;
            border-radius: 999px;
            border: 1px solid var(--line-2);
            background: var(--surface);
            color: var(--ink); text-decoration: none;
            font-weight: 600; font-size: 14px;
        }
        .avatar-pill:hover { border-color: var(--brand); color: var(--ink); }

        .avatar-pill img {
            width: 30px; height: 30px; border-radius: 50%;
            object-fit: cover; background: var(--brand-wash); flex: 0 0 30px;
        }

        .avatar-name {
            max-width: 110px; overflow: hidden;
            text-overflow: ellipsis; white-space: nowrap;
        }

        .dropdown-menu { border-radius: 14px; border-color: var(--line); box-shadow: var(--sh-3); padding: 7px; }
        .dropdown-item {
            display: flex; align-items: center; gap: 10px;
            border-radius: 9px; font-size: 14.5px; padding: 9px 12px;
            width: 100%; border: 0; background: transparent;
            color: var(--ink); text-align: left;
        }

        /* Icons inherit the row's colour, so they follow hover and the
           active state without a second rule each. */
        .dropdown-item .mi {
            display: inline-flex; flex: 0 0 18px;
            width: 18px; height: 18px; color: var(--ink-3);
        }
        .dropdown-item .mi svg { width: 100%; height: 100%; }
        .dropdown-item:hover .mi { color: currentColor; }

        .dropdown-item.is-signout { color: var(--bad); }
        .dropdown-item.is-signout:hover { background: var(--bad-wash); color: var(--bad); }
        .dropdown-item.is-signout .mi { color: currentColor; }
        .dropdown-item.active, .dropdown-item:active { background: var(--brand); color: #fff; }

        /* A second row on small screens, rather than cramming one line. */
        .mobile-row { display: none; }

        @media (max-width: 991px) {
            .top-links { display: none; }
            .top-search { display: none; }
            .mobile-row {
                display: flex; gap: 8px; align-items: center;
                padding-bottom: 12px;
            }
            .mobile-row .top-search { display: block; max-width: none; }
            .mobile-links {
                display: flex; gap: 4px; overflow-x: auto;
                padding-bottom: 10px;
            }
            .avatar-name { display: none; }
            .avatar-pill { padding: 4px; }
        }

        /* Khmer text mixed into an English page still needs the Khmer face. */
        .km { font-family: 'Kantumruy Pro', sans-serif; }

        .navbar-brand {
            font-size: 26px;
            font-weight: bold;
            color: #198754 !important;
        }

        .nav-link {
            font-weight: 500;
            margin-left: 10px;
        }

        .hero {
            background: linear-gradient(135deg, #198754, #43b97f);
            color: white;
            padding: clamp(44px, 9vw, 100px) 0;
            border-radius: 0 0 40px 40px;
        }

        /* Fixed at 52px this ran to four lines on a phone and pushed the
           whole hero past one screen, so the shop began below the fold. */
        .hero h1 {
            font-size: clamp(30px, 7.5vw, 52px);
            font-weight: bold;
            line-height: 1.12;
        }

        @media (max-width: 575.98px) {
            /* The break is placed for a wide screen; on a narrow one it just
               makes the ragged edge worse. Let the text wrap where it likes. */
            .hero h1 br { display: none; }

            .hero .lead { font-size: 16px; }
            .hero .btn-lg { width: 100%; }
        }

        .category-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            text-align: center;
            height: 100%;
            transition: 0.3s;
            border: none;
        }

        .category-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.08);
        }

        .product-card {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            border: none;
            transition: 0.3s;
            height: 100%;
        }

        .product-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.1);
        }

        .product-image {
            height: 200px;
            background: #eaf7ef;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 70px;
        }

        .price {
            color: #198754;
            font-size: 20px;
            font-weight: bold;
        }

        /* Bootstrap's pagination and focus rings default to blue; bring them
           onto the brand green used everywhere else on the site. */
        .pagination {
            --bs-pagination-color: #198754;
            --bs-pagination-hover-color: #146c43;
            --bs-pagination-hover-bg: #e8f3ec;
            --bs-pagination-hover-border-color: #b7dcc7;
            --bs-pagination-focus-color: #146c43;
            --bs-pagination-focus-bg: #e8f3ec;
            --bs-pagination-focus-box-shadow: 0 0 0 .25rem rgba(25, 135, 84, .25);
            --bs-pagination-active-bg: #198754;
            --bs-pagination-active-border-color: #198754;
        }

        .page-link:focus,
        .form-control:focus,
        .form-select:focus {
            border-color: #8fcdaa;
            box-shadow: 0 0 0 .25rem rgba(25, 135, 84, .25);
        }

        a {
            color: #198754;
        }

        a:hover {
            color: #146c43;
        }

        /* Bootstrap's checkboxes/radios are blue by default. */
        .form-check-input:checked {
            background-color: #198754;
            border-color: #198754;
        }

        .form-check-input:focus {
            border-color: #8fcdaa;
            box-shadow: 0 0 0 .25rem rgba(25, 135, 84, .25);
        }

        /* A Google display name can be very long; keep it from pushing the
           rest of the navbar around. */
        .user-menu-name {
            max-width: 170px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .user-menu-avatar {
            width: 32px;
            height: 32px;
            object-fit: cover;
            flex: 0 0 32px;
            background: #e6f2ea;
        }
    </style>
    {{-- Page-specific styles, so a page's CSS sits in <head> rather than
         halfway down the body. --}}
    @stack('styles')
</head>

<body>

<header class="topbar">
    <div class="container">

        <div class="topbar-inner">

            <a class="brand" href="{{ route('home') }}">
                @if($brandMark)
                    <img src="{{ asset($brandMark) }}" alt="{{ config('app.name') }}" class="brand-logo">
                @else
                    <span class="brand-mark">&#129382;</span>
                @endif
                <span class="d-none d-sm-inline">{{ config('app.name') }}</span>
            </a>

            {{-- Search earns the middle of the bar: it is how most people
                 actually navigate a shop. --}}
            <form class="top-search" action="{{ route('products.index') }}" method="GET" role="search"
                  data-suggest autocomplete="off">
                <span class="ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="7"></circle>
                        <path d="m20 20-3.6-3.6"></path>
                    </svg>
                </span>
                <input type="search" name="search" value="{{ request('search') }}"
                       placeholder="{{ __('site.nav.search') }}" aria-label="{{ __('site.nav.search') }}"
                       role="combobox" aria-expanded="false" aria-autocomplete="list">
                <kbd aria-hidden="true">/</kbd>
                <div class="sg-panel" hidden></div>
            </form>

            <nav class="top-links">
                <a class="top-link {{ request()->routeIs('home') ? 'on' : '' }}" href="{{ route('home') }}">@lang('site.nav.home')</a>
                <a class="top-link {{ request()->routeIs('products.*') ? 'on' : '' }}" href="{{ route('products.index') }}">@lang('site.nav.products')</a>
                <a class="top-link {{ request()->routeIs('categories.*') ? 'on' : '' }}" href="{{ route('categories.index') }}">@lang('site.nav.categories')</a>
            </nav>

            <div class="top-actions">



                @shopper
                    <a class="cart-pill" href="{{ auth()->check() ? route('cart.index') : route('login') }}">
                        <svg class="pill-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M2.5 3h2.1l2.2 11.2a1.8 1.8 0 0 0 1.8 1.4h8.4a1.8 1.8 0 0 0 1.8-1.4l1.4-6.9H6"></path>
                            <circle cx="9.5" cy="20" r="1.5"></circle>
                            <circle cx="17.5" cy="20" r="1.5"></circle>
                        </svg>
                        <span class="d-none d-sm-inline">@lang('site.nav.cart')</span>
                        @auth
                            @if(Auth::user()->cart?->totalItems())
                                <span class="cart-count">{{ Auth::user()->cart->totalItems() }}</span>
                            @endif
                        @endauth
                    </a>
                @else
                    {{-- Staff browse the shop but do not buy from it. --}}
                    <a class="cart-pill" href="{{ route('admin.dashboard') }}">
                        <svg class="pill-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="3"></circle>
                            <path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2v.2a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-2.9-1.2l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.7 1.7 0 0 0 3 15a2 2 0 1 1 0-4h.2a1.7 1.7 0 0 0 1.2-2.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.7 1.7 0 0 0 10 4.6a2 2 0 1 1 4 0v.2a1.7 1.7 0 0 0 2.9 1.2l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0 1.2 2.9 2 2 0 1 1 0 4h-.2a1.7 1.7 0 0 0-1.3.3Z"></path>
                        </svg>
                        <span class="d-none d-sm-inline">Admin</span>
                    </a>
                @endshopper

                @guest
                    <a class="icon-pill d-none d-md-inline-flex" href="{{ route('login') }}">@lang('site.nav.login')</a>
                    <a class="cart-pill" style="background: var(--ink);" href="{{ route('register') }}">
                        @lang('site.nav.register')
                    </a>
                @else
                    <div class="dropdown">
                        <a class="avatar-pill" href="#" data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="{{ Auth::user()->avatarUrl() }}" alt=""
                                 onerror="this.onerror=null;this.src='{{ asset('images/default-avatar.svg') }}';">
                            <span class="avatar-name">{{ Auth::user()->name }}</span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            @if(Auth::user()->isAdmin())
                                <li>
                                    <a class="dropdown-item" href="{{ route('admin.dashboard') }}">
                                        <span class="mi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l8 4v5c0 4.5-3.2 8.3-8 9-4.8-.7-8-4.5-8-9V7z"/><path d="M9 12l2 2 4-4"/></svg></span>@lang('site.nav.admin')
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                            @endif

                            {{-- The account area and order history are for
                                 shopping, which staff accounts cannot do. --}}
                            @shopper
                                <li>
                                    <a class="dropdown-item" href="{{ route('dashboard') }}">
                                        <span class="mi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg></span>@lang('site.nav.account')
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="{{ route('orders.index') }}">
                                        <span class="mi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8"/><path d="M2 4h20v4H2z"/><path d="M10 12h4"/></svg></span>@lang('site.nav.orders')
                                    </a>
                                </li>
                            @endshopper

                            <li>
                                <a class="dropdown-item" href="{{ route('profile.index') }}">
                                    <span class="mi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1A1.6 1.6 0 0 0 9 19.4a1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.6 1.6 0 0 0 4.6 9a1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/></svg></span>@lang('site.nav.profile')
                                </a>
                            </li>

                            <li><hr class="dropdown-divider"></li>

                            <li>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="dropdown-item is-signout">
                                        <span class="mi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg></span>@lang('site.nav.logout')
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </div>
                @endguest

            </div>
        </div>

        {{-- Small screens get search and the links on their own rows. --}}
        <div class="mobile-row">
            <form class="top-search" action="{{ route('products.index') }}" method="GET" role="search"
                  data-suggest autocomplete="off">
                <span class="ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="7"></circle>
                        <path d="m20 20-3.6-3.6"></path>
                    </svg>
                </span>
                <input type="search" name="search" value="{{ request('search') }}"
                       placeholder="{{ __('site.nav.search') }}" aria-label="{{ __('site.nav.search') }}"
                       role="combobox" aria-expanded="false" aria-autocomplete="list">
                <div class="sg-panel" hidden></div>
            </form>
        </div>

        <div class="mobile-links d-lg-none">
            <a class="top-link {{ request()->routeIs('home') ? 'on' : '' }}" href="{{ route('home') }}">@lang('site.nav.home')</a>
            <a class="top-link {{ request()->routeIs('products.*') ? 'on' : '' }}" href="{{ route('products.index') }}">@lang('site.nav.products')</a>
            <a class="top-link {{ request()->routeIs('categories.*') ? 'on' : '' }}" href="{{ route('categories.index') }}">@lang('site.nav.categories')</a>
        </div>

    </div>
</header>

<main>

    @if (session('success'))
        <div class="container mt-4">
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>
    @endif

    @yield('content')
</main>

<footer class="site-footer">
    <div class="container">

        <div class="row g-4 py-5">

            <div class="col-lg-3">
                <div class="d-flex align-items-center gap-2 mb-3">
                    @if($brandMark)
                        <img src="{{ asset($brandMark) }}" alt="" class="footer-logo">
                    @endif
                    <span class="footer-brand">{{ config('app.name') }}</span>
                </div>

                <p class="footer-text mb-3">
                    Fresh groceries delivered across Cambodia, from our shop in
                    {{ config('cambodia.shop.address') }}.
                </p>

                <div class="d-flex flex-wrap gap-2">
                    <span class="pay-chip">KHQR</span>
                    <span class="pay-chip">Cash on delivery</span>
                </div>
            </div>

            <div class="col-6 col-lg-2">
                <h6 class="footer-head">Shop</h6>
                <ul class="footer-list">
                    <li><a href="{{ route('products.index') }}">All products</a></li>
                    <li><a href="{{ route('categories.index') }}">Categories</a></li>
                    <li><a href="{{ route('cart.index') }}">My cart</a></li>
                </ul>
            </div>

            <div class="col-6 col-lg-2">
                <h6 class="footer-head">Account</h6>
                <ul class="footer-list">
                    @auth
                        @shopper
                            <li><a href="{{ route('dashboard') }}">My account</a></li>
                        @endshopper
                        <li><a href="{{ route('profile.index') }}">My profile</a></li>
                        @shopper
                            <li><a href="{{ route('orders.index') }}">My orders</a></li>
                        @endshopper
                        @unless(auth()->user()->canShop())
                            <li><a href="{{ route('admin.dashboard') }}">Admin</a></li>
                        @endunless
                    @else
                        <li><a href="{{ route('login') }}">Sign in</a></li>
                        <li><a href="{{ route('register') }}">Create account</a></li>
                    @endauth
                </ul>
            </div>

            <div class="col-md-6 col-lg-3">
                <h6 class="footer-head">Delivery</h6>
                <ul class="footer-list">
                    @foreach(\App\Support\Cambodia::zones() as $zone)
                        <li class="footer-zone">
                            <span>{{ $zone['label'] }}</span>
                            <span class="footer-zone-meta">
                                ${{ number_format($zone['fee'], 2) }} &middot; {{ $zone['eta'] }}
                            </span>
                        </li>
                    @endforeach
                    @if($free = \App\Support\Cambodia::bestFreeThreshold())
                        <li class="footer-note">
                            Free delivery in Phnom Penh over ${{ number_format($free, 2) }}.
                        </li>
                    @endif
                </ul>
            </div>

            <div class="col-md-6 col-lg-2">
                <h6 class="footer-head">Contact</h6>
                <ul class="footer-list">
                    <li>
                        <a href="https://t.me/{{ config('cambodia.shop.telegram') }}" target="_blank" rel="noopener">
                            &#64;{{ config('cambodia.shop.telegram') }}
                        </a>
                    </li>
                    <li>
                        <a href="tel:{{ preg_replace('/\s+/', '', config('cambodia.shop.phone')) }}">
                            {{ config('cambodia.shop.phone') }}
                        </a>
                    </li>
                    <li>
                        <a href="mailto:{{ config('cambodia.shop.email') }}">
                            {{ config('cambodia.shop.email') }}
                        </a>
                    </li>
                    <li class="footer-note">{{ config('cambodia.shop.hours') }}</li>
                </ul>
            </div>

        </div>

        <div class="footer-base">
            <span>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</span>
            <span>Made in Cambodia</span>
        </div>

    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

{{-- The shop assistant, on every storefront page. --}}
<x-chat-widget />

<script>
/**
 * Small touches on the top bar: it lifts once the page scrolls, and "/" puts
 * the cursor in the search box the way most catalogues now do.
 */
(function () {
    const bar = document.querySelector('.topbar');
    if (!bar) return;

    const onScroll = () => bar.classList.toggle('is-scrolled', window.scrollY > 4);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });

    document.addEventListener('keydown', function (event) {
        if (event.key !== '/' || event.metaKey || event.ctrlKey || event.altKey) return;

        // Not while the person is already typing somewhere.
        const tag = (document.activeElement?.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'textarea' || document.activeElement?.isContentEditable) return;

        const search = document.querySelector('.topbar input[name="search"]');
        if (search) {
            event.preventDefault();
            search.focus();
            search.select();
        }
    });
})();
</script>

<script>
/**
 * Search suggestions.
 *
 * Shows matching products while the customer types, so someone unsure of the
 * spelling still lands on the right thing instead of an empty results page.
 * The form still submits normally, so this only ever accelerates search.
 */
(function () {
    var endpoint = @json(route('search.suggest'));

    function escape(value) {
        var div = document.createElement('div');
        div.textContent = value == null ? '' : value;
        return div.innerHTML;
    }

    document.querySelectorAll('form[data-suggest]').forEach(function (form) {
        var input = form.querySelector('input[name="search"]');
        var panel = form.querySelector('.sg-panel');
        if (!input || !panel) return;

        var timer = null;
        var controller = null;
        var items = [];
        var active = -1;

        function close() {
            panel.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            items = [];
            active = -1;
        }

        function highlight(step) {
            if (!items.length) return;
            if (items[active]) items[active].classList.remove('is-active');
            active = (active + step + items.length) % items.length;
            items[active].classList.add('is-active');
            items[active].scrollIntoView({ block: 'nearest' });
        }

        function render(data, term) {
            var parts = [];

            if (data.categories && data.categories.length) {
                parts.push('<div class="sg-head">Categories</div>');
                data.categories.forEach(function (c) {
                    parts.push(
                        '<a class="sg-item" href="' + escape(c.url) + '">' +
                            '<span class="sg-thumb">&#128230;</span>' +
                            '<span class="sg-name">' + escape(c.name) + '</span>' +
                        '</a>'
                    );
                });
            }

            if (data.products && data.products.length) {
                parts.push('<div class="sg-head">Products</div>');
                data.products.forEach(function (p) {
                    var media = p.image ? '<img src="' + escape(p.image) + '" alt="">' : '&#128722;';

                    var price = p.in_stock
                        ? '$' + escape(p.price) + (p.was ? '<s class="sg-was">$' + escape(p.was) + '</s>' : '')
                        : '<span class="sg-out">Out of stock</span>';

                    parts.push(
                        '<a class="sg-item" href="' + escape(p.url) + '">' +
                            '<span class="sg-thumb">' + media + '</span>' +
                            '<span style="min-width:0">' +
                                '<span class="sg-name">' + escape(p.name) + '</span>' +
                                '<span class="sg-meta">' + escape(p.category || '') + '</span>' +
                            '</span>' +
                            '<span class="sg-price">' + price + '</span>' +
                        '</a>'
                    );
                });
            }

            if (!parts.length) {
                panel.innerHTML = '<div class="sg-empty">Nothing matches &ldquo;' + escape(term) + '&rdquo;</div>';
            } else {
                parts.push(
                    '<a class="sg-all" href="' + escape(form.action) + '?search=' + encodeURIComponent(term) + '">' +
                        'See everything for &ldquo;' + escape(term) + '&rdquo;' +
                    '</a>'
                );
                panel.innerHTML = parts.join('');
            }

            panel.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            items = Array.prototype.slice.call(panel.querySelectorAll('.sg-item, .sg-all'));
            active = -1;
        }

        function ask() {
            var term = input.value.trim();

            if (term.length < 2) {
                close();
                return;
            }

            // Only the latest keystroke matters; drop whatever is still in flight.
            if (controller) controller.abort();
            controller = new AbortController();

            fetch(endpoint + '?q=' + encodeURIComponent(term), {
                headers: { 'Accept': 'application/json' },
                signal: controller.signal
            })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) { if (data) render(data, term); })
                .catch(function () { /* aborted or offline - the form still works */ });
        }

        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(ask, 180);
        });

        input.addEventListener('focus', function () {
            if (input.value.trim().length >= 2 && panel.innerHTML) panel.hidden = false;
        });

        input.addEventListener('keydown', function (event) {
            if (panel.hidden) return;

            if (event.key === 'ArrowDown') { event.preventDefault(); highlight(1); }
            else if (event.key === 'ArrowUp') { event.preventDefault(); highlight(-1); }
            else if (event.key === 'Escape') { close(); }
            else if (event.key === 'Enter' && active > -1) {
                event.preventDefault();
                items[active].click();
            }
        });

        document.addEventListener('click', function (event) {
            if (!form.contains(event.target)) close();
        });
    });
})();
</script>

</body>
</html>