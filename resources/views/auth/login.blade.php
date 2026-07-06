<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — WGIMSv2</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com">
    </script>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            background: white;
        }

        /* Floating label */
        .fl-group { position: relative; margin-bottom: 1.25rem; }
        .fl-input {
            display: block;
            width: 100%;
            padding: 1.125rem 1rem 0.5rem;
            border: 1.5px solid #e2e8f0;
            border-radius: 0.75rem;
            font-size: 0.9375rem;
            color: #1e293b;
            background: white;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            outline: none;
            line-height: 1.5;
        }
        .fl-input:focus {
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79,70,229,0.1);
        }
        .fl-input.is-invalid { border-color: #ef4444; }
        .fl-input.is-invalid:focus { box-shadow: 0 0 0 3px rgba(239,68,68,0.1); }

        .fl-label {
            position: absolute;
            left: 0.875rem;
            top: 0.8125rem;
            font-size: 0.9375rem;
            color: #94a3b8;
            transition: all 0.2s ease-out;
            pointer-events: none;
            background: white;
            padding: 0 0.25rem;
            transform-origin: left top;
        }
        .fl-input:focus ~ .fl-label,
        .fl-input:not(:placeholder-shown) ~ .fl-label {
            top: -0.5rem;
            left: 0.625rem;
            font-size: 0.75rem;
            font-weight: 600;
            color: #4f46e5;
        }
        .fl-input.is-invalid ~ .fl-label { color: #ef4444; }
        .fl-input.is-invalid:focus ~ .fl-label { color: #ef4444; }

        /* Password toggle */
        .password-toggle {
            position: absolute;
            right: 0.875rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 0.25rem;
            font-size: 1rem;
            transition: color 0.2s;
            line-height: 1;
        }
        .password-toggle:hover { color: #475569; }

        /* Primary button */
        .btn-primary {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            width: 100%;
            padding: 0.875rem 1.5rem;
            background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%);
            color: white;
            border: none;
            border-radius: 0.75rem;
            font-size: 0.9375rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.25s ease;
            box-shadow: 0 4px 14px rgba(79,70,229,0.3);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(79,70,229,0.4);
        }
        .btn-primary:active {
            transform: translateY(0);
            box-shadow: 0 4px 14px rgba(79,70,229,0.25);
        }

        /* Left panel decorative orb */
        .deco-orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.18;
        }

        /* Subtle animated gradient */
        @keyframes gradientShift {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        .animated-gradient {
            background-size: 200% 200%;
            animation: gradientShift 8s ease infinite;
        }

        /* Smooth scrollbar */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }

        /* Checkbox custom */
        .custom-checkbox {
            width: 18px;
            height: 18px;
            border: 1.5px solid #cbd5e1;
            border-radius: 4px;
            appearance: none;
            -webkit-appearance: none;
            cursor: pointer;
            transition: all 0.15s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            position: relative;
        }
        .custom-checkbox:checked {
            background: #4f46e5;
            border-color: #4f46e5;
        }
        .custom-checkbox:checked::after {
            content: '\f00c';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            color: white;
            font-size: 10px;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }
        .custom-checkbox:focus-visible {
            outline: 2px solid #4f46e5;
            outline-offset: 2px;
        }

        /* Remove number input spinners if any */
        input[type="number"]::-webkit-inner-spin-button,
        input[type="number"]::-webkit-outer-spin-button {
            -webkit-appearance: none;
        }
    </style>
</head>
<body>
    <div class="flex min-h-screen">

        {{-- Left Panel: Branding & Atmosphere --}}
        <div class="hidden lg:flex lg:w-1/2 relative items-center justify-center overflow-hidden animated-gradient select-none"
             style="background: linear-gradient(135deg, #0b0f1a 0%, #111629 25%, #1a1040 50%, #0f172a 75%, #0b0f1a 100%);">

            {{-- Decorative orbs --}}
            <div class="deco-orb w-96 h-96 -top-24 -left-24" style="background: #6366f1;"></div>
            <div class="deco-orb w-[32rem] h-[32rem] -bottom-40 -right-24" style="background: #4f46e5;"></div>
            <div class="deco-orb w-[28rem] h-[28rem] top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2" style="background: #8b5cf6;"></div>

            {{-- Subtle grid overlay --}}
            <div class="absolute inset-0 opacity-[0.03]"
                 style="background-image: url('data:image/svg+xml,%3Csvg width=\'60\' height=\'60\' viewBox=\'0 0 60 60\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cg fill=\'none\' fill-rule=\'evenodd\'%3E%3Cg fill=\'%23ffffff\' fill-opacity=\'1\'%3E%3Cpath d=\'M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z\'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E'); background-repeat: repeat;">
            </div>

            {{-- Branding content --}}
            <div class="relative z-10 text-center px-12 max-w-lg">
                <div class="inline-flex items-center justify-center w-20 h-20 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-2xl shadow-lg shadow-indigo-500/25 mb-8 ring-1 ring-white/10">
                    <span class="text-white text-2xl font-extrabold tracking-tight">WG</span>
                </div>
                <h1 class="text-4xl font-extrabold text-white tracking-tight mb-3">WGIMSv2</h1>
                <p class="text-lg text-indigo-200/70 font-light leading-relaxed mb-8">
                    Welfare Goods Inventory Management System
                </p>
                <div class="h-px bg-gradient-to-r from-transparent via-indigo-400/40 to-transparent mb-8"></div>
                <div class="inline-flex items-center gap-2.5 px-5 py-2.5 bg-white/5 backdrop-blur-sm rounded-full border border-white/[0.08]">
                    <i class="fas fa-shield-alt text-indigo-300 text-xs"></i>
                    <span class="text-sm text-indigo-200/60 font-medium">DSWD Region X — Northern Mindanao</span>
                </div>
            </div>
        </div>

        {{-- Right Panel: Login Form --}}
        <div class="w-full lg:w-1/2 flex items-center justify-center p-6 sm:p-8 lg:p-12 xl:p-16">
            <div class="w-full max-w-sm">

                {{-- Mobile branding (visible only on small screens) --}}
                <div class="lg:hidden text-center mb-10">
                    <div class="inline-flex items-center justify-center w-16 h-16 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-2xl shadow-lg shadow-indigo-500/25 mb-4">
                        <span class="text-white text-xl font-extrabold tracking-tight">WG</span>
                    </div>
                    <h1 class="text-2xl font-bold text-slate-900">WGIMSv2</h1>
                    <p class="text-sm text-slate-500 mt-1">Welfare Goods Inventory Management System</p>
                    <div class="mt-3 inline-flex items-center gap-2 px-3.5 py-1.5 bg-indigo-50 rounded-full">
                        <i class="fas fa-shield-alt text-indigo-400 text-[10px]"></i>
                        <span class="text-xs text-indigo-600 font-medium">DSWD Region X</span>
                    </div>
                </div>

                {{-- Form header --}}
                <div class="mb-8">
                    <h2 class="text-2xl font-bold text-slate-900 tracking-tight">Welcome back</h2>
                    <p class="text-sm text-slate-500 mt-1.5">Sign in to your account to continue</p>
                </div>

                {{-- Validation errors --}}
                @if($errors->any())
                <div class="flex items-start gap-3 p-4 mb-6 bg-red-50 border border-red-100 rounded-xl" role="alert">
                    <i class="fas fa-exclamation-circle text-red-400 mt-0.5 shrink-0"></i>
                    <div class="text-sm text-red-700">
                        <span class="font-semibold">Unable to sign in</span>
                        <ul class="mt-1.5 space-y-1">
                            @foreach($errors->all() as $error)
                            <li class="flex items-start gap-1.5">
                                <span class="text-red-300 mt-0.5">•</span>
                                <span>{{ $error }}</span>
                            </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
                @endif

                @if(session('status'))
                <div class="flex items-start gap-3 p-4 mb-6 bg-blue-50 border border-blue-100 rounded-xl" role="alert">
                    <i class="fas fa-info-circle text-blue-400 mt-0.5 shrink-0"></i>
                    <div class="text-sm text-blue-700">{{ session('status') }}</div>
                </div>
                @endif

                {{-- Login form --}}
                <form action="{{ route('login.post') }}" method="POST">
                    @csrf

                    {{-- Username field --}}
                    <div class="fl-group">
                        <input type="text" name="username" id="username"
                               class="fl-input @error('username') is-invalid @enderror"
                               placeholder=" " value="{{ old('username') }}" required autofocus autocomplete="username">
                        <label for="username" class="fl-label">Username</label>
                        @error('username')
                        <p class="mt-1.5 text-xs text-red-500 flex items-center gap-1.5">
                            <i class="fas fa-circle text-[5px] text-red-400"></i>
                            {{ $message }}
                        </p>
                        @enderror
                    </div>

                    {{-- Password field --}}
                    <div class="fl-group">
                        <input type="password" name="password" id="password"
                               class="fl-input @error('password') is-invalid @enderror"
                               placeholder=" " required autocomplete="current-password">
                        <label for="password" class="fl-label">Password</label>
                        <button type="button" class="password-toggle" onclick="togglePassword()" tabindex="-1" aria-label="Toggle password visibility">
                            <i class="far fa-eye" id="pwdIcon"></i>
                        </button>
                        @error('password')
                        <p class="mt-1.5 text-xs text-red-500 flex items-center gap-1.5">
                            <i class="fas fa-circle text-[5px] text-red-400"></i>
                            {{ $message }}
                        </p>
                        @enderror
                    </div>

                    {{-- Remember me + Forgot password --}}
                    <div class="flex items-center justify-between mb-6">
                        <label for="remember" class="flex items-center gap-2.5 cursor-pointer select-none">
                            <input type="checkbox" name="remember" id="remember" class="custom-checkbox">
                            <span class="text-sm text-slate-600 font-medium">Remember me</span>
                        </label>
                        <a href="#" class="text-sm font-medium text-indigo-600 hover:text-indigo-700 transition-colors duration-200">
                            Forgot password?
                        </a>
                    </div>

                    {{-- Submit button --}}
                    <button type="submit" class="btn-primary">
                        <span>Sign in</span>
                        <i class="fas fa-arrow-right text-sm opacity-70"></i>
                    </button>
                </form>

                {{-- Footer --}}
                <p class="mt-8 text-center text-xs text-slate-400">
                    &copy; {{ date('Y') }} WGIMSv2 — Welfare Goods Inventory Management System
                </p>
            </div>
        </div>
    </div>

    <script>
        function togglePassword() {
            const pwd = document.getElementById('password');
            const icon = document.getElementById('pwdIcon');
            if (pwd.type === 'password') {
                pwd.type = 'text';
                icon.className = 'far fa-eye-slash';
            } else {
                pwd.type = 'password';
                icon.className = 'far fa-eye';
            }
        }
    </script>
</body>
</html>
