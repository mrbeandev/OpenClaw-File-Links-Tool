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
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $files[] = [
                'name' => $file,
                'size' => filesize($path),
                'created' => filemtime($path),
                'url' => rtrim(BASE_URL, '/') . '/' . UPLOAD_DIR . $file,
                'view_url' => rtrim(BASE_URL, '/') . '/index.php?action=view&file=' . urlencode($file),
                'is_image' => in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']),
                'is_zip' => ($ext === 'zip'),
                'is_text' => in_array($ext, ['txt', 'md', 'json', 'css', 'js', 'php', 'html'])
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
                    <p class="text-xs text-gray-400">File Bridge</p>
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

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6" x-show="!loading && files.length > 0">
            <template x-for="file in files" :key="file.name">
                <div class="bg-gray-800 rounded-2xl p-4 border border-gray-700/50 relative">
                    
                    <!-- NEW BADGE -->
                    <template x-if="isNew(file.created)">
                        <div class="absolute -top-2 -right-2 z-30 bg-indigo-500 text-white text-[10px] font-bold px-2 py-0.5 rounded-full shadow-lg ring-2 ring-gray-900">
                            NEW
                        </div>
                    </template>

                    <!-- CHECKBOX -->
                    <div class="absolute top-4 left-4 z-20">
                        <input type="checkbox" :value="file.name" x-model="selectedFiles"
                               class="w-5 h-5 rounded-full border-gray-600 text-indigo-600 bg-gray-900/80 backdrop-blur-sm focus:ring-indigo-500/50 cursor-pointer transition-transform active:scale-90">
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
                        
                        <!-- OVERLAY ACTIONS REMOVED -->
                    </div>

                    <!-- INFO -->
                    <div class="mt-4">
                        <div class="flex items-start justify-between gap-2">
                            <p class="text-sm font-semibold text-gray-100 truncate flex-1" :title="file.name" x-text="file.name"></p>
                        </div>
                        <div class="flex flex-row flex-1 justify-between items-center mt-3">
                            <div class="flex items-center gap-2">
                                <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500 bg-gray-900/50 px-2 py-0.5 rounded" x-text="file.name.split('.').pop()"></span>
                                <span class="w-1 h-1 bg-gray-700 rounded-full"></span>
                                <span class="text-xs text-gray-400 font-medium" x-text="formatSize(file.size)"></span>
                            </div>
                            <div class="flex items-center gap-1.5 text-indigo-400">
                                <i class="ph ph-clock text-xs"></i>
                                <span class="text-[11px] font-medium" x-text="formatDate(file.created)"></span>
                            </div>
                        </div>

                        <!-- ACTIONS -->
                        <div class="grid grid-cols-4 gap-2 mt-4 pt-4 border-t border-gray-700/50">
                            <a :href="file.view_url" target="_blank" class="flex items-center justify-center p-2 bg-gray-900/50 hover:bg-indigo-500/20 hover:text-indigo-400 rounded-xl transition-all" title="View">
                                <i class="ph ph-eye text-lg"></i>
                            </a>
                            <a :href="file.url" download class="flex items-center justify-center p-2 bg-gray-900/50 hover:bg-indigo-500/20 hover:text-indigo-400 rounded-xl transition-all" title="Download">
                                <i class="ph ph-download-simple text-lg"></i>
                            </a>
                            <button @click="copyViewLink(file.view_url)" class="flex items-center justify-center p-2 bg-gray-900/50 hover:bg-indigo-500/20 hover:text-indigo-400 rounded-xl transition-all" title="Copy Link">
                                <i class="ph ph-link text-lg"></i>
                            </button>
                            <button @click="deleteFile(file.name)" class="flex items-center justify-center p-2 bg-gray-900/50 hover:bg-red-500/20 hover:text-red-400 rounded-xl transition-all" title="Delete">
                                <i class="ph ph-trash text-lg"></i>
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
        <div class="bg-gray-800 border border-gray-700 shadow-xl rounded-lg px-4 py-3 flex items-center gap-3">
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
            background: #0f172a !important; 
            border-radius: 0 0 0.75rem 0.75rem; 
            border: 1px solid #1e293b; 
            border-top: none;
            margin: 0 !important;
        }
        code[class*="language-"] { font-size: 0.875rem !important; }
        .line-numbers .line-numbers-rows { border-right: 1px solid #1e293b; }
    </style>
    <?php endif; ?>

    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        gray: { 900: '#0f172a', 800: '#1e293b', 700: '#334155' }
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-900 text-gray-100 min-h-screen font-sans">
    <div class="max-w-5xl mx-auto px-4 py-8">
        <!-- HEADER -->
        <header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4 border-b border-gray-800 pb-6">
            <div class="flex items-center gap-4">
                <a href="<?php echo $isInsideZip ? 'index.php?action=view&file='.urlencode($filename) : 'index.php'; ?>" class="w-10 h-10 bg-gray-800 rounded-xl flex items-center justify-center hover:bg-gray-700 transition-colors">
                    <i class="ph ph-arrow-left text-xl"></i>
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
                <a href="<?php echo $rawUrl; ?>" target="_blank" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg transition-colors flex items-center gap-2 text-sm font-medium">
                    <i class="ph ph-hash"></i> Raw
                </a>
                <button onclick="copyToClipboard('<?php echo $rawUrl; ?>')" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg transition-colors flex items-center gap-2 text-sm font-medium">
                    <i class="ph ph-link"></i> Link
                </button>
                <a href="<?php echo $rawUrl; ?>" download class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 rounded-lg transition-colors flex items-center gap-2 text-sm font-medium shadow-lg shadow-indigo-500/20">
                    <i class="ph ph-download-simple"></i> Download
                </a>
            </div>
        </header>

        <!-- CONTENT -->
        <main class="bg-gray-800/50 rounded-2xl border border-gray-800 overflow-hidden">
            <?php if ($type === 'image'): ?>
                <div class="p-8 flex items-center justify-center bg-gray-900/30">
                    <img src="<?php echo $rawUrl; ?>" alt="Preview" class="max-w-full h-auto rounded-lg shadow-2xl">
                </div>
            <?php elseif ($type === 'text'): ?>
                <div class="flex flex-col">
                    <div class="bg-gray-800 px-4 py-2 border border-gray-700 border-b-0 rounded-t-2xl flex justify-between items-center text-xs text-gray-400">
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
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="bg-gray-900/50 text-gray-400 text-xs uppercase tracking-wider">
                                    <th class="px-6 py-4 font-semibold uppercase">File Name</th>
                                    <th class="px-6 py-4 font-semibold uppercase text-right">Size</th>
                                    <th class="px-6 py-4 font-semibold uppercase text-right">Compressed</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-800">
                                <?php foreach ($zipFiles as $file): ?>
                                    <tr class="hover:bg-gray-800/50 transition-colors">
                                        <td class="px-6 py-4 flex items-center justify-between gap-3">
                                            <div class="flex items-center gap-3">
                                                <i class="ph ph-file text-gray-400"></i>
                                                <span class="text-sm font-medium"><?php echo htmlspecialchars($file['name']); ?></span>
                                            </div>
                                            <?php if ($file['is_viewable']): ?>
                                                <a href="index.php?action=view&file=<?php echo urlencode($filename); ?>&inner_file=<?php echo urlencode($file['name']); ?>" 
                                                   class="text-xs bg-indigo-500/10 text-indigo-400 px-2 py-1 rounded hover:bg-indigo-500/20 transition-colors">
                                                    View Content
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-400 text-right"><?php echo formatBytes($file['size']); ?></td>
                                        <td class="px-6 py-4 text-sm text-gray-500 text-right"><?php echo formatBytes($file['compressed']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
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