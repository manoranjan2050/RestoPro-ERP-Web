<div x-data="{ items: [], push(msg) { const id = Date.now() + Math.random(); this.items.push({ id, msg }); setTimeout(() => this.items = this.items.filter(i => i.id !== id), 3200); } }"
     @toast.window="push($event.detail.message)"
     class="pointer-events-none fixed inset-x-0 bottom-4 z-50 flex flex-col items-center gap-2 px-4 sm:bottom-6 sm:items-end sm:px-6"
     aria-live="polite">
    <template x-for="t in items" :key="t.id">
        <div x-transition class="pointer-events-auto flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-3 text-sm font-medium text-white shadow-xl dark:bg-white dark:text-slate-900">
            <span class="grid size-5 place-items-center rounded-full bg-emerald-500 text-white"><x-icon name="check" class="size-3.5" /></span>
            <span x-text="t.msg"></span>
        </div>
    </template>
</div>
