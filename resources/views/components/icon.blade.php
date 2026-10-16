@props(['name'=>'box'])
<svg {{ $attributes->merge(['class'=>'icon']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
@switch($name)
@case('home')<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1Z"/>@break
@case('grid')<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>@break
@case('cart')<path d="M3 3h2l3 13h11l2-9H6"/><circle cx="9" cy="21" r="1"/><circle cx="18" cy="21" r="1"/>@break
@case('search')<circle cx="10.5" cy="10.5" r="7"/><path d="m16 16 5 5"/>@break
@case('plus')<path d="M12 5v14M5 12h14"/>@break
@case('check')<path d="m5 12 4 4L19 6"/>@break
@case('arrow')<path d="M5 12h14m-6-6 6 6-6 6"/>@break
@case('upload')<path d="M12 16V3m-5 5 5-5 5 5M4 16v5h16v-5"/>@break
@case('settings')<path d="m12 3 2 3 4-1 1 4 3 3-3 3-1 4-4-1-2 3-2-3-4 1-1-4-3-3 3-3 1-4 4 1Z"/><circle cx="12" cy="12" r="3"/>@break
@case('leaf')<path d="M20 3C8 2 1 10 6 17s16 1 14-14ZM5 21 16 9"/>@break
@case('alert')<path d="m12 3 10 18H2ZM12 9v5m0 3v.1"/>@break
@case('logout')<path d="M9 4H4v16h5m6-13 5 5-5 5m-7-5h12"/>@break
@case('menu')<path d="M4 6h16M4 12h16M4 18h16"/>@break
@case('list')<path d="M9 5h12M9 12h12M9 19h12M3 5h.1M3 12h.1M3 19h.1"/>@break
@case('trash')<path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7"/>@break
@default<path d="m12 3 9 5v9l-9 5-9-5V8ZM3 8l9 5 9-5M12 13v9M7 5l10 6"/>
@endswitch
</svg>
