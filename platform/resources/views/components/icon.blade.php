@props(['name'])

{{--
  أيقونات خطّية بأسلوب التصميم (Stroke 2): شبكة ٢٤، خطّ ١٫٨، أطراف مدوّرة.
  كلّ أيقونة مكتوبة هنا حرفياً — لا {!! !!} في الصفحات، ولا ملفّات خارجية.
  والاتّجاهية منها (السهم، الخروج) تُقلَب في الصفحة اليمينية بـ rtl:-scale-x-100.
--}}
<svg {{ $attributes->merge(['class' => 'size-5']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($name)
        @case('home')
            <path d="M3.5 11 12 4l8.5 7"/><path d="M5.5 9.5V20h13V9.5"/><path d="M10 20v-5h4v5"/>
            @break
        @case('box')
            <path d="M3.5 7.5 12 3l8.5 4.5v9L12 21l-8.5-4.5z"/><path d="M3.5 7.5 12 12l8.5-4.5"/><path d="M12 12v9"/>
            @break
        @case('plus')
            <path d="M12 5v14"/><path d="M5 12h14"/>
            @break
        @case('upload')
            <path d="M12 15V4"/><path d="M7.5 8.5 12 4l4.5 4.5"/><path d="M4 15v3.5A1.5 1.5 0 0 0 5.5 20h13a1.5 1.5 0 0 0 1.5-1.5V15"/>
            @break
        @case('chat')
            <path d="M4 5.5A1.5 1.5 0 0 1 5.5 4h13A1.5 1.5 0 0 1 20 5.5v9a1.5 1.5 0 0 1-1.5 1.5H9l-5 4z"/><path d="M8 8.5h8"/><path d="M8 12h5"/>
            @break
        @case('megaphone')
            <path d="M4 10v4l11 5V5z"/><path d="M15 9a3 3 0 0 1 0 6"/><path d="m7 15 1.2 4.5h2.5L9.8 16"/>
            @break
        @case('clipboard')
            <path d="M9 3.5h6v3H9z"/><path d="M15 5h3.5a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-13a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1H9"/><path d="M8.5 12h7"/><path d="M8.5 16h4.5"/>
            @break
        @case('undo')
            <path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/>
            @break
        @case('sort')
            <path d="M7 4v16"/><path d="M3.5 16.5 7 20l3.5-3.5"/><path d="M17 20V4"/><path d="M13.5 7.5 17 4l3.5 3.5"/>
            @break
        @case('handover')
            <path d="M14 4h6v6"/><path d="m20 4-9 9"/><path d="M18 14v4.5a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 4 18.5v-11A1.5 1.5 0 0 1 5.5 6H10"/>
            @break
        @case('bag')
            <path d="M5.5 8h13l-1 12.5h-11z"/><path d="M9 8V6.5a3 3 0 0 1 6 0V8"/>
            @break
        @case('truck')
            <path d="M3 6h11v10H3z"/><path d="M14 9.5h4l3 3.5v3h-7"/><circle cx="7" cy="17.5" r="1.8"/><circle cx="17.5" cy="17.5" r="1.8"/>
            @break
        @case('inbound')
            <path d="M4 13.5h4.5l1.5 2.5h4l1.5-2.5H20"/><path d="M4 13.5 6 5h12l2 8.5V20H4z"/><path d="M12 7v5"/><path d="m9.5 9.5 2.5 2.5 2.5-2.5"/>
            @break
        @case('archive')
            <path d="M3.5 4.5h17v4h-17z"/><path d="M5 8.5V20h14V8.5"/><path d="M10 12.5h4"/>
            @break
        @case('store')
            <path d="M4 9 5.5 4h13L20 9"/><path d="M4 9h16"/><path d="M5 9v11h14V9"/><path d="M9.5 20v-5h5v5"/>
            @break
        @case('user')
            <circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/>
            @break
        @case('users')
            <circle cx="9" cy="8.5" r="3"/><path d="M3 19.5a6 6 0 0 1 12 0"/><path d="M15.5 5.8a3 3 0 0 1 0 5.4"/><path d="M17.5 14a6 6 0 0 1 3.5 5.5"/>
            @break
        @case('cash')
            <rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6.5 9.5v.01"/><path d="M17.5 14.5v.01"/>
            @break
        @case('exchange')
            <path d="M4 8h13"/><path d="M13.5 4.5 17 8l-3.5 3.5"/><path d="M20 16H7"/><path d="M10.5 12.5 7 16l3.5 3.5"/>
            @break
        @case('vault')
            <rect x="3.5" y="4" width="17" height="15" rx="2"/><circle cx="12" cy="11.5" r="3"/><path d="M12 8.5V7"/><path d="M6.5 19v1.5"/><path d="M17.5 19v1.5"/>
            @break
        @case('receipt')
            <path d="M6 3h12v18l-2-1.5-2 1.5-2-1.5-2 1.5-2-1.5L6 21z"/><path d="M9 8h6"/><path d="M9 12h6"/><path d="M9 16h3"/>
            @break
        @case('building')
            <path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9.5 11h1"/><path d="M13.5 11h1"/><path d="M9.5 15h1"/><path d="M13.5 15h1"/>
            @break
        @case('file')
            <path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5"/><path d="M10 13h6"/><path d="M10 17h6"/>
            @break
        @case('scale')
            <path d="M12 4v16"/><path d="M7.5 20h9"/><path d="M4 8h16"/><path d="m6 8-2.5 6a2.8 2.8 0 0 0 5 0z"/><path d="m18 8-2.5 6a2.8 2.8 0 0 0 5 0z"/>
            @break
        @case('shield')
            <path d="M12 3 20 6v6c0 4.5-3.4 8-8 9-4.6-1-8-4.5-8-9V6z"/><path d="m9 12 2 2 4-4"/>
            @break
        @case('tag')
            <path d="M3.5 12.5v-8a1 1 0 0 1 1-1h8l8 8-9 9z"/><circle cx="8" cy="8" r="1.5"/>
            @break
        @case('chart')
            <path d="M4 4v16h16"/><path d="M8.5 16v-5"/><path d="M12.5 16V8"/><path d="M16.5 16v-3"/>
            @break
        @case('copy')
            <rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5.5A1.5 1.5 0 0 0 14.5 4h-9A1.5 1.5 0 0 0 4 5.5v9A1.5 1.5 0 0 0 5.5 16H8"/>
            @break
        @case('bolt')
            <path d="M13 3 5 13.5h6L10 21l8-10.5h-6z"/>
            @break
        @case('lock')
            <rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/>
            @break
        @case('pin')
            <path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>
            @break
        @case('sliders')
            <path d="M4 7h9"/><path d="M17 7h3"/><circle cx="15" cy="7" r="2"/><path d="M4 17h3"/><path d="M11 17h9"/><circle cx="9" cy="17" r="2"/>
            @break
        @case('logout')
            <path d="M14 4h4.5A1.5 1.5 0 0 1 20 5.5v13a1.5 1.5 0 0 1-1.5 1.5H14"/><path d="M9 8 5 12l4 4"/><path d="M5 12h10"/>
            @break
        @case('search')
            <circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>
            @break
        @case('bell')
            <path d="M6 9.5a6 6 0 0 1 12 0c0 5 2 6.5 2 6.5H4s2-1.5 2-6.5"/><path d="M10 19.5a2 2 0 0 0 4 0"/>
            @break
        @case('menu')
            <path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/>
            @break
        @case('arrow')
            <path d="M7 17 17 7"/><path d="M8.5 7H17v8.5"/>
            @break
        @case('clock')
            <circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>
            @break
        @case('alert')
            <path d="M12 4 21 20H3z"/><path d="M12 10v4"/><path d="M12 17v.01"/>
            @break
        @case('check')
            <circle cx="12" cy="12" r="8.5"/><path d="m8.5 12.5 2.5 2.5 5-5"/>
            @break
        @case('x')
            <path d="M6 6l12 12"/><path d="M18 6 6 18"/>
            @break
        @case('wallet')
            <path d="M4 7.5A1.5 1.5 0 0 1 5.5 6H18v3"/><path d="M4 7.5V18a1.5 1.5 0 0 0 1.5 1.5H20V9H5.5A1.5 1.5 0 0 1 4 7.5z"/><path d="M16 14.5h.01"/>
            @break
        @case('calendar')
            <rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 10h16"/><path d="M8 3v4"/><path d="M16 3v4"/>
            @break
        @case('eye')
            <path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>
            @break
        @case('invoice')
            <path d="M7 3h10a1 1 0 0 1 1 1v17l-3-2-3 2-3-2-3 2V4a1 1 0 0 1 1-1z"/><path d="M9.5 8h5"/><path d="M9.5 12h5"/>
            @break
        @case('chevron')
            <path d="m9 6 6 6-6 6"/>
            @break
        @case('grid')
            <rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/>
            @break
        @case('chevron-down')
            <path d="m6 9 6 6 6-6"/>
            @break
        @case('boxes')
            <rect x="3" y="13" width="8" height="7.5" rx="1"/><rect x="13" y="13" width="8" height="7.5" rx="1"/><rect x="8" y="3.5" width="8" height="7.5" rx="1"/><path d="M7 13v2.5"/><path d="M17 13v2.5"/><path d="M12 3.5V6"/>
            @break
        @case('bank')
            <path d="M3 9.5 12 4l9 5.5"/><path d="M4.5 9.5h15"/><path d="M6 12v5"/><path d="M10 12v5"/><path d="M14 12v5"/><path d="M18 12v5"/><path d="M4.5 17h15"/><path d="M3.5 20h17"/>
            @break
        @case('trend')
            <path d="M4 4v16h16"/><path d="m7.5 14.5 3.5-4 3 2.5 4.5-5.5"/><path d="M15 7.5h3.5V11"/>
            @break
        @case('review')
            <rect x="4" y="4" width="16" height="16" rx="2.5"/><path d="m8.5 12.5 2.5 2.5 5-5.5"/>
            @break
        @case('card')
            <rect x="3" y="5.5" width="18" height="13" rx="2"/><path d="M3 10h18"/><path d="M7 15h3"/>
            @break
        @default
            <circle cx="12" cy="12" r="2"/>
    @endswitch
</svg>
