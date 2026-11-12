<div class="member-screen" data-member-app>
    <header class="member-header">
        <div class="member-header-brand">
            @if(request()->routeIs('dashboard'))
                <a href="/dashboard" aria-label="Beranda COPP Luxury"><x-logo/></a>
            @else
                <a href="/dashboard" class="member-icon-button" aria-label="Kembali ke beranda"><x-icon name="back"/></a>
                <span class="member-page-title">@yield('title','COPP Luxury')</span>
            @endif
        </div>
        <div class="member-header-actions">
            <a href="/notifications" class="member-icon-button" aria-label="Notifikasi, {{ auth()->user()->unreadNotifications()->count() }} belum dibaca">
                <x-icon name="bell"/>
                @if(auth()->user()->unreadNotifications()->exists())<span class="member-unread-dot"></span>@endif
            </a>
            <button type="button" class="member-avatar-button" @click="$refs.memberMenu.showModal()" aria-label="Buka menu akun"><span>{{ mb_substr(auth()->user()->name,0,1) }}</span></button>
        </div>
    </header>

    <main id="main" class="member-content">
        @include('member.withdrawal-ticker')
        @if(session('status'))
            <div class="toast" role="status" x-data="{show:true}" x-show="show"><x-icon name="shield"/><span>{{ \App\Support\UiText::local(session('status')) }}</span><button type="button" @click="show=false" aria-label="Tutup pesan">×</button></div>
        @endif
        @if($errors->any())
            <div class="error-summary" role="alert"><strong>Periksa kembali data Anda.</strong><ul>@foreach($errors->all() as $error)<li>{{ \App\Support\UiText::local($error) }}</li>@endforeach</ul></div>
        @endif
        @yield('content')
        @if(request()->routeIs('profile'))
            <section class="member-section member-account-links" aria-label="Menu akun">
                <h2>Jelajahi akun</h2>
                <a href="/referrals"><span class="member-list-icon violet"><x-icon name="network"/></span><span>Referral & poin internal<small>Lihat jaringan referral Anda</small></span><x-icon name="arrow"/></a>
                <a href="/activity"><span class="member-list-icon"><x-icon name="clock"/></span><span>Aktivitas akun<small>Riwayat tindakan Anda</small></span><x-icon name="arrow"/></a>
                <a href="/notifications"><span class="member-list-icon amber"><x-icon name="bell"/></span><span>Pusat notifikasi<small>Informasi transaksi dan portofolio</small></span><x-icon name="arrow"/></a>
                <form method="post" action="/logout">@csrf<button class="member-logout-button"><x-icon name="logout"/>Keluar dari akun</button></form>
            </section>
        @endif
        <footer class="member-footer"><x-icon name="shield"/><span>COPP Luxury</span></footer>
    </main>

    @php($whatsappNumber = \App\Models\SystemSetting::supportWhatsappNumber())
    @if($whatsappNumber)
        <a href="https://wa.me/{{ $whatsappNumber }}?text={{ rawurlencode('Halo Admin COPP Luxury, saya ingin melaporkan kendala.') }}" class="member-whatsapp-button" target="_blank" rel="noopener noreferrer" aria-label="Hubungi admin melalui WhatsApp (buka tab baru)" title="Chat admin via WhatsApp"><x-icon name="whatsapp"/></a>
    @endif

    <nav class="member-bottom-nav" aria-label="Navigasi member">
        @foreach([
            ['dashboard','home','Beranda','/dashboard'],
            ['packages.*','box','Produk Investasi','/packages'],
            ['investments.*','chart','Portofolio','/investments'],
            ['wallet','wallet','Wallet','/wallet'],
            ['profile','person','Akun','/profile'],
        ] as [$pattern,$icon,$label,$url])
            @php($selected = request()->routeIs($pattern) || ($pattern === 'wallet' && request()->routeIs('payments.*','withdrawals.*')) || ($pattern === 'profile' && request()->routeIs('activity','referrals','notifications')))
            <a href="{{ $url }}" class="{{ $selected ? 'is-active':'' }}" @if($selected) aria-current="page" @endif><span><x-icon :name="$icon"/></span><small>{{ $label }}</small></a>
        @endforeach
    </nav>

    <dialog class="member-menu-dialog" x-ref="memberMenu" aria-labelledby="member-menu-title" @click="if ($event.target === $refs.memberMenu) $refs.memberMenu.close()">
        <div class="member-menu-handle" aria-hidden="true"></div>
        <div class="member-section-heading"><div><h2 id="member-menu-title">Akun saya</h2><p>{{ auth()->user()->name }}</p></div><button type="button" class="member-icon-button" @click="$refs.memberMenu.close()" aria-label="Tutup menu akun"><x-icon name="close"/></button></div>
        <div class="member-menu-links">
            @foreach([['person','Profil & keamanan','/profile'],['down','Deposit','/payments'],['up','Withdrawal','/withdrawals'],['network','Referral internal','/referrals'],['bell','Notifikasi','/notifications'],['clock','Aktivitas akun','/activity']] as [$icon,$label,$url])
                <a href="{{ $url }}"><x-icon :name="$icon"/><span>{{ $label }}</span><x-icon name="arrow"/></a>
            @endforeach
            <form method="post" action="/logout">@csrf<button class="member-logout-button"><x-icon name="logout"/>Keluar dari akun</button></form>
        </div>
    </dialog>
</div>
