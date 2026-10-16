import Alpine from '@alpinejs/csp'
import Lenis from 'lenis'
import gsap from 'gsap'
import {
    ScanFace, Users, Video, Wifi, VideoOff, Activity, User, LogIn, LogOut,
    Clock, History, ArrowRight, PowerOff, Cpu, Key, LayoutDashboard, FileText,
    Building, Menu, Home, CheckCircle, AlertCircle, Search, Filter, Download,
    FileSpreadsheet, Inbox, X, Plus, Edit, Trash2, ChevronDown, ChevronLeft, Edit3, Edit2,
    AlertTriangle, FolderUp, Upload, CheckCircle2, Camera, UserPlus, UploadCloud,
    UserCheck, RefreshCw, PlusCircle, List, Loader2, Radio, Check, Maximize2, Minimize2,
    Sparkles, ShieldCheck, Eye, createIcons
} from 'lucide'

Alpine.data('layoutData', () => ({
    sidebarOpen: false,
    sidebarCollapsed: localStorage.getItem('sidebarCollapsed') === 'true',

    toggleSidebar() {
        if (window.innerWidth < 768) {
            this.sidebarOpen = !this.sidebarOpen;
        } else {
            this.sidebarCollapsed = !this.sidebarCollapsed;
            localStorage.setItem('sidebarCollapsed', this.sidebarCollapsed);
            
            if (this.sidebarCollapsed) {
                document.documentElement.classList.add('sidebar-collapsed');
            } else {
                document.documentElement.classList.remove('sidebar-collapsed');
            }
        }
    },

    confirmMessage: '',
    confirmAction: null,
    confirmSubmit(e) {
        e.preventDefault();
        this.confirmMessage = e.target.getAttribute('data-confirm') || 'Anda yakin?';
        this.confirmAction = () => {
            e.target.submit();
        };
        this.$refs.globalConfirmModal.showModal();
    },
    executeConfirm() {
        if (this.confirmAction) {
            this.confirmAction();
        }
        this.$refs.globalConfirmModal.close();
    }
}));

Alpine.data('schoolsData', () => ({
    openRows: [],
    toggleRow(id) {
        if (this.openRows.includes(id)) {
            this.openRows = this.openRows.filter(r => r !== id);
        } else {
            this.openRows.push(id);
        }
    },
    editSchoolData: {},
    editClassroomData: {},
    createClassroomSchoolId: null,
    bulkEditSchoolId: null,
    confirmRetroactive: false,

    openEditSchool(e) {
        const data = JSON.parse(e.currentTarget.dataset.school);
        this.editSchoolData = data;
        this.$refs.editModal.showModal();
        this.$refs.editForm.action = '/schools/' + data.id;
    },

    openCreateClassroom(schoolId) {
        this.createClassroomSchoolId = schoolId;
        this.$refs.createClassroomModal.showModal();
    },

    openEditClassroom(e) {
        const data = JSON.parse(e.currentTarget.dataset.classroom);
        this.editClassroomData = data;
        if (data.start_time) {
            this.editClassroomData.start_time = data.start_time.substring(0, 5);
        }
        this.$refs.editClassroomModal.showModal();
        this.$refs.editClassroomForm.action = '/schools/classrooms/' + data.id;
    },

    openBulkEdit(schoolId) {
        this.bulkEditSchoolId = schoolId;
        this.confirmRetroactive = false;
        this.$refs.bulkEditClassroomModal.showModal();
        this.$refs.bulkEditClassroomForm.action = '/schools/' + schoolId + '/classrooms/timing';
    }
}));

Alpine.data('reportsData', () => ({
    correctionLogData: {},
    logs: [],
    isFetchingLogs: false,
    openCorrectionModal(e) {
        this.correctionLogData = JSON.parse(e.currentTarget.dataset.log);
        this.$refs.correctionModal.showModal();
        this.$refs.correctionForm.action = '/reports/logs/' + this.correctionLogData.id + '/correct';
    },
    closeCorrectionModal() {
        this.$refs.correctionModal.close();
    },
    async fetchLogs(studentId, date) {
        this.isFetchingLogs = true;
        this.logs = [];
        this.$refs.logsModal.showModal();
        try {
            const res = await fetch(`/reports/student-logs?student_id=${studentId}&date=${date}`);
            this.logs = await res.json();
            setTimeout(() => window.refreshIcons?.(), 50);
        } catch (e) {
            console.error(e);
        } finally {
            this.isFetchingLogs = false;
        }
    },
    closeLogsModal() {
        this.$refs.logsModal.close();
    }
}));

Alpine.data('devicesData', (initialEditDevice = null, initialConfigDevice = null, initialNewDeviceEnv = null) => ({
    editDevice: initialEditDevice,
    configDevice: initialConfigDevice,
    newDeviceEnv: initialNewDeviceEnv,
    addDeviceModal: false,
    openEdit(device) {
        this.editDevice = device;
        this.newDeviceEnv = null;
    },
    closeEdit() {
        this.editDevice = null;
        this.newDeviceEnv = null;
    },
    openConfig(device) {
        this.configDevice = device;
        this.newDeviceEnv = null;
    },
    closeConfig() {
        this.configDevice = null;
        this.newDeviceEnv = null;
    }
}));

Alpine.data('cameraFeedData', (defaultIp = '', initialPresent = 0, initialLogs = []) => ({
    streamIp: defaultIp,
    status: 'connecting',
    isFullscreen: false,
    currentTime: '',
    clockTimer: null,
    pollTimer: null,
    eventSource: null,
    recentLogs: Array.isArray(initialLogs) ? initialLogs : [],
    presentCount: initialPresent,
    filterDirection: 'all',
    lastSyncTime: '',
    isRefreshing: false,

    get filteredLogs() {
        if (this.filterDirection === 'all') return this.recentLogs;
        return this.recentLogs.filter(log => log.direction === this.filterDirection);
    },

    init() {
        this.updateClock();
        this.clockTimer = setInterval(() => this.updateClock(), 1000);

        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                this.pollRecentLogs();
            }
        });

        this.pollTimer = setInterval(() => {
            if (!document.hidden) {
                this.pollRecentLogs();
            }
        }, 6000);

        document.addEventListener('fullscreenchange', () => {
            this.isFullscreen = !!document.fullscreenElement;
        });

        this.initSSE();
        setTimeout(() => window.refreshIcons?.(), 50);
    },

    destroy() {
        if (this.clockTimer) clearInterval(this.clockTimer);
        if (this.pollTimer) clearInterval(this.pollTimer);
        this.closeSSE();
    },

    updateClock() {
        const now = new Date();
        this.currentTime = now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) + ' WIB';
    },

    reconnect() {
        this.status = 'connecting';
        this.isRefreshing = true;
        const img = document.getElementById('liveStreamImg');
        if (img) {
            img.style.display = 'block';
            img.src = 'http://' + this.streamIp + ':5000/video_feed?t=' + Date.now();
        }
        this.initSSE();
        this.pollRecentLogs();
        setTimeout(() => {
            this.isRefreshing = false;
        }, 800);
    },

    toggleFullscreen() {
        const viewport = this.$refs.cameraViewport;
        if (!viewport) return;

        if (!document.fullscreenElement) {
            viewport.requestFullscreen?.().catch(() => {});
        } else {
            document.exitFullscreen?.().catch(() => {});
        }
    },

    captureSnapshot() {
        const snapshotUrl = 'http://' + this.streamIp + ':5000/snapshot.jpg?t=' + Date.now();
        window.open(snapshotUrl, '_blank');
    },

    async pollRecentLogs() {
        try {
            const res = await fetch('/live-camera/recent');
            if (!res.ok) return;
            const data = await res.json();
            if (data && Array.isArray(data.logs)) {
                this.recentLogs = data.logs;
                if (typeof data.present_today === 'number') {
                    this.presentCount = data.present_today;
                }
                const now = new Date();
                this.lastSyncTime = now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                setTimeout(() => window.refreshIcons?.(), 50);
            }
        } catch (e) {
            // Silently ignore polling network failures
        }
    },

    onStreamLoad() {
        this.status = 'online';
        const img = document.getElementById('liveStreamImg');
        if (img) img.style.display = 'block';
    },

    onStreamError() {
        this.status = 'offline';
        const img = document.getElementById('liveStreamImg');
        if (img) img.style.display = 'none';
        this.clearOverlay();
    },

    initSSE() {
        this.closeSSE();
        try {
            const sseUrl = 'http://' + this.streamIp + ':5000/events';
            this.eventSource = new EventSource(sseUrl);
            this.eventSource.onmessage = (e) => {
                try {
                    const data = JSON.parse(e.data);
                    this.renderOverlay(data);
                } catch (err) {}
            };
            this.eventSource.onerror = () => {
                this.closeSSE();
            };
        } catch (e) {}
    },

    closeSSE() {
        if (this.eventSource) {
            this.eventSource.close();
            this.eventSource = null;
        }
        this.clearOverlay();
    },

    renderOverlay(data) {
        const canvas = this.$refs.overlayCanvas;
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        if (!ctx) return;

        if (data.frame_width && data.frame_height) {
            if (canvas.width !== data.frame_width || canvas.height !== data.frame_height) {
                canvas.width = data.frame_width;
                canvas.height = data.frame_height;
            }
        }

        ctx.clearRect(0, 0, canvas.width, canvas.height);

        if (data.faces && data.faces.length > 0) {
            for (const face of data.faces) {
                ctx.save();
                ctx.lineWidth = 2;
                ctx.strokeStyle = face.color || '#10b981';
                ctx.strokeRect(face.x, face.y, face.width, face.height);

                const label = face.name_text || '';
                const score = face.score_text || '';
                ctx.font = 'bold 12px monospace, sans-serif';
                const textWidth = Math.max(ctx.measureText(label).width, ctx.measureText(score).width);

                ctx.fillStyle = 'rgba(15, 23, 42, 0.85)';
                ctx.fillRect(face.x, face.y + face.height - 32, textWidth + 12, 32);

                ctx.fillStyle = face.color || '#10b981';
                if (score) ctx.fillText(score, face.x + 6, face.y + face.height - 18);
                ctx.fillText(label, face.x + 6, face.y + face.height - 5);
                ctx.restore();
            }
        }
    },

    clearOverlay() {
        const canvas = this.$refs.overlayCanvas;
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        if (ctx) ctx.clearRect(0, 0, canvas.width, canvas.height);
    }
}));

window.Alpine = Alpine
Alpine.start()

const lucideIcons = {
    ScanFace, Users, Video, Wifi, VideoOff, Activity, User, LogIn, LogOut,
    Clock, History, ArrowRight, PowerOff, Cpu, Key, LayoutDashboard, FileText,
    Building, Menu, Home, CheckCircle, AlertCircle, Search, Filter, Download,
    FileSpreadsheet, Inbox, X, Plus, Edit, Trash2, ChevronDown, ChevronLeft, Edit3, Edit2,
    AlertTriangle, FolderUp, Upload, CheckCircle2, Camera, UserPlus, UploadCloud,
    UserCheck, RefreshCw, PlusCircle, List, Loader2, Radio, Check, Maximize2, Minimize2,
    Sparkles, ShieldCheck, Eye
};

export function refreshIcons() {
    createIcons({ icons: lucideIcons });
}

refreshIcons();
window.refreshIcons = refreshIcons;
window.lucide = { createIcons: refreshIcons };

// ═════════════════════════════════════════════════════════════════════
// Modern Web Animations: Lenis Smooth Scroll & GSAP
// ═════════════════════════════════════════════════════════════════════

// 1. Lenis Smooth Scroll (Hardware-accelerated, synced with GSAP ticker)
if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    const lenis = new Lenis({
        duration: 0.9,
        easing: (t) => Math.min(1, 1.001 - Math.pow(2, -10 * t)),
        orientation: 'vertical',
        smoothWheel: true,
        wheelMultiplier: 0.95,
        touchMultiplier: 1.5,
    });

    gsap.ticker.add((time) => {
        lenis.raf(time * 1000);
    });
    gsap.ticker.lagSmoothing(0);
    window.__lenis = lenis;
}

// 2. GSAP Micro-Interactions & Stagger Entrance
function initGsapInteractions() {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    // Entrance animation for dashboard elements
    const fadeCards = document.querySelectorAll('.animate-fade-up');
    if (fadeCards.length > 0) {
        gsap.from(fadeCards, {
            opacity: 0,
            y: 10,
            duration: 0.4,
            stagger: 0.05,
            ease: 'power2.out',
            clearProps: 'all'
        });
    }

    // Recent activity rows stagger entrance
    const activityRows = document.querySelectorAll('.animate-row');
    if (activityRows.length > 0) {
        gsap.from(activityRows, {
            opacity: 0,
            x: -6,
            duration: 0.35,
            stagger: 0.03,
            ease: 'power1.out',
            clearProps: 'all'
        });
    }
}

document.addEventListener('DOMContentLoaded', () => {
    initGsapInteractions();
});

window.gsap = gsap;
