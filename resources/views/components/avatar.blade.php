@props(['user', 'class' => 'size-10', 'text' => 'text-sm'])
@if ($url = $user->photoUrl())
    <img src="{{ $url }}" alt="{{ $user->name }}" {{ $attributes->merge(['class' => "$class shrink-0 rounded-full object-cover ring-2 ring-white dark:ring-slate-800"]) }}>
@else
    <span {{ $attributes->merge(['class' => "$class $text grid shrink-0 place-items-center rounded-full bg-gradient-to-br from-brand-400 to-rose-500 font-bold text-white ring-2 ring-white dark:ring-slate-800"]) }}>{{ $user->initials() }}</span>
@endif
