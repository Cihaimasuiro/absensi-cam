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
    Sparkles, ShieldCheck, Eye, Pause, Play, createIcons,
    ArrowLeft, Calendar, ChevronRight, DoorClosed, DoorOpen, Globe, UserMinus, Zap,
    UsersRound, LogIn as LogInIcon, Edit2 as Edit2Icon, Info, ScanLine, WifiOff,
    ArrowRightLeft, CircleCheck, BadgeCheck, FileCheck2, Timer, Layers,
    MonitorCog, ExternalLink
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
    },

    // ── Embedded edge control panel ─────────────────────────────────────
    controlDevice: null,
    controlLoading: false,

    openControl(device) {
        this.controlDevice = device;
        this.controlLoading = !!device.ip_address;
        this.$refs.controlModal.showModal();
        setTimeout(() => window.refreshIcons?.(), 30);
    },

    closeControl() {
        this.controlDevice = null;
        this.controlLoading = false;
        if (this.$refs.controlModal?.open) this.$refs.controlModal.close();
    },

    reloadControl() {
        const frame = this.$refs.controlFrame;
        if (!frame || !this.controlDevice?.ip_address) return;
        this.controlLoading = true;
        frame.src = 'http://' + this.controlDevice.ip_address + ':5000/control?t=' + Date.now();
    },

    // ── Device telemetry ────────────────────────────────────────────────
    telemetryDevice: null,
    telemetry: null,
    telemetryError: '',
    telemetryLoading: false,
    telemetryUpdatedAt: '',
    telemetryTimer: null,

    openTelemetry(device) {
        this.telemetryDevice = device;
        this.telemetry = null;
        this.telemetryError = '';
        this.telemetryUpdatedAt = '';
        this.$refs.telemetryModal.showModal();

        if (!device.ip_address) return;

        this.refreshTelemetry();
        this.telemetryTimer = setInterval(() => {
            if (!document.hidden) this.refreshTelemetry();
        }, 5000);
    },

    closeTelemetry() {
        if (this.telemetryTimer) {
            clearInterval(this.telemetryTimer);
            this.telemetryTimer = null;
        }
        this.telemetryDevice = null;
        this.telemetry = null;
        this.telemetryError = '';
        if (this.$refs.telemetryModal?.open) this.$refs.telemetryModal.close();
    },

    async refreshTelemetry() {
        const ip = this.telemetryDevice?.ip_address;
        if (!ip) return;

        this.telemetryLoading = true;
        try {
            // /health is unauthenticated, so it doubles as a reachability probe.
            const health = await fetch('http://' + ip + ':5000/api/control/health', {
                cache: 'no-store',
                signal: AbortSignal.timeout(4000)
            });
            if (!health.ok) throw new Error('HTTP ' + health.status);

            // Full telemetry requires the edge admin session cookie.
            const res = await fetch('http://' + ip + ':5000/api/control/info', {
                credentials: 'include',
                cache: 'no-store',
                signal: AbortSignal.timeout(6000)
            });

            if (res.status === 401) {
                this.telemetryError = 'Perangkat online, tetapi sesi admin edge belum aktif. Buka panel Kontrol dan login sekali.';
                this.telemetry = null;
                return;
            }
            if (!res.ok) throw new Error('HTTP ' + res.status);

            this.telemetry = await res.json();
            this.telemetryError = '';
            this.telemetryUpdatedAt = new Date().toLocaleTimeString('id-ID', {
                hour: '2-digit', minute: '2-digit', second: '2-digit'
            });
        } catch (e) {
            this.telemetryError = 'Tidak dapat menghubungi ' + ip + ':5000 — ' + (e.message || e);
            this.telemetry = null;
        } finally {
            this.telemetryLoading = false;
        }
    },

    // ── Formatting helpers used by the telemetry modal ──────────────────
    clampPct(v) {
        return Math.max(0, Math.min(100, Number(v) || 0));
    },

    fmtPct(v) {
        return (Number(v) || 0).toFixed(1) + '%';
    },

    barColor(pct) {
        const v = Number(pct) || 0;
        if (v >= 90) return 'bg-[#f87171]';
        if (v >= 75) return 'bg-[#fbbf24]';
        return 'bg-[#4ade80]';
    },

    fmtBytes(bytes) {
        const n = Number(bytes) || 0;
        if (n < 1024) return n + ' B';
        if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
        if (n < 1024 * 1024 * 1024) return (n / (1024 * 1024)).toFixed(1) + ' MB';
        return (n / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
    },

    fmtUptime(sec) {
        const s = Number(sec) || 0;
        const d = Math.floor(s / 86400);
        const h = Math.floor((s % 86400) / 3600);
        const m = Math.floor((s % 3600) / 60);
        if (d > 0) return d + 'd ' + h + 'h ' + m + 'm';
        if (h > 0) return h + 'h ' + m + 'm';
        return m + 'm';
    }
}));

Alpine.data('cameraFeedData', (defaultIp = '', initialPresent = 0) => {
    let initialLogs = [];
    const logsEl = document.getElementById('recent-logs-data');
    if (logsEl && logsEl.textContent) {
        try {
            initialLogs = JSON.parse(logsEl.textContent) || [];
        } catch (e) {}
    }

    return {
        streamIp: defaultIp,
        status: 'connecting',
        isFullscreen: false,
        currentTime: '',
        clockTimer: null,
        pollTimer: null,
        reconnectTimer: null,
        eventSource: null,
        recentLogs: Array.isArray(initialLogs) ? initialLogs : [],
        presentCount: Number(initialPresent) || 0,
        filterDirection: 'all',
        lastSyncTime: '',
        isRefreshing: false,
        isPaused: false,

        get filteredLogs() {
            if (this.filterDirection === 'all') return this.recentLogs;
            return this.recentLogs.filter(log => log.direction === this.filterDirection);
        },

        init() {
            this.updateClock();
            this.clockTimer = setInterval(() => this.updateClock(), 1000);

            // Automatic initial feed connection
            this.reconnect();

            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) {
                    this.pollRecentLogs();
                    const img = document.getElementById('liveStreamImg');
                    if (img && this.status === 'offline' && !this.isPaused) {
                        this.reconnect();
                    }
                }
            });

            this.pollTimer = setInterval(() => {
                if (!document.hidden) {
                    this.pollRecentLogs();
                }
            }, 5000);

            const handleFullscreenChange = () => {
                this.isFullscreen = !!(document.fullscreenElement || document.webkitFullscreenElement);
                setTimeout(() => window.refreshIcons?.(), 50);
            };
            document.addEventListener('fullscreenchange', handleFullscreenChange);
            document.addEventListener('webkitfullscreenchange', handleFullscreenChange);

            setTimeout(() => window.refreshIcons?.(), 50);
        },

        destroy() {
            if (this.clockTimer) clearInterval(this.clockTimer);
            if (this.pollTimer) clearInterval(this.pollTimer);
            if (this.reconnectTimer) clearTimeout(this.reconnectTimer);
            this.closeSSE();
        },

        updateClock() {
            const now = new Date();
            this.currentTime = now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) + ' WIB';
        },

        reconnect() {
            if (this.reconnectTimer) {
                clearTimeout(this.reconnectTimer);
                this.reconnectTimer = null;
            }
            this.isPaused = false;
            this.status = 'connecting';
            this.isRefreshing = true;
            const img = document.getElementById('liveStreamImg');
            if (img) {
                img.style.display = 'block';
                img.src = 'http://' + this.streamIp + ':5000/stream?t=' + Date.now();
            }
            this.initSSE();
            this.pollRecentLogs();
            setTimeout(() => {
                this.isRefreshing = false;
            }, 800);
        },

        togglePause() {
            this.isPaused = !this.isPaused;
            const img = document.getElementById('liveStreamImg');
            if (!img) return;
            if (this.isPaused) {
                try {
                    const canvas = document.createElement('canvas');
                    canvas.width = img.naturalWidth || 640;
                    canvas.height = img.naturalHeight || 480;
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(img, 0, 0);
                    img.src = canvas.toDataURL('image/jpeg');
                } catch (e) {
                    img.src = 'http://' + this.streamIp + ':5000/snapshot.jpg?t=' + Date.now();
                }
            } else {
                this.reconnect();
            }
        },

        toggleFullscreen() {
            const viewport = this.$refs?.cameraViewport || document.getElementById('cameraViewport');
            if (!viewport) return;

            if (!document.fullscreenElement && !document.webkitFullscreenElement) {
                if (viewport.requestFullscreen) {
                    viewport.requestFullscreen().catch(() => {});
                } else if (viewport.webkitRequestFullscreen) {
                    viewport.webkitRequestFullscreen();
                }
            } else {
                if (document.exitFullscreen) {
                    document.exitFullscreen().catch(() => {});
                } else if (document.webkitExitFullscreen) {
                    document.webkitExitFullscreen();
                }
            }
        },

        captureSnapshot() {
            const now = new Date();
            const timestamp = now.getFullYear() +
                String(now.getMonth() + 1).padStart(2, '0') +
                String(now.getDate()).padStart(2, '0') + '_' +
                String(now.getHours()).padStart(2, '0') +
                String(now.getMinutes()).padStart(2, '0') +
                String(now.getSeconds()).padStart(2, '0');
            const snapshotUrl = 'http://' + this.streamIp + ':5000/snapshot.jpg?t=' + Date.now();
            
            const link = document.createElement('a');
            link.href = snapshotUrl;
            link.target = '_blank';
            link.download = `snapshot_${this.streamIp}_${timestamp}.jpg`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
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
            if (this.isPaused) return;
            this.status = 'offline';
            // Automatically attempt reconnect after 3 seconds if disconnected
            if (!this.reconnectTimer) {
                this.reconnectTimer = setTimeout(() => {
                    this.reconnectTimer = null;
                    this.reconnect();
                }, 3000);
            }
        },

        initSSE() {
            this.closeSSE();
            try {
                const sseUrl = 'http://' + this.streamIp + ':5000/events';
                this.eventSource = new EventSource(sseUrl);
                this.eventSource.onopen = () => {
                    this.status = 'online';
                    const img = document.getElementById('liveStreamImg');
                    if (img) img.style.display = 'block';
                };
                this.eventSource.onmessage = (e) => {
                    this.status = 'online';
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
        }
    };
});

Alpine.data('membersData', () => ({
    // ── Edit modal ──────────────────────────────────────────────────────
    openEdit(student) {
        const f = this.$refs.editForm;
        f.action = '/students/' + student.id;
        f.querySelector('[name="code"]').value = student.code || '';
        f.querySelector('[name="name"]').value = student.name || '';
        f.querySelector('[name="role"]').value = student.role || 'student';
        f.querySelector('[name="school_id"]').value = student.school_id || '';
        f.querySelector('[name="email"]').value = student.email || '';
        f.querySelector('[name="phone"]').value = student.phone || '';
        f.querySelector('[name="is_active"]').value = student.is_active ? '1' : '0';
        f.querySelector('[name="has_consent"]').checked = true;

        window.filterClassrooms?.('edit');
        f.querySelector('[name="classroom_id"]').value = student.classroom_id || '';

        this.$refs.editModal.showModal();
    },

    // ── Face enrollment ─────────────────────────────────────────────────
    enrollStudent: null,
    enrollTab: 'camera',
    enrollStatus: '',
    enrollStatusType: 'info',
    enrollBusy: false,
    cameraLoading: false,
    cameraError: '',
    uploadPreview: '',
    stream: null,

    openEnroll(student) {
        this.enrollStudent = student;
        this.enrollStatus = '';
        this.enrollBusy = false;
        this.uploadPreview = '';
        if (this.$refs.enrollFileInput) this.$refs.enrollFileInput.value = '';

        this.$refs.enrollModal.showModal();
        this.switchEnrollTab(student.face_template ? 'current' : 'camera');
    },

    closeEnrollModal() {
        this.stopCamera();
        this.uploadPreview = '';
        if (this.$refs.enrollModal?.open) this.$refs.enrollModal.close();
    },

    switchEnrollTab(tab) {
        this.enrollTab = tab;
        if (tab === 'camera') {
            this.startCamera();
        } else {
            this.stopCamera();
        }
        setTimeout(() => window.refreshIcons?.(), 30);
    },

    async startCamera() {
        if (this.stream) return;
        this.cameraLoading = true;
        this.cameraError = '';
        try {
            this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } });
            this.$refs.enrollVideo.srcObject = this.stream;
            this.cameraLoading = false;
        } catch (e) {
            this.cameraError = 'Kamera tidak dapat diakses. Gunakan tab Unggah Berkas.';
            this.cameraLoading = true;
        }
    },

    stopCamera() {
        if (this.stream) {
            this.stream.getTracks().forEach(t => t.stop());
            this.stream = null;
        }
    },

    previewUpload(e) {
        const file = e.target.files && e.target.files[0];
        if (!file) { this.uploadPreview = ''; return; }
        const reader = new FileReader();
        reader.onload = (ev) => { this.uploadPreview = ev.target.result; };
        reader.readAsDataURL(file);
    },

    async postEnroll(formData) {
        this.enrollBusy = true;
        this.enrollStatus = 'Memproses pendaftaran wajah…';
        this.enrollStatusType = 'info';
        try {
            const res = await fetch('/students/' + this.enrollStudent.id + '/enroll', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json'
                },
                body: formData
            });
            const result = await res.json().catch(() => ({}));

            if (res.ok) {
                this.enrollStatus = 'Wajah berhasil didaftarkan. Memuat ulang…';
                this.enrollStatusType = 'success';
                setTimeout(() => window.location.reload(), 1400);
                return;
            }
            this.enrollStatus = result.message || 'Gagal mendaftarkan wajah.';
            this.enrollStatusType = 'error';
        } catch (e) {
            this.enrollStatus = 'Terjadi kesalahan jaringan.';
            this.enrollStatusType = 'error';
        } finally {
            this.enrollBusy = false;
        }
    },

    captureAndEnroll() {
        if (!this.enrollStudent || !this.stream) return;
        const video = this.$refs.enrollVideo;
        const canvas = this.$refs.enrollCanvas;
        canvas.width = video.videoWidth || 640;
        canvas.height = video.videoHeight || 480;
        canvas.getContext('2d').drawImage(video, 0, 0);

        canvas.toBlob((blob) => {
            const fd = new FormData();
            fd.append('photo', blob, 'face.jpg');
            this.postEnroll(fd);
        }, 'image/jpeg', 0.9);
    },

    submitUploadAndEnroll() {
        const input = this.$refs.enrollFileInput;
        if (!this.enrollStudent || !input || !input.files.length) {
            this.enrollStatus = 'Pilih berkas foto terlebih dahulu.';
            this.enrollStatusType = 'error';
            return;
        }
        const fd = new FormData();
        fd.append('photo', input.files[0]);
        this.postEnroll(fd);
    },

    async deleteFaceTemplate() {
        if (!this.enrollStudent) return;
        if (!confirm('Hapus data biometrik wajah untuk ' + this.enrollStudent.name + '?\n\nPerangkat kamera absensi tidak akan lagi mengenali anggota ini.')) {
            return;
        }

        this.enrollBusy = true;
        this.enrollStatus = 'Menghapus data wajah dari sistem…';
        this.enrollStatusType = 'info';
        try {
            const res = await fetch('/students/' + this.enrollStudent.id + '/enroll', {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json'
                }
            });
            const result = await res.json().catch(() => ({}));

            if (res.ok) {
                this.enrollStatus = 'Data wajah berhasil dihapus. Memuat ulang…';
                this.enrollStatusType = 'success';
                setTimeout(() => window.location.reload(), 1200);
                return;
            }
            this.enrollStatus = result.message || 'Gagal menghapus data wajah.';
            this.enrollStatusType = 'error';
        } catch (e) {
            this.enrollStatus = 'Terjadi kesalahan jaringan.';
            this.enrollStatusType = 'error';
        } finally {
            this.enrollBusy = false;
        }
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
    Sparkles, ShieldCheck, Eye, Pause, Play,
    ArrowLeft, Calendar, ChevronRight, DoorClosed, DoorOpen, Globe, UserMinus, Zap,
    UsersRound, LogInIcon, Info, ScanLine, WifiOff, ArrowRightLeft, CircleCheck,
    BadgeCheck, FileCheck2, Timer, Layers, MonitorCog, ExternalLink
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
