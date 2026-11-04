<button type="button"
        x-data="{
            dark: document.documentElement.classList.contains('dark'),
            toggle() {
                this.dark = !this.dark;
                document.documentElement.classList.toggle('dark', this.dark);
                try { localStorage.setItem('theme', this.dark ? 'dark' : 'light'); } catch (e) {}
                window.dispatchEvent(new CustomEvent('theme-changed'));
            }
        }"
        @click="toggle()"
        class="rounded-full p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"
        :aria-label="dark ? 'Switch to light mode' : 'Switch to dark mode'">
    <span x-show="!dark"><x-icon name="moon" /></span>
    <span x-cloak x-show="dark"><x-icon name="sun" /></span>
</button>
