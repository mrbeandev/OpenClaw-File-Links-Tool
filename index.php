<?php
/**
 * Simple File Upload API & Dashboard
 * Deployed at: mrbean.dev/upload/index.php
 * * For usage instructions and API documentation, please refer to DOC.md
 */

// --- CONFIGURATION ---

// 1. Set a secure API Key
define('API_KEY', 'NaNQQWBDM5oCyGqB0xmxPAySYXbL36oYcL');

// 2. Define the upload directory (relative to this script)
define('UPLOAD_DIR', 'uploads/');

// 3. Base URL for the download link
define('BASE_URL', 'https://mrbean.dev/upload/');

// 4. Security: Allow only specific extensions
$allowed_extensions = ['zip', 'md', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'json', 'pdf'];

// --- BACKEND LOGIC ---

// Helper: Send JSON Response
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// Helper: Authenticate
function authenticate() {
    $headers = getallheaders();
    $key = $_REQUEST['api_key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? ($headers['X-API-Key'] ?? '');
    
    if ($key !== API_KEY) {
        jsonResponse(['status' => 'error', 'message' => 'Invalid or missing API Key'], 403);
    }
}

// Router Logic
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// 1. Serve Frontend (GET / with no action)
if ($method === 'GET' && empty($action)) {
    serveFrontend(); // Function defined at bottom
    exit;
}

// 2. API: List Files (GET ?action=list)
if ($method === 'GET' && $action === 'list') {
    authenticate();
    
    if (!is_dir(UPLOAD_DIR)) {
        jsonResponse(['status' => 'success', 'files' => []]);
    }

    $files = [];
    $scanned = scandir(UPLOAD_DIR);
    
    foreach ($scanned as $file) {
        if ($file === '.' || $file === '..') continue;
        
        $path = UPLOAD_DIR . $file;
        if (is_file($path)) {
            $files[] = [
                'name' => $file,
                'size' => filesize($path),
                'created' => filemtime($path),
                'url' => rtrim(BASE_URL, '/') . '/' . UPLOAD_DIR . $file,
                'is_image' => preg_match('/\.(jpg|jpeg|png|gif|webp|svg)$/i', $file)
            ];
        }
    }
    
    // Sort by newest first
    usort($files, fn($a, $b) => $b['created'] <=> $a['created']);
    
    jsonResponse(['status' => 'success', 'files' => $files]);
}

// 3. API: Delete Files (POST ?action=delete)
if ($method === 'POST' && $action === 'delete') {
    authenticate();
    
    $input = json_decode(file_get_contents('php://input'), true);
    $filesToDelete = $input['files'] ?? [];
    
    if (empty($filesToDelete)) {
        jsonResponse(['status' => 'error', 'message' => 'No files specified'], 400);
    }
    
    $deletedCount = 0;
    foreach ($filesToDelete as $filename) {
        // Security: simple basename check to prevent traversal
        $safeName = basename($filename);
        $path = UPLOAD_DIR . $safeName;
        
        if (file_exists($path) && is_file($path)) {
            if (unlink($path)) {
                $deletedCount++;
            }
        }
    }
    
    jsonResponse(['status' => 'success', 'deleted' => $deletedCount]);
}

// 4. API: Upload File (POST /)
if ($method === 'POST' && !isset($_GET['action'])) {
    authenticate();

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $msg = isset($_FILES['file']) ? 'Error code: ' . $_FILES['file']['error'] : 'No file sent';
        jsonResponse(['status' => 'error', 'message' => $msg], 400);
    }

    if (!is_dir(UPLOAD_DIR)) {
        if (!mkdir(UPLOAD_DIR, 0755, true)) {
            jsonResponse(['status' => 'error', 'message' => 'Server failed to create upload directory'], 500);
        }
    }

    $original_name = basename($_FILES['file']['name']);
    $file_ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

    if (!in_array($file_ext, $allowed_extensions)) {
        jsonResponse(['status' => 'error', 'message' => 'File type not allowed'], 400);
    }

    // Generate unique name: timestamp_random.ext
    $new_filename = time() . '_' . bin2hex(random_bytes(4)) . '.' . $file_ext;
    $target_path = UPLOAD_DIR . $new_filename;

    if (move_uploaded_file($_FILES['file']['tmp_name'], $target_path)) {
        $url = rtrim(BASE_URL, '/') . '/' . UPLOAD_DIR . $new_filename;
        jsonResponse([
            'status' => 'success',
            'url' => $url,
            'filename' => $new_filename,
            'original_name' => $original_name
        ]);
    } else {
        jsonResponse(['status' => 'error', 'message' => 'Failed to move file'], 500);
    }
}


// --- FRONTEND HTML GENERATOR ---
function serveFrontend() {
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MrBeanDev Uploads</title>
    <!-- Tailwind CSS (CDN is fine for internal tools) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        gray: { 900: '#111827', 800: '#1f2937', 700: '#374151' }
                    }
                }
            }
        }
    </script>

    <!-- Icons -->
    <script src="https://unpkg.com/@phosphor-icons/web"></script>

    <!-- Application Logic (Moved to HEAD to ensure availability before Alpine inits) -->
    <script>
        function fileManager() {
            return {
                isAuthenticated: false,
                apiKey: localStorage.getItem('mrbean_upload_key') || '',
                inputKey: '',
                authError: false,
                files: [],
                selectedFiles: [],
                loading: false,
                uploading: false,
                dragHover: false,
                toast: { show: false, message: '', type: 'success' },

                init() {
                    if (this.apiKey) {
                        this.verifyAndFetch();
                    }
                },

                async verifyAndFetch() {
                    this.loading = true;
                    try {
                        const res = await fetch('?action=list', {
                            headers: { 'X-API-Key': this.apiKey }
                        });
                        if (res.status === 403) throw new Error('Auth failed');
                        const data = await res.json();
                        this.files = data.files;
                        this.isAuthenticated = true;
                        this.authError = false;
                    } catch (e) {
                        this.logout();
                        this.authError = true;
                    }
                    this.loading = false;
                },

                async login() {
                    this.apiKey = this.inputKey;
                    localStorage.setItem('mrbean_upload_key', this.apiKey);
                    await this.verifyAndFetch();
                },

                logout() {
                    this.isAuthenticated = false;
                    this.apiKey = '';
                    this.inputKey = '';
                    this.files = [];
                    localStorage.removeItem('mrbean_upload_key');
                },

                handleDrop(e) {
                    this.dragHover = false;
                    const files = e.dataTransfer.files;
                    if (files.length > 0) this.handleFiles(files);
                },

                async handleFiles(fileList) {
                    this.uploading = true;
                    let successCount = 0;
                    
                    for (let i = 0; i < fileList.length; i++) {
                        const formData = new FormData();
                        formData.append('file', fileList[i]);
                        formData.append('api_key', this.apiKey);

                        try {
                            const res = await fetch('index.php', {
                                method: 'POST',
                                body: formData
                            });
                            const data = await res.json();
                            if (data.status === 'success') successCount++;
                            else this.showToast(data.message, 'error');
                        } catch (e) {
                            console.error(e);
                        }
                    }

                    if (successCount > 0) {
                        this.showToast(`Uploaded ${successCount} files`);
                        await this.verifyAndFetch();
                    }
                    this.uploading = false;
                },

                async deleteSelected() {
                    if (!confirm(`Delete ${this.selectedFiles.length} files?`)) return;

                    try {
                        const res = await fetch('?action=delete', {
                            method: 'POST',
                            headers: { 
                                'Content-Type': 'application/json',
                                'X-API-Key': this.apiKey
                            },
                            body: JSON.stringify({ files: this.selectedFiles })
                        });
                        
                        const data = await res.json();
                        if (data.status === 'success') {
                            this.showToast(`Deleted ${data.deleted} files`);
                            this.selectedFiles = [];
                            await this.verifyAndFetch();
                        }
                    } catch (e) {
                        this.showToast('Failed to delete files', 'error');
                    }
                },

                toggleSelectAll() {
                    if (this.selectedFiles.length === this.files.length) {
                        this.selectedFiles = [];
                    } else {
                        this.selectedFiles = this.files.map(f => f.name);
                    }
                },

                copyLink(url) {
                    navigator.clipboard.writeText(url).then(() => {
                        this.showToast('Link copied to clipboard');
                    });
                },

                showToast(msg, type = 'success') {
                    this.toast.message = msg;
                    this.toast.type = type;
                    this.toast.show = true;
                    setTimeout(() => this.toast.show = false, 3000);
                },

                formatSize(bytes) {
                    if (bytes === 0) return '0 B';
                    const k = 1024;
                    const sizes = ['B', 'KB', 'MB', 'GB'];
                    const i = Math.floor(Math.log(bytes) / Math.log(k));
                    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
                },

                formatDate(timestamp) {
                    return new Date(timestamp * 1000).toLocaleDateString();
                }
            }
        }
    </script>
    
    <!-- Alpine.js (Loaded AFTER fileManager definition) -->
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js"></script>
    
    <style>
        [x-cloak] { display: none !important; }
        .scrollbar-hide::-webkit-scrollbar { display: none; }
    </style>
</head>
<body class="bg-gray-900 text-gray-100 font-sans min-h-screen selection:bg-indigo-500 selection:text-white"
      x-data="fileManager()">

    <!-- AUTH MODAL -->
    <div x-show="!isAuthenticated" 
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-sm px-4">
        <div class="bg-gray-800 p-8 rounded-2xl shadow-2xl w-full max-w-md border border-gray-700">
            <div class="text-center mb-6">
                <i class="ph ph-lock-key text-5xl text-indigo-500 mb-4"></i>
                <h2 class="text-2xl font-bold">Access Restricted</h2>
                <p class="text-gray-400 text-sm mt-2">Enter API Key to manage files</p>
            </div>
            <form @submit.prevent="login">
                <input type="password" x-model="inputKey" 
                       class="w-full bg-gray-900 border border-gray-700 rounded-lg px-4 py-3 text-white focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-all placeholder-gray-600" 
                       placeholder="API Key..." autofocus>
                <button type="submit" 
                        class="w-full mt-4 bg-indigo-600 hover:bg-indigo-500 text-white font-medium py-3 rounded-lg transition-colors flex items-center justify-center gap-2">
                    Enter Dashboard <i class="ph ph-arrow-right"></i>
                </button>
            </form>
            <p x-show="authError" x-transition class="text-red-400 text-sm mt-4 text-center">Invalid API Key</p>
        </div>
    </div>

    <!-- MAIN DASHBOARD -->
    <div x-show="isAuthenticated" x-cloak class="max-w-6xl mx-auto px-4 py-8">
        
        <!-- HEADER -->
        <header class="flex flex-col md:flex-row justify-between items-center mb-8 gap-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-indigo-600 rounded-xl flex items-center justify-center">
                    <i class="ph ph-cloud-arrow-up text-xl text-white"></i>
                </div>
                <div>
                    <h1 class="text-xl font-bold">Upload Center</h1>
                    <p class="text-xs text-gray-400">mrbean.dev</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-xs bg-red-500/10 text-red-400 px-3 py-1 rounded-full border border-red-500/20">
                    Temp Storage
                </span>
                <button @click="logout" class="text-gray-400 hover:text-white transition-colors" title="Logout">
                    <i class="ph ph-sign-out text-2xl"></i>
                </button>
            </div>
        </header>

        <!-- UPLOAD ZONE -->
        <div class="mb-10"
             @dragover.prevent="dragHover = true"
             @dragleave.prevent="dragHover = false"
             @drop.prevent="handleDrop($event)">
            
            <div class="relative group border-2 border-dashed rounded-2xl p-8 text-center transition-all duration-300"
                 :class="dragHover ? 'border-indigo-500 bg-indigo-500/10' : 'border-gray-700 bg-gray-800 hover:border-gray-600'">
                
                <input type="file" multiple @change="handleFiles($event.target.files)" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10">
                
                <div class="pointer-events-none">
                    <div class="w-16 h-16 bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4 group-hover:bg-gray-600 transition-colors">
                        <i class="ph ph-upload-simple text-3xl text-gray-300"></i>
                    </div>
                    <h3 class="text-lg font-medium text-white">Drop files here or click to upload</h3>
                    <p class="text-sm text-gray-500 mt-2">Supports ZIP, MD, TXT, Images</p>
                </div>
            </div>
            
            <!-- PROGRESS BAR -->
            <div x-show="uploading" class="mt-4 bg-gray-800 rounded-full h-2 overflow-hidden">
                <div class="bg-indigo-500 h-full transition-all duration-300 w-full animate-pulse"></div>
            </div>
        </div>

        <!-- CONTROLS -->
        <div class="flex justify-between items-center mb-4" x-show="files.length > 0">
            <div class="flex items-center gap-2">
                <button @click="toggleSelectAll()" 
                        class="text-sm px-3 py-1.5 rounded-lg border border-gray-700 hover:bg-gray-800 text-gray-300 transition-colors">
                    <span x-text="selectedFiles.length === files.length ? 'Deselect All' : 'Select All'"></span>
                </button>
                <span class="text-sm text-gray-500" x-show="selectedFiles.length > 0">
                    <span x-text="selectedFiles.length"></span> selected
                </span>
            </div>
            <button @click="deleteSelected()" 
                    x-show="selectedFiles.length > 0"
                    class="bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/20 text-sm px-4 py-1.5 rounded-lg transition-colors flex items-center gap-2">
                <i class="ph ph-trash"></i> Delete
            </button>
        </div>

        <!-- FILES GRID -->
        <div x-show="loading" class="text-center py-12">
            <i class="ph ph-spinner animate-spin text-3xl text-indigo-500"></i>
        </div>

        <div x-show="!loading && files.length === 0" class="text-center py-12 text-gray-500">
            <i class="ph ph-files text-4xl mb-2 opacity-50"></i>
            <p>No files uploaded yet.</p>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4" x-show="!loading && files.length > 0">
            <template x-for="file in files" :key="file.name">
                <div class="bg-gray-800 rounded-xl p-3 border border-gray-700/50 hover:border-gray-600 transition-all group relative">
                    
                    <!-- CHECKBOX -->
                    <div class="absolute top-3 left-3 z-20">
                        <input type="checkbox" :value="file.name" x-model="selectedFiles"
                               class="w-5 h-5 rounded border-gray-600 text-indigo-600 bg-gray-700 focus:ring-indigo-500/50 cursor-pointer">
                    </div>

                    <!-- PREVIEW -->
                    <div class="aspect-video rounded-lg bg-gray-900/50 mb-3 flex items-center justify-center overflow-hidden relative">
                        <template x-if="file.is_image">
                            <img :src="file.url" class="w-full h-full object-cover opacity-80 group-hover:opacity-100 transition-opacity">
                        </template>
                        <template x-if="!file.is_image">
                            <i class="ph ph-file-zip text-4xl text-gray-600" x-show="file.name.endsWith('.zip')"></i>
                            <i class="ph ph-file-text text-4xl text-gray-600" x-show="!file.name.endsWith('.zip')"></i>
                        </template>
                        
                        <!-- OVERLAY ACTIONS -->
                        <div class="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-2">
                            <a :href="file.url" target="_blank" class="p-2 bg-gray-700 rounded-lg hover:bg-gray-600 text-white" title="View">
                                <i class="ph ph-eye"></i>
                            </a>
                            <button @click="copyLink(file.url)" class="p-2 bg-indigo-600 rounded-lg hover:bg-indigo-500 text-white" title="Copy Link">
                                <i class="ph ph-link"></i>
                            </button>
                        </div>
                    </div>

                    <!-- INFO -->
                    <div class="px-1">
                        <p class="text-sm font-medium text-gray-200 truncate" x-text="file.name"></p>
                        <div class="flex justify-between items-center mt-1">
                            <span class="text-xs text-gray-500" x-text="formatSize(file.size)"></span>
                            <span class="text-xs text-gray-600" x-text="formatDate(file.created)"></span>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <!-- TOAST NOTIFICATION -->
    <div class="fixed bottom-6 right-6 z-50 transition-all duration-300 transform" 
         x-show="toast.show" 
         x-transition:enter="translate-y-10 opacity-0"
         x-transition:enter-end="translate-y-0 opacity-100"
         x-transition:leave="translate-y-0 opacity-100"
         x-transition:leave-end="translate-y-10 opacity-0">
        <div class="bg-gray-800 border border-gray-700 shadow-xl rounded-lg px-4 py-3 flex items-center gap-3">
            <i class="ph" :class="toast.type === 'success' ? 'ph-check-circle text-green-400' : 'ph-warning-circle text-red-400'"></i>
            <span class="text-sm text-gray-200" x-text="toast.message"></span>
        </div>
    </div>
</body>
</html>
<?php
}
?>