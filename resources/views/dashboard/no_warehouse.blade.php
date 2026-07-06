@extends('layouts.app')
@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('content')
<div style="display:flex;align-items:center;justify-content:center;min-height:60vh">
    <div style="text-align:center;max-width:480px;padding:40px">
        <div style="font-size:64px;margin-bottom:16px">🏭</div>
        <h2 style="font-size:22px;font-weight:700;color:var(--text-primary);margin-bottom:8px">
            No Warehouse Assigned
        </h2>
        <p style="color:var(--text-muted);font-size:15px;line-height:1.6;margin-bottom:24px">
            Your account is not yet assigned to any warehouse.
            Please contact an administrator to assign you to a warehouse before you can access inventory data.
        </p>
        <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:14px 18px;text-align:left;font-size:13px;color:#856404">
            <strong><i class="fas fa-info-circle"></i> What to do:</strong>
            <ul style="margin:6px 0 0 16px;padding:0">
                <li>Ask your system administrator to open <strong>Users → Edit</strong> for your account</li>
                <li>They should check the warehouses you are responsible for</li>
                <li>Once saved, refresh this page</li>
            </ul>
        </div>
    </div>
</div>
@endsection
