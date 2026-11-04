@props(['size' => 'size-10', 'text' => 'text-lg', 'radius' => 'rounded-xl'])
@php
    $logo = \App\Models\Setting::get('business_logo');
    $name = (string) \App\Models\Setting::get('business_name');
@endphp
{{-- Business logo from Settings, or the first letter of the business name on the brand gradient. --}}
@if ($logo)
    <img src="{{ route('media', $logo) }}" alt="{{ $name }}" {{ $attributes->merge(['class' => "$size $radius shrink-0 bg-white object-cover shadow-lg"]) }}>
@else
    <div {{ $attributes->merge(['class' => "$size $radius $text grid shrink-0 place-items-center bg-gradient-to-br from-brand-400 to-rose-500 font-extrabold text-white shadow-lg shadow-brand-500/30"]) }}>{{ mb_strtoupper(mb_substr($name ?: 'R', 0, 1)) }}</div>
@endif
