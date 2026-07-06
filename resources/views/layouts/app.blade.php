<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'WGIMSv2') — Welfare Goods Inventory Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --primary: #0284c7;
            --primary-dark: #0369a1;
            --sidebar-bg: #1e2a3a;
            --sidebar-hover: #2d3f55;
            --sidebar-active: #0284c7;
            --sidebar-text: #c8d6e5;
            --sidebar-width: 260px;
            --topbar-height: 60px;
            --bg: #f0f4f8;
            --card-bg: #ffffff;
            --border: #e2e8f0;
            --text: #1a202c;
            --text-muted: #718096;
            --success: #38a169;
            --warning: #d69e2e;
            --danger: #e53e3e;
            --info: #0284c7;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { overflow-x: auto; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); display: flex; min-height: 100vh; overflow-x: auto; }

        /* Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            background: #ffffff;
            height: 100vh;
            position: fixed;
            top: 0; left: 0;
            display: flex;
            flex-direction: column;
            z-index: 100;
            transition: transform 0.3s;
            border-right: 1px solid #e5e7eb;
            overflow: hidden;
        }
        .sidebar-brand {
            padding: 16px 16px 12px;
            border-bottom: 1px solid #f3f4f6;
            display: flex; align-items: center; gap: 10px;
            flex-shrink: 0;
        }
        .sidebar-brand .logo-icon {
            width: 38px; height: 38px;
            background: var(--primary);
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 18px; font-weight: bold;
        }
        .sidebar-brand .brand-text { color: #1f2937; }
        .sidebar-brand .brand-text strong { display: block; font-size: 15px; font-weight: 700; line-height: 1.3; }
        .sidebar-brand .brand-text small { font-size: 11px; color: #6b7280; }
        .sidebar-nav { flex: 1 1 auto; overflow-y: auto; overflow-x: hidden; padding: 8px 0; min-height: 0; }
        .sidebar-nav::-webkit-scrollbar { width: 4px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 4px; }
        .nav-section { padding: 16px 16px 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #9ca3af; }
        .nav-item { display: flex; align-items: center; gap: 12px; padding: 10px 16px; color: #374151; text-decoration: none; font-size: 15px; font-weight: 500; transition: all 0.15s; border-radius: 8px; margin: 2px 8px; }
        .nav-item:hover { background: #f3f4f6; color: #111827; }
        .nav-item.active { background: #f0f9ff; color: #0284c7; font-weight: 600; }
        .nav-item i { width: 20px; text-align: center; font-size: 18px; flex-shrink: 0; }
        .nav-item .badge-count { margin-left: auto; background: var(--danger); color: white; border-radius: 10px; padding: 1px 7px; font-size: 11px; }

        /* Stock card submenu */
        .nav-submenu { display: none; }
        .nav-submenu.open { display: block; }
        .nav-submenu .nav-item { padding-left: 44px; font-size: 14px; margin: 1px 8px; }
        .nav-parent { cursor: pointer; }
        .nav-parent .arrow { margin-left: auto; transition: transform 0.2s; }
        .nav-parent.open .arrow { transform: rotate(90deg); }

        .sidebar-footer { padding: 12px 16px; border-top: 1px solid #f3f4f6; background: #ffffff; flex-shrink: 0; }
        .user-info { display: flex; align-items: center; gap: 10px; }
        .user-avatar { width: 36px; height: 36px; border-radius: 50%; background: var(--primary); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 14px; flex-shrink: 0; }
        .user-details { flex: 1; min-width: 0; }
        .user-details strong { display: block; color: #111827; font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .user-details small { color: #6b7280; font-size: 11px; }
        .logout-btn { color: #9ca3af; font-size: 16px; cursor: pointer; background: none; border: none; padding: 6px; border-radius: 6px; transition: all 0.15s; }
        .logout-btn:hover { color: #ef4444; background: #fef2f2; }

        /* Main content */
        .main-wrapper { margin-left: var(--sidebar-width); flex: 1; display: flex; flex-direction: column; min-height: 100vh; overflow-x: auto; min-width: 0; }
        .topbar { height: var(--topbar-height); background: white; border-bottom: 1px solid var(--border); display: flex; align-items: center; padding: 0 24px; gap: 16px; position: sticky; top: 0; z-index: 50; }
        .topbar-title { font-size: 18px; font-weight: 600; flex: 1; }
        .topbar-actions { display: flex; align-items: center; gap: 12px; }
        .notif-btn { position: relative; background: none; border: none; cursor: pointer; color: var(--text-muted); font-size: 20px; padding: 4px; }
        .notif-badge { position: absolute; top: -2px; right: -2px; background: var(--danger); color: white; border-radius: 50%; width: 18px; height: 18px; font-size: 10px; display: flex; align-items: center; justify-content: center; }
        .notif-dropdown { position: absolute; top: 100%; right: 0; width: 320px; background: white; border: 1px solid var(--border); border-radius: 8px; box-shadow: 0 8px 24px rgba(0,0,0,0.12); z-index: 200; display: none; }
        .notif-dropdown.open { display: block; }
        .notif-header { padding: 12px 16px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; font-weight: 600; }
        .notif-list { max-height: 300px; overflow-y: auto; }
        .notif-item { padding: 12px 16px; border-bottom: 1px solid var(--border); cursor: pointer; }
        .notif-item:hover { background: #f7fafc; }
        .notif-item.unread { background: #f0f9ff; }
        .notif-item-title { font-size: 13px; font-weight: 600; }
        .notif-item-msg { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
        .notif-footer { padding: 10px 16px; text-align: center; }
        .notif-footer a { color: var(--primary); font-size: 13px; text-decoration: none; }

        /* Page content */
        .page-content { flex: 1; padding: 24px; overflow-x: auto; min-width: 0; }
        .page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
        .page-header h1 { font-size: 22px; font-weight: 700; }
        .breadcrumb { font-size: 13px; color: var(--text-muted); margin-top: 4px; }
        .breadcrumb a { color: var(--primary); text-decoration: none; }

        /* Cards */
        .card { background: var(--card-bg); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
        .card-header { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
        .card-header h3 { font-size: 15px; font-weight: 600; }
        .card-body { padding: 20px; }
        .card-footer { padding: 12px 20px; border-top: 1px solid var(--border); background: #f7fafc; }

        /* Stats cards */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: white; border: 1px solid var(--border); border-radius: 10px; padding: 20px; display: flex; align-items: center; gap: 16px; }
        .stat-icon { width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 22px; }
        .stat-icon.blue { background: #f0f9ff; color: var(--primary); }
        .stat-icon.green { background: #f0fff4; color: var(--success); }
        .stat-icon.yellow { background: #fffff0; color: var(--warning); }
        .stat-icon.red { background: #fff5f5; color: var(--danger); }
        .stat-value { font-size: 26px; font-weight: 700; }
        .stat-label { font-size: 13px; color: var(--text-muted); }

        /* Quick nav cards */
        .quick-nav { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .quick-card { background: white; border: 1px solid var(--border); border-radius: 10px; padding: 20px; text-align: center; text-decoration: none; color: var(--text); transition: all 0.2s; }
        .quick-card:hover { border-color: var(--primary); box-shadow: 0 4px 12px rgba(2,132,199,0.1); transform: translateY(-2px); }
        .quick-card i { font-size: 28px; color: var(--primary); margin-bottom: 10px; display: block; }
        .quick-card span { font-size: 13px; font-weight: 500; }

        /* Tables */
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        thead th { background: #f7fafc; padding: 10px 14px; text-align: left; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); border-bottom: 2px solid var(--border); white-space: nowrap; }
        tbody td { padding: 12px 14px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tbody tr:hover { background: #f7fafc; }
        tbody tr:last-child td { border-bottom: none; }

        /* Badges */
        .badge { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .badge-success { background: #f0fff4; color: #276749; }
        .badge-warning { background: #fffff0; color: #744210; }
        .badge-danger { background: #fff5f5; color: #9b2c2c; }
        .badge-info { background: #f0f9ff; color: #0369a1; }
        .badge-secondary { background: #f7fafc; color: #4a5568; }
        .badge-primary { background: #f0f9ff; color: #0284c7; }

        /* Buttons */
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer; border: none; text-decoration: none; transition: all 0.2s; }
        .btn-sm { padding: 5px 10px; font-size: 12px; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #2f855a; }
        .btn-warning { background: var(--warning); color: white; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover { background: #c53030; }
        .btn-secondary { background: #e2e8f0; color: var(--text); }
        .btn-secondary:hover { background: #cbd5e0; }
        .btn-outline { background: transparent; border: 1px solid var(--border); color: var(--text); }
        .btn-outline:hover { background: #f7fafc; }
        .btn-icon { padding: 6px 8px; }

        /* Forms */
        .form-group { margin-bottom: 18px; }
        .form-label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #4a5568; }
        .form-control { width: 100%; padding: 9px 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 14px; color: var(--text); background: white; transition: border-color 0.2s; }
        .form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(2,132,199,0.1); }
        .form-control.is-invalid { border-color: var(--danger); }
        .invalid-feedback { color: var(--danger); font-size: 12px; margin-top: 4px; }
        .form-row { display: grid; gap: 16px; }
        .form-row.cols-2 { grid-template-columns: 1fr 1fr; }
        .form-row.cols-3 { grid-template-columns: 1fr 1fr 1fr; }
        .form-row.cols-4 { grid-template-columns: 1fr 1fr 1fr 1fr; }
        select.form-control { cursor: pointer; }
        textarea.form-control { resize: vertical; min-height: 80px; }

        /* Alerts */
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; display: flex; align-items: flex-start; gap: 10px; font-size: 14px; }
        .alert-success { background: #f0fff4; border: 1px solid #9ae6b4; color: #276749; }
        .alert-danger { background: #fff5f5; border: 1px solid #feb2b2; color: #9b2c2c; }
        .alert-warning { background: #fffff0; border: 1px solid #faf089; color: #744210; }
        .alert-info { background: #f0f9ff; border: 1px solid #bae6fd; color: #0369a1; }

        /* Filters bar */
        .filters-bar { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 16px; }
        .filters-bar .form-control { width: auto; min-width: 160px; }
        .search-input { position: relative; }
        .search-input i { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--text-muted); }
        .search-input input { padding-left: 32px; }

        /* Two-row search + filter header */
        .card-header-filters { padding: 14px 20px; border-bottom: 1px solid var(--border); display: flex; flex-direction: column; gap: 10px; }
        .search-row { display: flex; gap: 8px; align-items: center; }
        .search-row .search-input { width: 320px; }
        .search-row .search-input input { width: 100%; }
        .filter-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; padding-top: 10px; border-top: 1px solid var(--border); }
        .filter-row .form-control { width: auto; min-width: 150px; }

        /* Pagination */
        .pagination { display: flex; gap: 4px; align-items: center; justify-content: center; padding: 16px; }
        .pagination a, .pagination span { padding: 6px 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 13px; text-decoration: none; color: var(--text); }
        .pagination a:hover { background: #f7fafc; }
        .pagination .active { background: var(--primary); color: white; border-color: var(--primary); }
        .pagination .disabled { color: var(--text-muted); cursor: not-allowed; }

        /* Print styles */
        @media print {
            .sidebar, .topbar, .no-print { display: none !important; }
            .main-wrapper { margin-left: 0; }
            .page-content { padding: 0; }
        }

        /* Responsive — also handles browser zoom scenarios */
        @media (max-width: 1024px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); box-shadow: 4px 0 24px rgba(0,0,0,0.15); }
            .main-wrapper { margin-left: 0; }
            .form-row.cols-2, .form-row.cols-3, .form-row.cols-4 { grid-template-columns: 1fr; }
            #menu-toggle { display: block !important; }
        }

        /* Overlay when sidebar is open on small screens / zoomed */
        @media (max-width: 1024px) {
            body.sidebar-open::after {
                content: '';
                position: fixed;
                inset: 0;
                background: rgba(0,0,0,0.3);
                z-index: 99;
            }
        }

        /* Line items table */
        .line-items-table { width: 100%; border-collapse: collapse; }
        .line-items-table th, .line-items-table td { padding: 8px 10px; border: 1px solid var(--border); font-size: 13px; }
        .line-items-table th { background: #f7fafc; font-weight: 600; }
        .line-items-table input, .line-items-table select { width: 100%; padding: 6px 8px; border: 1px solid var(--border); border-radius: 4px; font-size: 13px; color: #000 !important; -webkit-text-fill-color: #000 !important; background: #fff !important; opacity: 1 !important; }
        .line-items-table input:focus, .line-items-table select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px rgba(2,132,199,0.1); }
        .line-items-table input[readonly] { background: #f7fafc !important; color: #718096 !important; -webkit-text-fill-color: #718096 !important; }
        .remove-row { background: none; border: none; color: var(--danger); cursor: pointer; font-size: 16px; }

        /* Balance table */
        .balance-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .balance-table th, .balance-table td { padding: 8px 12px; border: 1px solid var(--border); }
        .balance-table th { background: #1e2a3a; color: white; font-weight: 600; }
        .balance-table .warehouse-row { background: #f0f9ff; font-weight: 600; }
        .balance-table .total-row { background: #f0fff4; font-weight: 700; }

        /* Stock card print */
        .stock-card-print { font-family: Arial, sans-serif; font-size: 11px; }
        .stock-card-print table { border-collapse: collapse; width: 100%; }
        .stock-card-print th, .stock-card-print td { border: 1px solid black; padding: 3px 5px; }
    </style>
    @stack('styles')
</head>
<body>
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <img src="{{ asset ('images/logo.png')}}"
           alt="DSWD Logo"
           style="height:42px;width:auto;object-fit:contain;">
            <div class="brand-text">
                <strong>Welfare Goods Inventory Management</strong>
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
                <i class="fas fa-truck-loading"></i> Delivery / Subsidies
            </a>
            <a href="{{ route('requisitions.index') }}" class="nav-item {{ request()->routeIs('requisitions*') ? 'active' : '' }}">
                <i class="fas fa-clipboard-check"></i> Requisitions (RIS)
            </a>
            <a href="{{ route('transfers.index') }}" class="nav-item {{ request()->routeIs('transfers*') ? 'active' : '' }}">
                <i class="fas fa-arrows-alt-h"></i> Stock Transfers
            </a>

            <a href="{{ route('stock_cards.summary') }}" class="nav-item {{ request()->routeIs('stock_cards.summary') || request()->routeIs('stock_cards.home') ? 'active' : '' }}">
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
            @if(auth()->user()->hasAdminAccess())
            <a href="{{ route('inventory_balance_report') }}" class="nav-item {{ request()->routeIs('inventory_balance_report*') ? 'active' : '' }}">
                <i class="fas fa-scale-balanced"></i> Inventory Balance
            </a>
            @endif

            @if(auth()->user()->isAdmin())
            <div class="nav-section">Administration</div>
            <a href="{{ route('item_categories.index') }}" class="nav-item {{ request()->routeIs('item_categories*') ? 'active' : '' }}">
                <i class="fas fa-tags"></i> Item Categories
            </a>
            @endif
            @if(auth()->user()->hasAdminAccess() || auth()->user()->isCenterUser())
            <a href="{{ route('warehouses.index') }}" class="nav-item {{ request()->routeIs('warehouses*') ? 'active' : '' }}">
                <i class="fas fa-warehouse"></i> Warehouses
            </a>
            @endif
            @if(auth()->user()->isAdmin())
            <a href="{{ route('users.index') }}" class="nav-item {{ request()->routeIs('users*') ? 'active' : '' }}">
                <i class="fas fa-users-gear"></i> Users
            </a>
            @endif
        </nav>

        <div class="sidebar-footer">
            <div class="user-info">
                <div class="user-avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</div>
                <div class="user-details">
                    <strong title="{{ auth()->user()->name }}">{{ auth()->user()->name }}</strong>
                    <small>{{ auth()->user()->getRoleLabel() }}</small>
                </div>
                <form action="{{ route('logout') }}" method="POST" style="display:inline">
                    @csrf
                    <button type="submit" class="logout-btn" title="Logout"><i class="fas fa-right-from-bracket"></i></button>
                </form>
            </div>
        </div>
    </aside>

    <!-- Main wrapper -->
    <div class="main-wrapper">
        <!-- Topbar -->
        <header class="topbar">
            <button onclick="toggleSidebar()" style="background:none;border:none;cursor:pointer;font-size:20px;color:var(--text-muted);display:none" id="menu-toggle">
                <i class="fas fa-bars"></i>
            </button>
            <div class="topbar-title">@yield('page-title', 'Dashboard')</div>
            <div class="topbar-actions">
                @php
                    // Use eager-loaded warehouses from the user relation if already loaded,
                    // otherwise fall back to a single targeted query.
                    $topbarWarehouses = auth()->user()->relationLoaded('warehouses')
                        ? auth()->user()->warehouses->pluck('name')
                        : auth()->user()->warehouses()->pluck('name');
                    if ($topbarWarehouses->isEmpty() && auth()->user()->warehouse) {
                        $topbarWarehouses = collect([auth()->user()->warehouse->name]);
                    }
                @endphp
                @if($topbarWarehouses->isNotEmpty())
                <span style="font-size:13px;color:var(--text-muted)">
                    <i class="fas fa-building"></i>
                    {{ $topbarWarehouses->implode(', ') }}
                </span>
                @endif
                <!-- Notification bell -->
                <div style="position:relative">
                    <button class="notif-btn" onclick="toggleNotifications()" id="notif-btn">
                        <i class="fas fa-bell"></i>
                        <span class="notif-badge" id="notif-count" style="display:none">0</span>
                    </button>
                    <div class="notif-dropdown" id="notif-dropdown">
                        <div class="notif-header">
                            Notifications
                            <form action="{{ route('notifications.read_all') }}" method="POST" style="display:inline">
                                @csrf
                                <button type="submit" style="background:none;border:none;color:var(--primary);font-size:12px;cursor:pointer">Mark all read</button>
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
        function toggleSubmenu(el) {
            el.classList.toggle('open');
            const submenu = el.nextElementSibling;
            submenu.classList.toggle('open');
        }

        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('open');
            document.body.classList.toggle('sidebar-open', sidebar.classList.contains('open'));
        }

        function closeSidebar() {
            document.getElementById('sidebar').classList.remove('open');
            document.body.classList.remove('sidebar-open');
        }

        // Close sidebar when clicking the overlay (body::after pseudo-element)
        document.addEventListener('click', function(e) {
            const sidebar = document.getElementById('sidebar');
            const toggle  = document.getElementById('menu-toggle');
            if (
                sidebar &&
                sidebar.classList.contains('open') &&
                ! sidebar.contains(e.target) &&
                toggle && ! toggle.contains(e.target)
            ) {
                closeSidebar();
            }
        });

        function toggleNotifications() {
            document.getElementById('notif-dropdown').classList.toggle('open');
        }

        document.addEventListener('click', function(e) {
            const btn = document.getElementById('notif-btn');
            const dropdown = document.getElementById('notif-dropdown');
            if (btn && dropdown && !btn.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.classList.remove('open');
            }
        });

        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        function loadNotifications() {
            fetch('{{ route("notifications.unread") }}', {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => {
                if (r.status === 401) {
                    // Session expired — stop polling and reload to redirect to login
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
            .catch(() => {
                // Silently ignore network errors — will retry on next interval
            });
        }

        function handleNotifClick(el, id, link) {
            // Mark as read via AJAX, then navigate
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
                // Update badge count
                const badge = document.getElementById('notif-count');
                if (data.remaining_count > 0) {
                    badge.style.display = 'flex';
                    badge.textContent = data.remaining_count > 99 ? '99+' : data.remaining_count;
                } else {
                    badge.style.display = 'none';
                }
                // Remove the item from the dropdown
                el.remove();
            })
            .catch(() => {})
            .finally(() => {
                // Navigate to the link regardless of whether mark-read succeeded
                if (link) window.location = link;
            });
        }

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
        }

        // Don't poll on the notifications page itself (user is already viewing them)
        const isNotifPage = window.location.pathname === '{{ parse_url(route("notifications.index"), PHP_URL_PATH) }}';
        let notifInterval = null;

        // Delay the first notification fetch by 2 seconds so it does not
        // compete with the page's initial render and database queries.
        setTimeout(loadNotifications, 2000);

        if (!isNotifPage) {
            notifInterval = setInterval(loadNotifications, 30000);
        }
    </script>
    @stack('scripts')
</body>
</html>
