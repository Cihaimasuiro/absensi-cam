@extends('layouts.app')
@section('title', 'Live Kamera')

@section('header-actions')
    <div class="flex items-center gap-xs">
        <a href="{{ route('reports.index') }}" class="btn btn-utility">
            <i data-lucide="file-text" class="w-4 h-4"></i> Laporan
        </a>
        <a href="{{ route('students.index') }}" class="btn btn-utility">
            <i data-lucide="users" class="w-4 h-4"></i> Kelola Anggota
        </a>
    </div>
@endsection

@section('content')
<div x-data="{ streamIp: '{{ $streamIp }}' }" class="flex flex-col gap-lg">

    {{-- Executive Overview KPI Tiles (Clean Notion Document Style) --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-md">
        {{-- Present Today --}}
        <div class="card stat-tile animate-fade-up">
            <div class="stat-tile-label">
                <i data-lucide="user-check" class="w-3.5 h-3.5 text-accent-green"></i>
                Hadir Hari Ini
            </div>
            <div class="stat-tile-value text-accent-green">
                {{ number_format($presentToday) }}
            </div>
            <div class="stat-tile-hint">
                Dari total {{ number_format($totalStudents) }} anggota aktif
            </div>
        </div>

        {{-- Attendance Rate --}}
        <div class="card stat-tile animate-fade-up">
            <div class="stat-tile-label">
                <i data-lucide="activity" class="w-3.5 h-3.5 text-primary"></i>
                Tingkat Kehadiran
            </div>
            <div class="stat-tile-value text-primary">
                {{ $totalStudents > 0 ? round(($presentToday / $totalStudents) * 100, 1) : 0 }}%
            </div>
            <div class="stat-tile-hint">
                Presensi terverifikasi hari ini
            </div>
        </div>

        {{-- Enrolled Face Templates --}}
        <div class="card stat-tile animate-fade-up">
            <div class="stat-tile-label">
                <i data-lucide="scan-face" class="w-3.5 h-3.5 text-ink-muted"></i>
                Wajah Terdaftar
            </div>
            <div class="stat-tile-value text-ink">
                {{ number_format($totalEnrolled) }}
            </div>
            <div class="stat-tile-hint">
                Template biometrik aktif
            </div>
        </div>

        {{-- Online Devices --}}
        <div class="card stat-tile animate-fade-up">
            <div class="stat-tile-label">
                <i data-lucide="cpu" class="w-3.5 h-3.5 text-ink-muted"></i>
                Terminal Online
            </div>
            <div class="stat-tile-value {{ $devicesOnline > 0 ? 'text-accent-green' : 'text-ink-muted' }}">
                {{ number_format($devicesOnline) }}
            </div>
            <div class="stat-tile-hint">
                Edge Engine aktif (3 mnt)
            </div>
        </div>
    </div>

    {{-- Main Live Monitor Grid --}}
    <div class="grid grid-cols-1 lg:grid-cols-[1fr_360px] gap-lg">

        {{-- Live Camera Viewport --}}
        <div class="card p-md flex flex-col animate-fade-up">
            <div class="flex justify-between items-center mb-md pb-xs border-b border-hairline">
                <div class="flex items-center gap-xs">
                    <span class="live-dot"></span>
                    <span class="text-[13px] font-semibold text-ink uppercase tracking-wide">Live Camera Feed</span>
                </div>
                <div class="flex items-center gap-xs text-eyebrow text-ink-faint">
                    <i data-lucide="wifi" class="w-3 h-3"></i>
                    <select x-model="streamIp" @change="const img = document.getElementById('liveStreamImg'); if (img) img.style.display = 'block';" class="bg-transparent border-none text-ink-faint text-eyebrow focus:ring-0 p-0 cursor-pointer font-medium hover:text-ink transition-colors">
                        @if($devices->isEmpty())
                            <option value="{{ $streamIp }}">Local Edge Engine ({{ $streamIp }})</option>
                        @else
                            @foreach($devices as $device)
                                <option value="{{ $device->ip_address }}">{{ $device->name }} ({{ $device->ip_address ?: 'Belum diset IP' }})</option>
                            @endforeach
                            <option value="127.0.0.1">Localhost (127.0.0.1)</option>
                        @endif
                    </select>
                </div>
            </div>

            <div class="bg-black aspect-video relative rounded-sm overflow-hidden flex items-center justify-center group border border-hairline/50">
                <div class="absolute inset-0 flex flex-col items-center justify-center text-ink-faint z-0 gap-sm">
                    <i data-lucide="video-off" class="w-8 h-8 opacity-40"></i>
                    <span class="text-[13px] font-mono">Stream Standby / Menghubungkan...</span>
                </div>
                <img id="liveStreamImg"
                     :src="'http://' + streamIp + ':5000/video_feed'"
                     alt="Live Stream"
                     class="relative z-5 w-full h-full object-contain"
                     onerror="this.style.display='none'"
                     onload="this.style.display='block'">
            </div>

            <div class="flex justify-between items-center pt-xs mt-xs text-[11px] text-ink-faint font-mono">
                <span>PORT: 5000 (MJPEG DECOUPLED)</span>
                <span x-text="'HOST: ' + streamIp"></span>
            </div>
        </div>

        {{-- Real-Time Attendance Activity Feed --}}
        <div class="card p-md flex flex-col h-[520px] animate-fade-up">
            <div class="text-[13px] font-semibold text-ink mb-md pb-xs border-b border-hairline flex items-center justify-between">
                <span class="flex items-center gap-xs uppercase tracking-wide">
                    <i data-lucide="activity" class="w-[14px] h-[14px] text-accent-green"></i>
                    Aktivitas Terbaru
                </span>
                <span class="text-[11px] text-ink-faint font-normal font-mono">
                    {{ isset($recentActivity) ? $recentActivity->count() : 0 }} Presensi
                </span>
            </div>

            <div class="flex-1 overflow-y-auto custom-scroll pr-xs">
                @if(isset($recentActivity) && $recentActivity->count() > 0)
                    <div class="flex flex-col gap-[2px]">
                        @foreach($recentActivity as $log)
                            <div class="animate-row flex justify-between items-center py-xs px-xs rounded-xs hover:bg-canvas-soft transition-colors border-b border-hairline/60 last:border-none">
                                <div class="flex items-center gap-sm min-w-0 pr-xs">
                                    <div class="w-7 h-7 rounded-full bg-canvas flex items-center justify-center border border-hairline shrink-0 text-ink-faint font-semibold text-[11px]">
                                        {{ substr($log->student->name ?? '?', 0, 1) }}
                                    </div>
                                    <div class="min-w-0">
                                        <div class="text-[13px] font-medium text-ink truncate leading-tight">
                                            {{ $log->student->name ?? 'Tidak Dikenal' }}
                                        </div>
                                        <div class="text-[11px] text-ink-muted font-mono leading-tight">
                                            {{ $log->student->code ?? '-' }}
                                        </div>
                                    </div>
                                </div>
                                <div class="text-right shrink-0">
                                    <div class="text-[11px] font-semibold uppercase {{ $log->direction === 'in' ? 'text-accent-green' : 'text-primary' }} flex items-center justify-end gap-[3px]">
                                        {{ $log->direction }}
                                        <i data-lucide="{{ $log->direction === 'in' ? 'log-in' : 'log-out' }}" class="w-3 h-3"></i>
                                    </div>
                                    <div class="text-[11px] text-ink-faint font-mono">
                                        {{ $log->captured_at ? $log->captured_at->format('H:i') : '--:--' }}
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="flex flex-col items-center justify-center h-full text-ink-faint gap-sm">
                        <i data-lucide="history" class="w-6 h-6 opacity-40"></i>
                        <div class="text-caption">Belum ada aktivitas presensi hari ini.</div>
                    </div>
                @endif
            </div>

            <div class="pt-sm border-t border-hairline text-center mt-auto">
                <a href="{{ route('reports.index') }}" class="text-[12px] text-primary font-medium no-underline hover:underline flex items-center justify-center gap-xs">
                    Lihat Seluruh Log Presensi <i data-lucide="arrow-right" class="w-3 h-3"></i>
                </a>
            </div>
        </div>

    </div>

</div>
@endsection
