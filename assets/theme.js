/**
 * FileDump — Dark/Light Theme Toggle
 *
 * Adds a floating theme toggle button to every page.
 * Persists the preference in localStorage.
 * Respects the system preference (prefers-color-scheme) as default.
 */
(function () {
    'use strict';

    // Determine initial theme: localStorage > system preference > light
    function getPreferredTheme() {
        const stored = localStorage.getItem('filedump-theme');
        if (stored) return stored;
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            return 'dark';
        }
        return 'light';
    }

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('filedump-theme', theme);
        updateButtonIcon(theme);
    }

    function updateButtonIcon(theme) {
        const btn = document.getElementById('theme-toggle');
        if (!btn) return;
        // Sun icon for dark mode (click to go light), moon for light mode (click to go dark)
        btn.textContent = theme === 'dark' ? '\u2600' : '\u263E';
        btn.title = theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode';
    }

    // Apply theme immediately (before DOM renders) to prevent flash
    const currentTheme = getPreferredTheme();
    document.documentElement.setAttribute('data-theme', currentTheme);

    // Create the toggle button once DOM is ready
    document.addEventListener('DOMContentLoaded', () => {
        const btn = document.createElement('button');
        btn.id = 'theme-toggle';
        btn.className = 'theme-toggle';
        btn.addEventListener('click', () => {
            const current = document.documentElement.getAttribute('data-theme');
            applyTheme(current === 'dark' ? 'light' : 'dark');
        });
        document.body.appendChild(btn);
        updateButtonIcon(currentTheme);
    });

    // Listen for system preference changes
    if (window.matchMedia) {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
            // Only auto-switch if user hasn't manually set a preference
            if (!localStorage.getItem('filedump-theme')) {
                applyTheme(e.matches ? 'dark' : 'light');
            }
        });
    }
})();
