@php
    $triggerBadge = [
        'manual' => 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-400',
        'scheduled' => 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-400',
        'pre-restore' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
    ];
    $destIcon = ['dropbox' => 'cloud', 'gdrive' => 'cloud', 'github' => 'book'];
    $connectedCount = count(array_filter($connected));
    $latest = $backups->firstWhere('trigger', '!=', 'pre-restore');
@endphp
<div class="space-y-6">
    {{-- Status hero --}}
    <div class="relative overflow-clip rounded-3xl bg-gradient-to-br from-sky-500 via-indigo-500 to-violet-600 p-6 text-white shadow-xl shadow-indigo-500/20 sm:p-8">
        <div class="pointer-events-none absolute -right-10 -top-10 size-48 rounded-full bg-white/10"></div>
        <div class="relative flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-medium text-white/80">Last backup</p>
                <h2 class="mt-1 text-2xl font-extrabold">{{ $latest ? $latest->created_at->diffForHumans() : 'Never' }}</h2>
                <p class="mt-1 text-sm text-white/85">
                    {{ $latest ? $latest->created_at->format('d M Y, h:i A').' · '.$latest->sizeLabel().' · '.number_format($latest->totalRows()).' records' : 'Make your first backup now.' }}
                </p>
                <p class="mt-1 text-sm text-white/85">
                    {{ $auto ? 'Automatic daily backup at '.\Illuminate\Support\Carbon::parse($time)->format('h:i A') : 'Automatic backup is off' }}
                    · {{ $connectedCount ? $connectedCount.' cloud destination(s) connected' : 'no cloud connected yet' }}
                </p>
            </div>
            <button wire:click="backupNow" wire:loading.attr="disabled" wire:target="backupNow" class="btn shrink-0 bg-white text-indigo-600 shadow-lg hover:bg-indigo-50">
                <span wire:loading.remove wire:target="backupNow" class="flex items-center gap-2"><x-icon name="cloud" class="size-4" /> Back up now</span>
                <span wire:loading wire:target="backupNow">Backing up…</span>
            </button>
        </div>
    </div>

    {{-- Tabs --}}
    <div class="inline-flex rounded-xl border border-slate-200 bg-white p-1 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        @foreach (['backups' => 'Backups & restore', 'cloud' => 'Cloud', 'schedule' => 'Schedule'] as $k => $l)
            <button wire:click="$set('tab', '{{ $k }}')" @class([
                'rounded-lg px-3 py-1.5 text-sm font-semibold',
                'bg-gradient-to-r from-brand-500 to-rose-500 text-white shadow' => $tab === $k,
                'text-slate-500 hover:text-slate-800 dark:hover:text-slate-200' => $tab !== $k,
            ])>{{ $l }}</button>
        @endforeach
    </div>

    @if ($tab === 'backups')
        <div class="card overflow-hidden">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 dark:border-slate-800">
                <h3 class="font-bold">Backups on this server <span class="text-sm font-medium text-slate-500">({{ $backups->count() }})</span></h3>
                <span class="text-xs text-slate-500">Newest {{ $keep }} kept · safety copies kept 30 days</span>
            </div>
            @forelse ($backups as $b)
                <div wire:key="b-{{ $b->id }}" class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-3 last:border-0 dark:border-slate-800">
                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-indigo-50 text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-400"><x-icon name="cloud" class="size-5" /></span>
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-2 text-sm font-semibold">
                            {{ $b->created_at->format('d M Y, h:i A') }}
                            <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase {{ $triggerBadge[$b->trigger] ?? 'bg-slate-100 text-slate-600' }}">{{ $b->trigger }}</span>
                        </p>
                        <p class="text-xs text-slate-500">{{ $b->sizeLabel() }} · {{ number_format($b->totalRows()) }} records{{ $b->creator ? ' · by '.$b->creator->name : '' }}</p>
                        @if ($b->destinations)
                            <div class="mt-1 flex flex-wrap gap-1.5">
                                @foreach ($b->destinations as $k => $d)
                                    <span title="{{ $d['message'] }}" @class(['rounded-md px-1.5 py-0.5 text-[10px] font-semibold',
                                        'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' => $d['ok'],
                                        'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400' => ! $d['ok']])>
                                        {{ $d['ok'] ? '✓' : '✗' }} {{ ($dests[$k] ?? null)?->label() ?? $k }}
                                    </span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-1">
                        <a href="{{ route('backup.download', $b) }}" class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">Download</a>
                        @if ($connectedCount && collect($b->destinations)->contains(fn ($d) => ! $d['ok']))
                            <button wire:click="reupload({{ $b->id }})" class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-sky-600 hover:bg-sky-50 dark:text-sky-400 dark:hover:bg-sky-500/10">Retry upload</button>
                        @endif
                        <button wire:click="chooseRestore({{ $b->id }})" class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-amber-600 hover:bg-amber-50 dark:text-amber-400 dark:hover:bg-amber-500/10">Restore</button>
                        <button wire:click="deleteBackup({{ $b->id }})" wire:confirm="Delete this backup file from the server? Copies already in the cloud are not affected." class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-500/10">Delete</button>
                    </div>
                </div>
            @empty
                <p class="px-5 py-12 text-center text-sm text-slate-500">No backups yet — tap “Back up now”.</p>
            @endforelse
        </div>

        {{-- Restore from file --}}
        <div class="card p-5">
            <h3 class="font-bold">Restore from a backup file</h3>
            <p class="mt-1 text-sm text-slate-500">Upload a <code>.zip</code> downloaded earlier, from Dropbox, Google Drive or GitHub. You'll see what's inside before anything changes.</p>
            <label class="btn-ghost mt-4 cursor-pointer">
                <x-icon name="cloud" class="size-4" /> Choose backup file
                <input type="file" wire:model="restoreFile" accept=".zip,application/zip" class="sr-only">
            </label>
            <span wire:loading wire:target="restoreFile" class="ml-2 text-sm text-slate-500">Checking…</span>
            @error('restoreFile') <p class="error">{{ $message }}</p> @enderror
            @error('restore') <p class="error">{{ $message }}</p> @enderror
        </div>
    @endif

    @if ($tab === 'cloud')
        <div class="grid gap-6 xl:grid-cols-3">
            {{-- Dropbox --}}
            @php $k = 'dropbox'; @endphp
            <div class="card flex flex-col p-5">
                <x-backup-dest-head :label="'Dropbox'" :connected="$connected[$k]" :enabled="$enabled[$k]" :k="$k" />
                <form wire:submit="saveCloud('dropbox')" class="mt-4 space-y-3">
                    <div>
                        <label class="label" for="db-key">App key</label>
                        <input id="db-key" wire:model="cfg.dropbox.app_key" class="input font-mono text-xs" placeholder="e.g. a1b2c3d4e5f6g7h" autocomplete="off">
                        @error('cfg.dropbox.app_key') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <button class="btn-ghost w-full py-2">Save</button>
                </form>
                <div class="mt-3 flex flex-wrap gap-2">
                    @if ($cfg['dropbox']['app_key'])
                        <a href="{{ route('backup.connect', 'dropbox') }}" class="btn-primary flex-1 py-2">{{ $connected[$k] ? 'Reconnect' : 'Connect Dropbox' }}</a>
                    @endif
                </div>
                <x-backup-dest-foot :k="$k" :connected="$connected[$k]" :test-result="$testResult[$k] ?? null" />
                <details class="mt-4 text-xs text-slate-500">
                    <summary class="cursor-pointer font-semibold text-slate-600 dark:text-slate-300">One-time setup</summary>
                    <ol class="mt-2 list-decimal space-y-1 pl-4">
                        <li>Open <b>dropbox.com/developers/apps</b> → Create app → <b>Scoped access</b> → <b>App folder</b>.</li>
                        <li>Permissions tab: tick <b>files.content.write</b>, <b>files.content.read</b>, <b>files.metadata.read</b> → Submit.</li>
                        <li>Settings tab → OAuth2 Redirect URIs → add:<br><code class="break-all">{{ $callback('dropbox') }}</code></li>
                        <li>Copy the <b>App key</b> here, Save, then Connect Dropbox.</li>
                    </ol>
                </details>
            </div>

            {{-- Google Drive --}}
            @php $k = 'gdrive'; @endphp
            <div class="card flex flex-col p-5">
                <x-backup-dest-head :label="'Google Drive'" :connected="$connected[$k]" :enabled="$enabled[$k]" :k="$k" />
                <form wire:submit="saveCloud('gdrive')" class="mt-4 space-y-3">
                    <div>
                        <label class="label" for="gd-id">Client ID</label>
                        <input id="gd-id" wire:model="cfg.gdrive.client_id" class="input font-mono text-xs" placeholder="…apps.googleusercontent.com" autocomplete="off">
                        @error('cfg.gdrive.client_id') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="gd-sec">Client secret</label>
                        <input id="gd-sec" type="password" wire:model="cfg.gdrive.client_secret" class="input font-mono text-xs" placeholder="{{ \App\Models\Setting::secret('backup_gdrive_client_secret') ? '•••••• saved (leave blank to keep)' : 'GOCSPX-…' }}" autocomplete="new-password">
                        @error('cfg.gdrive.client_secret') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <button class="btn-ghost w-full py-2">Save</button>
                </form>
                <div class="mt-3 flex flex-wrap gap-2">
                    @if ($cfg['gdrive']['client_id'] && \App\Models\Setting::secret('backup_gdrive_client_secret'))
                        <a href="{{ route('backup.connect', 'gdrive') }}" class="btn-primary flex-1 py-2">{{ $connected[$k] ? 'Reconnect' : 'Connect Google Drive' }}</a>
                    @endif
                </div>
                <x-backup-dest-foot :k="$k" :connected="$connected[$k]" :test-result="$testResult[$k] ?? null" />
                <details class="mt-4 text-xs text-slate-500">
                    <summary class="cursor-pointer font-semibold text-slate-600 dark:text-slate-300">One-time setup</summary>
                    <ol class="mt-2 list-decimal space-y-1 pl-4">
                        <li><b>console.cloud.google.com</b> → new project → APIs & Services → enable <b>Google Drive API</b>.</li>
                        <li>OAuth consent screen → External → add your Gmail as a test user (or publish the app).</li>
                        <li>Credentials → Create OAuth client ID → <b>Web application</b> → Authorized redirect URI:<br><code class="break-all">{{ $callback('gdrive') }}</code></li>
                        <li>Copy Client ID + secret here, Save, then Connect Google Drive. Backups go to a “RestoPro Backups” folder.</li>
                    </ol>
                </details>
            </div>

            {{-- GitHub --}}
            @php $k = 'github'; @endphp
            <div class="card flex flex-col p-5">
                <x-backup-dest-head :label="'GitHub (private repo)'" :connected="$connected[$k]" :enabled="$enabled[$k]" :k="$k" />
                <form wire:submit="saveCloud('github')" class="mt-4 space-y-3">
                    <div>
                        <label class="label" for="gh-repo">Repository</label>
                        <input id="gh-repo" wire:model="cfg.github.repo" class="input font-mono text-xs" placeholder="owner/restopro-backups" autocomplete="off">
                        @error('cfg.github.repo') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="label" for="gh-br">Branch</label>
                            <input id="gh-br" wire:model="cfg.github.branch" class="input font-mono text-xs">
                            @error('cfg.github.branch') <p class="error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label" for="gh-path">Folder</label>
                            <input id="gh-path" wire:model="cfg.github.path" class="input font-mono text-xs">
                            @error('cfg.github.path') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div>
                        <label class="label" for="gh-tok">Access token</label>
                        <input id="gh-tok" type="password" wire:model="cfg.github.token" class="input font-mono text-xs" placeholder="{{ \App\Models\Setting::secret('backup_github_token') ? '•••••• saved (leave blank to keep)' : 'github_pat_…' }}" autocomplete="new-password">
                        @error('cfg.github.token') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <button class="btn-primary w-full py-2">Save</button>
                </form>
                <x-backup-dest-foot :k="$k" :connected="$connected[$k]" :test-result="$testResult[$k] ?? null" />
                <details class="mt-4 text-xs text-slate-500">
                    <summary class="cursor-pointer font-semibold text-slate-600 dark:text-slate-300">One-time setup</summary>
                    <ol class="mt-2 list-decimal space-y-1 pl-4">
                        <li>Create a <b>private</b> repository (e.g. restopro-backups) with a README so the branch exists.</li>
                        <li>GitHub → Settings → Developer settings → <b>Fine-grained token</b> → only that repo → Repository permissions: <b>Contents: Read and write</b>.</li>
                        <li>Paste repo + token here and Save. Public repos are refused — backups contain private data.</li>
                    </ol>
                </details>
            </div>
        </div>
        <p class="text-xs text-slate-500">Secrets are stored encrypted with this server's key. Each cloud keeps the newest {{ $keepCloud }} backups; older ones are removed automatically.</p>
    @endif

    @if ($tab === 'schedule')
        <form wire:submit="saveSchedule" class="card max-w-xl space-y-5 p-5">
            <label class="flex items-center gap-3">
                <input type="checkbox" wire:model="auto" class="size-5 rounded border-slate-300 text-brand-500 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
                <span><span class="font-semibold">Automatic daily backup</span><br><span class="text-sm text-slate-500">Backs up and uploads to every connected cloud.</span></span>
            </label>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label class="label" for="s-time">Time</label>
                    <input id="s-time" type="time" wire:model="time" class="input dark:[color-scheme:dark]">
                    @error('time') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="s-keep">Keep on server</label>
                    <input id="s-keep" type="number" min="1" max="365" wire:model="keep" class="input">
                    @error('keep') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="s-keepc">Keep in cloud</label>
                    <input id="s-keepc" type="number" min="1" max="365" wire:model="keepCloud" class="input">
                    @error('keepCloud') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="rounded-2xl bg-slate-50 p-4 text-xs text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">
                <p class="font-semibold">Needs one cron job on the server (Hostinger hPanel → Advanced → Cron Jobs, every minute):</p>
                <code class="mt-2 block break-all font-mono">{{ $cronLine }}</code>
            </div>
            <div class="flex justify-end"><button class="btn-primary">Save schedule</button></div>
        </form>
    @endif

    {{-- Restore confirm --}}
    @if ($preview)
        <x-modal title="Restore this backup?" close="resetRestore">
            <div class="rounded-2xl border border-slate-200 p-4 text-sm dark:border-slate-800">
                <p class="font-semibold">{{ $preview['business'] ?? 'Backup' }} · {{ \Illuminate\Support\Carbon::parse($preview['created_at'])->format('d M Y, h:i A') }}</p>
                <p class="mt-0.5 break-all text-xs text-slate-500">{{ $preview['source'] }}</p>
                <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                    @foreach ($preview['counts'] as $t => $n)
                        @if ($n) <div class="flex justify-between"><dt class="text-slate-500">{{ str_replace('_', ' ', $t) }}</dt><dd class="font-semibold tabular-nums">{{ number_format($n) }}</dd></div> @endif
                    @endforeach
                    <div class="flex justify-between"><dt class="text-slate-500">photos & bills</dt><dd class="font-semibold tabular-nums">{{ $preview['files'] }}</dd></div>
                </dl>
            </div>
            <div class="mt-4 rounded-2xl bg-rose-50 p-4 text-sm text-rose-800 dark:bg-rose-500/10 dark:text-rose-300">
                <p class="font-bold">This replaces ALL current data and photos with the backup's.</p>
                <p class="mt-1 text-xs">A safety copy of today's data is saved first, so you can undo by restoring that copy. Everyone will need to sign in again.</p>
            </div>
            <div class="mt-4">
                <label class="label" for="r-confirm">Type <b>RESTORE</b> to confirm</label>
                <input id="r-confirm" wire:model="confirmText" class="input font-mono" autocomplete="off" autofocus>
                @error('confirmText') <p class="error">{{ $message }}</p> @enderror
                @error('restore') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div class="mt-5 flex justify-end gap-2">
                <button wire:click="resetRestore" class="btn-ghost">Cancel</button>
                <button wire:click="restore" wire:loading.attr="disabled" wire:target="restore" class="btn bg-rose-600 text-white hover:bg-rose-700">
                    <span wire:loading.remove wire:target="restore">Restore now</span><span wire:loading wire:target="restore">Restoring…</span>
                </button>
            </div>
        </x-modal>
    @endif
</div>
