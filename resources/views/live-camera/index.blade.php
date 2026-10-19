@extends('layouts.app')
@section('title', 'Smart Absensi — Live Gate Monitor')

@section('content')
<script id="recent-logs-data" type="application/json">@json($recentLogs)</script>
<div x-data="cameraFeedData('{{ $streamIp }}', {{ (int)$presentToday }})" class="flex flex-col h-[calc(100vh-56px)] bg-canvas-soft text-ink overflow-hidden select-none">

    {{-- Top Header Bar (White Theme) --}}
    <header class="h-[52px] px-4 bg-white border-b border-hairline flex items-center justify-between gap-3 shrink-0 shadow-xs">
        {{-- Brand & Status --}}
        <div class="flex items-center gap-3">
            <div class="flex items-center gap-2">
                <span class="text-sm font-bold tracking-tight text-ink">Smart Absensi</span>
                <span class="text-[10px] font-semibold uppercase px-2 py-0.5 rounded bg-slate-100 text-ink-muted border border-hairline">Gate Monitor</span>
            </div>

            {{-- Status Indicator --}}
            <div class="inline-flex items-center gap-2 px-2.5 py-1 rounded-full text-xs font-semibold border"
                 :class="{
                     'bg-emerald-50 border-emerald-200 text-emerald-700': status === 'online',
                     'bg-amber-50 border-amber-200 text-amber-700': status === 'connecting',
                     'bg-rose-50 border-rose-200 text-rose-700': status === 'offline'
                 }">
                <span class="w-2 h-2 rounded-full transition-colors" :class="{
                    'bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.5)] animate-pulse': status === 'online',
                    'bg-amber-500': status === 'connecting',
                    'bg-rose-500': status === 'offline'
                }"></span>
                <span class="text-[11px] font-mono font-medium tracking-wide"
                      x-text="status === 'online' ? 'TERHUBUNG' : (status === 'connecting' ? 'MENGHUBUNGKAN...' : 'TERPUTUS')">
                    TERHUBUNG
                </span>
            </div>

            {{-- Device IP Selector --}}
            <div class="flex items-center gap-1.5 bg-canvas-soft border border-hairline rounded px-2 py-1 text-xs text-ink">
                <i data-lucide="radio" class="w-3.5 h-3.5 text-ink-muted"></i>
                <select x-model="streamIp" @change="reconnect()" class="bg-transparent border-none text-xs text-ink font-medium focus:ring-0 p-0 cursor-pointer">
                    @if($devices->isEmpty())
                        <option value="{{ $streamIp }}">Local Edge ({{ $streamIp }})</option>
                    @else
                        @foreach($devices as $device)
                            <option value="{{ $device->ip_address }}">{{ $device->name }} ({{ $device->ip_address ?: 'Belum diset IP' }})</option>
                        @endforeach
                        <option value="127.0.0.1">Localhost (127.0.0.1)</option>
                    @endif
                </select>
            </div>
        </div>

        {{-- Metrics & Clock --}}
        <div class="hidden lg:flex items-center gap-4 text-xs font-mono text-ink-muted">
            <span>HOST: <strong class="text-primary font-semibold" x-text="streamIp"></strong></span>
            <span>PORT: <strong class="text-primary font-semibold">5000</strong></span>
            <span>PRESENSI HARI INI: <strong class="text-emerald-600 font-semibold" x-text="presentCount"></strong></span>
            <span>WAKTU: <strong class="text-ink font-semibold" x-text="currentTime"></strong></span>
        </div>

        {{-- Header Actions --}}
        <div class="flex items-center gap-2">
            <button type="button" @click="reconnect()" class="px-2.5 py-1 text-xs font-medium rounded bg-white hover:bg-slate-50 text-ink border border-hairline shadow-xs transition-colors flex items-center gap-1.5 cursor-pointer" title="Muat ulang aliran kamera">
                <i data-lucide="refresh-cw" class="w-3.5 h-3.5" :class="{'animate-spin': isRefreshing}"></i>
                <span>Refresh</span>
            </button>
            <button type="button" @click="captureSnapshot()" class="px-2.5 py-1 text-xs font-medium rounded bg-white hover:bg-slate-50 text-ink border border-hairline shadow-xs transition-colors flex items-center gap-1.5 cursor-pointer" title="Unduh snapshot frame">
                <i data-lucide="camera" class="w-3.5 h-3.5"></i>
                <span>Snapshot</span>
            </button>
        </div>
    </header>

    {{-- Main Gate Monitor Content --}}
    <main class="flex-1 flex flex-col md:flex-row overflow-hidden relative">

        {{-- Massive Camera Viewport --}}
        <section id="cameraViewport" x-ref="cameraViewport" class="flex-1 bg-slate-950 flex flex-col items-center justify-center relative overflow-hidden border-r border-hairline">
            
            {{-- Camera Stage --}}
            <div class="relative w-full h-full flex items-center justify-center p-2">
                {{-- Offline / Standby Placeholder --}}
                <div x-show="status !== 'online'" class="absolute inset-0 flex flex-col items-center justify-center text-slate-400 gap-2 p-4 text-center bg-slate-950/95 z-10">
                    <i data-lucide="video-off" class="w-10 h-10 opacity-40"></i>
                    <span class="text-sm font-mono text-slate-300" x-text="status === 'connecting' ? 'Menghubungkan ke Edge Engine /stream...' : 'Kamera Standby / Belum Terhubung'"></span>
                    <button type="button" @click="reconnect()" class="mt-2 px-3 py-1.5 text-xs font-medium rounded bg-slate-800 hover:bg-slate-700 text-white border border-slate-700 transition-colors flex items-center gap-1.5 cursor-pointer">
                        <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i> Hubungkan Ulang
                    </button>
                </div>

                {{-- Live MJPEG Stream from Edge (/stream) --}}
                <img id="liveStreamImg"
                     src="http://{{ $streamIp }}:5000/stream"
                     :src="'http://' + streamIp + ':5000/stream'"
                     alt="Live Stream"
                     class="max-w-full max-h-[calc(100vh-120px)] w-auto h-auto object-contain rounded"
                     x-on:load="onStreamLoad()"
                     x-on:error="onStreamError()">
            </div>

            {{-- Floating Controls Toolbar (Light Floating Pill) --}}
            <nav class="absolute bottom-4 left-1/2 -translate-x-1/2 bg-white/95 backdrop-blur-md border border-hairline rounded-lg px-2 py-1 flex items-center gap-2 z-20 shadow-md">
                <button type="button"
                        @click="togglePause()"
                        class="px-2.5 py-1 text-xs font-medium rounded transition-colors flex items-center gap-1 border border-hairline cursor-pointer"
                        :class="isPaused ? 'bg-primary/10 border-primary/40 text-primary' : 'bg-slate-50 hover:bg-slate-100 text-ink'"
                        title="Jeda atau lanjutkan video">
                    <i data-lucide="pause" x-show="!isPaused" class="w-3.5 h-3.5"></i>
                    <i data-lucide="play" x-show="isPaused" class="w-3.5 h-3.5"></i>
                    <span x-text="isPaused ? 'Lanjutkan' : 'Pause'">Pause</span>
                </button>

                <button type="button"
                        @click="toggleFullscreen()"
                        class="px-2.5 py-1 text-xs font-medium rounded bg-slate-50 hover:bg-slate-100 text-ink border border-hairline transition-colors flex items-center gap-1 cursor-pointer"
                        title="Mode Layar Penuh">
                    <i data-lucide="maximize-2" x-show="!isFullscreen" class="w-3.5 h-3.5"></i>
                    <i data-lucide="minimize-2" x-show="isFullscreen" class="w-3.5 h-3.5"></i>
                    <span>Fullscreen</span>
                </button>
            </nav>
        </section>

        {{-- Right: Attendance Sidebar (Presensi Terbaru - White Theme) --}}
        <aside class="w-full md:w-[280px] lg:w-[320px] bg-white flex flex-col border-t md:border-t-0 md:border-l border-hairline shrink-0 h-full shadow-xs">
            <div class="p-3 border-b border-hairline flex items-center justify-between bg-white">
                <span class="text-xs font-bold uppercase tracking-wider text-ink">Presensi Terbaru</span>
                <span class="text-[11px] font-mono px-2 py-0.5 rounded-full bg-slate-100 text-primary border border-hairline" x-text="filteredLogs.length">
                    {{ count($recentLogs) }}
                </span>
            </div>

            {{-- Direction Filter (Compact Segment in Light Theme) --}}
            <div class="px-3 py-1.5 border-b border-hairline flex items-center justify-between bg-canvas-soft">
                <div class="flex items-center gap-1 bg-white p-0.5 rounded border border-hairline">
                    <button type="button"
                            @click="filterDirection = 'all'"
                            class="px-2 py-0.5 text-[10px] rounded transition-colors cursor-pointer border-none"
                            :class="filterDirection === 'all' ? 'bg-slate-100 text-ink font-semibold shadow-xs' : 'text-ink-muted hover:text-ink'">
                        Semua
                    </button>
                    <button type="button"
                            @click="filterDirection = 'in'"
                            class="px-2 py-0.5 text-[10px] rounded transition-colors cursor-pointer border-none"
                            :class="filterDirection === 'in' ? 'bg-emerald-600 text-white font-semibold shadow-xs' : 'text-ink-muted hover:text-emerald-700'">
                        Masuk
                    </button>
                    <button type="button"
                            @click="filterDirection = 'out'"
                            class="px-2 py-0.5 text-[10px] rounded transition-colors cursor-pointer border-none"
                            :class="filterDirection === 'out' ? 'bg-primary text-white font-semibold shadow-xs' : 'text-ink-muted hover:text-primary'">
                        Keluar
                    </button>
                </div>

                <span class="text-[10px] text-ink-faint font-mono truncate" x-text="lastSyncTime ? 'Sync: ' + lastSyncTime : 'Live'">
                    Live
                </span>
            </div>

            {{-- Scrollable Log List --}}
            <div class="flex-1 overflow-y-auto custom-scroll p-2.5 flex flex-col gap-2 bg-canvas-soft/40">
                <template x-if="filteredLogs.length > 0">
                    <div class="flex flex-col gap-2">
                        <template x-for="log in filteredLogs" :key="log.id">
                            <div class="bg-white border border-hairline hover:border-slate-300 rounded-md p-2.5 flex flex-col gap-1 shadow-xs transition-colors">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-xs font-semibold text-ink truncate" x-text="log.name"></span>
                                    <span class="text-[10px] font-bold px-1.5 py-0.5 rounded uppercase shrink-0 border"
                                          :class="log.direction === 'in' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-blue-50 text-blue-700 border-blue-200'"
                                          x-text="log.direction === 'in' ? 'MASUK' : 'KELUAR'"></span>
                                </div>
                                <div class="flex items-center justify-between text-[11px] text-ink-muted font-mono">
                                    <span x-text="'ID: ' + log.code"></span>
                                    <span x-text="log.time"></span>
                                </div>
                                <div x-show="log.score" class="text-[10px] text-ink-faint font-mono" x-text="'Match: ' + log.score"></div>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- Empty State --}}
                <div x-show="filteredLogs.length === 0" class="flex flex-col items-center justify-center h-full text-ink-muted text-xs text-center p-4 leading-relaxed">
                    Belum ada presensi siswa terdeteksi.<br>
                    Silakan posisikan wajah di depan kamera.
                </div>
            </div>
        </aside>
    </main>

</div>
@endsection
