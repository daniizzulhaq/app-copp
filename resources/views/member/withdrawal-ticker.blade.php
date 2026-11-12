@if($successfulWithdrawals->isNotEmpty())
    <section class="member-wd-ticker" aria-label="Penarikan member yang sudah selesai" x-data="{ paused: false }" :class="{ 'is-paused': paused }">
        <div class="member-wd-ticker-heading">
            <span><x-icon name="shield"/>WD SUKSES</span>
            <button type="button" @click="paused = !paused" :aria-pressed="paused.toString()" aria-pressed="false" :aria-label="paused ? 'Lanjutkan notifikasi berjalan' : 'Jeda notifikasi berjalan'" aria-label="Jeda notifikasi berjalan" x-text="paused ? 'Lanjutkan' : 'Jeda'">Jeda</button>
        </div>
        <div class="member-wd-ticker-viewport">
            <div class="member-wd-ticker-track" style="--wd-ticker-duration: {{ max(24, $successfulWithdrawals->count() * 8) }}s">
                @foreach([false, true] as $duplicate)
                    <ul class="member-wd-ticker-items" @if($duplicate) aria-hidden="true" @endif>
                        @foreach($successfulWithdrawals as $withdrawal)
                            <li>
                                <span class="member-wd-ticker-dot" aria-hidden="true"></span>
                                <span><strong>{{ $withdrawal['name'] }}</strong> berhasil WD <strong>{{ \App\Support\Money::rupiah($withdrawal['amount']) }}</strong><time datetime="{{ $withdrawal['completed_at']->toIso8601String() }}">{{ $withdrawal['completed_at']->format('d M Y H:i') }} WIB</time></span>
                            </li>
                        @endforeach
                    </ul>
                @endforeach
            </div>
        </div>
    </section>
@endif
