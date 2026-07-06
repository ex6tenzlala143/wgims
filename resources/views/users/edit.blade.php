@extends('layouts.app')
@section('title', 'Edit User')
@section('page-title', 'Edit User')

@section('content')
<div class="page-header">
    <div>
        <h1>Edit User: {{ $user->name }}</h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / <a href="{{ route('users.index') }}">Users</a> / Edit</div>
    </div>
</div>

<div class="card" style="max-width:700px">
    <div class="card-header"><h3><i class="fas fa-user-edit" style="color:var(--primary)"></i> Edit User</h3></div>
    <div class="card-body">
        <form action="{{ route('users.update', $user->id) }}" method="POST">
            @csrf @method('PUT')
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Username <span style="color:red">*</span></label>
                    <input type="text" name="username" id="username" class="form-control" value="{{ old('username', $user->username) }}" required>
                    <div id="username-check" style="font-size:12px;margin-top:4px"></div>
                </div>
                <div class="form-group">
                    <label class="form-label">Full Name <span style="color:red">*</span></label>
                    <input type="text" name="name" class="form-control" value="{{ old('name', $user->name) }}" required>
                </div>
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="{{ old('email', $user->email) }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Role <span style="color:red">*</span></label>
                    <select name="role" id="role" class="form-control" required onchange="toggleCenter()">
                        <option value="admin" {{ old('role', $user->role)=='admin'?'selected':'' }}>Administrator</option>
                        <option value="warehouse_manager" {{ old('role', $user->role)=='warehouse_manager'?'selected':'' }}>Warehouse Manager</option>
                        <option value="supply_custodian" {{ old('role', $user->role)=='supply_custodian'?'selected':'' }}>Supply Custodian</option>
                        <option value="center_head" {{ old('role', $user->role)=='center_head'?'selected':'' }}>Warehouse Head</option>
                        <option value="center_staff" {{ old('role', $user->role)=='center_staff'?'selected':'' }}>Warehouse Staff</option>
                    </select>
                </div>
            </div>
            <div class="form-group" id="warehouse-group">
                <label class="form-label">
                    Warehouse Assignment
                    <small style="color:var(--text-muted);font-weight:400"> — select one or more; the first selected becomes the primary</small>
                </label>
                @php
                    // IDs to pre-check: old() on re-submission, otherwise the user's current pivot assignments
                    // Cast all IDs to int so in_array strict comparison works correctly whether
                    // the values come from old() (strings) or the Eloquent collection (ints).
                    $assignedIds = array_map('intval',
                        old('warehouse_ids', $user->warehouses->pluck('id')->toArray())
                    );
                @endphp
                <div style="border:1px solid var(--border);border-radius:6px;padding:10px 14px;max-height:320px;overflow-y:auto;background:#fff">
                    @forelse($warehouses as $wh)
                    <label style="display:flex;align-items:center;gap:8px;padding:5px 0;cursor:pointer;font-size:14px">
                        <input type="checkbox"
                               name="warehouse_ids[]"
                               value="{{ $wh->id }}"
                               {{ in_array((int) $wh->id, $assignedIds, true) ? 'checked' : '' }}>
                        <span>
                            <strong>{{ $wh->name }}</strong>
                            @if($wh->code)
                            <span class="badge badge-secondary" style="font-size:10px;margin-left:4px">{{ $wh->code }}</span>
                            @endif
                            @if($wh->place)
                            <span style="color:var(--text-muted);font-size:12px"> — {{ $wh->place }}</span>
                            @endif
                        </span>
                    </label>
                    @empty
                    <p style="color:var(--text-muted);font-size:13px;margin:0">No active warehouses found.</p>
                    @endforelse
                </div>
                @error('warehouse_ids')
                <div style="color:var(--danger);font-size:12px;margin-top:4px">{{ $message }}</div>
                @enderror
            </div>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                    <input type="checkbox" name="is_active" value="1" {{ old('is_active', $user->is_active) ? 'checked' : '' }}>
                    <span class="form-label" style="margin:0">Active Account</span>
                </label>
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">New Password <small style="color:var(--text-muted)">(leave blank to keep)</small></label>
                    <input type="password" name="password" class="form-control" minlength="8">
                </div>
                <div class="form-group">
                    <label class="form-label">Confirm Password</label>
                    <input type="password" name="password_confirmation" class="form-control">
                </div>
            </div>
            <div style="display:flex;gap:12px">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update User</button>
                <a href="{{ route('users.index') }}" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
function toggleCenter() {
    const role = document.getElementById('role').value;
    document.getElementById('warehouse-group').style.display = (role === 'admin' || role === 'warehouse_manager') ? 'none' : '';
}
toggleCenter();

let usernameTimer;
document.getElementById('username').addEventListener('input', function() {
    clearTimeout(usernameTimer);
    const val = this.value.trim();
    const el = document.getElementById('username-check');
    if (!val) { el.textContent = ''; return; }
    usernameTimer = setTimeout(() => {
        fetch(`/api/check-username?username=${encodeURIComponent(val)}&user_id={{ $user->id }}`)
            .then(r => r.json())
            .then(d => {
                el.innerHTML = d.available
                    ? '<span style="color:var(--success)"><i class="fas fa-check-circle"></i> Available</span>'
                    : '<span style="color:var(--danger)"><i class="fas fa-times-circle"></i> Already taken</span>';
            }).catch(() => {});
    }, 500);
});
</script>
@endpush
@endsection
