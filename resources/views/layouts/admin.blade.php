<!DOCTYPE html>
{{-- Theme is stamped on <html> before first paint, so dark never flashes white. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>@yield('title', 'Admin') · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">

    <script>
        (function () {
            try {
                var saved = localStorage.getItem('admin-theme');
                document.documentElement.setAttribute('data-theme',
                    saved || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
            } catch (e) { document.documentElement.setAttribute('data-theme', 'light'); }
        })();
    </script>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Kantumruy+Pro:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
    /* ==================================================================
       Tokens
    ================================================================== */
    :root[data-theme="light"] {
        --canvas:     #eceff0;   /* the page behind the panels */
        --panel:      #ffffff;   /* the big content panel */
        --card:       #ffffff;
        --card-2:     #f5f7f7;
        --line:       #e6eaea;
        --line-2:     #d6dcdc;

        --ink:        #101614;
        --ink-2:      #5f6d68;
        --ink-3:      #94a29d;

        --accent:     #0f9d5c;
        --accent-2:   #0b7d49;
        --accent-ink: #ffffff;
        --accent-wash:#e7f6ef;
        --ring:       rgba(15, 157, 92, .25);

        /* Light theme gets a light sidebar — a permanently dark one made
           the theme toggle look like it did nothing. */
        --rail:       #ffffff;
        --rail-ink:   #5f6d68;
        --rail-hover: #f0f3f3;
        --rail-line:  #e6eaea;
        --rail-head:  #101614;

        --good:#0f9d5c; --warn:#c07f0a; --bad:#cf3b4a; --info:#2f7fe0; --violet:#6d5bd0;
        --good-w:#e7f6ef; --warn-w:#fdf3e2; --bad-w:#fdecee; --info-w:#e9f1fd; --violet-w:#eeebfa;

        --sh-1: 0 1px 2px rgba(10,24,18,.05);
        --sh-2: 0 6px 20px rgba(10,24,18,.07);
        --sh-3: 0 18px 44px rgba(10,24,18,.14);
    }

    :root[data-theme="dark"] {
        --canvas:     #090c0b;
        --panel:      #111715;
        --card:       #161e1b;
        --card-2:     #1c2622;
        --line:       #232e29;
        --line-2:     #2f3d37;

        --ink:        #eef3f1;
        --ink-2:      #9aaaa3;
        --ink-3:      #6b7d76;

        --accent:     #2bc07a;
        --accent-2:   #46d18f;
        --accent-ink: #05170e;
        --accent-wash:#122a1f;
        --ring:       rgba(43, 192, 122, .3);

        --rail:       #0c120f;
        --rail-ink:   #8fa79b;
        --rail-hover: rgba(255,255,255,.07);
        --rail-line:  rgba(255,255,255,.08);
        --rail-head:  #ffffff;

        --good:#2bc07a; --warn:#e2ab43; --bad:#ef6b79; --info:#5ea0f0; --violet:#9083e8;
        --good-w:#122a1f; --warn-w:#2b2413; --bad-w:#2e191c; --info-w:#14243a; --violet-w:#1e1b35;

        --sh-1: 0 1px 2px rgba(0,0,0,.5);
        --sh-2: 0 6px 20px rgba(0,0,0,.5);
        --sh-3: 0 18px 44px rgba(0,0,0,.6);
    }

    * { -webkit-font-smoothing: antialiased; box-sizing: border-box; }

    body {
        background: var(--canvas);
        color: var(--ink);
        font-family: 'Plus Jakarta Sans', 'Kantumruy Pro', system-ui, sans-serif;
        font-size: 14px;
        margin: 0;
        transition: background-color .25s ease, color .25s ease;
    }

    html[lang="km"] body { font-family: 'Kantumruy Pro', 'Plus Jakarta Sans', sans-serif; line-height: 1.75; }

    /* ==================================================================
       Shell: a floating rail beside a rounded content panel
    ================================================================== */
    .shell { display: flex; gap: 14px; padding: 14px; min-height: 100vh; }

    .rail {
        position: sticky; top: 14px;
        flex: 0 0 226px; width: 226px;
        height: calc(100vh - 28px);
        background: var(--rail);
        border: 1px solid var(--rail-line);
        border-radius: 22px;
        display: flex; flex-direction: column;
        padding: 14px 0;
        box-shadow: var(--sh-1);
        transition: width .2s cubic-bezier(.2,.8,.3,1), flex-basis .2s cubic-bezier(.2,.8,.3,1);
        overflow: hidden;
        /* A flex item defaults to min-width:auto, which refuses to shrink
           below its content — the collapsed width would be ignored. */
        min-width: 0;
        z-index: 40;
    }

    /* Collapsing is a deliberate click, not something that happens because
       the pointer drifted over the sidebar. The choice is remembered. */
    .rail.collapsed { flex: 0 0 74px; width: 74px; min-width: 0; }
    .rail.collapsed .tx,
    .rail.collapsed .rail-name { display: none; }
    .rail.collapsed .rail-section { opacity: 0; height: 12px; padding: 0; overflow: hidden; }
    .rail.collapsed .rail-head { justify-content: center; }
    .rail.collapsed .rail-link { justify-content: center; padding: 0; }

    .rail-head {
        display: flex; align-items: center; gap: 11px;
        padding: 2px 16px 14px;
        border-bottom: 1px solid var(--rail-line);
        margin-bottom: 10px;
    }

    .rail-mark {
        width: 38px; height: 38px; border-radius: 12px;
        background: var(--accent); color: var(--accent-ink);
        display: grid; place-items: center;
        font-size: 17px; font-weight: 800;
        flex: 0 0 38px;
    }

    /* A logo fills the square; the accent tile behind it is only for the
       letter fallback, so drop it once there is an image. */
    .rail-mark:has(img) { background: transparent; }
    .rail-mark img { width: 100%; height: 100%; object-fit: contain; border-radius: inherit; }

    .rail-name {
        font-weight: 800; font-size: 15px; letter-spacing: -.02em;
        color: var(--rail-head); white-space: nowrap;
        transition: opacity .16s ease;
    }

    .rail-section {
        padding: 12px 20px 5px;
        font-size: 10.5px; text-transform: uppercase; letter-spacing: .09em;
        color: var(--ink-3); font-weight: 700; white-space: nowrap;
        transition: opacity .16s ease;
    }

    .rail nav { width: 100%; min-width: 0; flex: 1; overflow-y: auto; overflow-x: hidden; }
    .rail-link { margin: 2px 12px; }
    .rail nav::-webkit-scrollbar { width: 0; }

    .rail-link {
        display: flex; align-items: center; gap: 14px;
        height: 46px; margin: 3px 12px; padding: 0 13px;
        border-radius: 14px;
        color: var(--rail-ink);
        text-decoration: none;
        font-weight: 600; font-size: 14px;
        white-space: nowrap;
        border: 0; background: none; width: calc(100% - 24px);
        transition: background-color .15s ease, color .15s ease;
    }

    .rail-link .ic { flex: 0 0 24px; font-size: 16px; text-align: center; line-height: 1; }
    .rail-link .tx { transition: opacity .16s ease; }

    .rail-link:hover { background: var(--rail-hover); color: var(--ink); }
    .rail-link.on { background: var(--accent); color: var(--accent-ink); }
    .rail-link.on:hover { background: var(--accent); color: var(--accent-ink); }

    .rail-foot { padding: 10px 12px 0; border-top: 1px solid var(--rail-line); margin-top: 8px; }

    .rail-collapse {
        margin: 0 12px; width: calc(100% - 24px); height: 38px;
        border: 0; border-radius: 12px; background: none;
        color: var(--ink-3); font-size: 15px;
        display: flex; align-items: center; justify-content: center;
    }
    .rail-collapse:hover { background: var(--rail-hover); color: var(--ink); }

    /* ---------------------------------------------------- panel */
    .panel {
        flex: 1; min-width: 0;
        background: var(--panel);
        border-radius: 22px;
        box-shadow: var(--sh-1);
        display: flex; flex-direction: column;
        overflow: hidden;
    }

    .panel-top {
        display: flex; align-items: center; gap: 12px;
        padding: 18px 26px;
        border-bottom: 1px solid var(--line);
    }

    .panel-title { font-size: 17px; font-weight: 700; letter-spacing: -.02em; }
    .panel-body { padding: 24px 26px 30px; flex: 1; }

    .chip-btn {
        height: 38px; min-width: 38px; padding: 0 11px;
        border-radius: 12px;
        border: 1px solid var(--line);
        background: var(--card);
        color: var(--ink-2);
        display: inline-flex; align-items: center; justify-content: center; gap: 7px;
        font-size: 14px; font-weight: 600;
        transition: .15s ease;
    }
    .chip-btn:hover { border-color: var(--line-2); color: var(--ink); }

    /* ==================================================================
       Cards & bento
    ================================================================== */
    .bento { display: grid; grid-template-columns: repeat(12, 1fr); gap: 14px; }

    .b-3  { grid-column: span 3; }
    .b-4  { grid-column: span 4; }
    .b-5  { grid-column: span 5; }
    .b-6  { grid-column: span 6; }
    .b-7  { grid-column: span 7; }
    .b-8  { grid-column: span 8; }
    .b-12 { grid-column: span 12; }

    @media (max-width: 1200px) {
        .b-3, .b-4, .b-5 { grid-column: span 6; }
        .b-7, .b-8 { grid-column: span 12; }
    }
    @media (max-width: 760px) {
        .b-3, .b-4, .b-5, .b-6, .b-7, .b-8 { grid-column: span 12; }
    }

    /* Bootstrap ships its own .card that pins colour to --bs-body-color,
       which is dark and does not follow our theme. Declaring colour here
       (and the --bs-card-* vars) keeps the two from fighting. */
    .card, .stat-card {
        background: var(--card);
        color: var(--ink);
        border: 1px solid var(--line);
        border-radius: 18px;
        padding: 18px;
        --bs-card-bg: var(--card);
        --bs-card-color: var(--ink);
        --bs-card-border-color: var(--line);
    }

    .card-hero {
        background: linear-gradient(145deg, var(--accent) 0%, var(--accent-2) 100%);
        color: var(--accent-ink);
        border: 0;
    }
    .card-hero .k-label, .card-hero .k-sub { color: rgba(255,255,255,.8); }

    .k-label { font-size: 12.5px; font-weight: 600; color: var(--ink-2); }
    .k-value { font-size: 30px; font-weight: 800; letter-spacing: -.03em; line-height: 1.1; margin-top: 6px; }
    .k-value-xl { font-size: 46px; }
    .k-sub { font-size: 12.5px; color: var(--ink-3); margin-top: 5px; }

    .trend { display: inline-flex; align-items: center; gap: 3px; font-size: 12.5px; font-weight: 700; }
    .trend-up { color: var(--good); }
    .trend-down { color: var(--bad); }
    .card-hero .trend-up, .card-hero .trend-down { color: #fff; }

    h1,h2,h3,h4,h5,h6 { color: var(--ink); letter-spacing: -.02em; }
    .text-muted { color: var(--ink-2) !important; }
    .sec-title { font-size: 14.5px; font-weight: 700; }

    /* ==================================================================
       Tables
    ================================================================== */
    .table-x { width: 100%; border-collapse: separate; border-spacing: 0; }

    .table-x th {
        font-size: 11px; text-transform: uppercase; letter-spacing: .07em;
        color: var(--ink-3); font-weight: 700;
        padding: 9px 14px; white-space: nowrap;
        border-bottom: 1px solid var(--line);
        background: var(--card);
    }

    .table-x td { padding: 13px 14px; border-bottom: 1px solid var(--line); vertical-align: middle; }
    .table-x tbody tr:last-child td { border-bottom: 0; }
    .table-x tbody tr:hover { background: var(--card-2); }

    th.sortable { cursor: pointer; user-select: none; }
    th.sortable::after { content: '↕'; opacity: .35; margin-left: 5px; font-size: 10px; }
    th.sortable.asc::after  { content: '↑'; opacity: 1; color: var(--accent); }
    th.sortable.desc::after { content: '↓'; opacity: 1; color: var(--accent); }

    .table-empty { padding: 42px 20px; text-align: center; color: var(--ink-2); }

    /* ==================================================================
       Pills, bars, forms, buttons
    ================================================================== */
    .pill {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 3px 10px; border-radius: 999px;
        font-size: 11.5px; font-weight: 700; line-height: 1.7;
    }
    .pill-good{background:var(--good-w);color:var(--good)} .pill-warn{background:var(--warn-w);color:var(--warn)}
    .pill-bad {background:var(--bad-w); color:var(--bad)}  .pill-info{background:var(--info-w);color:var(--info)}
    .pill-mute{background:var(--card-2);color:var(--ink-2)}

    .track { height: 7px; background: var(--card-2); border-radius: 999px; overflow: hidden; }
    .track-fill { height: 100%; border-radius: 999px; background: var(--accent); }
    .track-fill[data-tone="warning"]{background:var(--warn)} .track-fill[data-tone="info"]{background:var(--info)}
    .track-fill[data-tone="primary"]{background:var(--violet)} .track-fill[data-tone="secondary"]{background:var(--ink-3)}
    .track-fill[data-tone="success"]{background:var(--good)} .track-fill[data-tone="danger"]{background:var(--bad)}

    .split-bar { display:flex; height:11px; border-radius:999px; overflow:hidden; background:var(--card-2); gap:2px; }
    .split-seg:first-child{border-radius:999px 0 0 999px} .split-seg:last-child{border-radius:0 999px 999px 0}
    .split-seg:only-child{border-radius:999px}
    .legend-swatch { width:10px; height:10px; border-radius:3px; flex:0 0 10px; }

    .form-control, .form-select {
        background: var(--card); border: 1px solid var(--line-2); color: var(--ink);
        border-radius: 11px; font-size: 14px; padding: 9px 12px;
    }
    .form-control:focus, .form-select:focus {
        background: var(--card); color: var(--ink);
        border-color: var(--accent); box-shadow: 0 0 0 3px var(--ring);
    }
    .form-control::placeholder { color: var(--ink-3); }
    .form-label { color: var(--ink); font-weight: 600; }
    .form-text { color: var(--ink-2); }
    .input-group-text { background: var(--card-2); border-color: var(--line-2); color: var(--ink-2); }
    .form-check-input { background-color: var(--card); border-color: var(--line-2); }
    .form-check-input:checked { background-color: var(--accent); border-color: var(--accent); }
    .form-check-input:focus { box-shadow: 0 0 0 3px var(--ring); border-color: var(--accent); }

    .btn { border-radius: 11px; font-weight: 600; font-size: 14px; }
    .btn-success { background: var(--accent); border-color: var(--accent); color: var(--accent-ink); }
    .btn-success:hover { background: var(--accent-2); border-color: var(--accent-2); color: var(--accent-ink); }
    .btn-outline-secondary { color: var(--ink-2); border-color: var(--line-2); }
    .btn-outline-secondary:hover { background: var(--card-2); color: var(--ink); border-color: var(--line-2); }
    .btn-light { background: var(--card-2); border-color: var(--line); color: var(--ink); }
    .btn-light:hover { background: var(--line); color: var(--ink); }
    .btn-link { color: var(--accent); }

    /* Links inside a panel are accent-coloured — but only plain ones. An
       anchor that is really a component (a button, a tab, a card) paints its
       own text, and forcing accent on top of that makes a green label sit on
       a green pill, invisible. Each component class opts out here. */
    .panel-body a:not(.btn):not(.dropdown-item):not(.chip-btn):not(.o-tab):not(.o-tile):not(.c-tab):not(.c-name):not(.cd-ref):not(.pr-tab):not(.pr-name) {
        color: var(--accent); text-decoration: none;
    }
    .panel-body a:not(.btn):not(.dropdown-item):not(.chip-btn):not(.o-tab):not(.o-tile):not(.c-tab):not(.c-name):not(.cd-ref):not(.pr-tab):not(.pr-name):hover {
        text-decoration: underline;
    }

    .pagination {
        --bs-pagination-color: var(--ink-2); --bs-pagination-bg: var(--card);
        --bs-pagination-border-color: var(--line); --bs-pagination-hover-color: var(--ink);
        --bs-pagination-hover-bg: var(--card-2); --bs-pagination-hover-border-color: var(--line-2);
        --bs-pagination-focus-box-shadow: 0 0 0 3px var(--ring);
        --bs-pagination-active-bg: var(--accent); --bs-pagination-active-border-color: var(--accent);
        --bs-pagination-disabled-bg: var(--card); --bs-pagination-disabled-border-color: var(--line);
        --bs-pagination-disabled-color: var(--ink-3);
    }

    .dropdown-menu { background: var(--card); border-color: var(--line); border-radius: 14px; box-shadow: var(--sh-3); }
    .dropdown-item { color: var(--ink); border-radius: 9px; }
    .dropdown-item:hover { background: var(--card-2); color: var(--ink); }
    .alert { border-radius: 13px; }
    hr { border-color: var(--line); opacity: 1; }

    /* ==================================================================
       Toasts
    ================================================================== */
    .toast-stack {
        position: fixed; right: 22px; bottom: 22px; z-index: 1090;
        display: flex; flex-direction: column; gap: 10px;
        max-width: min(380px, calc(100vw - 44px));
    }

    .toast-x {
        display: flex; align-items: flex-start; gap: 11px;
        background: var(--card); border: 1px solid var(--line);
        border-left: 3px solid var(--accent);
        border-radius: 14px; padding: 13px 15px;
        box-shadow: var(--sh-3);
        animation: t-in .26s cubic-bezier(.2,.9,.3,1);
    }
    .toast-x.is-error { border-left-color: var(--bad); }
    .toast-x.is-warn  { border-left-color: var(--warn); }
    .toast-x.leaving  { animation: t-out .2s ease forwards; }
    .toast-x .t-icon { font-size: 15px; line-height: 1.4; }
    .toast-x .t-body { flex: 1; font-size: 14px; color: var(--ink); }
    .toast-x .t-close { border: 0; background: none; color: var(--ink-3); font-size: 18px; line-height: 1; }
    .toast-x .t-close:hover { color: var(--ink); }

    @keyframes t-in  { from { opacity:0; transform: translateY(12px) scale(.97); } }
    @keyframes t-out { to   { opacity:0; transform: translateX(18px); } }

    #trendChartWrap { position: relative; }
    .chart-tooltip {
        position: absolute; background: var(--ink); color: var(--panel);
        border-radius: 11px; padding: 8px 12px; font-size: 12px;
        pointer-events: none; min-width: 140px; box-shadow: var(--sh-3); z-index: 5;
    }
    .chart-tooltip .tt-title { font-weight: 800; margin-bottom: 4px; }
    .chart-tooltip .tt-row { display: flex; justify-content: space-between; gap: 14px; opacity: .72; }
    .chart-tooltip .tt-row strong { opacity: 1; }

    @media (prefers-reduced-motion: reduce) { *, .toast-x { transition: none !important; animation: none !important; } }

    /* ==================================================================
       Mobile
    ================================================================== */
    @media (max-width: 900px) {
        .shell { padding: 10px; gap: 10px; }
        .rail {
            position: fixed; left: 10px; top: 10px;
            height: calc(100vh - 20px);
            transform: translateX(calc(-100% - 14px));
            transition: transform .24s cubic-bezier(.2,.8,.3,1);
            flex-basis: 226px; width: 226px;
        }
        .rail.open { transform: translateX(0); }
        .rail.collapsed { flex-basis: 226px; width: 226px; }
        .rail .tx, .rail .rail-section { opacity: 1; }
        .rail-collapse { display: none; }
        .backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 35; display: none; }
        .backdrop.show { display: block; }
        .panel-body { padding: 16px; }
        .panel-top { padding: 14px 16px; }
        .bento { gap: 10px; }
    }
    @media (min-width: 901px) { .rail-toggle { display: none; } }
    </style>

    @stack('styles')
</head>

<body>

<div class="shell">

    <aside class="rail" id="rail">

        <div class="rail-head">
            <div class="rail-mark">
                @if($brandMark)
                    <img src="{{ asset($brandMark) }}" alt="">
                @else
                    {{ strtoupper(substr(config('app.name'), 0, 1)) }}
                @endif
            </div>
            <div class="rail-name tx">{{ config('app.name') }}</div>
        </div>

        <nav>
            <div class="rail-section">Overview</div>

            <a href="{{ route('admin.dashboard') }}" class="rail-link {{ request()->routeIs('admin.dashboard') ? 'on' : '' }}" title="Dashboard">
                <span class="ic">&#9678;</span><span class="tx">Dashboard</span>
            </a>
            <a href="{{ route('admin.orders.index') }}" class="rail-link {{ request()->routeIs('admin.orders.*') ? 'on' : '' }}" title="Orders">
                <span class="ic">&#9635;</span><span class="tx">Orders</span>
            </a>

            <div class="rail-section">Catalogue</div>

            <a href="{{ route('admin.products.index') }}" class="rail-link {{ request()->routeIs('admin.products.*') ? 'on' : '' }}" title="Products">
                <span class="ic">&#9634;</span><span class="tx">Products</span>
            </a>
            <a href="{{ route('admin.categories.index') }}" class="rail-link {{ request()->routeIs('admin.categories.*') ? 'on' : '' }}" title="Categories">
                <span class="ic">&#9776;</span><span class="tx">Categories</span>
            </a>
            <a href="{{ route('admin.inventory.index') }}" class="rail-link {{ request()->routeIs('admin.inventory.*') ? 'on' : '' }}" title="Inventory">
                <span class="ic">&#9707;</span><span class="tx">Inventory</span>
            </a>

            <div class="rail-section">Growth</div>

            <a href="{{ route('admin.coupons.index') }}" class="rail-link {{ request()->routeIs('admin.coupons.*') ? 'on' : '' }}" title="Coupons">
                <span class="ic">&#9733;</span><span class="tx">Coupons</span>
            </a>
            <a href="{{ route('admin.customers.index') }}" class="rail-link {{ request()->routeIs('admin.customers.*') ? 'on' : '' }}" title="Customers">
                <span class="ic">&#9737;</span><span class="tx">Customers</span>
            </a>

            <div class="rail-section">Settings</div>

            <a href="{{ route('admin.delivery.edit') }}" class="rail-link {{ request()->routeIs('admin.delivery.*') ? 'on' : '' }}" title="Delivery charges">
                <span class="ic">&#128666;</span><span class="tx">Delivery</span>
            </a>
        </nav>

        <div class="rail-foot">
            <a href="{{ route('home') }}" class="rail-link" title="Storefront">
                <span class="ic">&#8592;</span><span class="tx">Storefront</span>
            </a>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="rail-link" title="Log out">
                    <span class="ic">&#9099;</span><span class="tx">Log out</span>
                </button>
            </form>

            <button class="rail-collapse" id="railCollapse" aria-label="Collapse sidebar" title="Collapse sidebar">
                <span id="railCollapseIcon">&#8676;</span>
            </button>
        </div>
    </aside>

    <div class="backdrop" id="backdrop"></div>

    <div class="panel">

        <header class="panel-top">
            <button class="chip-btn rail-toggle" id="railToggle" aria-label="Menu">&#9776;</button>

            <div class="panel-title flex-grow-1">@yield('title', 'Dashboard')</div>

            @yield('actions')

            <button class="chip-btn" id="themeToggle" aria-label="Switch theme" title="Light / dark">
                <span id="themeIcon">&#9789;</span>
            </button>

            <div class="dropdown">
                <button class="chip-btn" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account">&#9737;</button>
                <ul class="dropdown-menu dropdown-menu-end p-2">
                    <li><span class="dropdown-item-text small text-muted">{{ auth()->user()?->email }}</span></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="{{ route('profile.index') }}">My Profile</a></li>
                    <li><a class="dropdown-item" href="{{ route('home') }}">Storefront</a></li>
                </ul>
            </div>
        </header>

        <main class="panel-body">
            @yield('content')
        </main>

    </div>

</div>

<div class="toast-stack" id="toastStack" aria-live="polite" aria-atomic="true">
    @foreach (['success' => 'ok', 'error' => 'error', 'warning' => 'warn', 'status' => 'ok'] as $key => $tone)
        @if (session($key))
            <div class="toast-x {{ $tone === 'error' ? 'is-error' : ($tone === 'warn' ? 'is-warn' : '') }}">
                <span class="t-icon">{!! $tone === 'ok' ? '&#9989;' : '&#9888;' !!}</span>
                <div class="t-body">{{ session($key) }}</div>
                <button class="t-close" aria-label="Dismiss">&times;</button>
            </div>
        @endif
    @endforeach
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const root = document.documentElement;
    const icon = document.getElementById('themeIcon');
    const paint = () => icon.innerHTML = root.getAttribute('data-theme') === 'dark' ? '&#9728;' : '&#9789;';

    document.getElementById('themeToggle').addEventListener('click', function () {
        const next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        try { localStorage.setItem('admin-theme', next); } catch (e) {}
        paint();
    });
    paint();

    const rail = document.getElementById('rail');
    const backdrop = document.getElementById('backdrop');

    // Collapsed state is the admin's choice, so it survives navigation.
    try {
        if (localStorage.getItem('admin-rail') === 'collapsed') rail.classList.add('collapsed');
    } catch (e) {}

    const collapseBtn = document.getElementById('railCollapse');
    const collapseIcon = document.getElementById('railCollapseIcon');

    function paintCollapse() {
        const off = rail.classList.contains('collapsed');
        collapseIcon.innerHTML = off ? '&#8677;' : '&#8676;';
        collapseBtn.setAttribute('aria-label', off ? 'Expand sidebar' : 'Collapse sidebar');
        collapseBtn.title = off ? 'Expand sidebar' : 'Collapse sidebar';
    }

    collapseBtn.addEventListener('click', function () {
        rail.classList.toggle('collapsed');
        try { localStorage.setItem('admin-rail', rail.classList.contains('collapsed') ? 'collapsed' : 'open'); } catch (e) {}
        paintCollapse();
    });

    paintCollapse();
    const close = () => { rail.classList.remove('open'); backdrop.classList.remove('show'); };

    document.getElementById('railToggle').addEventListener('click', function () {
        rail.classList.toggle('open'); backdrop.classList.toggle('show');
    });
    backdrop.addEventListener('click', close);
    document.addEventListener('keydown', e => e.key === 'Escape' && close());

    document.querySelectorAll('.toast-x').forEach(function (t) {
        const go = () => { t.classList.add('leaving'); setTimeout(() => t.remove(), 220); };
        t.querySelector('.t-close').addEventListener('click', go);
        if (!t.classList.contains('is-error')) setTimeout(go, 5000);
    });

    // Sortable headers + row filters, opted into with data attributes.
    document.querySelectorAll('table[data-sortable]').forEach(function (table) {
        const body = table.tBodies[0];
        if (!body) return;

        table.querySelectorAll('th[data-sort]').forEach(function (th) {
            th.classList.add('sortable');
            th.addEventListener('click', function () {
                const asc = !th.classList.contains('asc');
                const idx = [...th.parentNode.children].indexOf(th);
                const numeric = th.dataset.sort === 'number';

                table.querySelectorAll('th[data-sort]').forEach(o => o.classList.remove('asc', 'desc'));
                th.classList.add(asc ? 'asc' : 'desc');

                [...body.rows].sort(function (a, b) {
                    const x = a.cells[idx]?.dataset.value ?? a.cells[idx]?.innerText.trim() ?? '';
                    const y = b.cells[idx]?.dataset.value ?? b.cells[idx]?.innerText.trim() ?? '';
                    const r = numeric
                        ? (parseFloat(String(x).replace(/[^0-9.-]/g, '')) || 0) - (parseFloat(String(y).replace(/[^0-9.-]/g, '')) || 0)
                        : String(x).localeCompare(String(y), undefined, { numeric: true });
                    return asc ? r : -r;
                }).forEach(r => body.appendChild(r));
            });
        });
    });

    document.querySelectorAll('input[data-filters]').forEach(function (input) {
        const table = document.getElementById(input.dataset.filters);
        if (!table || !table.tBodies[0]) return;
        const empty = document.getElementById(input.dataset.filters + '-empty');

        input.addEventListener('input', function () {
            const term = input.value.trim().toLowerCase();
            let shown = 0;
            [...table.tBodies[0].rows].forEach(function (row) {
                const hit = term === '' || row.innerText.toLowerCase().includes(term);
                row.hidden = !hit;
                if (hit) shown++;
            });
            if (empty) empty.hidden = shown !== 0;
        });
    });
})();
</script>

@stack('scripts')

</body>
</html>
