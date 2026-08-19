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

            --sidebar-width: 260px;
            --topbar-height: 60px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { overflow-x: auto; }
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            overflow-x: auto;
            display: flex;
        }

        /* ── Sidebar ──────────────────────────────────────────────────────── */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--surface);
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            display: flex;
            flex-direction: column;
            z-index: 100;
            transition: transform 0.3s;
            border-right: 1px solid var(--border);
            overflow: hidden;
        }
        .sidebar-brand {
            padding: 16px 16px 12px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }
        .sidebar-brand img { height: 42px; width: auto; object-fit: contain; }
        .sidebar-brand .brand-text { line-height: 1.25; min-width: 0; }
        .sidebar-brand .brand-text strong { display: block; font-size: 14px; font-weight: 700; color: var(--text); line-height: 1.3; }
        .sidebar-brand .brand-text small { font-size: 11px; color: var(--text-muted); }

        .sidebar-nav {
            flex: 1 1 auto;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 8px 0;
            min-height: 0;
        }
        .sidebar-nav::-webkit-scrollbar { width: 4px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: var(--border-strong); border-radius: 4px; }

        .nav-section { padding: 16px 20px 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); }
        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 16px;
            color: var(--text);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.15s;
            border-radius: 8px;
            margin: 2px 10px;
        }
        .nav-item:hover { background: var(--surface-hover); color: var(--text); }
        .nav-item.active { background: var(--nav-active-bg); color: var(--nav-active-text); font-weight: 600; }
        .nav-item i { width: 20px; text-align: center; font-size: 17px; flex-shrink: 0; color: var(--primary); }
        .nav-item.active i { color: var(--primary); }

        .sidebar-footer { padding: 12px 16px; border-top: 1px solid var(--border); background: var(--surface); flex-shrink: 0; }
        .user-info { display: flex; align-items: center; gap: 10px; }
        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }
        .user-details { flex: 1; min-width: 0; }
        .user-details strong { display: block; color: var(--text); font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .user-details small { color: var(--text-muted); font-size: 11px; }
        .logout-btn { color: var(--text-muted); font-size: 16px; cursor: pointer; background: none; border: none; padding: 6px; border-radius: 6px; transition: all 0.15s; }
        .logout-btn:hover { color: var(--danger); background: var(--danger-bg); }

        /* ── Topbar (page title + actions, NOT a nav menu) ────────────────── */
        .main-wrapper {
            margin-left: var(--sidebar-width);
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            overflow-x: auto;
            min-width: 0;
        }
        .topbar {
            height: var(--topbar-height);
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            padding: 0 20px;
            gap: 16px;
            position: sticky;
            top: 0;
            z-index: 50;
        }
        .topbar-title { font-size: 17px; font-weight: 600; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .topbar-actions { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }

        #menu-toggle {
            display: none;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 20px;
            color: var(--text-muted);
            padding: 4px;
            border-radius: 8px;
            flex-shrink: 0;
        }
        #menu-toggle:hover { color: var(--text); background: var(--surface-hover); }

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

        .notif-wrap { position: relative; }
        .notif-btn {
            position: relative;
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-muted);
            font-size: 18px;
            padding: 6px;
            border-radius: 8px;
        }
        .notif-btn:hover { color: var(--text); background: var(--surface-hover); }
        .notif-badge {
            position: absolute;
            top: 0;
            right: 0;
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

        /* ── Page content ─────────────────────────────────────────────────── */
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

        /* ── Searchable selects (combobox) ─────────────────────────────────── */
        .ss { position: relative; display: inline-flex; padding: 0 !important; background: transparent; vertical-align: middle; }
        .ss .ss-native { position: absolute !important; inset: 0; width: 100% !important; height: 100% !important; opacity: 0; pointer-events: none; }
        .ss .ss-btn {
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
            width: 100%; min-height: 38px; padding: 9px 12px;
            background: var(--input-bg); border: none; border-radius: 5px;
            font-size: 14px; color: var(--text); cursor: pointer; text-align: left;
            font-family: inherit;
        }
        .ss .ss-value { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .ss .ss-value.ss-placeholder { color: var(--text-muted); }
        .ss .ss-caret { color: var(--text-muted); font-size: 11px; flex-shrink: 0; }
        .ss:focus-within { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.1); }
        .ss .ss-btn:hover { color: var(--primary); }
        .ss.ss-disabled { opacity: 0.6; }
        .ss.ss-disabled .ss-btn { cursor: not-allowed; }
        .ss.ss-invalid { border-color: var(--danger); }
        .ss:has(.ss-native:invalid) { border-color: var(--danger); }
        .ss:has(.ss-native:invalid:focus) { box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1); }

        /* combobox inside line-item tables */
        .line-items-table .ss { width: 100%; min-width: 120px; }
        .line-items-table .ss .ss-btn { padding: 10px 12px; font-size: 13px; }

        /* combobox panel (portal to <body>) */
        .ss-panel {
            position: fixed;
            z-index: 1500;
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 10px;
            box-shadow: 0 10px 34px rgba(2, 6, 23, 0.2);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .ss-panel .ss-search-wrap { padding: 6px; border-bottom: 1px solid var(--border); background: #f8fafc; flex-shrink: 0; }
        .ss-panel .ss-search {
            width: 100%; box-sizing: border-box;
            padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px;
            font-size: 13px; outline: none; background: #fff; color: var(--text);
        }
        .ss-panel .ss-search:focus { border-color: var(--primary); }
        .ss-panel .ss-list { margin: 0; padding: 6px; list-style: none; overflow-y: auto; flex: 1; min-height: 0; overscroll-behavior: contain; -webkit-overflow-scrolling: touch; }
        .ss-panel .ss-item {
            padding: 8px 10px; border-radius: 6px; font-size: 13px; color: var(--text);
            cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .ss-panel .ss-item:hover, .ss-panel .ss-item.active { background: var(--primary); color: #fff; }
        .ss-panel .ss-item.disabled { color: var(--text-muted); cursor: not-allowed; background: transparent; }
        .ss-panel .ss-empty { padding: 14px 12px; font-size: 12px; color: var(--text-muted); text-align: center; }
        .ss-panel .ss-more { padding: 8px 12px; font-size: 11px; color: var(--text-muted); text-align: center; flex-shrink: 0; }

        @media (max-width: 820px) {
            .line-items-table .ss .ss-btn { padding: 8px 10px; font-size: 12px; }
        }
        @media (max-width: 640px) {
            .line-items-table .ss .ss-btn { min-height: 44px; }
        }

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

        /* ── Edit / large form layout (wide main column, compact summary) ──── */
        .edit-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 340px;
            gap: 24px;
            align-items: start;
        }
        .edit-main { min-width: 0; }
        .edit-side { min-width: 0; }
        .edit-side .sticky-card { position: sticky; top: 80px; }
        @media (max-width: 1499px) {
            .edit-layout { grid-template-columns: minmax(0, 1fr); }
            .edit-side .sticky-card { position: static; }
        }

        /* ── Shipment line-item cards (Edit Shipment) ──────────────────────── */
        .shipment-item-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 10px;
            margin-bottom: 16px;
            overflow: hidden;
        }
        .shipment-item-head {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px 16px;
            padding: 14px 20px;
            background: var(--surface-soft);
            border-bottom: 1px solid var(--border);
        }
        .shipment-item-desc { flex: 1 1 280px; min-width: 0; }
        .shipment-item-desc strong { font-size: 14px; }
        .shipment-item-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 2px 20px;
            padding: 16px 20px 0;
        }
        .shipment-item-grid + .shipment-item-grid { padding-top: 0; }
        .shipment-item-grid .form-group { margin-bottom: 14px; }
        /* ── Batch rows (Record Shipment) ──────────────────────────────────── */
        .batch-row {
            border-bottom: 1px solid var(--border);
            padding-bottom: 4px;
        }
        .batch-row-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 14px 20px 0;
        }
        .batch-row-cloned { background: #fffbeb; }

        .shipment-item-total {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-end;
            gap: 10px 28px;
            padding: 12px 20px;
            border-top: 1px dashed var(--border);
            background: var(--surface-soft);
        }
        .total-cell { display: inline-flex; align-items: baseline; gap: 7px; }
        .total-cell .tlabel { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; color: var(--text-muted); }
        .total-cell .tvalue { font-size: 15px; font-weight: 800; color: var(--text); }
        .total-cell .tvalue.engas { color: var(--primary); }
        .shipment-grand {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-end;
            gap: 10px 40px;
            padding: 14px 20px;
            border-top: 1px solid var(--border);
            background: var(--surface-soft);
        }
        @media (max-width: 991px) {
            .shipment-item-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 639px) {
            .shipment-item-grid { grid-template-columns: 1fr; }
        }

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
        .pagination {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 0;
            list-style: none;
            max-width: 100%;
        }
        .pagination .page-item { margin: 0; padding: 0; list-style: none; }
        .pagination .page-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            min-height: 36px;
            padding: 6px 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--surface);
            font-size: 13px;
            font-weight: 500;
            line-height: 1;
            white-space: nowrap;
            text-decoration: none;
            color: var(--text);
            transition: all 0.15s;
        }
        .pagination a.page-link:hover { background: var(--surface-hover); border-color: var(--primary); color: var(--primary); }
        .pagination .page-item.active .page-link { background: var(--primary); border-color: var(--primary); color: #fff; font-weight: 700; box-shadow: 0 1px 4px rgba(2, 132, 199, 0.35); }
        .pagination .page-item.disabled .page-link { background: var(--surface-soft); color: var(--text-muted); cursor: not-allowed; }
        .pagination-wrap { display: flex; flex-direction: column; align-items: center; gap: 8px; }
        .pagination-summary { font-size: 12px; color: var(--text-muted); }

        /* ── Pagination responsive ─────────────────────────────────────────── */
        @media (max-width: 640px) {
            .pagination { gap: 4px; }
            .pagination .page-link { min-width: 32px; min-height: 32px; padding: 4px 9px; font-size: 12px; }
            .pagination-summary { font-size: 11px; }
        }

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
            .sidebar, .topbar, .no-print { display: none !important; }
            .main-wrapper { margin-left: 0; }
            .page-content { padding: 0; }
            body { background: #fff; }
        }

        /* ── Responsive: sidebar slides off-canvas + hamburger ────────────── */
        @media (max-width: 1024px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); box-shadow: 4px 0 24px rgba(0, 0, 0, 0.15); }
            .main-wrapper { margin-left: 0; }
            .form-row.cols-2, .form-row.cols-3, .form-row.cols-4 { grid-template-columns: 1fr; }
            #menu-toggle { display: inline-flex; }
            .warehouse-label { display: none; }
        }

        /* Overlay when sidebar is open on small screens / zoomed */
        @media (max-width: 1024px) {
            body.sidebar-open::after {
                content: '';
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.3);
                z-index: 99;
            }
        }

        @media (max-width: 640px) {
            .page-content { padding: 16px; }
            .topbar { padding: 0 14px; }
        }
    </style>
    @stack('styles')
</head>
<body>
    @php
        $navUser = auth()->user();
        $topbarWarehouses = $navUser->relationLoaded('warehouses')
            ? $navUser->warehouses->pluck('name')
            : $navUser->warehouses()->pluck('name');
        if ($topbarWarehouses->isEmpty() && $navUser->warehouse) {
            $topbarWarehouses = collect([$navUser->warehouse->name]);
        }
    @endphp

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <img src="{{ asset('images/logo.png') }}" alt="DSWD Logo" style="height:42px;width:auto;object-fit:contain;">
            <div class="brand-text">
                <strong>Welfare Goods Inventory</strong>
                <small>WGIMSv2 · DSWD</small>
            </div>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section">Core System</div>
            <a href="{{ route('dashboard') }}" class="nav-item {{ request()->routeIs('dashboard*') ? 'active' : '' }}">
                <i class="fas fa-th-large"></i> Dashboard
            </a>

            <div class="nav-section">Inventory</div>
            <a href="{{ route('items.index') }}" class="nav-item {{ request()->routeIs('items*') ? 'active' : '' }}">
                <i class="fas fa-cubes"></i> Items
            </a>
            <a href="{{ route('delivery_subsidies.index') }}" class="nav-item {{ request()->routeIs('delivery_subsidies*') ? 'active' : '' }}">
                <i class="fas fa-truck-loading"></i> Subsidies / Deliveries
            </a>
            <a href="{{ route('requisitions.index') }}" class="nav-item {{ request()->routeIs('requisitions*') ? 'active' : '' }}">
                <i class="fas fa-clipboard-check"></i> Requisitions / Augmentations
            </a>
            <a href="{{ route('transfers.index') }}" class="nav-item {{ request()->routeIs('transfers*') ? 'active' : '' }}">
                <i class="fas fa-arrows-alt-h"></i> Stock Transfers
            </a>
            <a href="{{ route('stock_cards.summary') }}" class="nav-item {{ request()->routeIs('stock_cards*') ? 'active' : '' }}">
                <i class="fas fa-book-open"></i> Stock Cards
            </a>

            <div class="nav-section">Procurement</div>
            <a href="{{ route('suppliers.index') }}" class="nav-item {{ request()->routeIs('suppliers*') ? 'active' : '' }}">
                <i class="fas fa-handshake"></i> Suppliers
            </a>

            <div class="nav-section">Reports</div>
            <a href="{{ route('rpci_report') }}" class="nav-item {{ request()->routeIs('rpci_report*') ? 'active' : '' }}">
                <i class="fas fa-chart-simple"></i> RPCI Report
            </a>
            <a href="{{ route('rsmi_report') }}" class="nav-item {{ request()->routeIs('rsmi_report*') ? 'active' : '' }}">
                <i class="fas fa-file-lines"></i> RSMI Report
            </a>
            @if($navUser->hasAdminAccess())
            <a href="{{ route('inventory_balance_report') }}" class="nav-item {{ request()->routeIs('inventory_balance_report*') ? 'active' : '' }}">
                <i class="fas fa-scale-balanced"></i> Inventory Balance
            </a>
            @endif

            <div class="nav-section">Administration</div>
            @if($navUser->isAdmin())
            <a href="{{ route('item_categories.index') }}" class="nav-item {{ request()->routeIs('item_categories*') ? 'active' : '' }}">
                <i class="fas fa-tags"></i> Item Categories
            </a>
            @endif
            @if($navUser->hasAdminAccess() || $navUser->isCenterUser())
            <a href="{{ route('warehouses.index') }}" class="nav-item {{ request()->routeIs('warehouses*') ? 'active' : '' }}">
                <i class="fas fa-warehouse"></i> Warehouses
            </a>
            @endif
            @if($navUser->isAdmin())
            <a href="{{ route('users.index') }}" class="nav-item {{ request()->routeIs('users*') ? 'active' : '' }}">
                <i class="fas fa-users-gear"></i> Users
            </a>
            @endif
        </nav>

        <div class="sidebar-footer">
            <div class="user-info">
                <div class="user-avatar">{{ strtoupper(substr($navUser->name, 0, 1)) }}</div>
                <div class="user-details">
                    <strong title="{{ $navUser->name }}">{{ $navUser->name }}</strong>
                    <small>{{ $navUser->getRoleLabel() }}</small>
                </div>
                <form action="{{ route('logout') }}" method="POST" style="display:inline">
                    @csrf
                    <button type="submit" class="logout-btn" title="Sign out"><i class="fas fa-right-from-bracket"></i></button>
                </form>
            </div>
        </div>
    </aside>

    <!-- Main wrapper -->
    <div class="main-wrapper">
        <!-- Topbar -->
        <header class="topbar">
            <button id="menu-toggle" onclick="toggleSidebar()" aria-label="Toggle menu" aria-expanded="false">
                <i class="fas fa-bars"></i>
            </button>
            <div class="topbar-title">@yield('page-title', 'Dashboard')</div>
            <div class="topbar-actions">
                @if($topbarWarehouses->isNotEmpty())
                <span class="warehouse-label"><i class="fas fa-building"></i> {{ $topbarWarehouses->implode(', ') }}</span>
                @endif
                <div class="notif-wrap" id="notif-wrap">
                    <button class="notif-btn" onclick="toggleNotifications()" id="notif-btn" aria-label="Notifications">
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
            </div>
        </header>

        <!-- Page content -->
        <main class="page-content">
            @if(session('success'))
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> {{ session('success') }}</div>
            @endif
            @if(session('error'))
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> {{ session('error') }}</div>
            @endif
            @if(session('warning'))
            <div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> {{ session('warning') }}</div>
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

    @include('partials.subsidy-details-modal')

    <script>
        function toggleSidebar() {
            var sidebar = document.getElementById('sidebar');
            var open = sidebar.classList.toggle('open');
            document.body.classList.toggle('sidebar-open', open);
            var toggle = document.getElementById('menu-toggle');
            if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        function closeSidebar() {
            var sidebar = document.getElementById('sidebar');
            if (sidebar) sidebar.classList.remove('open');
            document.body.classList.remove('sidebar-open');
            var toggle = document.getElementById('menu-toggle');
            if (toggle) toggle.setAttribute('aria-expanded', 'false');
        }

        // Close sidebar when clicking outside it (overlay), and close the
        // notifications panel when clicking anywhere else.
        document.addEventListener('click', function(e) {
            var sidebar = document.getElementById('sidebar');
            var toggle  = document.getElementById('menu-toggle');
            if (
                sidebar &&
                sidebar.classList.contains('open') &&
                ! sidebar.contains(e.target) &&
                toggle && ! toggle.contains(e.target)
            ) {
                closeSidebar();
            }

            var wrap = document.getElementById('notif-wrap');
            if (wrap && ! wrap.contains(e.target)) wrap.classList.remove('open');
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeSidebar();
        });

        function toggleNotifications() {
            var w = document.getElementById('notif-wrap');
            if (w) w.classList.toggle('open');
        }

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

    <script>
    // ── Global searchable select component (combobox) ──────────────────────
    // Enhances every <select> into a writable/searchable dropdown. The native
    // select stays in the DOM (hidden) so form submission, validation and all
    // existing onchange handlers keep working unchanged.
    (function () {
        'use strict';

        if (!window.HTMLSelectElement || window.__ssLoaded) return;
        window.__ssLoaded = true;

        var nativeValue = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
        if (nativeValue && nativeValue.set) {
            Object.defineProperty(HTMLSelectElement.prototype, 'value', {
                configurable: true,
                enumerable: true,
                get: function () { return nativeValue.get.call(this); },
                set: function (v) {
                    nativeValue.set.call(this, v);
                    if (this.ss && typeof this.ss.sync === 'function') this.ss.sync();
                }
            });
        }

        var MAX_RENDER = 100;
        var instances  = [];

        function escapeHtml(str) {
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        function closeAll(except) {
            instances.forEach(function (inst) {
                if (inst !== except && inst.open) inst.close();
            });
        }

        function repositionAll() {
            instances.forEach(function (inst) {
                if (inst.open) inst.position();
            });
        }

        function SearchableSelect(select) {
            this.select  = select;
            this.wrapper  = null;
            this.btn      = null;
            this.valueEl  = null;
            this.panel    = null;
            this.search   = null;
            this.list     = null;
            this.highlight = -1;
            this.term     = '';
            this.open     = false;
            this.matches  = [];
            select.ss = this;
            instances.push(this);
            this.build();
        }

        SearchableSelect.prototype.build = function () {
            var self = this, s = this.select;

            this.wrapper = document.createElement('div');
            var classes = (s.className || '').replace(/\bform-control\b/g, '').replace(/\s+/g, ' ').trim();
            this.wrapper.className = 'ss form-control' + (classes ? ' ' + classes : '');

            if (s.getAttribute('style')) {
                var style = s.getAttribute('style');
                var m;
                m = style.match(/width\s*:\s*[^;]+/i); if (m) this.wrapper.style.width = m[0].split(':')[1].trim();
                m = style.match(/min-width\s*:\s*[^;]+/i); if (m) this.wrapper.style.minWidth = m[0].split(':')[1].trim();
                m = style.match(/max-width\s*:\s*[^;]+/i); if (m) this.wrapper.style.maxWidth = m[0].split(':')[1].trim();
            }

            s.parentNode.insertBefore(this.wrapper, s);
            this.wrapper.appendChild(s);
            s.setAttribute('tabindex', '-1');
            s.classList.add('ss-native');

            this.btn = document.createElement('button');
            this.btn.type = 'button';
            this.btn.className = 'ss-btn';
            this.btn.setAttribute('aria-haspopup', 'listbox');
            this.btn.innerHTML = '<span class="ss-value"></span><i class="fas fa-chevron-down ss-caret"></i>';
            this.btn.addEventListener('click', function (e) { e.stopPropagation(); self.toggle(); });
            this.btn.addEventListener('keydown', function (e) {
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    self.openPanel();
                }
            });
            this.wrapper.appendChild(this.btn);
            this.valueEl = this.btn.querySelector('.ss-value');

            s.addEventListener('change', function () { self.sync(); });

            this.sync();
            this.observe();
        };

        SearchableSelect.prototype.observe = function () {
            var self = this;
            this.observer = new MutationObserver(function () {
                self.sync();
                if (self.open) self.renderOptions();
            });
            this.observer.observe(this.select, {
                childList: true,
                attributes: true,
                attributeFilter: ['disabled', 'required', 'class']
            });
        };

        SearchableSelect.prototype.sync = function () {
            var s = this.select;
            if (!this.valueEl) return;
            var opt = s.options[s.selectedIndex] || s.options[0];
            this.valueEl.textContent = opt ? opt.text : (s.getAttribute('data-placeholder') || '— Select —');
            this.valueEl.classList.toggle('ss-placeholder', !s.value);
            if (this.btn) this.btn.disabled = s.disabled;
            this.wrapper.classList.toggle('ss-disabled', s.disabled);
            this.wrapper.classList.toggle('ss-invalid', s.classList.contains('is-invalid'));
        };

        SearchableSelect.prototype.toggle = function () {
            if (this.open) this.close();
            else this.openPanel();
        };

        SearchableSelect.prototype.openPanel = function () {
            if (this.open || this.select.disabled) return;
            var self = this;

            closeAll(this);

            this.panel = document.createElement('div');
            this.panel.className = 'ss-panel';
            this.panel.innerHTML =
                '<div class="ss-search-wrap"><input type="text" class="ss-search" placeholder="Search…" autocomplete="off" spellcheck="false"></div>' +
                '<div class="ss-list" role="listbox"></div>';
            document.body.appendChild(this.panel);
            this.search = this.panel.querySelector('.ss-search');
            this.list   = this.panel.querySelector('.ss-list');
            this.term = '';
            this.highlight = -1;

            this.search.addEventListener('input', function () {
                self.term = self.search.value;
                self.renderOptions();
            });
            this.search.addEventListener('keydown', function (e) {
                if (e.key === 'ArrowDown') { e.preventDefault(); self.highlightNext(1); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); self.highlightNext(-1); }
                else if (e.key === 'Enter') { e.preventDefault(); self.commitHighlight(); }
                else if (e.key === 'Escape') { self.close(); }
            });
            this.list.addEventListener('mousedown', function (e) {
                var item = e.target.closest ? e.target.closest('.ss-item') : null;
                if (!item) return;
                if (item.classList.contains('disabled')) return;
                self.commit(parseInt(item.getAttribute('data-idx'), 10));
            });

            this.renderOptions();
            this.position();
            this.open = true;
            this.search.focus();
        };

        SearchableSelect.prototype.position = function () {
            var MAX_PANEL = 300;
            var r = this.btn.getBoundingClientRect();
            var vw = window.innerWidth, vh = window.innerHeight;
            if (r.bottom < 0 || r.top > vh || r.right < 0 || r.left > vw) { this.close(); return; }
            var w = Math.max(r.width, 240);
            var left = Math.min(Math.max(r.left, 8), vw - w - 8);
            this.panel.style.width = w + 'px';
            this.panel.style.left = left + 'px';
            var below = vh - r.bottom;
            var above = r.top;
            if (below < 220 && above > below) {
                this.panel.style.top = '';
                this.panel.style.bottom = (vh - r.top + 4) + 'px';
                this.panel.style.maxHeight = (Math.max(120, Math.min(above, MAX_PANEL)) - 4) + 'px';
            } else {
                this.panel.style.bottom = '';
                this.panel.style.top = (r.bottom + 4) + 'px';
                this.panel.style.maxHeight = (Math.max(120, Math.min(below, MAX_PANEL)) - 4) + 'px';
            }
        };

        SearchableSelect.prototype.renderOptions = function () {
            if (!this.list) return;
            var self = this;
            var term = (this.term || '').toLowerCase().trim();
            var opts = this.select.options;
            this.matches = [];
            for (var i = 0; i < opts.length; i++) {
                if (term && opts[i].text.toLowerCase().indexOf(term) === -1) continue;
                this.matches.push(i);
            }
            var html = '', shown = 0;
            for (var j = 0; j < this.matches.length && shown < MAX_RENDER; j++) {
                var o = opts[this.matches[j]];
                shown++;
                html += '<li class="ss-item' + (o.disabled ? ' disabled' : '') + '" data-idx="' + this.matches[j] + '" role="option">' + escapeHtml(o.text) + '</li>';
            }
            if (shown === 0) {
                this.list.innerHTML = '<div class="ss-empty">No matching options</div>';
            } else {
                this.list.innerHTML = html + (this.matches.length > MAX_RENDER
                    ? '<div class="ss-more">' + (this.matches.length - MAX_RENDER) + ' more — keep typing to narrow results</div>'
                    : '');
            }
            this.highlight = -1;
            this.highlightNext(1, true);
        };

        SearchableSelect.prototype.highlightNext = function (delta, force) {
            var items = this.list.querySelectorAll('.ss-item:not(.disabled)');
            if (!items.length) { this.highlight = -1; return; }
            var pos;
            if (force || this.highlight < 0) pos = delta > 0 ? 0 : items.length - 1;
            else pos = (this.highlight + delta + items.length) % items.length;
            for (var i = 0; i < items.length; i++) {
                items[i].classList.toggle('active', i === pos);
                if (i === pos) items[i].scrollIntoView({ block: 'nearest' });
            }
            this.highlight = pos;
        };

        SearchableSelect.prototype.commitHighlight = function () {
            var items = this.list.querySelectorAll('.ss-item:not(.disabled)');
            if (!items.length) return;
            var pos = this.highlight < 0 ? 0 : this.highlight;
            var el  = items[pos];
            if (!el) return;
            this.commit(parseInt(el.getAttribute('data-idx'), 10));
        };

        SearchableSelect.prototype.commit = function (optIndex) {
            var s = this.select;
            var prev = s.value;
            s.selectedIndex = optIndex;
            if (s.value !== prev) {
                s.dispatchEvent(new Event('change', { bubbles: true }));
            }
            this.sync();
            this.close();
        };

        SearchableSelect.prototype.close = function () {
            if (!this.open) return;
            if (this.panel) {
                this.panel.remove();
                this.panel = null;
            }
            this.open = false;
            this.btn.focus();
        };

        function enhance(select) {
            if (!select || select.ss || select.hasAttribute('data-ss') && select.getAttribute('data-ss') === 'false') return;
            new SearchableSelect(select);
        }

        function initAll(root) {
            var selects = (root || document).querySelectorAll('select');
            for (var i = 0; i < selects.length; i++) enhance(selects[i]);
        }

        function domReady(fn) {
            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
            else fn();
        }

        domReady(function () { initAll(document); });

        var mo = new MutationObserver(function (muts) {
            for (var i = 0; i < muts.length; i++) {
                var nodes = muts[i].addedNodes;
                for (var j = 0; j < nodes.length; j++) {
                    var n = nodes[j];
                    if (n.nodeType !== 1) continue;
                    if (n.matches && n.matches('select')) enhance(n);
                    if (n.querySelectorAll) {
                        var inner = n.querySelectorAll('select');
                        for (var k = 0; k < inner.length; k++) enhance(inner[k]);
                    }
                }
            }
        });
        mo.observe(document.documentElement, { childList: true, subtree: true });

        document.addEventListener('mousedown', function (e) {
            if (e.target.closest && e.target.closest('.ss-panel')) return;
            if (e.target.closest && e.target.closest('.ss')) return;
            closeAll();
        });
        window.addEventListener('scroll', function (e) {
            var t = e.target;
            if (t && t.closest && t.closest('.ss-panel')) return;
            repositionAll();
        }, true);
        window.addEventListener('resize', function () { repositionAll(); });

        window.SS = {
            sync: function (el) { if (el && el.ss) el.ss.sync(); },
            refresh: function (root) { initAll(root); }
        };
    })();
    </script>
    <script>
    // Disable a form's submit button the instant it is submitted, so a
    // double-click (or any second submit) can never fire the same request twice.
    // Native-submit forms only — fetch-based modals manage their own buttons.
    function guardFormSubmit(form) {
        if (!form) return;
        form.addEventListener('submit', function () {
            var btn = form.querySelector('button[type="submit"]');
            if (btn && !btn.disabled) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            }
        });
    }
    </script>
    @stack('scripts')
</body>
</html>
