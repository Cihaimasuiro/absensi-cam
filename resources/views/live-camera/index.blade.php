@extends('layouts.app')
@section('title', 'Live Kamera & Monitor Presensi')

@section('header-actions')
    <div class="flex items-center gap-xs">
        <a href="{{ route('reports.index') }}" class="btn btn-secondary">
            <i data-lucide="file-text" class="w-4 h-4"></i> Laporan
        </a>
        <a href="{{ route('students.index') }}" class="btn btn-secondary">
            <i data-lucide="users" class="w-4 h-4"></i> Kelola Anggota
        </a>
        <a href="{{ route('devices.index') }}" class="btn btn-secondary">
            <i data-lucide="cpu" class="w-4 h-4"></i> Perangkat Edge
        </a>
    </div>
@endsection

@section('content')
<div x-data="cameraFeedData('{{ $streamIp }}', {{ $presentToday }}, @json($recentLogs))" class="flex flex-col gap-md">

    {{-- Main Workspace Grid --}}
    <div class="grid grid-cols-1 lg:grid-cols-[1fr_380px] gap-md items-start">

        {{-- Left: Modern CCTV Viewport Frame --}}
        <div x-ref="cameraViewport" class="card p-md flex flex-col gap-sm bg-surface animate-fade-up">
            
            {{-- HUD Top Bar --}}
            <div class="flex flex-wrap justify-between items-center pb-xs border-b border-hairline gap-xs">
                
                {{-- Status & Host Selector --}}
                <div class="flex items-center gap-sm">
                    <button type="button" @click="reconnect()" class="flex items-center gap-xs bg-transparent border-none p-0 cursor-pointer text-left group" title="Klik untuk memuat ulang koneksi">
                        <span class="live-dot" :class="{
                            'live-dot-online': status === 'online',
                            'live-dot-connecting': status === 'connecting',
                            'live-dot-offline': status === 'offline'
                        }"></span>
                        <span class="text-[11.5px] font-semibold tracking-wide uppercase font-mono text-ink group-hover:text-primary transition-colors"
                              x-text="status === 'online' ? 'LIVE MONITOR ACTIVE' : (status === 'connecting' ? 'CONNECTING...' : 'FEED STANDBY / OFFLINE')">
                            LIVE MONITOR ACTIVE
                        </span>
                    </button>

                    <div class="flex items-center gap-xs bg-canvas-soft border border-hairline rounded-sm px-xs py-[2px]">
                        <i data-lucide="radio" class="w-3 h-3 text-ink-faint"></i>
                        <select x-model="streamIp" @change="reconnect()" class="bg-transparent border-none text-[11px] text-ink font-medium focus:ring-0 p-0 cursor-pointer">
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

                {{-- Right Controls: Clock & Action Buttons --}}
                <div class="flex items-center gap-xs">
                    <span class="hidden sm:inline-block font-mono text-[11px] text-ink-muted bg-canvas-soft border border-hairline px-xs py-[3px] rounded-sm" x-text="currentTime">
                        --:--:-- WIB
                    </span>

                    <button type="button" @click="captureSnapshot()" class="btn-icon btn-secondary" title="Ambil snapshot gambar (JPEG)">
                        <i data-lucide="camera" class="w-3.5 h-3.5"></i>
                    </button>

                    <button type="button" @click="reconnect()" class="btn-icon btn-secondary" :class="{'animate-spin': isRefreshing}" title="Muat ulang stream">
                        <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i>
                    </button>

                    <button type="button" @click="toggleFullscreen()" class="btn-icon btn-secondary" title="Mode Layar Penuh">
                        <i data-lucide="maximize-2" x-show="!isFullscreen" class="w-3.5 h-3.5"></i>
                        <i data-lucide="minimize-2" x-show="isFullscreen" class="w-3.5 h-3.5"></i>
                    </button>
                </div>
            </div>

            {{-- 16:9 Aspect Video Container with Viewfinder Overlay --}}
            <div class="relative bg-black rounded-sm overflow-hidden aspect-video flex items-center justify-center border border-hairline/60">
                
                {{-- Viewfinder Corner Brackets --}}
                <div class="absolute top-2 left-2 w-3.5 h-3.5 border-t-2 border-l-2 border-white/30 z-20 pointer-events-none"></div>
                <div class="absolute top-2 right-2 w-3.5 h-3.5 border-t-2 border-r-2 border-white/30 z-20 pointer-events-none"></div>
                <div class="absolute bottom-2 left-2 w-3.5 h-3.5 border-b-2 border-l-2 border-white/30 z-20 pointer-events-none"></div>
                <div class="absolute bottom-2 right-2 w-3.5 h-3.5 border-b-2 border-r-2 border-white/30 z-20 pointer-events-none"></div>

                {{-- Offline / Connecting State Display --}}
                <div x-show="status !== 'online'" class="absolute inset-0 flex flex-col items-center justify-center text-ink-faint z-0 gap-xs p-md text-center bg-black/95">
                    <i data-lucide="video-off" class="w-8 h-8 opacity-40 mb-1"></i>
                    <span class="text-[12px] font-mono text-ink-muted" x-text="status === 'connecting' ? 'Menghubungkan ke Edge Engine...' : 'Kamera Standby / Belum Terhubung'"></span>
                    <span class="text-[11px] font-mono text-ink-faint" x-text="'Endpoint: http://' + streamIp + ':5000/video_feed'"></span>
                    <div class="flex items-center gap-xs mt-xs">
                        <button type="button" @click="reconnect()" class="btn btn-secondary btn-sm">
                            <i data-lucide="refresh-cw" class="w-3 h-3"></i> Hubungkan Ulang
                        </button>
                        <a href="{{ route('devices.index') }}" class="btn btn-secondary btn-sm">
                            <i data-lucide="cpu" class="w-3 h-3"></i> Cek Terminal Edge
                        </a>
                    </div>
                </div>

                {{-- Live Image MJPEG Stream --}}
                <img id="liveStreamImg"
                     :src="'http://' + streamIp + ':5000/video_feed'"
                     alt="Live Stream"
                     class="relative z-5 w-full h-full object-contain"
                     x-on:load="onStreamLoad()"
                     x-on:error="onStreamError()">

                {{-- Face Detection Bounding Box Canvas --}}
                <canvas x-ref="overlayCanvas" class="absolute inset-0 w-full h-full pointer-events-none z-10"></canvas>
            </div>

            {{-- HUD Bottom Diagnostics Strip --}}
            <div class="flex flex-wrap justify-between items-center pt-xs text-[11px] font-mono text-ink-faint border-t border-hairline/60">
                <div class="flex items-center gap-sm">
                    <span>PORT 5000 (MJPEG)</span>
                    <span class="hidden sm:inline">•</span>
                    <span class="hidden sm:inline" x-text="'HOST: ' + streamIp"></span>
                    <span class="hidden md:inline">•</span>
                    <span class="hidden md:inline" x-text="status === 'online' ? 'FRAME SINK READY' : 'AWAITING FRAME'"></span>
                </div>
            </div>

        </div>

        {{-- Right: Real-Time Attendance Activity Ticker --}}
        <div class="card p-md flex flex-col h-[560px] bg-surface animate-fade-up">
            
            {{-- Header with Filter Tabs --}}
            <div class="pb-xs border-b border-hairline flex flex-col gap-xs mb-xs">
                <div class="flex items-center justify-between">
                    <span class="flex items-center gap-xs text-[12.5px] font-semibold text-ink uppercase tracking-wide">
                        <i data-lucide="activity" class="w-3.5 h-3.5 text-accent-green"></i>
                        Aktivitas Presensi
                    </span>
                    <span class="text-[11px] text-ink-faint font-mono" x-text="filteredLogs.length + ' Entri'">
                        {{ count($recentLogs) }} Entri
                    </span>
                </div>

                {{-- Direction Segment Filter & Sync Indicator --}}
                <div class="flex items-center justify-between pt-xxs">
                    <div class="flex items-center gap-[2px] bg-canvas-soft p-[2px] rounded-xs border border-hairline">
                        <button type="button"
                                @click="filterDirection = 'all'"
                                class="px-xs py-[2px] text-[10.5px] rounded-xs transition-colors cursor-pointer border-none"
                                :class="filterDirection === 'all' ? 'bg-white text-ink font-semibold shadow-xs' : 'bg-transparent text-ink-muted hover:text-ink'">
                            Semua
                        </button>
                        <button type="button"
                                @click="filterDirection = 'in'"
                                class="px-xs py-[2px] text-[10.5px] rounded-xs transition-colors cursor-pointer border-none"
                                :class="filterDirection === 'in' ? 'bg-white text-accent-green font-semibold shadow-xs' : 'bg-transparent text-ink-muted hover:text-ink'">
                            Masuk
                        </button>
                        <button type="button"
                                @click="filterDirection = 'out'"
                                class="px-xs py-[2px] text-[10.5px] rounded-xs transition-colors cursor-pointer border-none"
                                :class="filterDirection === 'out' ? 'bg-white text-primary font-semibold shadow-xs' : 'bg-transparent text-ink-muted hover:text-ink'">
                            Keluar
                        </button>
                    </div>

                    <span class="text-[10px] text-ink-faint font-mono truncate" x-text="lastSyncTime ? 'Sinkron: ' + lastSyncTime : 'Live Polling'">
                        Live Polling
                    </span>
                </div>
            </div>

            {{-- Real-Time Dynamic List --}}
            <div class="flex-1 overflow-y-auto custom-scroll pr-xs py-xxs">
                <template x-if="filteredLogs.length > 0">
                    <div class="flex flex-col gap-[2px]">
                        <template x-for="log in filteredLogs" :key="log.id">
                            <div class="flex items-center justify-between py-xs px-xs rounded-xs hover:bg-canvas-soft transition-colors border-b border-hairline/60 last:border-none">
                                <div class="flex items-center gap-xs min-w-0 pr-xs">
                                    <div class="w-7 h-7 rounded-full bg-canvas-soft border border-hairline flex items-center justify-center font-semibold text-[11px] text-ink shrink-0"
                                         x-text="log.initial">
                                    </div>
                                    <div class="min-w-0">
                                        <div class="text-[12.5px] font-medium text-ink truncate leading-tight" x-text="log.name"></div>
                                        <div class="flex items-center gap-xs text-[10.5px] text-ink-muted font-mono leading-tight">
                                            <span x-text="log.code"></span>
                                            <span x-show="log.device" class="text-ink-faint truncate" x-text="'• ' + log.device"></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-right shrink-0">
                                    <div class="text-[11px] font-semibold uppercase flex items-center justify-end gap-[3px]"
                                         :class="log.direction === 'in' ? 'text-accent-green' : 'text-primary'">
                                        <span x-text="log.direction === 'in' ? 'MASUK' : 'KELUAR'"></span>
                                        <i data-lucide="log-in" x-show="log.direction === 'in'" class="w-3 h-3"></i>
                                        <i data-lucide="log-out" x-show="log.direction === 'out'" class="w-3 h-3"></i>
                                    </div>
                                    <div class="text-[10.5px] text-ink-faint font-mono" x-text="log.time"></div>
                                    <div x-show="log.score" class="text-[9.5px] text-ink-faint font-mono" x-text="'Match ' + log.score"></div>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- Empty State --}}
                <div x-show="filteredLogs.length === 0" class="flex flex-col items-center justify-center h-full text-ink-faint gap-xs p-md text-center">
                    <i data-lucide="history" class="w-6 h-6 opacity-40 mb-1"></i>
                    <span class="text-caption">Belum ada aktivitas presensi pada filter ini.</span>
                </div>
            </div>

            {{-- Footer Link to Full Report --}}
            <div class="pt-xs border-t border-hairline text-center mt-auto">
                <a href="{{ route('reports.index') }}" class="btn-link justify-center text-[11.5px] py-xxs">
                    <span>Lihat Seluruh Log di Laporan</span> <i data-lucide="arrow-right" class="w-3 h-3"></i>
                </a>
            </div>

        </div>

    </div>

</div>
@endsection
