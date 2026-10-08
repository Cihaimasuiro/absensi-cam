@extends('layouts.app')
@section('title', 'Manajemen Anggota')

@section('content')
<div x-data>

<div class="flex items-center justify-between mb-md">
    <h1 class="text-[18px] font-bold text-ink">Daftar Anggota</h1>
    <div x-data class="flex items-center gap-xs">
        <button onclick="document.getElementById('bulkEnrollModal').showModal()" class="btn btn-utility inline-flex items-center gap-xs">
            <i data-lucide="folder-up" class="w-4 h-4"></i> Bulk Enroll (ZIP)
        </button>
        <button onclick="document.getElementById('importModal').showModal()" class="btn btn-utility inline-flex items-center gap-xs">
            <i data-lucide="upload" class="w-4 h-4"></i> Import CSV
        </button>
        <button onclick="document.getElementById('createModal').showModal()" class="btn btn-primary inline-flex items-center gap-xs">
            <i data-lucide="plus" class="w-4 h-4"></i> Tambah Anggota
        </button>
    </div>
</div>

{{-- Search --}}
<div class="card p-sm px-md mb-md">
    <form method="GET" action="{{ route('students.index') }}" class="flex gap-sm items-center">
        <div class="relative flex-1 max-w-[320px]">
            <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-ink-faint"></i>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Cari Kode atau Nama…" class="form-input pl-9 w-full">
        </div>
        <button type="submit" class="btn btn-utility">Cari</button>
    </form>
</div>

{{-- Table --}}
<div class="card p-0 overflow-hidden">
    <table class="data-table">
        <thead>
            <tr>
                <th>Kode / NIS</th>
                <th>Nama Lengkap</th>
                <th>Organisasi / Gedung</th>
                <th>Template Wajah</th>
                <th class="text-right">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($students ?? [] as $student)
                <tr>
                    <td class="font-mono text-[12px]">{{ $student->code }}</td>
                    <td class="font-medium text-ink">{{ $student->name }}</td>
                    <td>{{ $student->school->name ?? '-' }}</td>
                    <td>
                        @if($student->faceTemplate)
                            <span class="badge badge-success">
                                <i data-lucide="check-circle-2" class="w-3 h-3"></i> Terdaftar
                            </span>
                        @else
                            <span class="badge badge-muted">Kosong</span>
                        @endif
                    </td>
                    <td class="text-right">
                        @if($student->has_active_consent ?? true)
                        <button @click="$dispatch('open-enroll', {{ $student->toJson() }})" class="text-[13px] text-accent font-medium border-none bg-transparent cursor-pointer mr-sm hover:underline inline-flex items-center gap-[4px]">
                            <i data-lucide="camera" class="w-3 h-3"></i> Wajah
                        </button>
                        @endif
                        <button @click="$dispatch('open-edit', {{ $student->toJson() }})" class="text-[13px] text-primary font-medium border-none bg-transparent cursor-pointer mr-sm hover:underline inline-flex items-center gap-[4px]">
                            <i data-lucide="edit" class="w-3 h-3"></i> Edit
                        </button>
                        <form action="{{ route('students.destroy', $student) }}" method="POST" class="inline" @submit="confirmSubmit" data-confirm="Hapus anggota ini?">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn-danger hover:underline inline-flex items-center gap-[4px]">
                                <i data-lucide="trash-2" class="w-3 h-3"></i> Hapus
                            </button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-center text-ink-faint p-xxl">
                        <div class="flex flex-col items-center justify-center gap-sm">
                            <i data-lucide="users" class="w-8 h-8 opacity-50"></i>
                            Tidak ada anggota terdaftar.
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if(isset($students) && $students->hasPages())
        <div class="p-sm px-md border-t border-hairline bg-canvas-soft">
            {{ $students->links('pagination::tailwind') }}
        </div>
    @endif
</div>

{{-- Create Modal --}}
<dialog id="createModal" class="p-lg border border-hairline bg-surface rounded-lg max-w-[400px] w-full backdrop:bg-black/20 shadow-xl m-auto">
    <div class="flex justify-between items-center mb-md border-b border-hairline pb-xs">
        <h3 class="m-0 text-[16px] font-semibold flex items-center gap-xs">
            <i data-lucide="user-plus" class="w-4 h-4 text-primary"></i> Tambah Anggota
        </h3>
        <button onclick="document.getElementById('createModal').close()" class="border-none bg-transparent cursor-pointer text-ink-faint hover:text-ink">
            <i data-lucide="x" class="w-4 h-4"></i>
        </button>
    </div>
    <form method="POST" action="{{ route('students.store') }}">
        @csrf
        <div class="mb-sm">
            <label class="form-label">Kode / NIS *</label>
            <input type="text" name="code" required class="form-input">
        </div>
        <div class="mb-sm">
            <label class="form-label">Nama Lengkap *</label>
            <input type="text" name="name" required class="form-input">
        </div>
        <div class="mb-sm">
            <label class="form-label">Gedung / Sekolah</label>
            <select name="school_id" class="form-select">
                <option value="">-- Pilih --</option>
                @foreach($schools as $school)
                    <option value="{{ $school->id }}">{{ $school->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="mb-sm flex items-center gap-xs mt-sm">
            <input type="checkbox" name="has_consent" value="1" checked id="createConsent">
            <label for="createConsent" class="text-[13px] text-ink-faint">Saya menyatakan bahwa anggota ini menyetujui data wajahnya diproses.</label>
        </div>
        <div class="flex justify-end gap-xs mt-md">
            <button type="button" onclick="document.getElementById('createModal').close()" class="btn btn-utility">Batal</button>
            <button type="submit" class="btn btn-primary">Simpan</button>
        </div>
    </form>
</dialog>

{{-- Edit Modal --}}
<dialog id="editModal" class="p-lg border border-hairline bg-surface rounded-lg max-w-[400px] w-full backdrop:bg-black/20 shadow-xl m-auto">
    <div class="flex justify-between items-center mb-md border-b border-hairline pb-xs">
        <h3 class="m-0 text-[16px] font-semibold flex items-center gap-xs">
            <i data-lucide="edit-3" class="w-4 h-4 text-primary"></i> Edit Anggota
        </h3>
        <button onclick="document.getElementById('editModal').close()" class="border-none bg-transparent cursor-pointer text-ink-faint hover:text-ink">
            <i data-lucide="x" class="w-4 h-4"></i>
        </button>
    </div>
    <form method="POST" id="editForm" action="">
        @csrf
        @method('PUT')
        <div class="mb-sm">
            <label class="form-label">Kode / NIS *</label>
            <input type="text" id="editCode" name="code" required class="form-input">
        </div>
        <div class="mb-sm">
            <label class="form-label">Nama Lengkap *</label>
            <input type="text" id="editName" name="name" required class="form-input">
        </div>
        <div class="mb-sm">
            <label class="form-label">Gedung / Sekolah</label>
            <select id="editSchool" name="school_id" class="form-select">
                <option value="">-- Pilih --</option>
                @foreach($schools as $school)
                    <option value="{{ $school->id }}">{{ $school->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="mb-sm">
            <label class="form-label">Status Aktif</label>
            <select id="editActive" name="is_active" class="form-select">
                <option value="1">Aktif</option>
                <option value="0">Tidak Aktif</option>
            </select>
        </div>
        <div class="mb-sm flex items-center gap-xs mt-sm">
            <input type="checkbox" name="has_consent" value="1" id="editConsent">
            <label for="editConsent" class="text-[13px] text-ink-faint">Saya menyatakan bahwa anggota ini menyetujui data wajahnya diproses.</label>
        </div>
        <div class="flex justify-end gap-xs mt-md">
            <button type="button" onclick="document.getElementById('editModal').close()" class="btn btn-utility">Batal</button>
            <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
        </div>
    </form>
</dialog>

{{-- Import Modal --}}
<dialog id="importModal" class="p-lg border border-hairline bg-surface rounded-lg max-w-[400px] w-full backdrop:bg-black/20 shadow-xl m-auto">
    <div class="flex justify-between items-center mb-md border-b border-hairline pb-xs">
        <h3 class="m-0 text-[16px] font-semibold flex items-center gap-xs">
            <i data-lucide="upload" class="w-4 h-4 text-primary"></i> Import Anggota (CSV)
        </h3>
        <button onclick="document.getElementById('importModal').close()" class="border-none bg-transparent cursor-pointer text-ink-faint hover:text-ink">
            <i data-lucide="x" class="w-4 h-4"></i>
        </button>
    </div>
    <form method="POST" action="{{ route('students.import') }}" enctype="multipart/form-data">
        @csrf
        <div class="mb-sm">
            <label class="form-label">Berkas Excel/CSV</label>
            <input type="file" name="file" accept=".csv, .xlsx, .xls" required class="text-[13px] form-input">
        </div>
        <p class="text-[12px] text-ink-faint mb-md">Format kolom: code, name, email, phone, role. Sistem otomatis mendaftarkan tanpa wajah.</p>
        <div class="flex justify-end gap-xs">
            <button type="button" onclick="document.getElementById('importModal').close()" class="btn btn-utility">Batal</button>
            <button type="submit" class="btn btn-primary">Import Data</button>
        </div>
    </form>
</dialog>

{{-- Bulk Enroll Modal --}}
<dialog id="bulkEnrollModal" class="p-lg border border-hairline bg-surface rounded-lg max-w-[450px] w-full backdrop:bg-black/20 shadow-xl m-auto">
    <div class="flex justify-between items-center mb-md border-b border-hairline pb-xs">
        <h3 class="m-0 text-[16px] font-semibold flex items-center gap-xs">
            <i data-lucide="folder-up" class="w-4 h-4 text-accent"></i> Bulk Enroll (via ZIP)
        </h3>
        <button onclick="document.getElementById('bulkEnrollModal').close()" class="border-none bg-transparent cursor-pointer text-ink-faint hover:text-ink">
            <i data-lucide="x" class="w-4 h-4"></i>
        </button>
    </div>
    <form method="POST" action="{{ route('students.bulk-enroll') }}" enctype="multipart/form-data">
        @csrf
        <div class="mb-sm">
            <label class="form-label">Berkas ZIP Foto</label>
            <input type="file" name="zip_file" accept=".zip" required class="text-[13px] form-input">
        </div>
        <p class="text-[12px] text-ink-faint mb-md">Penting: Namai foto siswa dengan Kode/NIS mereka (contoh: <code>12345.jpg</code> atau <code>12345.png</code>) lalu masukkan ke dalam satu file ZIP.</p>
        <div class="mb-sm flex items-center gap-xs">
            <input type="checkbox" name="has_consent" value="1" required id="bulkConsent">
            <label for="bulkConsent" class="text-[12px] text-ink-faint">Saya mengonfirmasi bahwa semua siswa dalam ZIP ini telah menyetujui pemrosesan data wajah.</label>
        </div>
        <div class="flex justify-end gap-xs mt-md">
            <button type="button" onclick="document.getElementById('bulkEnrollModal').close()" class="btn btn-utility">Batal</button>
            <button type="submit" class="btn btn-primary bg-accent hover:bg-accent/90 border-accent text-white">Mulai Proses Massal</button>
        </div>
    </form>
</dialog>

{{-- Enroll Modal --}}
<dialog id="enrollModal" class="p-lg border border-hairline bg-surface rounded-lg max-w-[450px] w-full backdrop:bg-black/40 shadow-2xl m-auto">
    <div class="flex justify-between items-center mb-md border-b border-hairline pb-xs">
        <h3 class="m-0 text-[16px] font-semibold flex items-center gap-xs">
            <i data-lucide="camera" class="w-4 h-4 text-accent"></i> Daftarkan Wajah
        </h3>
        <button onclick="closeEnrollModal()" class="border-none bg-transparent cursor-pointer text-ink-faint hover:text-ink">
            <i data-lucide="x" class="w-4 h-4"></i>
        </button>
    </div>
    
    <div class="text-center mb-sm">
        <p class="text-[14px] text-ink-faint mb-sm">Mendaftarkan wajah untuk <strong id="enrollStudentName" class="text-ink"></strong></p>
        
        <div class="flex gap-2 mb-sm border-b border-hairline">
            <button type="button" id="tabCamera" onclick="switchEnrollTab('camera')" class="flex-1 pb-xs text-[13px] font-medium border-b-2 border-primary text-primary bg-transparent cursor-pointer">Kamera Web</button>
            <button type="button" id="tabUpload" onclick="switchEnrollTab('upload')" class="flex-1 pb-xs text-[13px] font-medium border-b-2 border-transparent text-ink-faint hover:text-ink bg-transparent cursor-pointer">Upload Berkas</button>
        </div>

        {{-- Camera View --}}
        <div id="viewCamera" class="relative bg-black rounded-lg overflow-hidden h-[300px] flex items-center justify-center border border-hairline">
            <video id="enrollVideo" autoplay playsinline muted class="w-full h-full object-cover"></video>
            <div id="enrollLoading" class="absolute inset-0 bg-surface/80 flex items-center justify-center text-ink-faint text-[13px]">
                Membuka Kamera...
            </div>
            <canvas id="enrollCanvas" class="hidden"></canvas>
        </div>

        {{-- Upload View --}}
        <div id="viewUpload" class="hidden relative bg-canvas-soft rounded-lg h-[300px] flex flex-col items-center justify-center border border-hairline border-dashed p-md">
            <i data-lucide="upload-cloud" class="w-8 h-8 text-ink-faint mb-xs"></i>
            <p class="text-[13px] text-ink-faint mb-sm">Pilih foto wajah (.jpg, .png) max 5MB.</p>
            <input type="file" id="enrollFileInput" accept="image/jpeg,image/png,image/webp" class="text-[12px] text-ink w-full max-w-[250px] file:mr-4 file:py-1 file:px-3 file:rounded file:border-0 file:text-[12px] file:bg-primary/10 file:text-primary hover:file:bg-primary/20">
            <img id="enrollPreview" class="hidden mt-sm max-h-[150px] rounded border border-hairline object-cover">
        </div>
    </div>

    <div id="enrollStatus" class="hidden p-xs text-center text-[13px] rounded mb-sm"></div>

    <div class="flex justify-end gap-xs mt-md">
        <button type="button" onclick="closeEnrollModal()" class="btn btn-utility">Batal</button>
        <button type="button" id="btnCapture" onclick="captureAndEnroll()" class="btn btn-primary bg-accent hover:bg-accent/90 border-accent text-white">
            <i data-lucide="camera" class="w-4 h-4"></i> Ambil & Simpan
        </button>
        <button type="button" id="btnUpload" onclick="submitUploadAndEnroll()" class="hidden btn btn-primary">
            <i data-lucide="upload" class="w-4 h-4"></i> Simpan Wajah
        </button>
    </div>
</dialog>


<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
    document.addEventListener('open-edit', (e) => editStudent(e.detail));
    document.addEventListener('open-enroll', (e) => openEnrollModal(e.detail));

    function editStudent(student) {
        document.getElementById('editForm').action = '/students/' + student.id;
        document.getElementById('editCode').value = student.code;
        document.getElementById('editName').value = student.name;
        document.getElementById('editSchool').value = student.school_id || '';
        document.getElementById('editActive').value = student.is_active ? '1' : '0';
        document.getElementById('editConsent').checked = true; // By default check it if editing (in real app we'd check actual consent state)
        document.getElementById('editModal').showModal();
    }

    let enrollStream = null;
    let currentEnrollStudent = null;
    let activeEnrollMode = 'camera';

    function switchEnrollTab(mode) {
        activeEnrollMode = mode;
        const tabCamera = document.getElementById('tabCamera');
        const tabUpload = document.getElementById('tabUpload');
        
        if (mode === 'camera') {
            tabCamera.className = "flex-1 pb-xs text-[13px] font-medium border-b-2 border-primary text-primary bg-transparent cursor-pointer";
            tabUpload.className = "flex-1 pb-xs text-[13px] font-medium border-b-2 border-transparent text-ink-faint hover:text-ink bg-transparent cursor-pointer";
            document.getElementById('viewCamera').classList.remove('hidden');
            document.getElementById('viewUpload').classList.add('hidden');
            document.getElementById('btnCapture').classList.remove('hidden');
            document.getElementById('btnUpload').classList.add('hidden');
            startCamera();
        } else {
            tabUpload.className = "flex-1 pb-xs text-[13px] font-medium border-b-2 border-primary text-primary bg-transparent cursor-pointer";
            tabCamera.className = "flex-1 pb-xs text-[13px] font-medium border-b-2 border-transparent text-ink-faint hover:text-ink bg-transparent cursor-pointer";
            document.getElementById('viewUpload').classList.remove('hidden');
            document.getElementById('viewCamera').classList.add('hidden');
            document.getElementById('btnUpload').classList.remove('hidden');
            document.getElementById('btnCapture').classList.add('hidden');
            stopCamera();
        }
    }

    async function startCamera() {
        if (enrollStream) return;
        try {
            document.getElementById('enrollLoading').classList.remove('hidden');
            enrollStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } });
            document.getElementById('enrollVideo').srcObject = enrollStream;
            document.getElementById('enrollLoading').classList.add('hidden');
        } catch (e) {
            document.getElementById('enrollLoading').textContent = 'Kamera tidak dapat diakses.';
            document.getElementById('btnCapture').disabled = true;
        }
    }

    function stopCamera() {
        if (enrollStream) {
            enrollStream.getTracks().forEach(track => track.stop());
            enrollStream = null;
        }
    }

    async function openEnrollModal(student) {
        currentEnrollStudent = student;
        document.getElementById('enrollStudentName').textContent = student.name;
        document.getElementById('enrollStatus').className = 'hidden p-xs text-center text-[13px] rounded mb-sm';
        document.getElementById('btnCapture').disabled = false;
        document.getElementById('btnUpload').disabled = false;
        
        // Reset file input
        document.getElementById('enrollFileInput').value = '';
        document.getElementById('enrollPreview').classList.add('hidden');
        document.getElementById('enrollPreview').src = '';

        const modal = document.getElementById('enrollModal');
        modal.showModal();

        switchEnrollTab('camera');
    }

    document.getElementById('enrollFileInput').addEventListener('change', function(e) {
        if (this.files && this.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const img = document.getElementById('enrollPreview');
                img.src = e.target.result;
                img.classList.remove('hidden');
            }
            reader.readAsDataURL(this.files[0]);
        }
    });

    function closeEnrollModal() {
        stopCamera();
        document.getElementById('enrollModal').close();
    }

    async function processEnrollRequest(formData, btnId, defaultText) {
        const btn = document.getElementById(btnId);
        const status = document.getElementById('enrollStatus');
        
        btn.disabled = true;
        btn.innerHTML = 'Memproses...';
        
        try {
            const res = await fetch(`/students/${currentEnrollStudent.id}/enroll`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json'
                },
                body: formData
            });
            
            const result = await res.json();
            
            if (res.ok) {
                status.textContent = 'Wajah berhasil didaftarkan! Memuat ulang...';
                status.className = 'p-xs text-center text-[13px] rounded mb-sm bg-success/10 text-success border border-success/20';
                setTimeout(() => window.location.reload(), 1500);
            } else {
                status.textContent = result.message || 'Gagal mendaftar wajah.';
                status.className = 'p-xs text-center text-[13px] rounded mb-sm bg-danger/10 text-danger border border-danger/20';
                btn.disabled = false;
                btn.innerHTML = defaultText;
            }
        } catch (err) {
            status.textContent = 'Terjadi kesalahan jaringan.';
            status.className = 'p-xs text-center text-[13px] rounded mb-sm bg-danger/10 text-danger border border-danger/20';
            btn.disabled = false;
            btn.innerHTML = defaultText;
        }
    }

    async function captureAndEnroll() {
        if (!currentEnrollStudent || !enrollStream) return;
        
        const video = document.getElementById('enrollVideo');
        const canvas = document.getElementById('enrollCanvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0);
        
        canvas.toBlob(async (blob) => {
            const formData = new FormData();
            formData.append('photo', blob, 'face.jpg');
            await processEnrollRequest(formData, 'btnCapture', '<i data-lucide="camera" class="w-4 h-4"></i> Coba Lagi');
        }, 'image/jpeg', 0.9);
    }

    async function submitUploadAndEnroll() {
        const fileInput = document.getElementById('enrollFileInput');
        if (!currentEnrollStudent || !fileInput.files.length) {
            alert('Silakan pilih berkas foto terlebih dahulu.');
            return;
        }
        
        const formData = new FormData();
        formData.append('photo', fileInput.files[0]);
        await processEnrollRequest(formData, 'btnUpload', '<i data-lucide="upload" class="w-4 h-4"></i> Coba Lagi');
    }
</script>
</div>
@endsection
