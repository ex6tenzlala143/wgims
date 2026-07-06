@extends('layouts.app')
@section('title', 'Add User')
@section('page-title', 'Add User')

@section('content')
<div class="page-header">
    <div>
        <h1>Add New User</h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / <a href="{{ route('users.index') }}">Users</a> / Create</div>
    </div>
</div>

<div class="card" style="max-width:700px">
    <div class="card-header"><h3><i class="fas fa-user-plus" style="color:var(--primary)"></i> User Details</h3></div>
    <div class="card-body">
        <form action="{{ route('users.store') }}" method="POST" id="user-form">
            @csrf
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Username <span style="color:red">*</span></label>
                    <input type="text" name="username" id="username" class="form-control {{ $errors->has('username') ? 'is-invalid' : '' }}"
                        value="{{ old('username') }}" required autocomplete="off">
                    <div id="username-check" style="font-size:12px;margin-top:4px"></div>
                    @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-group">
                    <label class="form-label">Full Name <span style="color:red">*</span></label>
                    <input type="text" name="name" class="form-control {{ $errors->has('name') ? 'is-invalid' : '' }}" value="{{ old('name') }}" required>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="{{ old('email') }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Role <span style="color:red">*</span></label>
                    <select name="role" id="role" class="form-control" required onchange="toggleCenter()">
                        <option value="">— Select Role —</option>
                        <option value="admin" {{ old('role')=='admin'?'selected':'' }}>Administrator</option>
                        <option value="warehouse_manager" {{ old('role')=='warehouse_manager'?'selected':'' }}>Warehouse Manager</option>
                        <option value="supply_custodian" {{ old('role')=='supply_custodian'?'selected':'' }}>Supply Custodian</option>
                        <option value="center_head" {{ old('role')=='center_head'?'selected':'' }}>Warehouse Head</option>
                        <option value="center_staff" {{ old('role')=='center_staff'?'selected':'' }}>Warehouse Staff</option>
                    </select>
                </div>
            </div>

            <!-- Role permissions info -->
            <div id="role-info" style="display:none;margin-bottom:16px;padding:12px;background:#ebf4ff;border-radius:8px;font-size:13px">
                <strong id="role-info-title"></strong>
                <ul id="role-info-list" style="margin-top:6px;padding-left:20px"></ul>
            </div>

            <div class="form-group" id="warehouse-group" style="{{ in_array(old('role'), ['admin', 'warehouse_manager']) ? 'display:none' : '' }}">
                <label class="form-label">
                    Warehouse Assignment <span style="color:red">*</span>
                    <small style="color:var(--text-muted);font-weight:400"> — select one or more; the first selected becomes the primary</small>
                </label>
                @php
                    // Cast to int so strict comparison works whether values come from
                    // old() (strings after a failed POST) or a fresh page load (empty).
                    $oldWarehouseIds = array_map('intval', old('warehouse_ids', []));
                @endphp
                <div style="border:1px solid var(--border);border-radius:6px;padding:10px 14px;max-height:320px;overflow-y:auto;background:#fff">
                    @forelse($warehouses as $wh)
                    <label style="display:flex;align-items:center;gap:8px;padding:5px 0;cursor:pointer;font-size:14px">
                        <input type="checkbox"
                               name="warehouse_ids[]"
                               value="{{ $wh->id }}"
                               {{ in_array((int) $wh->id, $oldWarehouseIds, true) ? 'checked' : '' }}>
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

            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Password <span style="color:red">*</span></label>
                    <div style="position:relative">
                        <input type="password" name="password" id="password" class="form-control" required minlength="8" oninput="checkStrength()">
                        <button type="button" onclick="togglePwd('password','pwd-eye')" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#a0aec0">
                            <i class="fas fa-eye" id="pwd-eye"></i>
                        </button>
                    </div>
                    <div id="pwd-strength" style="margin-top:4px;font-size:12px"></div>
                </div>
                <div class="form-group">
                    <label class="form-label">Confirm Password <span style="color:red">*</span></label>
                    <input type="password" name="password_confirmation" class="form-control" required>
                </div>
            </div>

            <div style="display:flex;gap:12px">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Create User</button>
                <a href="{{ route('users.index') }}" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
const roleInfo = {
    admin: { title: 'Administrator', perms: ['Full access to all modules', 'Manage users and warehouses', 'View all warehouses data', 'Approve/delete any record'] },
    warehouse_manager: { title: 'Warehouse Manager', perms: ['Full view/read access across all modules', 'View all warehouses and reports', 'Cannot create, edit, or delete any records'] },
    supply_custodian: { title: 'Supply Custodian', perms: ['Manage warehouse inventory', 'Create and view Delivery/Subsidies', 'Create and approve RIS', 'View own warehouse data only'] },
    center_head: { title: 'Warehouse Head', perms: ['View warehouse inventory', 'Create RIS', 'Approve RIS', 'View own warehouse data only'] },
    center_staff: { title: 'Warehouse Staff', perms: ['View warehouse inventory', 'Create RIS', 'View own warehouse data only'] },
};

function toggleCenter() {
    const role = document.getElementById('role').value;
    const cg = document.getElementById('warehouse-group');
    const ri = document.getElementById('role-info');
    cg.style.display = (role === 'admin' || role === 'warehouse_manager') ? 'none' : '';
    if (role && roleInfo[role]) {
        ri.style.display = 'block';
        document.getElementById('role-info-title').textContent = roleInfo[role].title + ' Permissions:';
        document.getElementById('role-info-list').innerHTML = roleInfo[role].perms.map(p => `<li>${p}</li>`).join('');
    } else {
        ri.style.display = 'none';
    }
}

let usernameTimer;
document.getElementById('username').addEventListener('input', function() {
    clearTimeout(usernameTimer);
    const val = this.value.trim();
    const el = document.getElementById('username-check');
    if (!val) { el.textContent = ''; return; }
    usernameTimer = setTimeout(() => {
        fetch(`/api/check-username?username=${encodeURIComponent(val)}`)
            .then(r => r.json())
            .then(d => {
                el.innerHTML = d.available
                    ? '<span style="color:var(--success)"><i class="fas fa-check-circle"></i> Username available</span>'
                    : '<span style="color:var(--danger)"><i class="fas fa-times-circle"></i> Username already taken</span>';
            }).catch(() => {});
    }, 500);
});

function checkStrength() {
    const pwd = document.getElementById('password').value;
    const el = document.getElementById('pwd-strength');
    let score = 0;
    if (pwd.length >= 8) score++;
    if (/[A-Z]/.test(pwd)) score++;
    if (/[0-9]/.test(pwd)) score++;
    if (/[^A-Za-z0-9]/.test(pwd)) score++;
    const labels = ['', 'Weak', 'Fair', 'Good', 'Strong'];
    const colors = ['', 'var(--danger)', 'var(--warning)', 'var(--info)', 'var(--success)'];
    el.innerHTML = score > 0 ? `<span style="color:${colors[score]}">Password strength: ${labels[score]}</span>` : '';
}

function togglePwd(id, iconId) {
    const el = document.getElementById(id);
    const icon = document.getElementById(iconId);
    el.type = el.type === 'password' ? 'text' : 'password';
    icon.className = el.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
}

toggleCenter();
</script>
@endpush
@endsection
