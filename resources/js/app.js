import Alpine from '@alpinejs/csp'
import Lenis from 'lenis'
import gsap from 'gsap'
import {
    ScanFace, Users, Video, Wifi, VideoOff, Activity, User, LogIn, LogOut,
    Clock, History, ArrowRight, PowerOff, Cpu, Key, LayoutDashboard, FileText,
    Building, Menu, Home, CheckCircle, AlertCircle, Search, Filter, Download,
    FileSpreadsheet, Inbox, X, Plus, Edit, Trash2, ChevronDown, ChevronLeft, Edit3, Edit2,
    AlertTriangle, FolderUp, Upload, CheckCircle2, Camera, UserPlus, UploadCloud,
    UserCheck, RefreshCw, PlusCircle, List, Loader2, createIcons
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

window.Alpine = Alpine
Alpine.start()

createIcons({
    icons: {
        ScanFace, Users, Video, Wifi, VideoOff, Activity, User, LogIn, LogOut,
        Clock, History, ArrowRight, PowerOff, Cpu, Key, LayoutDashboard, FileText,
        Building, Menu, Home, CheckCircle, AlertCircle, Search, Filter, Download,
        FileSpreadsheet, Inbox, X, Plus, Edit, Trash2, ChevronDown, ChevronLeft, Edit3, Edit2,
        AlertTriangle, FolderUp, Upload, CheckCircle2, Camera, UserPlus, UploadCloud,
        UserCheck, RefreshCw, PlusCircle, List, Loader2
    }
})

// ═════════════════════════════════════════════════════════════════════
// Modern Web Animations: Lenis Smooth Scroll, GSAP, & React Bits
// ═════════════════════════════════════════════════════════════════════

// 1. Lenis Smooth Scroll (Hardware-accelerated, respects prefers-reduced-motion)
if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    const lenis = new Lenis({
        duration: 0.9,
        easing: (t) => Math.min(1, 1.001 - Math.pow(2, -10 * t)),
        orientation: 'vertical',
        smoothWheel: true,
        wheelMultiplier: 0.95,
        touchMultiplier: 1.5,
    });

    function raf(time) {
        lenis.raf(time);
        requestAnimationFrame(raf);
    }
    requestAnimationFrame(raf);
    window.__lenis = lenis;
}

// 2. React Bits: Spotlight Card cursor radial highlight
function initSpotlightCards() {
    document.querySelectorAll('.spotlight-card').forEach((card) => {
        if (card.__hasSpotlight) return;
        card.__hasSpotlight = true;
        card.addEventListener('mousemove', (e) => {
            const rect = card.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            card.style.setProperty('--mouse-x', `${x}px`);
            card.style.setProperty('--mouse-y', `${y}px`);
        });
    });
}

// 3. GSAP Micro-Interactions & Stagger Entrance
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
    initSpotlightCards();
    initGsapInteractions();
});

window.initSpotlightCards = initSpotlightCards;
window.gsap = gsap;
