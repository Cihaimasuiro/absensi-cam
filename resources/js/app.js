import Alpine from '@alpinejs/csp'
import { createIcons, icons } from 'lucide'

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
    confirmRetroactive: false
}));

window.Alpine = Alpine
Alpine.start()

createIcons({ icons })
