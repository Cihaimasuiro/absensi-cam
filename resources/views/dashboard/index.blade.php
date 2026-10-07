@extends('layouts.app')
@section('title', 'Dashboard')

@section('header-actions')
    <a href="{{ route('students.index') }}" class="btn btn-utility">
        <i data-lucide="users" class="w-4 h-4"></i> Kelola Anggota
    </a>
@endsection

@section('content')
<div x-data="{ streamIp: '{{ $streamIp }}' }" class="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-lg">

    {{-- Live Camera --}}
    <div class="card p-md flex flex-col">
        <div class="flex justify-between items-center mb-md pb-xs border-b border-hairline">
            <span class="text-[14px] font-semibold text-ink flex items-center gap-xs">
                <i data-lucide="video" class="w-[14px] h-[14px] text-primary"></i>
                Live Camera Stream
            </span>
            <span class="text-eyebrow text-ink-faint flex items-center gap-xs">
                <i data-lucide="wifi" class="w-[12px] h-[12px]"></i>
                <select x-model="streamIp" class="bg-transparent border-none text-ink-faint text-eyebrow focus:ring-0 p-0 cursor-pointer">
                    @if($devices->isEmpty())
                        <option value="{{ $streamIp }}">Local Edge Engine ({{ $streamIp }})</option>
                    @else
                        @foreach($devices as $device)
                            <option value="{{ $device->ip_address }}">{{ $device->name }} ({{ $device->ip_address ?: 'Belum diset IP' }})</option>
                        @endforeach
                        <option value="127.0.0.1">Localhost (127.0.0.1)</option>
                    @endif
                </select>
            </span>
        </div>
        <div class="bg-black aspect-video relative rounded-sm overflow-hidden flex items-center justify-center group">
            <div class="absolute inset-0 flex flex-col items-center justify-center text-ink-faint z-0 gap-sm">
                <i data-lucide="video-off" class="w-8 h-8 opacity-50"></i>
                <span class="text-[13px]">Stream Offline / Loading...</span>
            </div>
            <img :src="'http://' + streamIp + ':5000/video_feed'"
                 alt="Live Stream"
                 class="relative z-5 w-full h-full object-contain"
                 onerror="this.style.display='none'"
                 onload="this.style.display='block'">
        </div>
    </div>

    {{-- Activity Log --}}
    <div class="card p-md flex flex-col h-[520px]">
        <div class="text-[14px] font-semibold text-ink mb-md pb-xs border-b border-hairline flex items-center gap-xs">
            <i data-lucide="activity" class="w-[14px] h-[14px] text-accent-green"></i>
            Log Aktivitas Terbaru
        </div>
        <div class="flex-1 overflow-y-auto custom-scroll pr-xs">
            @if(isset($recentLogs) && $recentLogs->count() > 0)
                @foreach($recentLogs as $log)
                    <div class="flex justify-between py-xs border-b border-hairline last:border-0 hover:bg-canvas-soft transition-colors px-xs -mx-xs rounded-xs">
                        <div class="flex items-center gap-sm">
                            <div class="w-8 h-8 rounded-full bg-canvas flex items-center justify-center border border-hairline shrink-0">
                                <i data-lucide="user" class="w-4 h-4 text-ink-faint"></i>
                            </div>
                            <div>
                                <div class="text-[13px] font-medium text-ink">{{ $log->student->name ?? 'Unknown' }}</div>
                                <div class="text-caption text-ink-muted">{{ $log->student->code ?? '-' }}</div>
                            </div>
                        </div>
                        <div class="text-right flex flex-col justify-center">
                            <div class="text-[11px] font-semibold uppercase {{ $log->direction === 'in' ? 'text-accent-green' : 'text-ink-muted' }} flex items-center justify-end gap-[4px]">
                                {{ $log->direction }}
                                <i data-lucide="{{ $log->direction === 'in' ? 'log-in' : 'log-out' }}" class="w-3 h-3"></i>
                            </div>
                            <div class="text-caption text-ink-faint flex items-center justify-end gap-[4px]">
                                <i data-lucide="clock" class="w-3 h-3"></i>
                                {{ $log->scanned_at->format('H:i') }}
                            </div>
                        </div>
                    </div>
                @endforeach
            @else
                <div class="flex flex-col items-center justify-center h-full text-ink-faint gap-sm">
                    <i data-lucide="history" class="w-6 h-6 opacity-50"></i>
                    <div class="text-caption">Belum ada aktivitas.</div>
                </div>
            @endif
        </div>
        <div class="pt-sm border-t border-hairline text-center mt-auto">
            <a href="{{ route('reports.index') }}" class="text-[13px] text-primary font-medium no-underline hover:underline flex items-center justify-center gap-xs">
                Lihat Semua Laporan <i data-lucide="arrow-right" class="w-3 h-3"></i>
            </a>
        </div>
    </div>

</div>
@endsection
