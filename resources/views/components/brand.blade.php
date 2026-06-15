@php [$plain, $accent] = \App\Support\Brand::parts(); @endphp

<span {{ $attributes }}>{{ $plain }}@if ($accent !== '')<span class="text-sky-400">{{ $accent }}</span>@endif</span>
