@extends('layouts.app')
@section('title', 'Notifications')
@section('page-title', 'Notifications')

@section('content')
<div class="page-header">
    <div>
        <h1>Notifications</h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / Notifications</div>
    </div>
    <form action="{{ route('notifications.read_all') }}" method="POST">
        @csrf
        <button type="submit" class="btn btn-secondary"><i class="fas fa-check-double"></i> Mark All Read</button>
    </form>
</div>

<div class="card">
    @forelse($notifications as $notif)
    <div style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;gap:16px;{{ !$notif->is_read ? 'background:#ebf4ff' : '' }}">
        <div style="width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;
            background:{{ $notif->type == 'success' ? '#f0fff4' : ($notif->type == 'warning' ? '#fffff0' : ($notif->type == 'danger' ? '#fff5f5' : '#ebf8ff')) }};
            color:{{ $notif->type == 'success' ? 'var(--success)' : ($notif->type == 'warning' ? 'var(--warning)' : ($notif->type == 'danger' ? 'var(--danger)' : 'var(--info)')) }}">
            <i class="fas {{ $notif->type == 'success' ? 'fa-check-circle' : ($notif->type == 'warning' ? 'fa-exclamation-triangle' : ($notif->type == 'danger' ? 'fa-times-circle' : 'fa-info-circle')) }}"></i>
        </div>
        <div style="flex:1">
            <div style="font-weight:600;font-size:14px">{{ $notif->title }}</div>
            <div style="font-size:13px;color:var(--text-muted);margin-top:2px">{{ $notif->message }}</div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:4px">{{ $notif->created_at->diffForHumans() }}</div>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            @if(!$notif->is_read)
            <form action="{{ route('notifications.read', $notif->id) }}" method="POST">
                @csrf
                <input type="hidden" name="_stay" value="1">
                <button type="submit" class="btn btn-sm btn-outline"><i class="fas fa-check"></i> Mark Read</button>
            </form>
            @else
            <span class="badge badge-secondary">Read</span>
            @endif
            @if($notif->link)
            <a href="{{ $notif->link }}" class="btn btn-sm btn-primary"><i class="fas fa-arrow-right"></i> View</a>
            @endif
        </div>
    </div>
    @empty
    <div style="padding:60px;text-align:center;color:var(--text-muted)">
        <i class="fas fa-bell-slash" style="font-size:40px;margin-bottom:12px;display:block"></i>
        No notifications yet.
    </div>
    @endforelse
    @if($notifications->hasPages())
    <div class="card-footer">{{ $notifications->links() }}</div>
    @endif
</div>
@endsection
