@extends('layouts.app')
@section('title', 'Smart Absensi')

@section('content')
<script id="recent-logs-data" type="application/json">@json($recentLogs)</script>
<div x-data="cameraFeedData('{{ $streamIp }}', {{ (int)$presentToday }})" class="flex flex-col h-[calc(100vh-56px)] bg-canvas-soft text-ink overflow-hidden select-none">

    {{-- Top Command Bar: Bulletproof Non-Wrapping Layout --}}
    <header class="h-[52px] px-4 bg-white border-b border-hairline flex items-center justify-between gap-3 shrink-0 shadow-xs z-20">
        
        {{-- Left Cluster: Brand, Status, and Constrained Device Selector --}}
        <div class="flex items-center gap-2.5 shrink-0 min-w-0">

            {{-- Status Indicator Pill --}}
            <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold border whitespace-nowrap shrink-0 transition-colors"
                 :class="{
                     'bg-emerald-50 border-emerald-200 text-emerald-700': status === 'online',
                     'bg-amber-50 border-amber-200 text-amber-700': status === 'connecting',
                     'bg-rose-50 border-rose-200 text-rose-700': status === 'offline'
                 }">
                <span class="w-2 h-2 rounded-full shrink-0 transition-colors" :class="{
                    'bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.6)] animate-pulse': status === 'online',
                    'bg-amber-500': status === 'connecting',
                    'bg-rose-500': status === 'offline'
                }"></span>
                <span class="text-[11px] font-mono tracking-wide uppercase whitespace-nowrap"
                      x-text="status === 'online' ? 'Online' : (status === 'connecting' ? 'Menghubungkan...' : 'Offline')">
                    Online
                </span>
            </div>

            {{-- Device Selector: Fixed Max Width with Ellipsis to prevent layout blowouts --}}
            <div class="flex items-center gap-1.5 bg-canvas-soft border border-hairline rounded-md px-2.5 py-1 text-xs text-ink max-w-[190px] sm:max-w-[240px] shrink-0" title="Pilih perangkat kamera edge">
                <i data-lucide="radio" class="w-3.5 h-3.5 text-ink-muted shrink-0"></i>
                <select x-model="streamIp"
                        @change="reconnect()"
                        class="bg-transparent border-none text-xs text-ink font-medium focus:ring-0 p-0 cursor-pointer truncate w-full">
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

        {{-- Center Telemetry Strip: Responsive Monospace Data (Hides cleanly on small viewports) --}}
        <div class="hidden xl:flex items-center gap-4 text-xs font-mono text-ink-muted whitespace-nowrap shrink-0 tabular-nums">
            <span class="flex items-center gap-1">
                <span>HOST:</span>
                <strong class="text-primary font-semibold" x-text="streamIp + ':5000'"></strong>
            </span>
            <span class="flex items-center gap-1">
                <span>HADIR HARI INI:</span>
                <strong class="text-emerald-600 font-semibold" x-text="presentCount + ' Siswa'"></strong>
            </span>
            <span class="flex items-center gap-1">
                <span>WAKTU:</span>
                <strong class="text-ink font-semibold" x-text="currentTime"></strong>
            </span>
        </div>

        {{-- Right Actions: Clean Button Group with explicit non-wrapping --}}
        <div class="flex items-center gap-1.5 shrink-0">
            <button type="button"
                    @click="reconnect()"
                    class="btn btn-secondary text-xs font-medium px-2.5 py-1 rounded-md border border-hairline shadow-xs flex items-center gap-1.5 whitespace-nowrap cursor-pointer hover:bg-slate-50 transition-colors"
                    title="Muat ulang aliran kamera">
                <i data-lucide="refresh-cw" class="w-3.5 h-3.5 text-ink-muted" :class="{'animate-spin': isRefreshing}"></i>
                <span class="hidden sm:inline">Refresh</span>
            </button>

            <button type="button"
                    @click="captureSnapshot()"
                    class="btn btn-secondary text-xs font-medium px-2.5 py-1 rounded-md border border-hairline shadow-xs flex items-center gap-1.5 whitespace-nowrap cursor-pointer hover:bg-slate-50 transition-colors"
                    title="Unduh snapshot gambar saat ini (JPEG)">
                <i data-lucide="camera" class="w-3.5 h-3.5 text-ink-muted"></i>
                <span class="hidden sm:inline">Snapshot</span>
            </button>

            <button type="button"
                    @click="toggleFullscreen()"
                    class="btn btn-secondary text-xs font-medium px-2.5 py-1 rounded-md border border-hairline shadow-xs flex items-center gap-1.5 whitespace-nowrap cursor-pointer hover:bg-slate-50 transition-colors"
                    title="Mode Layar Penuh">
                <i data-lucide="maximize-2" x-show="!isFullscreen" class="w-3.5 h-3.5 text-ink-muted"></i>
                <i data-lucide="minimize-2" x-show="isFullscreen" class="w-3.5 h-3.5 text-ink-muted"></i>
            </button>
        </div>
    </header>

    {{-- Main Workspace Layout --}}
    <main class="flex-1 flex flex-col lg:flex-row overflow-hidden relative min-h-0">

        {{-- Left: Surveillance Monitor Stage --}}
        <section id="cameraViewport"
                 x-ref="cameraViewport"
                 class="flex-1 bg-slate-100/70 p-3 sm:p-4 flex flex-col items-center justify-center min-w-0 overflow-hidden relative">
            
            {{-- Surveillance Bezel Display --}}
            <div class="relative w-full h-full max-w-full rounded-xl overflow-hidden bg-slate-950 border border-slate-800 shadow-sm flex items-center justify-center">
                
                <div class="absolute top-3 right-3 z-10 hidden sm:flex items-center gap-2 px-2.5 py-1 rounded bg-slate-900/85 backdrop-blur-md border border-slate-800 text-[11px] font-mono text-slate-400 pointer-events-none select-none">
                    <span x-text="'HOST: ' + streamIp"></span>
                    <span class="text-slate-600">•</span>
                    <span class="text-slate-300">MJPEG</span>
                </div>

                {{-- Offline / Connecting Standby Display --}}
                <div x-show="status !== 'online'"
                     class="absolute inset-0 flex flex-col items-center justify-center text-slate-400 gap-4 p-6 bg-slate-950/95 z-20 text-center select-none">
                    <div class="w-14 h-14 rounded-full bg-slate-900 border border-slate-800 flex items-center justify-center text-slate-400 shadow-sm shrink-0">
                        <i data-lucide="video-off" class="w-6 h-6 opacity-60"></i>
                    </div>

                    <div class="w-full max-w-[420px] px-4 mx-auto flex flex-col items-center text-center gap-2">
                        <h3 class="text-sm sm:text-base font-semibold text-slate-100 whitespace-nowrap text-center"
                            x-text="status === 'connecting' ? 'Menghubungkan ke Kamera Edge...' : 'Kamera Gerbang Belum Terhubung'">
                        </h3>
                        <p class="text-xs font-mono text-slate-400 whitespace-nowrap overflow-hidden text-ellipsis max-w-full text-center"
                           x-text="'Mencoba terhubung ke http://' + streamIp + ':5000/stream'">
                        </p>
                    </div>

                    <button type="button"
                            @click="reconnect()"
                            class="mt-2 px-4 py-2 text-xs font-medium rounded-md bg-slate-800 hover:bg-slate-700 text-white border border-slate-700 transition-colors flex items-center gap-2 cursor-pointer shadow-sm whitespace-nowrap">
                        <i data-lucide="refresh-cw" class="w-3.5 h-3.5" :class="{'animate-spin': isRefreshing}"></i>
                        <span>Hubungkan Ulang</span>
                    </button>
                </div>

                {{-- Live Camera Image Feed (Native bounding boxes burned by Edge Engine) --}}
                <img id="liveStreamImg"
                     src="http://{{ $streamIp }}:5000/stream"
                     :src="'http://' + streamIp + ':5000/stream'"
                     alt="Live Stream"
                     class="max-w-full max-h-full w-auto h-auto object-contain rounded"
                     x-on:load="onStreamLoad()"
                     x-on:error="onStreamError()">

                {{-- Floating Control Dock (Visible only when live stream is active) --}}
                <nav x-show="status === 'online'"
                     class="absolute bottom-4 left-1/2 -translate-x-1/2 bg-white/95 backdrop-blur-md border border-hairline shadow-lg rounded-full px-3 py-1.5 flex items-center gap-2 z-20 transition-all hover:bg-white">
                    <button type="button"
                            @click="togglePause()"
                            class="px-2.5 py-1 text-xs font-medium rounded-full transition-colors flex items-center gap-1.5 cursor-pointer border border-hairline"
                            :class="isPaused ? 'bg-primary text-white border-primary shadow-xs' : 'bg-slate-50 hover:bg-slate-100 text-ink'"
                            title="Jeda atau lanjutkan video">
                        <i data-lucide="pause" x-show="!isPaused" class="w-3.5 h-3.5"></i>
                        <i data-lucide="play" x-show="isPaused" class="w-3.5 h-3.5"></i>
                        <span x-text="isPaused ? 'Lanjutkan' : 'Jeda'">Jeda</span>
                    </button>

                    <div class="h-3 w-px bg-slate-200"></div>

                    <button type="button"
                            @click="captureSnapshot()"
                            class="px-2.5 py-1 text-xs font-medium rounded-full bg-slate-50 hover:bg-slate-100 text-ink border border-hairline transition-colors flex items-center gap-1.5 cursor-pointer"
                            title="Unduh snapshot frame JPEG">
                        <i data-lucide="camera" class="w-3.5 h-3.5 text-ink-muted"></i>
                        <span>Snapshot</span>
                    </button>

                    <div class="h-3 w-px bg-slate-200"></div>

                    <button type="button"
                            @click="toggleFullscreen()"
                            class="px-2.5 py-1 text-xs font-medium rounded-full bg-slate-50 hover:bg-slate-100 text-ink border border-hairline transition-colors flex items-center gap-1.5 cursor-pointer"
                            title="Layar Penuh">
                        <i data-lucide="maximize-2" x-show="!isFullscreen" class="w-3.5 h-3.5 text-ink-muted"></i>
                        <i data-lucide="minimize-2" x-show="isFullscreen" class="w-3.5 h-3.5 text-ink-muted"></i>
                        <span>Layar Penuh</span>
                    </button>
                </nav>

            </div>
        </section>

        {{-- Right: Clean Attendance Activity Sidebar --}}
        <aside class="w-full lg:w-[320px] xl:w-[340px] bg-white flex flex-col border-t lg:border-t-0 lg:border-l border-hairline shrink-0 h-full shadow-xs select-none">
            
            {{-- Sidebar Header --}}
            <div class="px-4 py-3 border-b border-hairline flex items-center justify-between bg-white shrink-0">
                <div class="flex items-center gap-2">
                    <i data-lucide="activity" class="w-4 h-4 text-emerald-600 shrink-0"></i>
                    <span class="text-xs font-bold uppercase tracking-wider text-ink whitespace-nowrap">Presensi Terbaru</span>
                </div>
                <span class="text-[11px] font-mono font-semibold px-2 py-0.5 rounded-full bg-slate-100 text-primary border border-hairline whitespace-nowrap"
                      x-text="filteredLogs.length + ' Entri'">
                    {{ count($recentLogs) }} Entri
                </span>
            </div>

            {{-- Segment Filter Bar with Auto-Sync Indicator --}}
            <div class="px-3.5 py-2 border-b border-hairline flex items-center justify-between gap-2 bg-canvas-soft shrink-0">
                <div class="flex items-center gap-1 bg-white p-0.5 rounded-md border border-hairline shadow-xs">
                    <button type="button"
                            @click="filterDirection = 'all'"
                            class="px-2 py-0.5 text-[10.5px] rounded transition-colors cursor-pointer border-none whitespace-nowrap"
                            :class="filterDirection === 'all' ? 'bg-slate-100 text-ink font-semibold shadow-xs' : 'text-ink-muted hover:text-ink'">
                        Semua
                    </button>
                    <button type="button"
                            @click="filterDirection = 'in'"
                            class="px-2 py-0.5 text-[10.5px] rounded transition-colors cursor-pointer border-none whitespace-nowrap"
                            :class="filterDirection === 'in' ? 'bg-emerald-600 text-white font-semibold shadow-xs' : 'text-ink-muted hover:text-emerald-700'">
                        Masuk
                    </button>
                    <button type="button"
                            @click="filterDirection = 'out'"
                            class="px-2 py-0.5 text-[10.5px] rounded transition-colors cursor-pointer border-none whitespace-nowrap"
                            :class="filterDirection === 'out' ? 'bg-primary text-white font-semibold shadow-xs' : 'text-ink-muted hover:text-primary'">
                        Keluar
                    </button>
                </div>

                <span class="text-[10px] text-ink-muted font-mono truncate"
                      x-text="lastSyncTime ? 'Sync: ' + lastSyncTime : 'Live (5s)'">
                    Live (5s)
                </span>
            </div>

            {{-- Dynamic Scrollable Log List --}}
            <div class="flex-1 overflow-y-auto custom-scroll p-3 flex flex-col gap-2 bg-canvas-soft/30">
                <template x-if="filteredLogs.length > 0">
                    <div class="flex flex-col gap-2">
                        <template x-for="log in filteredLogs" :key="log.id">
                            <div class="bg-white border border-hairline hover:border-slate-300 rounded-md p-3 shadow-xs transition-colors flex flex-col gap-1.5">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="flex items-center gap-2 min-w-0 flex-1">
                                        <div class="w-6 h-6 rounded-full bg-slate-100 border border-slate-200 text-slate-700 text-[10px] font-bold flex items-center justify-center shrink-0"
                                             x-text="log.initial">
                                        </div>
                                        <span class="text-[12.5px] font-semibold text-ink truncate" x-text="log.name"></span>
                                    </div>
                                    <span class="text-[10px] font-bold px-1.5 py-0.5 rounded uppercase shrink-0 border whitespace-nowrap"
                                          :class="log.direction === 'in' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-blue-50 text-blue-700 border-blue-200'"
                                          x-text="log.direction === 'in' ? 'MASUK' : 'KELUAR'">
                                    </span>
                                </div>
                                <div class="flex items-center justify-between text-[11px] text-ink-muted font-mono pt-0.5">
                                    <span class="truncate" x-text="'ID: ' + log.code"></span>
                                    <span class="tabular-nums shrink-0" x-text="log.time"></span>
                                </div>
                                <div x-show="log.score" class="text-[10px] text-ink-faint font-mono" x-text="'Akurasi ' + log.score"></div>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- Composed Empty State --}}
                <div x-show="filteredLogs.length === 0"
                     class="flex-1 flex flex-col items-center justify-center text-center p-6 gap-2 text-ink-muted">
                    <div class="w-10 h-10 rounded-full bg-slate-100 border border-slate-200 flex items-center justify-center text-slate-400 mb-1">
                        <i data-lucide="history" class="w-5 h-5 opacity-70"></i>
                    </div>
                    <span class="text-xs font-semibold text-ink">Belum Ada Presensi Hari Ini</span>
                    <p class="text-[11px] text-ink-muted max-w-[220px] leading-relaxed">
                        Siswa yang terdeteksi di kamera gerbang akan otomatis tercatat di sini secara real-time.
                    </p>
                </div>
            </div>

            {{-- Sidebar Footer with Quick Report Link --}}
            <div class="px-4 py-2.5 border-t border-hairline bg-white flex items-center justify-center text-xs shrink-0">
                <a href="{{ route('reports.index') }}" class="text-primary hover:text-primary-hover font-medium flex items-center gap-1.5 group">
                    <span>Buka Laporan Presensi</span>
                    <i data-lucide="arrow-right" class="w-3.5 h-3.5 group-hover:translate-x-0.5 transition-transform"></i>
                </a>
            </div>
        </aside>
    </main>

</div>
@endsection
