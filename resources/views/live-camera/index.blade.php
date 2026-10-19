@extends('layouts.app')
@section('title', 'Smart Absensi — Live Gate Monitor')

@section('content')
<script id="recent-logs-data" type="application/json">@json($recentLogs)</script>
<div x-data="cameraFeedData('{{ $streamIp }}', {{ (int)$presentToday }})" class="flex flex-col h-[calc(100vh-56px)] bg-[#0b0f19] text-[#f9fafb] overflow-hidden select-none">

    {{-- Top Header Bar (Matching Gate Monitor Spec) --}}
    <header class="h-[52px] px-4 bg-[#111827] border-b border-[#374151] flex items-center justify-between gap-3 shrink-0">
        {{-- Brand & Status --}}
        <div class="flex items-center gap-3">
            <div class="flex items-center gap-2">
                <span class="text-sm font-bold tracking-tight text-[#f9fafb]">Smart Absensi</span>
                <span class="text-[10px] font-semibold uppercase px-2 py-0.5 rounded bg-[#1f2937] text-[#9ca3af] border border-[#374151]">Gate Monitor</span>
            </div>

            {{-- Status Indicator --}}
            <div class="inline-flex items-center gap-2 px-2.5 py-1 rounded-full text-xs font-semibold bg-[#1f2937] border border-[#374151]">
                <span class="w-2 h-2 rounded-full transition-colors" :class="{
                    'bg-[#10b981] shadow-[0_0_8px_rgba(16,185,129,0.6)] animate-pulse': status === 'online',
                    'bg-[#f59e0b]': status === 'connecting',
                    'bg-[#ef4444]': status === 'offline'
                }"></span>
                <span class="text-[11px] font-mono font-medium tracking-wide"
                      :class="status === 'online' ? 'text-[#10b981]' : (status === 'connecting' ? 'text-[#f59e0b]' : 'text-[#ef4444]')"
                      x-text="status === 'online' ? 'TERHUBUNG' : (status === 'connecting' ? 'MENGHUBUNGKAN...' : 'TERPUTUS')">
                    TERHUBUNG
                </span>
            </div>

            {{-- Device IP Selector --}}
            <div class="flex items-center gap-1.5 bg-[#1f2937] border border-[#374151] rounded px-2 py-0.5 text-xs">
                <i data-lucide="radio" class="w-3.5 h-3.5 text-[#9ca3af]"></i>
                <select x-model="streamIp" @change="reconnect()" class="bg-transparent border-none text-xs text-[#f9fafb] font-medium focus:ring-0 p-0 cursor-pointer">
                    @if($devices->isEmpty())
                        <option value="{{ $streamIp }}" class="bg-[#111827] text-white">Local Edge ({{ $streamIp }})</option>
                    @else
                        @foreach($devices as $device)
                            <option value="{{ $device->ip_address }}" class="bg-[#111827] text-white">{{ $device->name }} ({{ $device->ip_address ?: 'Belum diset IP' }})</option>
                        @endforeach
                        <option value="127.0.0.1" class="bg-[#111827] text-white">Localhost (127.0.0.1)</option>
                    @endif
                </select>
            </div>
        </div>

        {{-- Metrics & Clock --}}
        <div class="hidden lg:flex items-center gap-4 text-xs font-mono text-[#9ca3af]">
            <span>HOST: <strong class="text-[#38bdf8] font-semibold" x-text="streamIp"></strong></span>
            <span>PORT: <strong class="text-[#38bdf8] font-semibold">5000</strong></span>
            <span>PRESENSI HARI INI: <strong class="text-[#10b981] font-semibold" x-text="presentCount"></strong></span>
            <span>WAKTU: <strong class="text-white font-semibold" x-text="currentTime"></strong></span>
        </div>

        {{-- Header Actions --}}
        <div class="flex items-center gap-2">
            <button type="button" @click="reconnect()" class="px-2.5 py-1 text-xs font-medium rounded bg-[#1f2937] hover:bg-[#374151] text-[#f9fafb] border border-[#374151] transition-colors flex items-center gap-1.5" title="Muat ulang aliran kamera">
                <i data-lucide="refresh-cw" class="w-3.5 h-3.5" :class="{'animate-spin': isRefreshing}"></i>
                <span>Refresh</span>
            </button>
            <button type="button" @click="captureSnapshot()" class="px-2.5 py-1 text-xs font-medium rounded bg-[#1f2937] hover:bg-[#374151] text-[#f9fafb] border border-[#374151] transition-colors flex items-center gap-1.5" title="Unduh snapshot frame">
                <i data-lucide="camera" class="w-3.5 h-3.5"></i>
                <span>Snapshot</span>
            </button>
        </div>
    </header>

    {{-- Main Gate Monitor Content --}}
    <main class="flex-1 flex flex-col md:flex-row overflow-hidden relative">

        {{-- Massive Camera Viewport --}}
        <section id="cameraViewport" x-ref="cameraViewport" class="flex-1 bg-black flex flex-col items-center justify-center relative overflow-hidden border-r border-[#374151]">
            
            {{-- Camera Stage --}}
            <div class="relative w-full h-full flex items-center justify-center p-2">
                {{-- Offline / Standby Placeholder --}}
                <div x-show="status !== 'online'" class="absolute inset-0 flex flex-col items-center justify-center text-[#9ca3af] gap-2 p-4 text-center bg-black/95 z-10">
                    <i data-lucide="video-off" class="w-10 h-10 opacity-40"></i>
                    <span class="text-sm font-mono" x-text="status === 'connecting' ? 'Menghubungkan ke Edge Engine /stream...' : 'Kamera Standby / Belum Terhubung'"></span>
                    <button type="button" @click="reconnect()" class="mt-2 px-3 py-1.5 text-xs font-medium rounded bg-[#1f2937] hover:bg-[#374151] text-[#f9fafb] border border-[#374151] transition-colors flex items-center gap-1.5">
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

            {{-- Floating Controls Toolbar (Matching Gate Monitor Design) --}}
            <nav class="absolute bottom-4 left-1/2 -translate-x-1/2 bg-[#111827]/90 backdrop-blur-md border border-[#374151] rounded-lg px-2 py-1 flex items-center gap-2 z-20 shadow-lg">
                <button type="button"
                        @click="togglePause()"
                        class="px-2.5 py-1 text-xs font-medium rounded transition-colors flex items-center gap-1 border border-[#374151]"
                        :class="isPaused ? 'bg-[#38bdf8]/20 border-[#38bdf8] text-[#38bdf8]' : 'bg-[#1f2937] hover:bg-[#374151] text-[#f9fafb]'"
                        title="Jeda atau lanjutkan video">
                    <i data-lucide="pause" x-show="!isPaused" class="w-3.5 h-3.5"></i>
                    <i data-lucide="play" x-show="isPaused" class="w-3.5 h-3.5"></i>
                    <span x-text="isPaused ? 'Lanjutkan' : 'Pause'">Pause</span>
                </button>

                <button type="button"
                        @click="toggleFullscreen()"
                        class="px-2.5 py-1 text-xs font-medium rounded bg-[#1f2937] hover:bg-[#374151] text-[#f9fafb] border border-[#374151] transition-colors flex items-center gap-1"
                        title="Mode Layar Penuh">
                    <i data-lucide="maximize-2" x-show="!isFullscreen" class="w-3.5 h-3.5"></i>
                    <i data-lucide="minimize-2" x-show="isFullscreen" class="w-3.5 h-3.5"></i>
                    <span>Fullscreen</span>
                </button>
            </nav>
        </section>

        {{-- Right: Attendance Sidebar (Presensi Terbaru) --}}
        <aside class="w-full md:w-[280px] lg:w-[300px] bg-[#111827] flex flex-col border-t md:border-t-0 md:border-l border-[#374151] shrink-0 h-full">
            <div class="p-3 border-b border-[#374151] flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-[#9ca3af]">Presensi Terbaru</span>
                <span class="text-[11px] font-mono px-2 py-0.5 rounded-full bg-[#1f2937] text-[#38bdf8] border border-[#374151]" x-text="filteredLogs.length">
                    {{ count($recentLogs) }}
                </span>
            </div>

            {{-- Direction Filter (Compact Segment) --}}
            <div class="px-3 py-1.5 border-b border-[#374151] flex items-center justify-between bg-[#0b0f19]/40">
                <div class="flex items-center gap-1 bg-[#1f2937] p-0.5 rounded border border-[#374151]">
                    <button type="button"
                            @click="filterDirection = 'all'"
                            class="px-2 py-0.5 text-[10px] rounded transition-colors cursor-pointer border-none"
                            :class="filterDirection === 'all' ? 'bg-[#374151] text-white font-semibold' : 'text-[#9ca3af] hover:text-white'">
                        Semua
                    </button>
                    <button type="button"
                            @click="filterDirection = 'in'"
                            class="px-2 py-0.5 text-[10px] rounded transition-colors cursor-pointer border-none"
                            :class="filterDirection === 'in' ? 'bg-[#10b981] text-white font-semibold' : 'text-[#9ca3af] hover:text-[#10b981]'">
                        Masuk
                    </button>
                    <button type="button"
                            @click="filterDirection = 'out'"
                            class="px-2 py-0.5 text-[10px] rounded transition-colors cursor-pointer border-none"
                            :class="filterDirection === 'out' ? 'bg-[#38bdf8] text-white font-semibold' : 'text-[#9ca3af] hover:text-[#38bdf8]'">
                        Keluar
                    </button>
                </div>

                <span class="text-[10px] text-[#6b7280] font-mono truncate" x-text="lastSyncTime ? 'Sync: ' + lastSyncTime : 'Live'">
                    Live
                </span>
            </div>

            {{-- Scrollable Log List --}}
            <div class="flex-1 overflow-y-auto custom-scroll p-2.5 flex flex-col gap-2">
                <template x-if="filteredLogs.length > 0">
                    <div class="flex flex-col gap-2">
                        <template x-for="log in filteredLogs" :key="log.id">
                            <div class="bg-[#0b0f19] border border-[#374151] hover:border-[#4b5563] rounded-md p-2.5 flex flex-col gap-1 transition-colors">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-xs font-semibold text-white truncate" x-text="log.name"></span>
                                    <span class="text-[10px] font-bold px-1.5 py-0.5 rounded uppercase shrink-0"
                                          :class="log.direction === 'in' ? 'bg-[#059669] text-white' : 'bg-[#2563eb] text-white'"
                                          x-text="log.direction === 'in' ? 'MASUK' : 'KELUAR'"></span>
                                </div>
                                <div class="flex items-center justify-between text-[11px] text-[#9ca3af] font-mono">
                                    <span x-text="'ID: ' + log.code"></span>
                                    <span x-text="log.time"></span>
                                </div>
                                <div x-show="log.score" class="text-[10px] text-[#6b7280] font-mono" x-text="'Match: ' + log.score"></div>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- Empty State (Exact match to screenshot) --}}
                <div x-show="filteredLogs.length === 0" class="flex flex-col items-center justify-center h-full text-[#6b7280] text-xs text-center p-4 leading-relaxed">
                    Belum ada presensi siswa terdeteksi.<br>
                    Silakan posisikan wajah di depan kamera.
                </div>
            </div>
        </aside>
    </main>

</div>
@endsection
