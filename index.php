<?php
/**
 * Simple File Upload API & Dashboard
 * Deployed at: your-domain.com/upload/
 * * For usage instructions and API documentation, please refer to README.md
 */

// --- SECURE CONFIG LOADING ---
function loadEnv($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
        putenv(trim($name) . "=" . trim($value));
    }
}
loadEnv(__DIR__ . '/.env');

// 1. Set a secure API Key (Priority: .env > Environment Variable > Default)
define('API_KEY', getenv('API_KEY') ?: ($_ENV['API_KEY'] ?? 'CHANGE_ME_IN_ENV'));

// 2. Define the upload directory
define('UPLOAD_DIR', getenv('UPLOAD_DIR') ?: 'uploads/');

// 3. Base URL for the download link
define('BASE_URL', (isset($_SERVER['HTTPS']) ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/');

// 4. Security: Allow only specific extensions
$allowed_extensions = [
    // Archives
    'zip', 'tar', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'zst',
    // Images
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'tiff', 'tif', 'ico', 'avif', 'heic', 'heif',
    // Videos
    'mp4', 'mov', 'avi', 'mkv', 'wmv', 'flv', 'webm', 'm4v', 'mpeg', 'mpg', '3gp',
    // Documents
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'csv', 'rtf',
    // Text / Data
    'txt', 'md', 'json',
];

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
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $files[] = [
                'name' => $file,
                'size' => filesize($path),
                'created' => filemtime($path),
                'url' => rtrim(BASE_URL, '/') . '/' . UPLOAD_DIR . $file,
                'view_url' => rtrim(BASE_URL, '/') . '/index.php?action=view&file=' . urlencode($file),
                'is_image' => in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'tiff', 'tif', 'ico', 'avif', 'heic', 'heif']),
                'is_zip'   => in_array($ext, ['zip', 'tar', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'zst']),
                'is_video' => in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'wmv', 'flv', 'webm', 'm4v', 'mpeg', 'mpg', '3gp']),
                'is_text'  => in_array($ext, ['txt', 'md', 'json', 'css', 'js', 'php', 'html'])
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

// 5. Page: View File Content (GET ?action=view&file=filename)
if ($method === 'GET' && $action === 'view') {
    $filename = $_GET['file'] ?? '';
    if (empty($filename)) {
        die("No file specified.");
    }
    
    $safeName = basename($filename);
    $path = UPLOAD_DIR . $safeName;

    if (!file_exists($path) || !is_file($path)) {
        http_response_code(404);
        die("File not found.");
    }

    renderFileViewer($safeName, $path);
    exit;
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
                        gray: { 950: '#050505', 900: '#0a0a0a', 800: '#121212', 700: '#1c1c1c', 600: '#262626', 500: '#404040', 400: '#6b6b6b', 300: '#9b9b9b', 200: '#c8c8c8', 100: '#ebebeb' }
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

                // Allowed extensions (mirrors server-side list)
                allowedExtensions: [
                    // Archives
                    'zip','tar','gz','tgz','bz2','xz','7z','rar','zst',
                    // Images
                    'jpg','jpeg','png','gif','webp','svg','bmp','tiff','tif','ico','avif','heic','heif',
                    // Videos
                    'mp4','mov','avi','mkv','wmv','flv','webm','m4v','mpeg','mpg','3gp',
                    // Documents
                    'pdf','doc','docx','xls','xlsx','ppt','pptx','odt','ods','odp','csv','rtf',
                    // Text / Data
                    'txt','md','json',
                ],

                // Upload progress state
                uploadProgress: 0,
                uploadedBytes: 0,
                totalBytes: 0,
                uploadSpeed: 0,       // bytes/sec
                uploadETA: 0,         // seconds
                currentFileName: '',
                currentFileIndex: 0,
                totalFiles: 0,

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

                // Returns { valid: File[], rejected: string[] }
                validateFiles(fileList) {
                    const valid = [];
                    const rejected = [];
                    for (const file of fileList) {
                        const ext = file.name.split('.').pop().toLowerCase();
                        if (this.allowedExtensions.includes(ext)) {
                            valid.push(file);
                        } else {
                            rejected.push(file.name);
                        }
                    }
                    return { valid, rejected };
                },

                uploadFileXHR(formData, file) {
                    return new Promise((resolve) => {
                        const xhr = new XMLHttpRequest();
                        const startTime = Date.now();
                        let lastLoaded = 0;
                        let lastTime = startTime;

                        xhr.upload.addEventListener('progress', (e) => {
                            if (!e.lengthComputable) return;
                            const now = Date.now();
                            const elapsed = (now - lastTime) / 1000; // seconds since last sample

                            if (elapsed >= 0.25) { // sample every 250ms for smooth speed
                                const delta = e.loaded - lastLoaded;
                                this.uploadSpeed = delta / elapsed;
                                lastLoaded = e.loaded;
                                lastTime = now;
                            }

                            this.uploadedBytes = e.loaded;
                            this.totalBytes    = e.total;
                            this.uploadProgress = Math.round((e.loaded / e.total) * 100);

                            const remaining = e.total - e.loaded;
                            this.uploadETA = this.uploadSpeed > 0
                                ? Math.ceil(remaining / this.uploadSpeed)
                                : null;
                        });

                        xhr.addEventListener('load', () => {
                            this.uploadProgress = 100;
                            try { resolve(JSON.parse(xhr.responseText)); }
                            catch (e) { resolve({ status: 'error', message: 'Parse error' }); }
                        });

                        xhr.addEventListener('error', () => {
                            resolve({ status: 'error', message: 'Network error' });
                        });

                        xhr.open('POST', 'index.php');
                        xhr.setRequestHeader('X-API-Key', this.apiKey);
                        xhr.send(formData);
                    });
                },

                async handleFiles(fileList) {
                    const { valid, rejected } = this.validateFiles(fileList);

                    // Warn about rejected files immediately — before any upload
                    if (rejected.length > 0) {
                        const names = rejected.length <= 2
                            ? rejected.join(', ')
                            : rejected.slice(0, 2).join(', ') + ` +${rejected.length - 2} more`;
                        this.showToast(`Blocked (unsupported type): ${names}`, 'error');
                    }

                    if (valid.length === 0) return;

                    this.uploading = true;
                    this.totalFiles = valid.length;
                    let successCount = 0;

                    for (let i = 0; i < valid.length; i++) {
                        this.currentFileIndex = i + 1;
                        this.currentFileName  = valid[i].name;
                        this.uploadProgress   = 0;
                        this.uploadedBytes    = 0;
                        this.totalBytes       = valid[i].size;
                        this.uploadSpeed      = 0;
                        this.uploadETA        = null;

                        const formData = new FormData();
                        formData.append('file', valid[i]);

                        try {
                            const data = await this.uploadFileXHR(formData, valid[i]);
                            if (data.status === 'success') successCount++;
                            else this.showToast(data.message, 'error');
                        } catch (e) {
                            console.error(e);
                        }
                    }

                    if (successCount > 0) {
                        this.showToast(`Uploaded ${successCount} file${successCount > 1 ? 's' : ''}`);
                        await this.verifyAndFetch();
                    }
                    this.uploading = false;
                    this.uploadProgress = 0;
                },

                formatSpeed(bytesPerSec) {
                    if (!bytesPerSec || bytesPerSec <= 0) return '';
                    const k = 1024;
                    if (bytesPerSec >= k * k) return (bytesPerSec / (k * k)).toFixed(1) + ' MB/s';
                    if (bytesPerSec >= k)     return (bytesPerSec / k).toFixed(0) + ' KB/s';
                    return bytesPerSec.toFixed(0) + ' B/s';
                },

                formatETA(secs) {
                    if (!secs || secs <= 0) return '';
                    if (secs < 60)  return secs + 's';
                    if (secs < 3600) return Math.floor(secs / 60) + 'm ' + (secs % 60) + 's';
                    return Math.floor(secs / 3600) + 'h ' + Math.floor((secs % 3600) / 60) + 'm';
                },

                async deleteFile(name) {
                    if (!confirm(`Permanently delete ${name}?`)) return;
                    try {
                        const res = await fetch('?action=delete', {
                            method: 'POST',
                            headers: { 
                                'Content-Type': 'application/json',
                                'X-API-Key': this.apiKey
                            },
                            body: JSON.stringify({ files: [name] })
                        });
                        const data = await res.json();
                        if (data.status === 'success') {
                            this.showToast('File deleted');
                            await this.verifyAndFetch();
                        }
                    } catch (e) {
                        this.showToast('Failed to delete file', 'error');
                    }
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

                copyViewLink(url) {
                    navigator.clipboard.writeText(url).then(() => {
                        this.showToast('Viewer link copied');
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
                    const now = Math.floor(Date.now() / 1000);
                    const diff = now - timestamp;
                    
                    if (diff < 60) return 'Just now';
                    if (diff < 3600) return Math.floor(diff / 60) + ' mins ago';
                    if (diff < 86400) return Math.floor(diff / 3600) + ' hours ago';
                    if (diff < 172800) return 'Yesterday';
                    
                    return new Date(timestamp * 1000).toLocaleDateString();
                },

                isNew(timestamp) {
                    const now = Math.floor(Date.now() / 1000);
                    return (now - timestamp) < 3600; // New if uploaded in last 1 hour
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
<body class="bg-gray-900 text-gray-100 font-sans min-h-screen selection:bg-white selection:text-black"
      x-data="fileManager()">

    <!-- AUTH MODAL -->
    <div x-show="!isAuthenticated" 
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-sm px-4">
        <div class="bg-gray-800 p-8 rounded-2xl shadow-2xl w-full max-w-md border border-white/15">
            <div class="text-center mb-6">
                <i class="ph ph-lock-key text-7xl text-white mb-4"></i>
                <h2 class="text-3xl font-bold tracking-tight">Access Restricted</h2>
                <p class="text-gray-400 text-sm mt-2">Enter API Key to manage files</p>
            </div>
            <form @submit.prevent="login">
                <input type="password" x-model="inputKey" 
                       class="w-full bg-gray-900 border border-gray-600 rounded-lg px-4 py-3 text-white focus:outline-none focus:border-white focus:ring-1 focus:ring-white/20 transition-all placeholder-gray-500" 
                       placeholder="API Key..." autofocus>
                <button type="submit" 
                        class="w-full mt-4 bg-white hover:bg-gray-100 text-black font-semibold py-3 rounded-lg transition-colors flex items-center justify-center gap-2">
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
                <div class="w-12 h-12 bg-white rounded-xl flex items-center justify-center flex-shrink-0">
                    <i class="ph ph-cloud-arrow-up text-2xl text-black"></i>
                </div>
                <div>
                    <h1 class="text-2xl font-bold tracking-tight">Upload Center</h1>
                    <p class="text-xs text-gray-400 uppercase tracking-widest">File Bridge</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-xs bg-white/5 text-gray-300 px-3 py-1 rounded-full border border-white/10 uppercase tracking-widest">
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
                 :class="dragHover ? 'border-white bg-white/5' : 'border-white/20 bg-gray-800 hover:border-white/40'">
                
                <input type="file" multiple @change="handleFiles($event.target.files)" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10">
                
                <div class="pointer-events-none">
                    <div class="w-20 h-20 bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-5 group-hover:bg-gray-500 transition-colors">
                        <i class="ph ph-upload-simple text-4xl text-white"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-white">Drop files here or click to upload</h3>
                    <p class="text-sm text-gray-500 mt-2">Images &bull; Videos &bull; Archives &bull; Office Docs &bull; PDF &bull; Text</p>
                </div>
            </div>
            
            <!-- LIVE UPLOAD PROGRESS -->
            <div x-show="uploading" x-cloak class="mt-4 bg-gray-800/80 border border-white/12 rounded-2xl px-5 py-4 space-y-3">

                <!-- File info row -->
                <div class="flex items-center justify-between gap-2 text-sm">
                    <div class="flex items-center gap-2 min-w-0">
                        <i class="ph ph-file-arrow-up text-gray-300 flex-shrink-0"></i>
                        <span class="text-gray-200 truncate font-medium" x-text="currentFileName"></span>
                    </div>
                    <span class="flex-shrink-0 text-xs text-gray-400 font-medium"
                          x-show="totalFiles > 1"
                          x-text="'File ' + currentFileIndex + ' / ' + totalFiles"></span>
                </div>

                <!-- Progress bar -->
                <div class="w-full bg-gray-900 rounded-full h-2.5 overflow-hidden">
                    <div class="bg-white h-full rounded-full transition-all duration-150"
                         :style="'width:' + uploadProgress + '%'"></div>
                </div>

                <!-- Stats row -->
                <div class="flex items-center justify-between text-xs text-gray-400 font-mono">
                    <!-- Left: bytes -->
                    <span x-text="formatSize(uploadedBytes) + ' / ' + formatSize(totalBytes)"></span>

                    <!-- Center: percentage -->
                    <span class="text-white font-bold text-sm" x-text="uploadProgress + '%'"></span>

                    <!-- Right: speed + ETA -->
                    <div class="flex items-center gap-2">
                        <span x-show="uploadSpeed > 0" x-text="formatSpeed(uploadSpeed)"></span>
                        <span x-show="uploadETA > 0" class="text-gray-500">
                            &bull; ETA <span x-text="formatETA(uploadETA)"></span>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- CONTROLS -->
        <div class="flex justify-between items-center mb-4" x-show="files.length > 0">
            <div class="flex items-center gap-2">
                <button @click="toggleSelectAll()" 
                        class="text-sm px-3 py-1.5 rounded-lg border border-white/15 hover:bg-white/5 text-gray-300 transition-colors">
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
            <i class="ph ph-spinner animate-spin text-4xl text-white"></i>
        </div>

        <div x-show="!loading && files.length === 0" class="text-center py-16 text-gray-500">
            <i class="ph ph-files text-7xl mb-4 opacity-30"></i>
            <p>No files uploaded yet.</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6" x-show="!loading && files.length > 0">
            <template x-for="file in files" :key="file.name">
                <div class="bg-gray-800 rounded-2xl p-4 border border-white/10 relative">
                    
                    <!-- NEW BADGE -->
                    <template x-if="isNew(file.created)">
                        <div class="absolute -top-2 -right-2 z-30 bg-white text-black text-[10px] font-bold px-2 py-0.5 rounded-full shadow-lg ring-2 ring-black">
                            NEW
                        </div>
                    </template>

                    <!-- CHECKBOX -->
                    <div class="absolute top-4 left-4 z-20">
                        <input type="checkbox" :value="file.name" x-model="selectedFiles"
                               class="w-5 h-5 rounded border-gray-500 text-white bg-gray-900/80 backdrop-blur-sm focus:ring-white/30 cursor-pointer transition-transform active:scale-90">
                    </div>

                    <!-- PREVIEW -->
                    <div class="aspect-video rounded-lg bg-gray-900/50 mb-3 flex items-center justify-center overflow-hidden relative">
                        <template x-if="file.is_image">
                            <img :src="file.url" class="w-full h-full object-cover opacity-80 group-hover:opacity-100 transition-opacity">
                        </template>
                        <template x-if="file.is_video">
                            <video :src="file.url" class="w-full h-full object-cover opacity-80" muted preload="metadata"></video>
                        </template>
                        <template x-if="!file.is_image && !file.is_video">
                            <i class="ph text-5xl text-gray-400"
                               :class="{
                                   'ph-file-zip': file.is_zip,
                                   'ph-file-pdf': file.name.endsWith('.pdf'),
                                   'ph-microsoft-excel-logo': ['xls','xlsx','ods','csv'].includes(file.name.split('.').pop()),
                                   'ph-microsoft-word-logo': ['doc','docx','odt','rtf'].includes(file.name.split('.').pop()),
                                   'ph-microsoft-powerpoint-logo': ['ppt','pptx','odp'].includes(file.name.split('.').pop()),
                                   'ph-file-text': !file.is_zip && !file.name.endsWith('.pdf')
                               }"></i>
                        </template>
                    </div>

                    <!-- INFO -->
                    <div class="mt-4">
                        <div class="flex items-start justify-between gap-2">
                            <p class="text-base font-bold text-white truncate flex-1" :title="file.name" x-text="file.name"></p>
                        </div>
                        <div class="flex flex-row flex-1 justify-between items-center mt-3">
                            <div class="flex items-center gap-2">
                                <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500 bg-gray-900/50 px-2 py-0.5 rounded" x-text="file.name.split('.').pop()"></span>
                                <span class="w-1 h-1 bg-gray-700 rounded-full"></span>
                                <span class="text-xs text-gray-400 font-medium" x-text="formatSize(file.size)"></span>
                            </div>
                            <div class="flex items-center gap-1.5 text-gray-400">
                                <i class="ph ph-clock text-xs"></i>
                                <span class="text-[11px] font-medium" x-text="formatDate(file.created)"></span>
                            </div>
                        </div>

                        <!-- ACTIONS -->
                        <div class="grid grid-cols-4 gap-2 mt-4 pt-4 border-t border-white/8">
                            <a :href="file.view_url" target="_blank" class="flex items-center justify-center p-2.5 bg-gray-700/50 hover:bg-white/10 hover:text-white rounded-xl transition-all" title="View">
                                <i class="ph ph-eye text-xl"></i>
                            </a>
                            <a :href="file.url" download class="flex items-center justify-center p-2.5 bg-gray-700/50 hover:bg-white/10 hover:text-white rounded-xl transition-all" title="Download">
                                <i class="ph ph-download-simple text-xl"></i>
                            </a>
                            <button @click="copyViewLink(file.view_url)" class="flex items-center justify-center p-2.5 bg-gray-700/50 hover:bg-white/10 hover:text-white rounded-xl transition-all" title="Copy Link">
                                <i class="ph ph-link text-xl"></i>
                            </button>
                            <button @click="deleteFile(file.name)" class="flex items-center justify-center p-2.5 bg-gray-700/50 hover:bg-red-500/15 hover:text-red-400 rounded-xl transition-all" title="Delete">
                                <i class="ph ph-trash text-xl"></i>
                            </button>
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
        <div class="bg-gray-800 border border-white/15 shadow-xl rounded-lg px-4 py-3 flex items-center gap-3">
            <i class="ph" :class="toast.type === 'success' ? 'ph-check-circle text-green-400' : 'ph-warning-circle text-red-400'"></i>
            <span class="text-sm text-gray-200" x-text="toast.message"></span>
        </div>
    </div>
</body>
</html>
<?php
}

// --- FILE VIEWER PAGE ---
function renderFileViewer($filename, $path) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $innerFile = $_GET['inner_file'] ?? '';
    $filesize = filesize($path);
    $created = date("F j, Y, g:i a", filemtime($path));
    $rawUrl = rtrim(BASE_URL, '/') . '/' . UPLOAD_DIR . $filename;
    
    $content = '';
    $type = 'other';
    $language = '';
    $isInsideZip = false;

    if ($ext === 'zip' && $innerFile) {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive;
            if ($zip->open($path) === TRUE) {
                $innerContent = $zip->getFromName($innerFile);
                $zip->close();
                if ($innerContent !== false) {
                    $type = 'text';
                    $displayContent = htmlspecialchars($innerContent);
                    $innerExt = strtolower(pathinfo($innerFile, PATHINFO_EXTENSION));
                    $language = ($innerExt === 'md') ? 'markdown' : (($innerExt === 'js') ? 'javascript' : (in_array($innerExt, ['txt', 'json', 'css', 'js', 'php', 'html', 'xml', 'yaml', 'yml']) ? $innerExt : 'text'));
                    $isInsideZip = true;
                }
            }
        }
    }

    if (!$isInsideZip) {
        if (in_array($ext, ['txt', 'md', 'json', 'css', 'js', 'php', 'html', 'xml', 'yaml', 'yml'])) {
            $type = 'text';
            $content = file_get_contents($path);
            $displayContent = htmlspecialchars($content);
            $language = ($ext === 'md') ? 'markdown' : (($ext === 'js') ? 'javascript' : $ext);
        } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'])) {
            $type = 'image';
        } elseif ($ext === 'pdf') {
            $type = 'pdf';
        } elseif ($ext === 'zip') {
            $type = 'zip';
            $zipFiles = [];
            
            if (class_exists('ZipArchive')) {
                $zip = new ZipArchive;
                if ($zip->open($path) === TRUE) {
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $stat = $zip->statIndex($i);
                        $fext = strtolower(pathinfo($stat['name'], PATHINFO_EXTENSION));
                        $zipFiles[] = [
                            'name' => $stat['name'],
                            'size' => $stat['size'],
                            'compressed' => $stat['comp_size'],
                            'is_viewable' => in_array($fext, ['txt', 'md', 'json', 'css', 'js', 'php', 'html', 'xml', 'yaml', 'yml'])
                        ];
                    }
                    $zip->close();
                }
            } else {
                $zipError = true;
            }
        }
    }
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Viewing: <?php echo htmlspecialchars($filename); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    
    <?php if ($type === 'text'): ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/line-numbers/prism-line-numbers.min.css" rel="stylesheet" />
    <style>
        pre[class*="language-"] { 
            background: #0a0a0a !important; 
            border-radius: 0 0 0.75rem 0.75rem; 
            border: 1px solid #1c1c1c; 
            border-top: none;
            margin: 0 !important;
        }
        code[class*="language-"] { font-size: 0.9rem !important; }
        .line-numbers .line-numbers-rows { border-right: 1px solid #1c1c1c; }
    </style>
    <?php endif; ?>

    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        gray: { 900: '#0a0a0a', 800: '#121212', 700: '#1c1c1c', 600: '#262626', 500: '#404040', 400: '#6b6b6b' }
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-900 text-gray-100 min-h-screen font-sans">
    <div class="max-w-5xl mx-auto px-4 py-8">
        <!-- HEADER -->
        <header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4 border-b border-white/10 pb-6">
            <div class="flex items-center gap-4">
                <a href="<?php echo $isInsideZip ? 'index.php?action=view&file='.urlencode($filename) : 'index.php'; ?>" class="w-12 h-12 bg-gray-800 rounded-xl flex items-center justify-center hover:bg-gray-700 hover:text-white transition-colors flex-shrink-0">
                    <i class="ph ph-arrow-left text-2xl"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold truncate max-w-md">
                        <?php if ($isInsideZip): ?>
                            <span class="text-gray-500 text-lg block"><?php echo htmlspecialchars($filename); ?> /</span>
                            <?php echo htmlspecialchars(basename($innerFile)); ?>
                        <?php else: ?>
                            <?php echo htmlspecialchars($filename); ?>
                        <?php endif; ?>
                    </h1>
                    <div class="flex items-center gap-3 text-sm text-gray-400 mt-1">
                        <span><?php echo $isInsideZip ? 'Inner File' : formatBytes($filesize); ?></span>
                        <span class="w-1 h-1 bg-gray-600 rounded-full"></span>
                        <span><?php echo $created; ?></span>
                    </div>
                </div>
            </div>
            <div class="flex gap-2">
                <a href="<?php echo $rawUrl; ?>" target="_blank" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 border border-white/15 rounded-lg transition-colors flex items-center gap-2 text-sm font-medium">
                    <i class="ph ph-hash"></i> Raw
                </a>
                <button onclick="copyToClipboard('<?php echo $rawUrl; ?>')" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 border border-white/15 rounded-lg transition-colors flex items-center gap-2 text-sm font-medium">
                    <i class="ph ph-link"></i> Link
                </button>
                <a href="<?php echo $rawUrl; ?>" download class="px-5 py-2.5 bg-white hover:bg-gray-100 text-black rounded-lg transition-colors flex items-center gap-2 text-sm font-semibold shadow-lg shadow-black/30">
                    <i class="ph ph-download-simple"></i> Download
                </a>
            </div>
        </header>

        <!-- CONTENT -->
        <main class="bg-gray-800/40 rounded-2xl border border-white/10 overflow-hidden">
            <?php if ($type === 'image'): ?>
                <div class="p-8 flex items-center justify-center bg-gray-900/30">
                    <img src="<?php echo $rawUrl; ?>" alt="Preview" class="max-w-full h-auto rounded-lg shadow-2xl">
                </div>
            <?php elseif ($type === 'text'): ?>
                <div class="flex flex-col">
                    <div class="bg-gray-800 px-4 py-2 border border-white/10 border-b-0 rounded-t-2xl flex justify-between items-center text-xs text-gray-400">
                        <span class="font-mono uppercase tracking-widest"><?php echo $language; ?></span>
                        <button onclick="copyContent()" class="hover:text-white transition-colors flex items-center gap-1.5">
                            <i class="ph ph-copy"></i> Copy Content
                        </button>
                    </div>
                    <pre class="line-numbers language-<?php echo $language; ?>"><code id="code-block" class="language-<?php echo $language; ?>"><?php echo $displayContent; ?></code></pre>
                </div>
            <?php elseif ($type === 'pdf'): ?>
                <div class="aspect-[1/1.4] w-full">
                    <iframe src="<?php echo $rawUrl; ?>" class="w-full h-full border-none"></iframe>
                </div>
            <?php elseif ($type === 'zip'): ?>
                <?php if (isset($zipError)): ?>
                    <div class="p-20 text-center">
                        <i class="ph ph-warning-circle text-6xl text-amber-500 mb-4"></i>
                        <h2 class="text-xl font-medium text-gray-300">ZIP Extension Missing</h2>
                        <p class="text-gray-500 mt-2">The PHP `zip` extension is not installed. Unable to inspect contents.</p>
                    </div>
                <?php else: ?>
                    <!-- ZIP TREE HEADER -->
                    <div class="flex items-center justify-between px-5 py-3 bg-gray-900 border-b border-white/10 text-xs text-gray-400">
                        <span><?php echo count($zipFiles); ?> entries</span>
                        <div class="flex gap-3">
                            <button onclick="zipTree.expandAll()" class="hover:text-gray-200 transition-colors">Expand all</button>
                            <span class="text-gray-700">|</span>
                            <button onclick="zipTree.collapseAll()" class="hover:text-gray-200 transition-colors">Collapse all</button>
                        </div>
                    </div>

                    <!-- ZIP TREE BODY -->
                    <div id="zip-tree" class="py-2 font-mono text-sm select-none overflow-x-auto min-w-0"></div>

                    <style>
                        .zt-row { display:flex; align-items:center; gap:6px; padding:3px 12px; cursor:default; border-radius:4px; white-space:nowrap; }
                        .zt-row:hover { background:rgba(255,255,255,.05); }
                        .zt-folder { cursor:pointer; }
                        .zt-folder:hover { background:rgba(255,255,255,.08); }
                        .zt-caret { font-size:10px; color:#6b7280; width:12px; flex-shrink:0; transition:transform .15s; }
                        .zt-caret.open { transform:rotate(90deg); }
                        .zt-icon { font-size:16px; flex-shrink:0; }
                        .zt-name { color:#e8e8e8; flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; font-size:14px; }
                        .zt-folder .zt-name { color:#aaaaaa; }
                        .zt-meta { display:flex; align-items:center; gap:10px; margin-left:auto; padding-left:16px; flex-shrink:0; }
                        .zt-size { color:#555555; font-size:12px; min-width:52px; text-align:right; }
                        .zt-count { color:#444444; font-size:10px; }
                        .zt-view { font-size:11px; font-family:sans-serif; background:rgba(255,255,255,.1); color:#d4d4d4; padding:2px 8px; border-radius:4px; text-decoration:none; transition:background .15s; border:1px solid rgba(255,255,255,.15); }
                        .zt-view:hover { background:rgba(255,255,255,.2); color:#ffffff; }
                        .zt-children { overflow:hidden; transition:height .18s ease; }
                        #zip-tree { min-height:40px; }
                    </style>

                    <script>
                    (function() {
                        const FILES = <?php echo json_encode($zipFiles); ?>;
                        const BASE_FILE = '<?php echo addslashes($filename); ?>';

                        // ── helpers ───────────────────────────────────────
                        function fmtBytes(b) {
                            if (!b) return '';
                            if (b < 1024) return b + ' B';
                            if (b < 1024*1024) return (b/1024).toFixed(1) + ' KB';
                            return (b/1024/1024).toFixed(2) + ' MB';
                        }

                        const EXT_ICON = {
                            js:'ph-file-js', ts:'ph-file-ts', jsx:'ph-file-jsx', tsx:'ph-file-tsx',
                            html:'ph-file-html', css:'ph-file-css', json:'ph-file-code',
                            md:'ph-file-text', txt:'ph-file-text',
                            php:'ph-file-code', py:'ph-file-py', rb:'ph-file-code',
                            png:'ph-file-image', jpg:'ph-file-image', jpeg:'ph-file-image',
                            gif:'ph-file-image', svg:'ph-file-image', webp:'ph-file-image',
                            pdf:'ph-file-pdf', zip:'ph-file-zip',
                            sh:'ph-terminal', yaml:'ph-file-code', yml:'ph-file-code',
                            xml:'ph-file-code', csv:'ph-table',
                        };
                        function getIcon(name) {
                            const ext = name.split('.').pop().toLowerCase();
                            return EXT_ICON[ext] || 'ph-file';
                        }

                        // ── build tree ────────────────────────────────────
                        function buildTree(files) {
                            const root = { _dir: true, _children: {}, _count: 0 };
                            for (const f of files) {
                                const isDir = f.name.endsWith('/');
                                const parts = f.name.replace(/\/$/, '').split('/').filter(Boolean);
                                let node = root;
                                for (let i = 0; i < parts.length; i++) {
                                    const p = parts[i];
                                    if (!node._children[p]) {
                                        node._children[p] = { _dir: (i < parts.length - 1) || isDir, _children: {}, _count: 0, _data: null };
                                    }
                                    if (i === parts.length - 1 && !isDir) {
                                        node._children[p]._data = f;
                                    }
                                    node._count++;
                                    node = node._children[p];
                                }
                            }
                            return root;
                        }

                        // ── render ────────────────────────────────────────
                        let uidCounter = 0;
                        function renderNode(name, node, depth) {
                            const pad = depth * 18 + 8;

                            if (node._dir) {
                                const uid = 'ztd' + (++uidCounter);
                                const childCount = Object.keys(node._children).length;
                                const div = document.createElement('div');

                                const row = document.createElement('div');
                                row.className = 'zt-row zt-folder';
                                row.style.paddingLeft = pad + 'px';
                                row.innerHTML = `
                                    <i class="ph ph-caret-right zt-caret" id="c_${uid}"></i>
                                    <i class="ph ph-folder-open zt-icon" style="color:#facc15"></i>
                                    <span class="zt-name">${escHtml(name)}</span>
                                    <div class="zt-meta">
                                        <span class="zt-count">${childCount} item${childCount!==1?'s':''}</span>
                                    </div>`;

                                const children = document.createElement('div');
                                children.className = 'zt-children';
                                children.id = uid;
                                children.style.display = 'block';

                                // Render children
                                const sorted = sortedEntries(node._children);
                                for (const [cname, cnode] of sorted) {
                                    children.appendChild(renderNode(cname, cnode, depth + 1));
                                }

                                row.addEventListener('click', () => toggleDir(uid));
                                // Start open for first level
                                if (depth > 0) { children.style.display = 'none'; }
                                else { document.getElementById('c_' + uid)?.classList.add('open'); }

                                div.appendChild(row);
                                div.appendChild(children);
                                return div;
                            } else {
                                // File node
                                const f = node._data || {};
                                const row = document.createElement('div');
                                row.className = 'zt-row';
                                row.style.paddingLeft = (pad + 18) + 'px';
                                const viewHtml = f.is_viewable
                                    ? `<a href="index.php?action=view&file=${encodeURIComponent(BASE_FILE)}&inner_file=${encodeURIComponent(f.name)}" class="zt-view">View</a>`
                                    : '';
                                row.innerHTML = `
                                    <i class="ph ${getIcon(name)} zt-icon" style="color:#64748b"></i>
                                    <span class="zt-name">${escHtml(name)}</span>
                                    <div class="zt-meta">
                                        <span class="zt-size">${fmtBytes(f.size||0)}</span>
                                        ${viewHtml}
                                    </div>`;
                                return row;
                            }
                        }

                        function sortedEntries(obj) {
                            return Object.entries(obj).sort(([an, av], [bn, bv]) => {
                                if (av._dir && !bv._dir) return -1;
                                if (!av._dir && bv._dir) return 1;
                                return an.localeCompare(bn);
                            });
                        }

                        function escHtml(s) {
                            return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                        }

                        function toggleDir(uid) {
                            const el = document.getElementById(uid);
                            const caret = document.getElementById('c_' + uid);
                            if (!el) return;
                            const open = el.style.display !== 'none';
                            el.style.display = open ? 'none' : 'block';
                            caret?.classList.toggle('open', !open);
                        }

                        // ── public API for expand/collapse all ────────────
                        window.zipTree = {
                            expandAll() {
                                document.querySelectorAll('.zt-children').forEach(el => {
                                    el.style.display = 'block';
                                });
                                document.querySelectorAll('.zt-caret').forEach(el => el.classList.add('open'));
                            },
                            collapseAll() {
                                document.querySelectorAll('.zt-children').forEach(el => {
                                    el.style.display = 'none';
                                });
                                document.querySelectorAll('.zt-caret').forEach(el => el.classList.remove('open'));
                            }
                        };

                        // ── mount ─────────────────────────────────────────
                        const tree = buildTree(FILES);
                        const container = document.getElementById('zip-tree');
                        const sorted = sortedEntries(tree._children);
                        for (const [name, node] of sorted) {
                            container.appendChild(renderNode(name, node, 0));
                        }
                    })();
                    </script>
                <?php endif; ?>
            <?php else: ?>
                <div class="p-20 text-center">
                    <i class="ph ph-file-search text-6xl text-gray-600 mb-4"></i>
                    <h2 class="text-xl font-medium text-gray-300">Preview not available for this file type</h2>
                    <p class="text-gray-500 mt-2">You can still download the file using the button above.</p>
                </div>
            <?php endif; ?>
        </main>
        
        <footer class="mt-8 text-center text-gray-600 text-xs">
            &copy; <?php echo date('Y'); ?> MrBeanDev Upload Center Viewer
        </footer>
    </div>

    <?php if ($type === 'text'): ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/line-numbers/prism-line-numbers.min.js"></script>
    <?php if ($language === 'markdown'): ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-markdown.min.js"></script>
    <?php elseif ($language === 'javascript'): ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-javascript.min.js"></script>
    <?php elseif ($language === 'php'): ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-php.min.js"></script>
    <?php elseif (in_array($language, ['css', 'json', 'html'])): ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-<?php echo $language; ?>.min.js"></script>
    <?php endif; ?>
    <?php endif; ?>

    <script>
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                alert('Copied to clipboard!');
            });
        }
        
        function copyContent() {
            const code = document.getElementById('code-block').innerText;
            navigator.clipboard.writeText(code).then(() => {
                alert('Content copied to clipboard!');
            });
        }
    </script>
</body>
</html>
<?php
}

// Helper: Format Bytes
function formatBytes($bytes, $precision = 2) {
    if ($bytes <= 0) return '0 B';
    $base = log($bytes, 1024);
    $suffixes = array('B', 'KB', 'MB', 'GB', 'TB');
    return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)];
}
?>