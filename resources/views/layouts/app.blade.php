<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'WGIMSv2') — Welfare Goods Inventory Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* ── Theme tokens ─────────────────────────────────────────────────── */
        :root {
            --primary: #0284c7;
            --primary-dark: #0369a1;
            --primary-soft: #f0f9ff;

            --bg: #f0f4f8;
            --surface: #ffffff;
            --surface-hover: #f3f4f6;
            --surface-soft: #f7fafc;
            --border: #e2e8f0;
            --border-strong: #cbd5e0;

            --text: #1a202c;
            --text-muted: #718096;

            --success: #38a169;
            --warning: #d69e2e;
            --danger: #e53e3e;
            --info: #0284c7;

            --success-bg: #f0fff4;   --success-text: #276749;
            --warning-bg: #fffff0;   --warning-text: #744210;
            --danger-bg: #fff5f5;    --danger-text: #9b2c2c;
            --info-bg: #f0f9ff;      --info-text: #0369a1;
            --secondary-bg: #f7fafc; --secondary-text: #4a5568;

            --input-bg: #ffffff;

            --nav-bg: #ffffff;
            --nav-text: #374151;
            --nav-hover: #f3f4f6;
            --nav-active-bg: #f0f9ff;
            --nav-active-text: #0284c7;

            --shadow-sm: 0 1px 3px rgba(15, 23, 42, 0.08);
            --shadow-md: 0 12px 32px rgba(15, 23, 42, 0.16);
            --topbar-height: 64px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { overflow-x: auto; }
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            overflow-x: auto;
        }

        /* ── Top navigation ───────────────────────────────────────────────── */
        .topnav {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1300;
            background: var(--nav-bg);
            border-bottom: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
        }
        .topnav-inner {
            max-width: 1700px;
            margin: 0 auto;
            height: var(--topbar-height);
            display: flex;
            align-items: center;
            gap: 18px;
            padding: 0 20px;
        }

        .brand { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
        .brand img { height: 38px; width: auto; object-fit: contain; }
        .brand-text { line-height: 1.25; }
        .brand-text strong { display: block; font-size: 15px; font-weight: 700; color: var(--text); white-space: nowrap; }
        .brand-text small { font-size: 11px; color: var(--text-muted); white-space: nowrap; }

        #menu-toggle {
            display: none;
            width: 40px;
            height: 40px;
            align-items: center;
            justify-content: center;
            background: none;
            border: none;
            border-radius: 10px;
            color: var(--text-muted);
            font-size: 18px;
            cursor: pointer;
            transition: background 0.15s, color 0.15s;
            flex-shrink: 0;
        }
        #menu-toggle:hover { background: var(--surface-hover); color: var(--text); }

        .main-nav { display: flex; align-items: center; gap: 4px; flex: 1; min-width: 0; overflow-x: auto; scrollbar-width: none; }
        .main-nav::-webkit-scrollbar { display: none; }

        .nav-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 14px;
            border-radius: 10px;
            background: none;
            border: none;
            font-family: inherit;
            font-size: 14px;
            font-weight: 500;
            color: var(--nav-text);
            text-decoration: none;
            cursor: pointer;
            white-space: nowrap;
            transition: background 0.15s, color 0.15s;
        }
        .nav-link i.fa-lg { font-size: 16px; }
        .nav-link:hover { background: var(--nav-hover); color: var(--text); }
        .nav-link.active { background: var(--nav-active-bg); color: var(--nav-active-text); font-weight: 600; }
        .nav-link .chev { margin-left: 2px; font-size: 10px; transition: transform 0.2s; }
        .nav-drop.open > .nav-link .chev { transform: rotate(180deg); }

        .nav-drop { position: relative; }

        .drop-panel {
            position: absolute;
            top: calc(100% + 10px);
            left: 0;
            min-width: 232px;
            padding: 8px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: var(--shadow-md);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-6px);
            transition: opacity 0.18s ease, transform 0.18s ease, visibility 0.18s;
        }
        .nav-drop.open .drop-panel { opacity: 1; visibility: visible; transform: translateY(0); }
        /* Desktop: panels are viewport-fixed (escapes the horizontal-scroll container's clip on .main-nav)
           and are positioned under the trigger button by JS. Hover/click opening is handled in JS. */
        .main-nav .drop-panel {
            position: fixed;
            top: auto;
            left: auto;
            z-index: 100;
        }

        .drop-title {
            padding: 7px 12px 5px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
        }
        .drop-link {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 9px 12px;
            border-radius: 8px;
            color: var(--text);
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 500;
            transition: background 0.12s, color 0.12s;
        }
        .drop-link i { width: 18px; text-align: center; color: var(--primary); font-size: 15px; flex-shrink: 0; }
        .drop-link:hover { background: var(--surface-hover); color: var(--nav-active-text); }
        .drop-link.active { background: var(--nav-active-bg); color: var(--nav-active-text); font-weight: 600; }
        .drop-link .chev { margin-left: auto; font-size: 11px; color: var(--text-muted); transition: transform 0.2s; }
        .drop-sep { height: 1px; background: var(--border); margin: 6px 8px; }

        /* ── Top nav actions (right side) ─────────────────────────────────── */
        .topnav-actions { display: flex; align-items: center; gap: 8px; margin-left: auto; flex-shrink: 0; }

        .icon-btn {
            width: 38px;
            height: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: none;
            border: none;
            border-radius: 10px;
            color: var(--text-muted);
            font-size: 16px;
            cursor: pointer;
            position: relative;
            transition: background 0.15s, color 0.15s;
        }
        .icon-btn:hover { background: var(--surface-hover); color: var(--text); }

        .notif-badge {
            position: absolute;
            top: 2px;
            right: 2px;
            background: var(--danger);
            color: #fff;
            border-radius: 50%;
            min-width: 18px;
            height: 18px;
            font-size: 10px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 4px;
        }

        .notif-wrap { position: relative; }
        .notif-dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: 330px;
            max-width: calc(100vw - 24px);
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: var(--shadow-md);
            overflow: hidden;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-6px);
            transition: opacity 0.18s ease, transform 0.18s ease, visibility 0.18s;
        }
        .notif-wrap.open .notif-dropdown { opacity: 1; visibility: visible; transform: translateY(0); }
        .notif-header {
            padding: 13px 16px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
            font-size: 14px;
            background: var(--surface-soft);
        }
        .notif-header form button { background: none; border: none; color: var(--primary); font-size: 12px; cursor: pointer; }
        .notif-list { max-height: 300px; overflow-y: auto; }
        .notif-item { padding: 12px 16px; border-bottom: 1px solid var(--border); cursor: pointer; }
        .notif-item:hover { background: var(--surface-hover); }
        .notif-item.unread { background: var(--info-bg); }
        .notif-item-title { font-size: 13px; font-weight: 600; color: var(--text); }
        .notif-item-msg { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
        .notif-footer { padding: 10px 16px; text-align: center; border-top: 1px solid var(--border); }
        .notif-footer a { color: var(--primary); font-size: 13px; text-decoration: none; }

        .warehouse-label {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-size: 13px;
            color: var(--text-muted);
            padding: 6px 12px;
            border: 1px solid var(--border);
            border-radius: 999px;
            background: var(--surface-soft);
            white-space: nowrap;
        }
        .warehouse-label i { color: var(--primary); }

        .user-menu { position: relative; }
        .user-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 5px 8px;
            background: none;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-family: inherit;
            transition: background 0.15s;
        }
        .user-btn:hover { background: var(--surface-hover); }
        .user-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 13px;
            flex-shrink: 0;
        }
        .user-btn .meta { text-align: left; line-height: 1.2; min-width: 0; }
        .user-btn .meta strong { display: block; font-size: 13px; font-weight: 600; color: var(--text); max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .user-btn .meta small { font-size: 11px; color: var(--text-muted); }
        .user-btn .chev { font-size: 10px; color: var(--text-muted); transition: transform 0.2s; }
        .user-menu.open .user-btn .chev { transform: rotate(180deg); }
        .user-menu .user-drop { left: auto; right: 0; min-width: 230px; }
        .user-menu.open .user-drop { opacity: 1; visibility: visible; transform: translateY(0); }

        /* ── Main content (full width, no sidebar) ────────────────────────── */
        .main-wrapper { display: flex; flex-direction: column; min-height: 100vh; padding-top: calc(var(--topbar-height) + 1px); }
        .page-content { flex: 1; padding: 24px; overflow-x: auto; min-width: 0; }
        .page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; gap: 16px; flex-wrap: wrap; }
        .page-header h1 { font-size: 22px; font-weight: 700; }
        .breadcrumb { font-size: 13px; color: var(--text-muted); margin-top: 4px; }
        .breadcrumb a { color: var(--primary); text-decoration: none; }

        /* ── Cards ────────────────────────────────────────────────────────── */
        .card { background: var(--card-bg, var(--surface)); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
        .card-header { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .card-header h3 { font-size: 15px; font-weight: 600; }
        .card-body { padding: 20px; }
        .card-footer { padding: 12px 20px; border-top: 1px solid var(--border); background: var(--surface-soft); }

        /* ── Stats cards ──────────────────────────────────────────────────── */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 20px; display: flex; align-items: center; gap: 16px; }
        .stat-icon { width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 22px; }
        .stat-icon.blue { background: var(--info-bg); color: var(--info-text); }
        .stat-icon.green { background: var(--success-bg); color: var(--success); }
        .stat-icon.yellow { background: var(--warning-bg); color: var(--warning); }
        .stat-icon.red { background: var(--danger-bg); color: var(--danger); }
        .stat-value { font-size: 26px; font-weight: 700; }
        .stat-label { font-size: 13px; color: var(--text-muted); }

        /* ── Quick nav cards ──────────────────────────────────────────────── */
        .quick-nav { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .quick-card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 20px; text-align: center; text-decoration: none; color: var(--text); transition: all 0.2s; }
        .quick-card:hover { border-color: var(--primary); box-shadow: 0 4px 12px rgba(2, 132, 199, 0.1); transform: translateY(-2px); }
        .quick-card i { font-size: 28px; color: var(--primary); margin-bottom: 10px; display: block; }
        .quick-card span { font-size: 13px; font-weight: 500; }

        /* ── Tables ───────────────────────────────────────────────────────── */
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        thead th {
            background: var(--surface-soft);
            padding: 10px 14px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            border-bottom: 2px solid var(--border);
            white-space: nowrap;
        }
        tbody td { padding: 12px 14px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tbody tr:hover { background: var(--surface-hover); }
        tbody tr:last-child td { border-bottom: none; }

        /* ── Badges ───────────────────────────────────────────────────────── */
        .badge { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .badge-success { background: var(--success-bg); color: var(--success-text); }
        .badge-warning { background: var(--warning-bg); color: var(--warning-text); }
        .badge-danger { background: var(--danger-bg); color: var(--danger-text); }
        .badge-info { background: var(--info-bg); color: var(--info-text); }
        .badge-secondary { background: var(--secondary-bg); color: var(--secondary-text); }
        .badge-primary { background: var(--info-bg); color: var(--info-text); }

        /* ── Buttons ──────────────────────────────────────────────────────── */
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer; border: none; text-decoration: none; transition: all 0.2s; }
        .btn-sm { padding: 5px 10px; font-size: 12px; }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-success { background: var(--success); color: #fff; }
        .btn-success:hover { filter: brightness(0.92); }
        .btn-warning { background: var(--warning); color: #fff; }
        .btn-danger { background: var(--danger); color: #fff; }
        .btn-danger:hover { filter: brightness(0.92); }
        .btn-secondary { background: var(--secondary-bg); color: var(--secondary-text); }
        .btn-secondary:hover { background: var(--border-strong); }
        .btn-outline { background: transparent; border: 1px solid var(--border); color: var(--text); }
        .btn-outline:hover { background: var(--surface-hover); }
        .btn-icon { padding: 6px 8px; }

        /* ── Forms ────────────────────────────────────────────────────────── */
        .form-group { margin-bottom: 18px; }
        .form-label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: var(--text-muted); }
        .form-control {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 14px;
            color: var(--text);
            background: var(--input-bg);
            transition: border-color 0.2s;
        }
        .form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.1); }
        .form-control.is-invalid { border-color: var(--danger); }
        .form-control::placeholder { color: var(--text-muted); opacity: 0.7; }
        .invalid-feedback { color: var(--danger); font-size: 12px; margin-top: 4px; }
        .form-row { display: grid; gap: 16px; }
        .form-row.cols-2 { grid-template-columns: 1fr 1fr; }
        .form-row.cols-3 { grid-template-columns: 1fr 1fr 1fr; }
        .form-row.cols-4 { grid-template-columns: 1fr 1fr 1fr 1fr; }
        .form-section-label { display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 700; color: var(--primary); margin: 6px 0 16px; padding-bottom: 6px; border-bottom: 1px dashed var(--border); }
        .form-section-label i { color: var(--primary); }

        /* ── Requisition line-item cards ──────────────────────────────────── */
        .ris-item-card {
            background: #fafbfd;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px 16px 14px;
            margin-bottom: 14px;
            position: relative;
            transition: box-shadow 0.2s;
        }
        .ris-item-card:hover { box-shadow: 0 2px 10px rgba(15, 23, 42, 0.06); }
        .ris-item-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 12px;
        }
        .ris-item-num {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: var(--primary);
            background: #e0f2fe;
            border-radius: 6px;
            padding: 3px 10px;
        }
        .ris-item-meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 12px; }
        .ris-item-meta .form-group { margin-bottom: 0; }
        .ris-item-total {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px dashed var(--border);
        }
        .ris-item-total .total-cost { font-weight: 800; color: var(--primary); white-space: nowrap; }
        .ris-warn {
            font-size: 11.5px;
            color: var(--danger);
            background: var(--danger-bg);
            border: 1px solid var(--danger);
            border-radius: 6px;
            padding: 4px 8px;
            margin-top: 6px;
        }
        .hint { font-size: 11px; font-weight: 400; color: var(--text-muted); }
        .req { color: var(--danger); font-weight: 700; }
        select.form-control { cursor: pointer; }
        textarea.form-control { resize: vertical; min-height: 80px; }

        /* ── Alerts ───────────────────────────────────────────────────────── */
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; display: flex; align-items: flex-start; gap: 10px; font-size: 14px; }
        .alert-success { background: var(--success-bg); border: 1px solid var(--success); color: var(--success-text); }
        .alert-danger { background: var(--danger-bg); border: 1px solid var(--danger); color: var(--danger-text); }
        .alert-warning { background: var(--warning-bg); border: 1px solid var(--warning); color: var(--warning-text); }
        .alert-info { background: var(--info-bg); border: 1px solid var(--info); color: var(--info-text); }

        /* ── Filters bar ──────────────────────────────────────────────────── */
        .filters-bar { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 16px; }
        .filters-bar .form-control { width: auto; min-width: 160px; }
        .search-input { position: relative; }
        .search-input i { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--text-muted); }
        .search-input input { padding-left: 32px; }

        .card-header-filters { padding: 14px 20px; border-bottom: 1px solid var(--border); display: flex; flex-direction: column; gap: 10px; }
        .search-row { display: flex; gap: 8px; align-items: center; }
        .search-row .search-input { width: 320px; }
        .search-row .search-input input { width: 100%; }
        .filter-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; padding-top: 10px; border-top: 1px solid var(--border); }
        .filter-row .form-control { width: auto; min-width: 150px; }

        /* ── Pagination ───────────────────────────────────────────────────── */
        .pagination { display: flex; gap: 4px; align-items: center; justify-content: center; padding: 16px; }
        .pagination a, .pagination span { padding: 6px 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 13px; text-decoration: none; color: var(--text); }
        .pagination a:hover { background: var(--surface-hover); }
        .pagination .active { background: var(--primary); color: #fff; border-color: var(--primary); }
        .pagination .disabled { color: var(--text-muted); cursor: not-allowed; }

        /* ── Line items table ─────────────────────────────────────────────── */
        .line-items-table { width: 100%; border-collapse: collapse; }
        .line-items-table th, .line-items-table td { padding: 8px 10px; border: 1px solid var(--border); font-size: 13px; }
        .line-items-table th { background: var(--surface-soft); font-weight: 600; color: var(--text-muted); }
        .line-items-table input, .line-items-table select {
            width: 100%;
            padding: 6px 8px;
            border: 1px solid var(--border);
            border-radius: 4px;
            font-size: 13px;
            color: #000;
            -webkit-text-fill-color: #000;
            background: #fff;
            opacity: 1;
        }
        .line-items-table input:focus, .line-items-table select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.1); }
        .line-items-table input[readonly] { background: var(--surface-soft) !important; color: var(--text-muted) !important; -webkit-text-fill-color: var(--text-muted) !important; }
        .remove-row { background: none; border: none; color: var(--danger); cursor: pointer; font-size: 16px; }

        /* ── Balance table ────────────────────────────────────────────────── */
        .balance-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .balance-table th, .balance-table td { padding: 8px 12px; border: 1px solid var(--border); }
        .balance-table th { background: #1e2a3a; color: #fff; font-weight: 600; }
        .balance-table .warehouse-row { background: var(--info-bg); font-weight: 600; }
        .balance-table .total-row { background: var(--success-bg); font-weight: 700; }

        /* ── Stock card print ─────────────────────────────────────────────── */
        .stock-card-print { font-family: Arial, sans-serif; font-size: 11px; }
        .stock-card-print table { border-collapse: collapse; width: 100%; }
        .stock-card-print th, .stock-card-print td { border: 1px solid #000; padding: 3px 5px; }

        /* ── Print ────────────────────────────────────────────────────────── */
        @media print {
            .topnav, .no-print { display: none !important; }
            .main-wrapper { padding-top: 0; }
            .page-content { padding: 0; }
            body { background: #fff; }
        }

        /* ── Responsive: hamburger + slide-down menu ──────────────────────── */
        @media (max-width: 1024px) {
            #menu-toggle { display: inline-flex; }

            .main-nav {
                position: fixed;
                top: var(--topbar-height);
                left: 0;
                right: 0;
                bottom: 0;
                z-index: 350;
                flex-direction: column;
                align-items: stretch;
                gap: 2px;
                padding: 12px 16px 24px;
                background: var(--nav-bg);
                border-top: 1px solid var(--border);
                box-shadow: var(--shadow-md);
                display: none;
                overflow-y: auto;
            }
            body.nav-open .main-nav { display: flex; }
            body.nav-open::after {
                content: '';
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.35);
                z-index: 340;
                pointer-events: none;
            }

            .main-nav > .nav-link { width: 100%; justify-content: flex-start; font-size: 15px; padding: 12px 14px; }

            .nav-drop { width: 100%; }
            .nav-drop > .nav-link { width: 100%; justify-content: flex-start; font-size: 15px; padding: 12px 14px; }
            .nav-drop > .nav-link .chev { margin-left: auto; }
            .nav-drop .drop-panel {
                position: static;
                display: none;
                visibility: hidden;
                opacity: 1;
                transform: none;
                box-shadow: none;
                border: none;
                border-left: 3px solid var(--primary);
                border-radius: 0 8px 8px 0;
                background: var(--surface-soft);
                margin: 2px 0 4px 10px;
                padding: 6px;
            }
            .nav-drop.open .drop-panel { display: block; visibility: visible; }
            .drop-link { padding: 11px 12px; font-size: 14px; }

            .warehouse-label { display: none; }
            .user-btn .meta { display: none; }
            .user-btn .chev { display: none; }
        }

        @media (max-width: 640px) {
            .brand-text { display: none; }
            .page-content { padding: 16px; }
        }

        @media (max-width: 1279px) and (min-width: 1025px) {
            .brand-text small { display: none; }
            .nav-link { padding: 9px 10px; }
        }
    </style>
    @stack('styles')
</head>
<body>
    @php
        $navUser      = auth()->user();
        $isAdmin      = $navUser->isAdmin();
        $adminAccess  = $navUser->hasAdminAccess();
        $centerUser   = $navUser->isCenterUser();

        $invActive = request()->routeIs('items*')
            || request()->routeIs('item_categories*')
            || request()->routeIs('warehouses*')
            || request()->routeIs('stock_cards*')
            || request()->routeIs('inventory_balance_report*')
            || request()->routeIs('transfers*');
        $txnActive = request()->routeIs('delivery_subsidies*') || request()->routeIs('requisitions*');
        $repActive = request()->routeIs('rpci_report*') || request()->routeIs('rsmi_report*');
        $admActive = request()->routeIs('suppliers*') || request()->routeIs('users*');

        $topbarWarehouses = $navUser->relationLoaded('warehouses')
            ? $navUser->warehouses->pluck('name')
            : $navUser->warehouses()->pluck('name');
        if ($topbarWarehouses->isEmpty() && $navUser->warehouse) {
            $topbarWarehouses = collect([$navUser->warehouse->name]);
        }
    @endphp

    <!-- Top navigation -->
    <header class="topnav" id="topnav">
        <div class="topnav-inner">
            <button id="menu-toggle" onclick="toggleMobileNav()" aria-label="Toggle menu" aria-expanded="false">
                <i class="fas fa-bars" id="menu-toggle-icon"></i>
            </button>

            <a href="{{ route('dashboard') }}" class="brand" title="Dashboard">
                <img src="{{ asset('images/logo.png') }}" alt="DSWD Logo">
                <span class="brand-text">
                    <strong>Welfare Goods Inventory</strong>
                    <small>WGIMSv2 · DSWD</small>
                </span>
            </a>

            <nav class="main-nav" id="main-nav" aria-label="Main navigation">
                <a href="{{ route('dashboard') }}" class="nav-link {{ request()->routeIs('dashboard*') ? 'active' : '' }}">
                    <i class="fas fa-th-large"></i> Dashboard
                </a>

                {{-- Inventory --}}
                <div class="nav-drop" id="drop-inventory">
                    <button type="button" class="nav-link {{ $invActive ? 'active' : '' }}" onclick="toggleDrop(this)">
                        <i class="fas fa-boxes-stacked"></i> Inventory <i class="fas fa-chevron-down chev"></i>
                    </button>
                    <div class="drop-panel">
                        <div class="drop-title">Inventory</div>
                        <a href="{{ route('items.index') }}" class="drop-link {{ request()->routeIs('items*') ? 'active' : '' }}"><i class="fas fa-cubes"></i> Items</a>
                        @if($isAdmin)
                        <a href="{{ route('item_categories.index') }}" class="drop-link {{ request()->routeIs('item_categories*') ? 'active' : '' }}"><i class="fas fa-tags"></i> Item Categories</a>
                        @endif
                        @if($adminAccess || $centerUser)
                        <a href="{{ route('warehouses.index') }}" class="drop-link {{ request()->routeIs('warehouses*') ? 'active' : '' }}"><i class="fas fa-warehouse"></i> Warehouses</a>
                        @endif
                        <a href="{{ route('stock_cards.summary') }}" class="drop-link {{ request()->routeIs('stock_cards*') ? 'active' : '' }}"><i class="fas fa-book-open"></i> Stock Cards</a>
                        @if($adminAccess)
                        <a href="{{ route('inventory_balance_report') }}" class="drop-link {{ request()->routeIs('inventory_balance_report*') ? 'active' : '' }}"><i class="fas fa-scale-balanced"></i> Inventory Balance</a>
                        @endif
                        <a href="{{ route('transfers.index') }}" class="drop-link {{ request()->routeIs('transfers*') ? 'active' : '' }}"><i class="fas fa-arrows-alt-h"></i> Stock Transfers</a>
                    </div>
                </div>

                {{-- Transactions --}}
                <div class="nav-drop" id="drop-transactions">
                    <button type="button" class="nav-link {{ $txnActive ? 'active' : '' }}" onclick="toggleDrop(this)">
                        <i class="fas fa-arrow-right-arrow-left"></i> Transactions <i class="fas fa-chevron-down chev"></i>
                    </button>
                    <div class="drop-panel">
                        <div class="drop-title">Transactions</div>
                        <a href="{{ route('delivery_subsidies.index') }}" class="drop-link {{ request()->routeIs('delivery_subsidies*') ? 'active' : '' }}"><i class="fas fa-truck-loading"></i> Subsidies / Deliveries</a>
                        <a href="{{ route('requisitions.index') }}" class="drop-link {{ request()->routeIs('requisitions*') ? 'active' : '' }}"><i class="fas fa-clipboard-check"></i> Requisitions (RIS)</a>
                    </div>
                </div>

                {{-- Reports --}}
                <div class="nav-drop" id="drop-reports">
                    <button type="button" class="nav-link {{ $repActive ? 'active' : '' }}" onclick="toggleDrop(this)">
                        <i class="fas fa-chart-line"></i> Reports <i class="fas fa-chevron-down chev"></i>
                    </button>
                    <div class="drop-panel">
                        <div class="drop-title">Reports</div>
                        <a href="{{ route('rpci_report') }}" class="drop-link {{ request()->routeIs('rpci_report*') ? 'active' : '' }}"><i class="fas fa-chart-simple"></i> RPCI Report</a>
                        <a href="{{ route('rsmi_report') }}" class="drop-link {{ request()->routeIs('rsmi_report*') ? 'active' : '' }}"><i class="fas fa-file-lines"></i> RSMI Report</a>
                    </div>
                </div>

                {{-- Administration --}}
                <div class="nav-drop" id="drop-admin">
                    <button type="button" class="nav-link {{ $admActive ? 'active' : '' }}" onclick="toggleDrop(this)">
                        <i class="fas fa-gears"></i> Administration <i class="fas fa-chevron-down chev"></i>
                    </button>
                    <div class="drop-panel">
                        <div class="drop-title">Administration</div>
                        <a href="{{ route('suppliers.index') }}" class="drop-link {{ request()->routeIs('suppliers*') ? 'active' : '' }}"><i class="fas fa-handshake"></i> Suppliers</a>
                        @if($isAdmin)
                        <a href="{{ route('users.index') }}" class="drop-link {{ request()->routeIs('users*') ? 'active' : '' }}"><i class="fas fa-users-gear"></i> Users</a>
                        @endif
                    </div>
                </div>
            </nav>

            <div class="topnav-actions">
                @if($topbarWarehouses->isNotEmpty())
                <span class="warehouse-label"><i class="fas fa-building"></i> {{ $topbarWarehouses->implode(', ') }}</span>
                @endif

                <div class="notif-wrap" id="notif-wrap">
                    <button class="icon-btn" onclick="toggleNotifications()" id="notif-btn" aria-label="Notifications">
                        <i class="fas fa-bell"></i>
                        <span class="notif-badge" id="notif-count" style="display:none">0</span>
                    </button>
                    <div class="notif-dropdown" id="notif-dropdown">
                        <div class="notif-header">
                            Notifications
                            <form action="{{ route('notifications.read_all') }}" method="POST" style="display:inline">
                                @csrf
                                <button type="submit">Mark all read</button>
                            </form>
                        </div>
                        <div class="notif-list" id="notif-list">
                            <div style="padding:20px;text-align:center;color:var(--text-muted);font-size:13px">Loading...</div>
                        </div>
                        <div class="notif-footer">
                            <a href="{{ route('notifications.index') }}">View all notifications</a>
                        </div>
                    </div>
                </div>

                <div class="user-menu" id="user-menu">
                    <button class="user-btn" onclick="toggleUserMenu()" aria-label="Account menu">
                        <div class="user-avatar">{{ strtoupper(substr($navUser->name, 0, 1)) }}</div>
                        <span class="meta">
                            <strong title="{{ $navUser->name }}">{{ $navUser->name }}</strong>
                            <small>{{ $navUser->getRoleLabel() }}</small>
                        </span>
                        <i class="fas fa-chevron-down chev"></i>
                    </button>
                    <div class="drop-panel user-drop" id="user-drop">
                        <div class="drop-title">Signed in as</div>
                        <div style="padding:2px 12px 8px;font-size:13px;color:var(--text-muted)">
                            <strong style="color:var(--text);display:block">{{ $navUser->name }}</strong>
                            {{ $navUser->getRoleLabel() }}
                        </div>
                        <div class="drop-sep"></div>
                        <a href="{{ route('notifications.index') }}" class="drop-link"><i class="fas fa-bell"></i> Notifications</a>
                        <form action="{{ route('logout') }}" method="POST" style="margin:0">
                            @csrf
                            <button type="submit" class="drop-link" style="width:100%;border:none;background:none;cursor:pointer;font-family:inherit;text-align:left">
                                <i class="fas fa-right-from-bracket"></i> Sign out
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Main wrapper (full width) -->
    <div class="main-wrapper">
        <main class="page-content">
            @if(session('success'))
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> {{ session('success') }}</div>
            @endif
            @if(session('error'))
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> {{ session('error') }}</div>
            @endif
            @if($errors->any())
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    @foreach($errors->all() as $error)
                    <div>{{ $error }}</div>
                    @endforeach
                </div>
            </div>
            @endif

            @yield('content')
        </main>
    </div>

    <script>
        // ── Dropdowns (top nav) ────────────────────────────────────────────
        function positionDrop(drop) {
            var panel   = drop.querySelector('.drop-panel');
            var trigger = drop.querySelector(':scope > .nav-link');
            if (!panel || !trigger || window.innerWidth <= 1024) return;
            var r = trigger.getBoundingClientRect();
            var pw = panel.offsetWidth || 232;
            var left = r.left;
            if (left + pw > window.innerWidth - 12) left = Math.max(12, window.innerWidth - pw - 12);
            panel.style.left = left + 'px';
            panel.style.top  = (r.bottom + 10) + 'px';
        }

        function openDrop(drop) {
            positionDrop(drop);
            drop.classList.add('open');
        }

        function toggleDrop(trigger) {
            var drop = trigger.closest('.nav-drop');
            var wasOpen = drop.classList.contains('open');
            closeAllDrops();
            closeNotifications();
            closeUserMenu();
            if (!wasOpen) openDrop(drop);
        }

        function closeAllDrops() {
            document.querySelectorAll('.nav-drop.open').forEach(function (d) { d.classList.remove('open'); });
        }

        function repositionOpenDrops() {
            document.querySelectorAll('.nav-drop.open').forEach(positionDrop);
        }

        (function initNavDrops() {
            var drops = document.querySelectorAll('#main-nav .nav-drop');
            if (window.matchMedia('(hover: hover)').matches) {
                var timer = null;
                drops.forEach(function (d) {
                    d.addEventListener('mouseenter', function () {
                        if (window.innerWidth <= 1024) return;
                        clearTimeout(timer);
                        closeAllDrops();
                        closeNotifications();
                        closeUserMenu();
                        openDrop(d);
                    });
                    d.addEventListener('mouseleave', function () {
                        if (window.innerWidth <= 1024) return;
                        clearTimeout(timer);
                        timer = setTimeout(function () { d.classList.remove('open'); }, 160);
                    });
                });
            }
            window.addEventListener('resize', repositionOpenDrops);
            window.addEventListener('scroll', repositionOpenDrops, true);
        })();

        function toggleUserMenu() {
            var m = document.getElementById('user-menu');
            var wasOpen = m.classList.contains('open');
            closeAllDrops();
            closeNotifications();
            if (!wasOpen) m.classList.add('open');
        }
        function closeUserMenu() {
            var m = document.getElementById('user-menu');
            if (m) m.classList.remove('open');
        }

        function toggleNotifications() {
            var w = document.getElementById('notif-wrap');
            var wasOpen = w.classList.contains('open');
            closeAllDrops();
            closeUserMenu();
            if (!wasOpen) w.classList.add('open');
        }
        function closeNotifications() {
            var w = document.getElementById('notif-wrap');
            if (w) w.classList.remove('open');
        }

        function toggleMobileNav() {
            var open = document.body.classList.toggle('nav-open');
            var icon = document.getElementById('menu-toggle-icon');
            if (icon) icon.className = open ? 'fas fa-xmark' : 'fas fa-bars';
            document.getElementById('menu-toggle').setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) { closeAllDrops(); closeNotifications(); closeUserMenu(); }
        }

        // Close panels when clicking outside them
        document.addEventListener('click', function (e) {
            if (!e.target.closest('.nav-drop')) closeAllDrops();
            if (!e.target.closest('#user-menu')) closeUserMenu();
            if (!e.target.closest('#notif-wrap')) closeNotifications();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeAllDrops();
                closeNotifications();
                closeUserMenu();
                document.body.classList.remove('nav-open');
                var icon = document.getElementById('menu-toggle-icon');
                if (icon) icon.className = 'fas fa-bars';
            }
        });

        // A top-level nav link click on mobile should close the drawer
        document.querySelectorAll('#main-nav > .nav-link').forEach(function (a) {
            a.addEventListener('click', function () {
                if (window.innerWidth <= 1024) document.body.classList.remove('nav-open');
            });
        });

        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        function loadNotifications() {
            fetch('{{ route("notifications.unread") }}', {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => {
                if (r.status === 401) {
                    clearInterval(notifInterval);
                    return null;
                }
                if (!r.ok) throw new Error('Request failed: ' + r.status);
                return r.json();
            })
            .then(data => {
                if (!data) return;

                const badge = document.getElementById('notif-count');
                const list  = document.getElementById('notif-list');

                if (data.count > 0) {
                    badge.style.display = 'flex';
                    badge.textContent = data.count > 99 ? '99+' : data.count;
                } else {
                    badge.style.display = 'none';
                }

                if (data.notifications.length === 0) {
                    list.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text-muted);font-size:13px"><i class="fas fa-bell-slash" style="display:block;font-size:24px;margin-bottom:8px"></i>No new notifications</div>';
                } else {
                    list.innerHTML = data.notifications.map(n => {
                        const iconMap = { success: 'fa-check-circle', warning: 'fa-exclamation-triangle', danger: 'fa-times-circle', transfer: 'fa-arrows-alt-h', info: 'fa-info-circle' };
                        const colorMap = { success: 'var(--success)', warning: 'var(--warning)', danger: 'var(--danger)', transfer: 'var(--primary)', info: 'var(--info)' };
                        const icon  = iconMap[n.type]  || 'fa-info-circle';
                        const color = colorMap[n.type] || 'var(--info)';
                        return `
                        <div class="notif-item unread" data-id="${n.id}" data-link="${n.link || ''}"
                             style="cursor:pointer" onclick="handleNotifClick(this, ${n.id}, '${(n.link || '').replace(/'/g, "\\'")}')">
                            <div style="display:flex;align-items:flex-start;gap:10px">
                                <i class="fas ${icon}" style="color:${color};margin-top:2px;flex-shrink:0"></i>
                                <div style="flex:1;min-width:0">
                                    <div class="notif-item-title">${escapeHtml(n.title)}</div>
                                    <div class="notif-item-msg">${escapeHtml(n.message)}</div>
                                    <div style="font-size:11px;color:var(--text-muted);margin-top:3px">${n.created_at || ''}</div>
                                </div>
                            </div>
                        </div>`;
                    }).join('');
                }
            })
            .catch(() => {});
        }

        function handleNotifClick(el, id, link) {
            fetch(`/notifications/${id}/read-ajax`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                }
            })
            .then(r => r.json())
            .then(data => {
                const badge = document.getElementById('notif-count');
                if (data.remaining_count > 0) {
                    badge.style.display = 'flex';
                    badge.textContent = data.remaining_count > 99 ? '99+' : data.remaining_count;
                } else {
                    badge.style.display = 'none';
                }
                el.remove();
            })
            .catch(() => {})
            .finally(() => {
                if (link) window.location = link;
            });
        }

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
        }

        const isNotifPage = window.location.pathname === '{{ parse_url(route("notifications.index"), PHP_URL_PATH) }}';
        let notifInterval = null;

        setTimeout(loadNotifications, 2000);

        if (!isNotifPage) {
            notifInterval = setInterval(loadNotifications, 30000);
        }
    </script>
    @stack('scripts')
</body>
</html>
