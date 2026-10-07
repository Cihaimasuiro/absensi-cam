import Alpine from '@alpinejs/csp'
import {
    ScanFace, Users, Video, Wifi, VideoOff, Activity, User, LogIn, LogOut,
    Clock, History, ArrowRight, PowerOff, Cpu, Key, LayoutDashboard, FileText,
    Building, Menu, Home, CheckCircle, AlertCircle, Search, Filter, Download,
    FileSpreadsheet, Inbox, X, Plus, Edit, Trash2, ChevronDown, Edit3, Edit2,
    AlertTriangle, FolderUp, Upload, CheckCircle2, Camera, UserPlus, UploadCloud,
    createIcons
} from 'lucide'

Alpine.data('layoutData', () => ({
    sidebarOpen: false
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

    openEditSchool(data) {
        this.editSchoolData = data;
        this.$refs.editModal.showModal();
        this.$refs.editForm.action = '/schools/' + data.id;
    },

    openCreateClassroom(schoolId) {
        this.createClassroomSchoolId = schoolId;
        this.$refs.createClassroomModal.showModal();
    },

    openEditClassroom(data) {
        this.editClassroomData = data;
        this.editClassroomData.start_time = data.start_time.substring(0, 5);
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

window.Alpine = Alpine
Alpine.start()

createIcons({
    icons: {
        ScanFace, Users, Video, Wifi, VideoOff, Activity, User, LogIn, LogOut,
        Clock, History, ArrowRight, PowerOff, Cpu, Key, LayoutDashboard, FileText,
        Building, Menu, Home, CheckCircle, AlertCircle, Search, Filter, Download,
        FileSpreadsheet, Inbox, X, Plus, Edit, Trash2, ChevronDown, Edit3, Edit2,
        AlertTriangle, FolderUp, Upload, CheckCircle2, Camera, UserPlus, UploadCloud
    }
})
