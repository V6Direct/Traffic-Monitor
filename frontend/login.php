<?php
/**
 * frontend/login.php
 *
 * Login page. Redirects to dashboard if already authenticated.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once LIB_PATH . '/Database.php';
require_once LIB_PATH . '/Auth.php';

Auth::startSession();

if (Auth::check()) {
    header('Location: /frontend/dashboard.php');
    exit;
}

$csrf = Auth::csrfToken();
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BW Monitor – Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/frontend/css/tailwind.css">
    <style>
        /* Subtle animated gradient background */
        body { background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #0f172a 100%); }
    </style>
</head>
<body class="h-full flex items-center justify-center p-4">

<div class="w-full max-w-md">
    <!-- Card -->
    <div class="bg-gray-900 border border-gray-700 rounded-2xl shadow-2xl p-8">

        <!-- Logo / Heading -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-indigo-600 rounded-full mb-4">
                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0
                             002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2
                             0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2
                             0 01-2-2z"/>
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-white">Bandwidth Monitor</h1>
            <p class="text-gray-400 text-sm mt-1">PoP Infrastructure Dashboard</p>
        </div>

        <!-- Alert banner (hidden by default) -->
        <div id="alert"
             class="hidden mb-4 p-3 rounded-lg bg-red-900/50 border border-red-700 text-red-300 text-sm">
        </div>

        <!-- Login Form -->
        <form id="loginForm" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

            <div class="mb-5">
                <label for="username" class="block text-sm font-medium text-gray-300 mb-1.5">
                    Username
                </label>
                <input id="username" name="username" type="text"
                       autocomplete="username" required
                       class="w-full px-4 py-2.5 bg-gray-800 border border-gray-600 rounded-lg
                              text-white placeholder-gray-500 focus:outline-none focus:ring-2
                              focus:ring-indigo-500 focus:border-transparent transition">
            </div>

            <div class="mb-6">
                <label for="password" class="block text-sm font-medium text-gray-300 mb-1.5">
                    Password
                </label>
                <div class="relative">
                    <input id="password" name="password" type="password"
                           autocomplete="current-password" required
                           class="w-full px-4 py-2.5 bg-gray-800 border border-gray-600 rounded-lg
                                  text-white placeholder-gray-500 focus:outline-none focus:ring-2
                                  focus:ring-indigo-500 focus:border-transparent transition pr-12">
                    <button type="button" id="togglePwd"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400
                                   hover:text-white transition">
                        <svg id="eyeIcon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943
                                     9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                        </svg>
                    </button>
                </div>
            </div>

            <button type="submit" id="submitBtn"
                    class="w-full py-3 px-4 bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800
                           text-white font-semibold rounded-lg transition focus:outline-none
                           focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2
                           focus:ring-offset-gray-900 disabled:opacity-50 disabled:cursor-not-allowed">
                <span id="btnText">Sign in</span>
                <svg id="spinner" class="hidden animate-spin ml-2 h-5 w-5 text-white inline"
                     xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor"
                          d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
            </button>
        </form>

    </div>

    <p class="text-center text-gray-600 text-xs mt-4">
        Bandwidth Monitor &copy; <?= date('Y') ?>
    </p>
</div>

<script>
(function () {
    'use strict';

    const form      = document.getElementById('loginForm');
    const alertBox  = document.getElementById('alert');
    const submitBtn = document.getElementById('submitBtn');
    const btnText   = document.getElementById('btnText');
    const spinner   = document.getElementById('spinner');
    const pwdInput  = document.getElementById('password');
    const togglePwd = document.getElementById('togglePwd');

    // Toggle password visibility.
    togglePwd.addEventListener('click', () => {
        const isHidden = pwdInput.type === 'password';
        pwdInput.type = isHidden ? 'text' : 'password';
    });

    function showAlert(msg) {
        alertBox.textContent = msg;
        alertBox.classList.remove('hidden');
    }

    function setLoading(loading) {
        submitBtn.disabled = loading;
        btnText.textContent = loading ? 'Signing in…' : 'Sign in';
        spinner.classList.toggle('hidden', !loading);
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        alertBox.classList.add('hidden');

        const username = document.getElementById('username').value.trim();
        const password = pwdInput.value;

        if (!username || !password) {
            showAlert('Please enter your username and password.');
            return;
        }

        setLoading(true);

        try {
            const res = await fetch('/backend/api/login.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username, password }),
                credentials: 'same-origin',
            });

            const data = await res.json();

            if (res.ok) {
                // Redirect to dashboard.
                window.location.href = '/frontend/dashboard.php';
            } else {
                showAlert(data.error || 'Login failed. Please try again.');
                setLoading(false);
            }
        } catch (err) {
            showAlert('Network error. Please check your connection.');
            setLoading(false);
        }
    });
})();
</script>
</body>
</html>
