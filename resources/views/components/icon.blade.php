@props(['name'=>'grid'])
<svg {{ $attributes->merge(['class'=>'icon']) }} width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
@switch($name)
@case('whatsapp')
{{-- Bootstrap Icons WhatsApp, MIT license: public/licenses/bootstrap-icons.txt --}}
<g transform="scale(1.5)" fill="currentColor" stroke="none"><path d="M13.601 2.326A7.85 7.85 0 0 0 7.994 0C3.627 0 .068 3.558.064 7.926c0 1.399.366 2.76 1.057 3.965L0 16l4.204-1.102a7.9 7.9 0 0 0 3.79.965h.004c4.368 0 7.926-3.558 7.93-7.93A7.9 7.9 0 0 0 13.6 2.326zM7.994 14.521a6.6 6.6 0 0 1-3.356-.92l-.24-.144-2.494.654.666-2.433-.156-.251a6.56 6.56 0 0 1-1.007-3.505c0-3.626 2.957-6.584 6.591-6.584a6.56 6.56 0 0 1 4.66 1.931 6.56 6.56 0 0 1 1.928 4.66c-.004 3.639-2.961 6.592-6.592 6.592m3.615-4.934c-.197-.099-1.17-.578-1.353-.646-.182-.065-.315-.099-.445.099-.133.197-.513.646-.627.775-.114.133-.232.148-.43.05-.197-.1-.836-.308-1.592-.985-.59-.525-.985-1.175-1.103-1.372-.114-.198-.011-.304.088-.403.087-.088.197-.232.296-.346.1-.114.133-.198.198-.33.065-.134.034-.248-.015-.347-.05-.099-.445-1.076-.612-1.47-.16-.389-.323-.335-.445-.34-.114-.007-.247-.007-.38-.007a.73.73 0 0 0-.529.247c-.182.198-.691.677-.691 1.654s.71 1.916.81 2.049c.098.133 1.394 2.132 3.383 2.992.47.205.84.326 1.129.418.475.152.904.129 1.246.08.38-.058 1.171-.48 1.338-.943.164-.464.164-.86.114-.943-.049-.084-.182-.133-.38-.232"/></g>
@break
@case('home')<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1V10Z"/>@break
@case('person')<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a7 7 0 0 1 7-7h2a7 7 0 0 1 7 7v2"/>@break
@case('back')<path d="m14 5-7 7 7 7M7 12h14"/>@break
@case('eye')<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>@break
@case('eye-off')<path d="m3 3 18 18M10.6 5.1A12 12 0 0 1 12 5c6 0 10 7 10 7a18 18 0 0 1-3 3.8M6.3 6.3A24 24 0 0 0 2 12s4 7 10 7a12 12 0 0 0 5.7-1.6M10 10a3 3 0 0 0 4 4"/>@break
@case('grid')<rect x="3" y="3" width="7" height="7" rx="1.6"/><rect x="14" y="3" width="7" height="7" rx="1.6"/><rect x="3" y="14" width="7" height="7" rx="1.6"/><rect x="14" y="14" width="7" height="7" rx="1.6"/>@break
@case('users')<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m20 0v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/><circle cx="9" cy="7" r="4"/>@break
@case('wallet')<path d="M20 8V5a2 2 0 0 0-2-2H6a3 3 0 0 0 0 6h14v12H6a3 3 0 0 1-3-3V6m17 7h-5v4h5"/>@break
@case('chart')<path d="M3 3v18h18M7 14l4-5 4 3 6-8"/>@break
@case('box')<path d="m12 3 9 5-9 5-9-5 9-5Zm-9 5v10l9 5 9-5V8M12 13v10M7.5 5.5l9 5"/>@break
@case('arrow')<path d="M5 12h14m-5-5 5 5-5 5"/>@break
@case('up')<path d="M12 19V5m-6 6 6-6 6 6"/>@break
@case('down')<path d="M12 5v14m-6-6 6 6 6-6"/>@break
@case('swap')<path d="M3 7h17m-4-4 4 4-4 4M21 17H4m4-4-4 4 4 4"/>@break
@case('shield')<path d="m12 3 9 4v6c0 5-9 9-9 9s-9-4-9-9V7l9-4Z"/><path d="m8 12 3 3 5-6"/>@break
@case('file')<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6ZM14 2v6h6M8 13h8m-8 4h6"/>@break
@case('bell')<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9m-12 9h12m-8 4h4"/>@break
@case('settings')<path d="m12 2 2 3 4-.5.5 4 3 2-3 2-.5 4-4-.5-2 3-2-3-4 .5-.5-4-3-2 3-2 .5-4 4 .5 2-3Z"/><circle cx="12" cy="11" r="3"/>@break
@case('menu')<path d="M3 6h18M3 12h18M3 18h18"/>@break
@case('close')<path d="m6 6 12 12M6 18 18 6"/>@break
@case('search')<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>@break
@case('download')<path d="M12 3v12m-4-4 4 4 4-4M4 16v5h16v-5"/>@break
@case('plus')<path d="M12 5v14M5 12h14"/>@break
@case('clock')<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>@break
@case('logout')<path d="M9 21H3V3h6m6 4 5 5-5 5M8 12h12"/>@break
@case('network')<rect x="9" y="2" width="6" height="6" rx="1"/><path d="M12 8v5M5 17v-4h14v4"/><rect x="2" y="17" width="6" height="5" rx="1"/><rect x="16" y="17" width="6" height="5" rx="1"/>@break
@default<circle cx="12" cy="12" r="9"/><path d="M12 8v4m0 4h.01"/>
@endswitch
</svg>
