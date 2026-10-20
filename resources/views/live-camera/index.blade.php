@extends('layouts.app')
@section('title', 'Live Kamera')

@section('content')
<script id="recent-logs-data" type="application/json">@json($recentLogs)</script>

<div x-data="cameraFeedData('{{ $streamIp }}', {{ (int) $presentToday }})"
     class="flex flex-col h-[calc(100vh-56px)] overflow-hidden select-none">

    {{-- ─────────────────────────── Command bar ─────────────────────────── --}}
    <header class="h-[48px] px-md bg-canvas border-b border-hairline flex items-center justify-between gap-md shrink-0">

        <div class="flex items-center gap-sm min-w-0">
            {{-- Connection status --}}
            <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-full border text-[10.5px] font-mono font-semibold uppercase tracking-wide whitespace-nowrap shrink-0 transition-colors"
                  :class="{
                      'bg-[#ecfdf1] border-[#abefc6] text-[#067647]': status === 'online',
                      'bg-[#fff4ec] border-[#fedf89] text-[#b54708]': status === 'connecting',
                      'bg-[#fef3f2] border-[#fecdca] text-[#b42318]': status === 'offline'
                  }">
                <span class="live-dot"
                      :class="{
                          'live-dot-online': status === 'online',
                          'live-dot-connecting': status === 'connecting',
                          'live-dot-offline': status === 'offline'
                      }"></span>
                <span x-text="status === 'online' ? 'Online' : (status === 'connecting' ? 'Menghubungkan' : 'Offline')">Online</span>
            </span>

            {{-- Device selector --}}
            <label class="sr-only" for="streamDevice">Pilih perangkat kamera</label>
            <div class="flex items-center gap-1.5 bg-canvas-soft border border-hairline rounded-md px-2 py-1 max-w-[200px] sm:max-w-[260px] shrink-0">
                <i data-lucide="radio" class="w-3.5 h-3.5 text-ink-muted shrink-0"></i>
                <select id="streamDevice" x-model="streamIp" @change="reconnect()"
                        class="bg-transparent border-none text-[12px] text-ink font-medium focus:ring-0 p-0 cursor-pointer truncate w-full outline-none">
                    @if($devices->isEmpty())
                        <option value="{{ $streamIp }}">Local Edge ({{ $streamIp }})</option>
                    @else
                        @foreach($devices as $device)
                            <option value="{{ $device->ip_address }}">{{ $device->name }} ({{ $device->ip_address ?: 'IP belum diset' }})</option>
                        @endforeach
                        <option value="127.0.0.1">Localhost (127.0.0.1)</option>
                    @endif
                </select>
            </div>
        </div>

        {{-- Telemetry strip --}}
        <div class="hidden xl:flex items-center gap-lg text-[11px] font-mono text-ink-muted whitespace-nowrap shrink-0 tabular-nums">
            <span class="flex items-center gap-1.5">
                <span class="text-ink-faint">HOST</span>
                <strong class="text-primary font-semibold" x-text="streamIp + ':5000'"></strong>
            </span>
            <span class="flex items-center gap-1.5">
                <span class="text-ink-faint">HADIR</span>
                <strong class="text-success font-semibold" x-text="presentCount"></strong>
            </span>
            <span class="flex items-center gap-1.5">
                <span class="text-ink-faint">WAKTU</span>
                <strong class="text-ink font-semibold" x-text="currentTime"></strong>
            </span>
        </div>

        {{-- Actions --}}
        <div class="flex items-center gap-1.5 shrink-0">
            <button type="button" @click="reconnect()" class="btn btn-secondary btn-sm" title="Muat ulang aliran kamera">
                <i data-lucide="refresh-cw" class="w-3.5 h-3.5" :class="{ 'animate-spin': isRefreshing }"></i>
                <span class="hidden sm:inline">Refresh</span>
            </button>
            <button type="button" @click="captureSnapshot()" class="btn btn-secondary btn-sm" title="Unduh snapshot JPEG">
                <i data-lucide="camera" class="w-3.5 h-3.5"></i>
                <span class="hidden sm:inline">Snapshot</span>
            </button>
            <button type="button" @click="toggleFullscreen()" class="btn btn-secondary btn-sm" title="Layar penuh">
                <i data-lucide="maximize-2" x-show="!isFullscreen" class="w-3.5 h-3.5"></i>
                <i data-lucide="minimize-2" x-show="isFullscreen" class="w-3.5 h-3.5"></i>
                <span class="sr-only">Layar penuh</span>
            </button>
        </div>
    </header>

    {{-- ─────────────────────────── Workspace ───────────────────────────── --}}
    <main class="flex-1 flex flex-col lg:flex-row overflow-hidden min-h-0">

        {{-- Monitor stage --}}
        <section id="cameraViewport" x-ref="cameraViewport"
                 class="flex-1 min-w-0 p-sm sm:p-md flex items-center justify-center overflow-hidden bg-canvas-soft">

            <div class="relative w-full h-full rounded-lg overflow-hidden bg-[#0b0d10] border border-[#1f242b]
                        shadow-[0_1px_3px_rgba(16,24,40,0.12)] flex items-center justify-center">

                {{-- Standby overlay --}}
                <div x-show="status !== 'online'" x-cloak
                     class="absolute inset-0 z-20 flex flex-col items-center justify-center gap-3 p-6 text-center bg-[#0b0d10]/95">
                    <span class="flex items-center justify-center w-12 h-12 rounded-full bg-[#151a20] border border-[#232a33] text-[#7d8590]">
                        <i data-lucide="video-off" class="w-5 h-5"></i>
                    </span>
                    <div class="max-w-[420px]">
                        <h2 class="text-[13px] font-semibold text-[#e6edf3] m-0"
                            x-text="status === 'connecting' ? 'Menghubungkan ke kamera edge…' : 'Kamera gerbang belum terhubung'">
                        </h2>
                        <p class="text-[11px] font-mono text-[#7d8590] mt-1 mb-0 truncate"
                           x-text="'http://' + streamIp + ':5000/stream'"></p>
                    </div>
                    <button type="button" @click="reconnect()"
                            class="btn btn-sm mt-1 bg-[#21262d] text-[#e6edf3] border-[#30363d] hover:bg-[#30363d]">
                        <i data-lucide="refresh-cw" class="w-3.5 h-3.5" :class="{ 'animate-spin': isRefreshing }"></i>
                        <span>Hubungkan ulang</span>
                    </button>
                </div>

                {{-- Stream --}}
                <img id="liveStreamImg"
                     src="http://{{ $streamIp }}:5000/stream"
                     :src="'http://' + streamIp + ':5000/stream'"
                     alt="Aliran kamera langsung dari terminal gerbang"
                     class="max-w-full max-h-full w-auto h-auto object-contain"
                     x-on:load="onStreamLoad()"
                     x-on:error="onStreamError()">

                {{-- Floating control dock --}}
                <nav x-show="status === 'online'" x-cloak
                     class="absolute bottom-3 left-1/2 -translate-x-1/2 z-20 flex items-center gap-1
                            rounded-full border border-hairline bg-canvas/95 backdrop-blur-md px-1.5 py-1
                            shadow-[0_4px_8px_-2px_rgba(16,24,40,0.16)]">
                    <button type="button" @click="togglePause()" title="Jeda atau lanjutkan video"
                            class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium transition-colors cursor-pointer"
                            :class="isPaused ? 'bg-primary text-white' : 'text-ink hover:bg-canvas-soft'">
                        <i data-lucide="pause" x-show="!isPaused" class="w-3.5 h-3.5"></i>
                        <i data-lucide="play" x-show="isPaused" class="w-3.5 h-3.5"></i>
                        <span x-text="isPaused ? 'Lanjutkan' : 'Jeda'">Jeda</span>
                    </button>
                    <span class="w-px h-3 bg-hairline" aria-hidden="true"></span>
                    <button type="button" @click="captureSnapshot()" title="Unduh snapshot JPEG"
                            class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium text-ink hover:bg-canvas-soft transition-colors cursor-pointer">
                        <i data-lucide="camera" class="w-3.5 h-3.5 text-ink-muted"></i>
                        <span>Snapshot</span>
                    </button>
                    <span class="w-px h-3 bg-hairline" aria-hidden="true"></span>
                    <button type="button" @click="toggleFullscreen()" title="Layar penuh"
                            class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium text-ink hover:bg-canvas-soft transition-colors cursor-pointer">
                        <i data-lucide="maximize-2" x-show="!isFullscreen" class="w-3.5 h-3.5 text-ink-muted"></i>
                        <i data-lucide="minimize-2" x-show="isFullscreen" class="w-3.5 h-3.5 text-ink-muted"></i>
                        <span>Layar penuh</span>
                    </button>
                </nav>
            </div>
        </section>

        {{-- Activity sidebar --}}
        <aside class="w-full lg:w-[320px] xl:w-[340px] shrink-0 flex flex-col border-t lg:border-t-0 lg:border-l border-hairline bg-canvas min-h-0">

            <div class="px-md py-2.5 border-b border-hairline flex items-center justify-between gap-sm shrink-0">
                <div class="flex items-center gap-1.5 min-w-0">
                    <i data-lucide="activity" class="w-3.5 h-3.5 text-success shrink-0"></i>
                    <h2 class="text-[11px] font-bold uppercase tracking-[0.08em] text-ink m-0 whitespace-nowrap">Presensi Terbaru</h2>
                </div>
                <span class="text-[10.5px] font-mono font-semibold px-1.5 py-0.5 rounded-full bg-canvas-soft text-primary border border-hairline whitespace-nowrap"
                      x-text="filteredLogs.length + ' entri'">{{ count($recentLogs) }} entri</span>
            </div>

            {{-- Filter + sync indicator --}}
            <div class="px-md py-2 border-b border-hairline flex items-center justify-between gap-sm bg-canvas-soft shrink-0">
                <div class="flex items-center gap-0.5 p-0.5 rounded-md bg-canvas border border-hairline" role="group" aria-label="Filter arah presensi">
                    <button type="button" @click="filterDirection = 'all'"
                            class="px-2 py-0.5 text-[10.5px] rounded transition-colors cursor-pointer whitespace-nowrap"
                            :class="filterDirection === 'all' ? 'bg-canvas-soft text-ink font-semibold' : 'text-ink-muted hover:text-ink'">
                        Semua
                    </button>
                    <button type="button" @click="filterDirection = 'in'"
                            class="px-2 py-0.5 text-[10.5px] rounded transition-colors cursor-pointer whitespace-nowrap"
                            :class="filterDirection === 'in' ? 'bg-success text-white font-semibold' : 'text-ink-muted hover:text-success'">
                        Masuk
                    </button>
                    <button type="button" @click="filterDirection = 'out'"
                            class="px-2 py-0.5 text-[10.5px] rounded transition-colors cursor-pointer whitespace-nowrap"
                            :class="filterDirection === 'out' ? 'bg-primary text-white font-semibold' : 'text-ink-muted hover:text-primary'">
                        Keluar
                    </button>
                </div>
                <span class="text-[10px] text-ink-faint font-mono truncate"
                      x-text="lastSyncTime ? 'sync ' + lastSyncTime : 'poll 5s'">poll 5s</span>
            </div>

            {{-- Log list --}}
            <div class="flex-1 overflow-y-auto custom-scroll p-2 flex flex-col gap-1.5 min-h-0">

                <template x-if="filteredLogs.length > 0">
                    <div class="flex flex-col gap-1.5">
                        <template x-for="log in filteredLogs" :key="log.id">
                            <article class="rounded-md border border-hairline bg-canvas p-2.5 flex flex-col gap-1 transition-colors hover:border-ink-faint">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <span class="w-6 h-6 rounded-full bg-canvas-soft border border-hairline text-ink-muted
                                                     text-[10px] font-bold flex items-center justify-center shrink-0"
                                              x-text="log.initial"></span>
                                        <span class="text-[12.5px] font-semibold text-ink truncate" x-text="log.name"></span>
                                    </div>
                                    <span class="text-[9.5px] font-bold px-1.5 py-0.5 rounded uppercase tracking-wide shrink-0 border"
                                          :class="log.direction === 'in'
                                              ? 'bg-[#ecfdf1] text-[#067647] border-[#abefc6]'
                                              : 'bg-[#e8f2fd] text-[#005bab] border-[#b2ddff]'"
                                          x-text="log.direction === 'in' ? 'Masuk' : 'Keluar'"></span>
                                </div>
                                <div class="flex items-center justify-between text-[10.5px] text-ink-muted font-mono">
                                    <span class="truncate" x-text="log.code"></span>
                                    <span class="tabular-nums shrink-0" x-text="log.time"></span>
                                </div>
                                <div x-show="log.score" class="text-[10px] text-ink-faint font-mono" x-text="'akurasi ' + log.score"></div>
                            </article>
                        </template>
                    </div>
                </template>

                <div x-show="filteredLogs.length === 0" x-cloak class="flex-1 flex flex-col items-center justify-center gap-2 p-6 text-center">
                    <span class="empty-state-icon">
                        <i data-lucide="history" class="w-4 h-4"></i>
                    </span>
                    <span class="text-[12px] font-semibold text-ink-muted">Belum ada presensi hari ini</span>
                    <p class="text-[11px] text-ink-faint max-w-[26ch] leading-relaxed m-0">
                        Anggota yang terdeteksi di kamera gerbang akan muncul di sini secara real-time.
                    </p>
                </div>
            </div>

            <div class="px-md py-2 border-t border-hairline shrink-0">
                <a href="{{ route('reports.index') }}" class="btn-link group">
                    <span>Buka laporan presensi</span>
                    <i data-lucide="arrow-right" class="w-3 h-3 group-hover:translate-x-0.5 transition-transform"></i>
                </a>
            </div>
        </aside>
    </main>
</div>
@endsection
