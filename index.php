<?php
// === BACKEND (PHP) ===
$dataFolder = 'saved_data/';
$imageFolder = 'saved_images/';
date_default_timezone_set('Asia/Kolkata'); 

if (!file_exists($dataFolder)) mkdir($dataFolder, 0755, true);
if (!file_exists($imageFolder)) mkdir($imageFolder, 0755, true);

function getFormattedSize($path) {
    if (!file_exists($path)) return '0 B';
    $bytes = filesize($path);
    $units = ['B', 'KB', 'MB', 'GB'];
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) $bytes /= 1024;
    return round($bytes, 2) . ' ' . $units[$i];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $customId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['custom_id'] ?? '');
        $id = $customId !== '' ? $customId : substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyz"), 0, 6);
        
        if ($customId !== '' && file_exists($dataFolder . $id . '.json')) {
            echo json_encode(['success' => false, 'message' => 'Custom alias is already in use.']);
            exit;
        }

        $text = $_POST['text'] ?? '';
        $password = $_POST['password'] ?? '';
        $viewOnce = ($_POST['view_once'] ?? 'false') === 'true';
        $expiryHours = floatval($_POST['expiry'] ?? 0);
        $imagePath = '';

        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            // Check file size (5MB limit = 5 * 1024 * 1024 bytes)
            if ($_FILES['image']['size'] > 5242880) {
                echo json_encode(['success' => false, 'message' => 'Image exceeds 5MB limit.']);
                exit;
            }

            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                $dest = $imageFolder . $id . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
                    $imagePath = $dest;
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Only image files are allowed.']);
                exit;
            }
        }

        if (trim($text) !== '' || $imagePath !== '') {
            $data = [
                'text' => $text,
                'image' => $imagePath,
                'password_hash' => $password ? password_hash($password, PASSWORD_DEFAULT) : '',
                'view_once' => $viewOnce,
                'views' => 0,
                'created_at' => time(),
                'expires_at' => $expiryHours > 0 ? time() + ($expiryHours * 3600) : 0
            ];
            file_put_contents($dataFolder . $id . '.json', json_encode($data));
            echo json_encode(['success' => true, 'id' => $id]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Payload cannot be empty.']);
        }
        exit;
    }

    if ($action === 'unlock') {
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['id'] ?? '');
        $pass = $_POST['password'] ?? '';
        $file = $dataFolder . $id . '.json';
        
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            if (password_verify($pass, $data['password_hash'])) {
                $imgSize = (!empty($data['image']) && file_exists($data['image'])) ? getFormattedSize($data['image']) : '';
                $createdDate = isset($data['created_at']) ? date('M j, Y • h:i A', $data['created_at']) : 'Unknown';

                if ($data['view_once']) {
                    unlink($file); 
                } else {
                    $data['views'] = ($data['views'] ?? 0) + 1;
                    file_put_contents($file, json_encode($data)); 
                }
                echo json_encode([
                    'success' => true, 
                    'text' => $data['text'], 
                    'image' => $data['image'], 
                    'image_size' => $imgSize, 
                    'view_once' => $data['view_once'],
                    'views' => $data['views'] ?? 1,
                    'created_date' => $createdDate
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Decryption failed. Invalid key.']);
            }
        }
        exit;
    }

    if ($action === 'cleanup_image') {
        $path = $_POST['path'] ?? '';
        if (!empty($path) && strpos($path, $imageFolder) === 0 && file_exists($path)) {
            unlink($path);
            echo json_encode(['success' => true]);
        }
        exit;
    }
}

// GET Requests
$isReadMode = false;
$requiresPassword = false;
$viewText = "";
$viewImage = "";
$viewImageSize = "";
$errorMsg = "";
$isViewOnceRead = false;
$viewCount = 0;
$createdDate = "";

if (isset($_GET['id'])) {
    $isReadMode = true;
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['id']);
    $file = $dataFolder . $id . '.json';

    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        
        if ($data['expires_at'] > 0 && time() > $data['expires_at']) {
            unlink($file);
            if (file_exists($data['image'])) unlink($data['image']);
            $errorMsg = "This drop has expired and was permanently destroyed.";
        } else if (!empty($data['password_hash'])) {
            $requiresPassword = true;
        } else {
            $viewText = $data['text'];
            $viewImage = $data['image'];
            $viewImageSize = (!empty($viewImage) && file_exists($viewImage)) ? getFormattedSize($viewImage) : '';
            $isViewOnceRead = $data['view_once'];
            $createdDate = isset($data['created_at']) ? date('M j, Y • h:i A', $data['created_at']) : 'Unknown';
            
            if ($isViewOnceRead) {
                unlink($file); 
            } else {
                $data['views'] = ($data['views'] ?? 0) + 1;
                $viewCount = $data['views'];
                file_put_contents($file, json_encode($data));
            }
        }
    } else {
        $errorMsg = "Drop not found. It may have expired or been destroyed.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PasteShare | Secure Text & Image Drop</title>
    <meta name="description" content="Securely share text, code, and images with auto-destruct and password protection.">
    <meta name="theme-color" content="#0b0b0e">

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.8.0/styles/github-dark.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.8.0/highlight.min.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: #0b0b0e;
            color: #fafafa;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .premium-card {
            background-color: #141417; 
            border: 1px solid #2a2a2e; 
            box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.5);
        }

        pre, code, textarea {
            white-space: pre-wrap !important;
            word-break: break-word !important;
            overflow-wrap: break-word !important;
        }

        input, textarea, select {
            background-color: #0b0b0e !important;
            border: 1px solid #2a2a2e !important;
            color: #fafafa !important;
            transition: all 0.2s ease;
            width: 100%;
        }
        input:focus, textarea:focus, select:focus {
            border-color: #71717a !important; 
            outline: none;
            box-shadow: 0 0 0 2px rgba(250, 250, 250, 0.05) !important;
        }
        
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #3f3f46; border-radius: 4px; }
    </style>
</head>
<body class="antialiased selection:bg-white selection:text-black">

    <div class="premium-card w-full max-w-2xl rounded-3xl p-6 sm:p-10 space-y-6 sm:space-y-8 relative overflow-hidden flex flex-col">
        
        <div class="flex flex-col items-center space-y-1 mb-2">
            <svg class="w-8 h-8 sm:w-10 sm:h-10 text-white mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path></svg>
            <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tighter text-white">PasteShare</h1>
            <p class="text-xs sm:text-sm text-zinc-400 text-center">Securely paste text, code, or attach images to share.</p>
        </div>

        <?php if ($errorMsg): ?>
            <div class="border border-red-900/50 bg-red-950/20 p-6 sm:p-8 rounded-2xl text-center space-y-4">
                <svg class="w-12 h-12 text-red-500 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                <h3 class="text-lg sm:text-xl font-semibold text-red-400"><?= $errorMsg ?></h3>
                <a href="index.php" class="inline-block mt-3 bg-zinc-800 hover:bg-zinc-700 text-sm font-medium px-8 py-3 rounded-xl transition">Create New Drop</a>
            </div>

        <?php elseif ($requiresPassword): ?>
            <div id="lockScreen" class="w-full max-w-sm mx-auto space-y-6 text-center py-4">
                <div class="flex justify-center text-zinc-600 mb-2"><svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg></div>
                <p class="text-xs sm:text-sm text-zinc-400">Decryption key required to access drop.</p>
                <input type="password" id="unlockPass" placeholder="Enter Key" class="w-full p-4 rounded-xl text-center font-medium text-base sm:text-lg">
                <button onclick="unlockContent()" class="w-full bg-white text-black hover:bg-zinc-200 font-semibold py-4 rounded-xl transition shadow-lg text-base sm:text-lg">
                    Unlock Drop
                </button>
            </div>
            
            <div id="unlockedContent" class="hidden flex-col space-y-5 w-full">
                <div id="metaStatsBar" class="hidden flex flex-wrap justify-center gap-2 mb-2">
                    <span class="bg-zinc-800/80 text-zinc-300 text-[10px] sm:text-xs px-3 py-1.5 rounded-full font-mono border border-zinc-700/50 flex items-center gap-1.5" id="unlockViews"></span>
                    <span class="bg-zinc-800/80 text-zinc-400 text-[10px] sm:text-xs px-3 py-1.5 rounded-full font-mono border border-zinc-700/50 flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <span id="unlockDate"></span>
                    </span>
                </div>
                
                <div id="imgWrapper" class="hidden relative group border border-zinc-800 rounded-2xl overflow-hidden bg-[#0b0b0e] w-full">
                    <img id="unlockedImg" src="" onclick="openLightbox(this.src)" class="w-full max-h-[50vh] sm:max-h-[450px] object-contain cursor-zoom-in">
                    <div class="absolute bottom-0 left-0 right-0 p-3 sm:p-4 bg-gradient-to-t from-black/80 to-transparent flex justify-between items-center">
                        <span id="unlockedImgSize" class="text-[10px] sm:text-xs text-zinc-300 font-mono bg-black/50 px-2 py-1 rounded"></span>
                        <button onclick="downloadImage('unlockedImg')" class="bg-zinc-100 text-black text-[10px] sm:text-xs font-semibold px-3 sm:px-4 py-2 rounded-lg transition hover:bg-white flex items-center gap-1.5">
                            <svg class="w-3 h-3 sm:w-4 sm:h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg> Download
                        </button>
                    </div>
                </div>
                <div class="relative w-full">
                    <pre id="codeBlock" class="hidden border border-zinc-800 rounded-2xl overflow-y-auto max-h-[50vh]"><code id="unlockedCode" class="language-plaintext p-4 sm:p-6 text-xs sm:text-sm leading-relaxed block w-full"></code></pre>
                    <textarea id="unlockedText" readonly class="hidden w-full rounded-2xl p-4 sm:p-6 bg-[#0b0b0e] border border-zinc-800 text-sm leading-relaxed resize-none"></textarea>
                </div>
            </div>

        <?php else: ?>
            <div class="space-y-5 w-full flex-grow flex flex-col justify-center">
                
                <?php if ($isReadMode): ?>
                    <div class="flex flex-wrap justify-center gap-2 mb-2">
                        <?php if (!$isViewOnceRead && $viewCount > 0): ?>
                            <span class="bg-zinc-800/80 text-zinc-300 text-[10px] sm:text-xs px-3 py-1.5 rounded-full font-mono border border-zinc-700/50 flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                Views: <?= $viewCount ?>
                            </span>
                        <?php endif; ?>
                        
                        <?php if ($isViewOnceRead): ?>
                            <span class="bg-red-900/40 text-red-400 text-[10px] sm:text-xs px-3 py-1.5 rounded-full font-mono border border-red-800/50 flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M12.395 2.553a1 1 0 00-1.45-.385c-.345.23-.614.558-.822.88-.214.33-.403.713-.57 1.116-.334.804-.614 1.768-.84 2.734a31.365 31.365 0 00-.613 3.58 2.64 2.64 0 012.49-2.33c.247 0 .48.048.697.14.254.106.493.256.712.448.463.408.81.9 1.05 1.395.25.514.39 1.035.39 1.532 0 1.27-.63 2.331-1.429 3.132-.797.8-1.786 1.33-2.697 1.577m1.219-1.497a1.527 1.527 0 01-.021-.12c-.018-.109-.01-.228.024-.349 0 0 .011-.037.031-.087.051-.14.15-.381.28-.664.25-.547.655-1.264 1.021-1.933.192-.35.398-.662.611-.931.21-.266.439-.474.69-.61a1.64 1.64 0 012.228.616l.186.322c.272.473.199 1.037-.106 1.43z" clip-rule="evenodd"></path></svg>
                                Burning after view
                            </span>
                        <?php endif; ?>

                        <span class="bg-zinc-800/80 text-zinc-400 text-[10px] sm:text-xs px-3 py-1.5 rounded-full font-mono border border-zinc-700/50 flex items-center gap-1.5">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            <?= $createdDate ?>
                        </span>
                    </div>

                    <?php if ($viewImage): ?>
                        <div class="relative group border border-zinc-800 rounded-2xl overflow-hidden bg-[#0b0b0e] w-full">
                            <img id="viewImgData" src="<?= htmlspecialchars($viewImage) ?>" onclick="openLightbox(this.src)" class="w-full max-h-[50vh] sm:max-h-[450px] object-contain cursor-zoom-in">
                            <div class="absolute bottom-0 left-0 right-0 p-3 sm:p-4 bg-gradient-to-t from-black/80 to-transparent flex justify-between items-center">
                                <span class="text-[10px] sm:text-xs text-zinc-300 font-mono bg-black/50 px-2 py-1 rounded"><?= $viewImageSize ?></span>
                                <button onclick="downloadImage('viewImgData')" class="bg-zinc-100 text-black text-[10px] sm:text-xs font-semibold px-3 sm:px-4 py-2 rounded-lg transition hover:bg-white flex items-center gap-1.5">
                                    <svg class="w-3 h-3 sm:w-4 sm:h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg> Download
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($viewText): ?>
                        <div class="relative w-full mt-2">
                            <pre class="border border-zinc-800 rounded-2xl w-full overflow-y-auto max-h-[50vh] bg-[#0b0b0e]"><code class="language-plaintext p-4 sm:p-6 text-xs sm:text-sm leading-relaxed block w-full" id="readCodeBlock"><?= htmlspecialchars($viewText) ?></code></pre>
                            <textarea id="textData" class="hidden w-full"><?= htmlspecialchars($viewText) ?></textarea>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <label class="flex flex-col items-center justify-center w-full h-24 sm:h-28 border-2 border-dashed border-zinc-700 hover:border-zinc-500 rounded-2xl cursor-pointer bg-[#0b0b0e] transition group">
                        <div class="flex items-center gap-2 sm:gap-3 text-zinc-400 group-hover:text-zinc-200">
                            <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                            <span class="font-medium text-sm sm:text-base">Attach Image</span>
                        </div>
                        <p class="text-[10px] sm:text-xs text-zinc-600 mt-1">JPG, PNG, GIF (Max 5MB)</p>
                        <input id="imgUpload" type="file" class="hidden" accept="image/png, image/jpeg, image/webp, image/gif" onchange="previewImg(event)" />
                    </label>
                    
                    <div id="previewWrapper" class="hidden border border-zinc-800 rounded-2xl overflow-hidden bg-[#0b0b0e] p-2 sm:p-3 items-center justify-between shadow-inner w-full">
                        <div class="flex items-center gap-3 overflow-hidden">
                            <img id="imgPreview" class="h-10 w-10 sm:h-12 sm:w-12 object-cover rounded-lg border border-zinc-700">
                            <div class="flex flex-col truncate">
                                <span id="imgName" class="text-xs sm:text-sm font-medium text-zinc-200 truncate"></span>
                                <span id="imgSize" class="text-[10px] text-zinc-500 font-mono"></span>
                            </div>
                        </div>
                        <button onclick="removeImg()" class="text-zinc-500 hover:text-red-400 p-2 rounded-full transition">
                            <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                        </button>
                    </div>

                    <div class="relative w-full">
                        <textarea id="textData" oninput="autoResize(this)" class="w-full min-h-[140px] max-h-[50vh] rounded-2xl p-4 sm:p-6 text-sm leading-relaxed resize-none shadow-inner overflow-hidden" placeholder="Paste text, code, or secure notes here..."></textarea>
                    </div>

                    <div class="bg-[#0b0b0e] p-4 sm:p-6 rounded-2xl border border-zinc-800/60 space-y-4 sm:space-y-5 w-full">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-5">
                            <div class="space-y-1.5">
                                <label class="text-[10px] sm:text-xs text-zinc-500 font-bold uppercase tracking-wider">Custom Alias</label>
                                <div class="flex items-center rounded-xl overflow-hidden bg-[#0b0b0e] border border-zinc-800 focus-within:border-zinc-500 transition">
                                    <span class="pl-3 sm:pl-4 pr-1 text-zinc-600 text-xs sm:text-sm font-mono">/?id=</span>
                                    <input type="text" id="customId" placeholder="my-drop" class="w-full p-2.5 sm:p-3 bg-transparent border-none outline-none text-xs sm:text-sm text-zinc-200">
                                </div>
                            </div>
                            
                            <div class="space-y-1.5">
                                <label class="text-[10px] sm:text-xs text-zinc-500 font-bold uppercase tracking-wider">Auto-Destroy</label>
                                <select id="expiryInput" class="w-full p-2.5 sm:p-3 rounded-xl text-xs sm:text-sm appearance-none bg-[#0b0b0e] font-medium border-zinc-800">
                                    <option value="0">Never Expire</option>
                                    <option value="1">1 Hour</option>
                                    <option value="24">24 Hours</option>
                                    <option value="168">7 Days</option>
                                </select>
                            </div>

                            <div class="space-y-1.5">
                                <label class="text-[10px] sm:text-xs text-zinc-500 font-bold uppercase tracking-wider">Password</label>
                                <input type="password" id="passInput" placeholder="Leave blank for public" class="w-full p-2.5 sm:p-3 rounded-xl text-xs sm:text-sm bg-[#0b0b0e] border-zinc-800">
                            </div>

                            <div class="flex items-center justify-between bg-zinc-950 border border-zinc-800 rounded-xl p-3 mt-auto">
                                <p class="text-xs sm:text-sm font-semibold text-zinc-100">Burn After Reading</p>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" id="viewOnce" class="sr-only peer">
                                    <div class="w-10 h-5 sm:w-11 sm:h-6 bg-zinc-800 border border-zinc-700 rounded-full peer peer-checked:bg-white peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-zinc-400 peer-checked:after:bg-black after:border-zinc-600 after:border after:rounded-full after:h-4 after:w-4 sm:after:h-5 sm:after:w-5 after:transition-all"></div>
                                </label>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div id="actionArea" class="pt-2 sm:pt-5 w-full mt-auto border-t border-zinc-800/50">
                <?php if (!$isReadMode): ?>
                    <button id="saveBtn" onclick="saveData()" class="w-full bg-white text-black hover:bg-zinc-200 font-bold py-3.5 sm:py-4 rounded-xl transition shadow-lg text-sm sm:text-base flex justify-center items-center gap-2">
                        Create Drop
                    </button>
                <?php else: ?>
                    <div class="grid grid-cols-2 sm:flex sm:flex-row gap-2 sm:gap-4 mt-2 sm:mt-4 w-full">
                        <button onclick="copyText('textData')" class="w-full bg-zinc-800 hover:bg-zinc-700 text-white text-xs sm:text-sm font-semibold py-3.5 sm:py-4 rounded-xl transition border border-zinc-700 flex justify-center items-center gap-1.5">
                            <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"></path></svg>
                            Copy Note
                        </button>
                        <button onclick="downloadText('textData', 'readCodeBlock')" class="w-full bg-zinc-800 hover:bg-zinc-700 text-white text-xs sm:text-sm font-semibold py-3.5 sm:py-4 rounded-xl transition border border-zinc-700 flex justify-center items-center gap-1.5">
                            <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                            Download
                        </button>
                        <a href="index.php" class="col-span-2 sm:col-span-1 w-full bg-white text-black hover:bg-zinc-200 text-center text-sm font-semibold py-3.5 sm:py-4 rounded-xl transition shadow flex justify-center items-center gap-1.5">
                            New Drop
                        </a>
                        
                        <?php if (!$isViewOnceRead): ?>
                        <button onclick="copyCurrentUrl()" class="col-span-2 sm:col-span-1 w-full bg-zinc-900 hover:bg-zinc-800 text-white text-center text-sm font-semibold py-3.5 sm:py-4 rounded-xl transition border border-zinc-800 flex justify-center items-center gap-1.5">
                            <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg>
                            Copy Link
                        </button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div id="successPanel" class="hidden absolute inset-0 bg-[#0b0b0e]/98 backdrop-blur-md z-50 p-6 sm:p-8 flex flex-col items-center justify-center text-center space-y-6 rounded-3xl border border-zinc-800">
            <div class="w-16 h-16 sm:w-20 sm:h-20 bg-zinc-900 border border-zinc-800 rounded-full flex items-center justify-center text-white shadow-2xl">
                <svg class="w-8 h-8 sm:w-10 sm:h-10 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
            </div>
            <div>
                <h2 class="text-xl sm:text-2xl font-extrabold text-white mb-1">PasteShare Link Ready</h2>
                <p class="text-xs sm:text-sm text-zinc-400">Your payload is secured.</p>
            </div>
            
            <div class="bg-white p-2 sm:p-3 rounded-2xl shadow-xl">
                <img id="qrCode" src="" class="w-24 h-24 sm:w-32 sm:h-32">
            </div>
            
            <div class="w-full max-w-sm space-y-3">
                <div class="flex bg-[#0b0b0e] border border-zinc-800 rounded-xl overflow-hidden">
                    <input type="text" id="finalLink" readonly class="w-full bg-transparent p-3 text-xs sm:text-sm text-zinc-200 font-mono outline-none border-none">
                    <button onclick="copyFinalLink()" class="bg-zinc-800 hover:bg-zinc-700 px-4 text-xs font-semibold text-white border-l border-zinc-800 transition">Copy</button>
                    <button onclick="nativeShare()" id="shareBtn" class="hidden bg-zinc-700 hover:bg-zinc-600 px-4 text-xs font-semibold text-white border-l border-zinc-800 transition">Share</button>
                </div>
                <button onclick="location.reload()" class="w-full text-zinc-500 hover:text-white text-xs sm:text-sm font-medium py-2 transition">Close</button>
            </div>
        </div>

        <div id="lightbox" class="hidden fixed inset-0 bg-black/95 z-[200] flex items-center justify-center cursor-zoom-out" onclick="closeLightbox()">
            <img id="lightboxImg" src="" class="max-w-[95vw] max-h-[95vh] object-contain rounded-lg shadow-2xl">
            <button class="absolute top-6 right-6 text-white bg-zinc-800/80 p-2 rounded-full hover:bg-zinc-700 transition">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>

        <div id="toast" class="fixed top-4 right-4 transform translate-x-[150%] opacity-0 transition-all duration-400 bg-zinc-800 border border-zinc-700 text-white text-xs sm:text-sm px-4 sm:px-6 py-3 rounded-xl font-medium z-[100] shadow-2xl flex items-center gap-2 sm:gap-3"></div>
    </div>

    <script>
        let fileObj = null;

        function formatBytes(bytes, decimals = 2) {
            if (bytes === 0) return '0 B';
            const k = 1024, dm = decimals < 0 ? 0 : decimals, sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
        }

        function autoResize(el) {
            el.style.height = 'auto';
            el.style.height = (el.scrollHeight) + 'px';
            if(el.scrollHeight > (window.innerHeight * 0.5)) {
                el.style.overflowY = 'auto';
            } else {
                el.style.overflowY = 'hidden';
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            const codeBlock = document.getElementById('readCodeBlock');
            if(codeBlock && codeBlock.textContent.trim() !== '') {
                hljs.highlightElement(codeBlock);
            }
            if (navigator.share) {
                document.getElementById('shareBtn')?.classList.remove('hidden');
            }
        });

        // Lightbox functions
        function openLightbox(src) {
            document.getElementById('lightboxImg').src = src;
            document.getElementById('lightbox').classList.remove('hidden');
        }
        function closeLightbox() {
            document.getElementById('lightbox').classList.add('hidden');
        }

        function previewImg(e) {
            fileObj = e.target.files[0];
            if(fileObj) {
                // 5MB frontend check
                if(fileObj.size > 5242880) {
                    fileObj = null;
                    document.getElementById('imgUpload').value = '';
                    return showToast("Image is larger than 5MB", "⚠️");
                }
                document.getElementById('imgPreview').src = URL.createObjectURL(fileObj); 
                document.getElementById('imgName').innerText = fileObj.name;
                document.getElementById('imgSize').innerText = formatBytes(fileObj.size);
                document.getElementById('previewWrapper').classList.remove('hidden');
                document.getElementById('previewWrapper').classList.add('flex');
                e.target.parentElement.classList.add('hidden'); 
            }
        }
        
        function removeImg() {
            fileObj = null;
            document.getElementById('imgUpload').value = '';
            document.getElementById('previewWrapper').classList.add('hidden');
            document.getElementById('previewWrapper').classList.remove('flex');
            document.querySelector('label[for="imgUpload"]').classList.remove('hidden');
        }

        function saveData() {
            const text = document.getElementById('textData').value;
            if (!text.trim() && !fileObj) return showToast("Payload is empty.", "⚠️");

            const btn = document.getElementById('saveBtn');
            btn.innerHTML = `Encrypting...`; 
            btn.disabled = true;
            
            const fd = new FormData();
            fd.append('action', 'save');
            fd.append('text', text);
            fd.append('custom_id', document.getElementById('customId').value);
            if (fileObj) fd.append('image', fileObj);
            fd.append('password', document.getElementById('passInput').value);
            fd.append('expiry', document.getElementById('expiryInput').value);
            fd.append('view_once', document.getElementById('viewOnce').checked);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'index.php', true);
            
            xhr.onload = function() {
                btn.innerHTML = `Create Drop`; btn.disabled = false;
                if (xhr.status === 200) {
                    const res = JSON.parse(xhr.responseText);
                    if (res.success) {
                        const link = window.location.origin + window.location.pathname + '?id=' + res.id;
                        document.getElementById('finalLink').value = link;
                        document.getElementById('qrCode').src = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' + encodeURIComponent(link) + '&margin=0';
                        document.getElementById('successPanel').classList.remove('hidden'); 
                        document.getElementById('successPanel').classList.add('flex');
                    } else showToast(res.message, "❌");
                } else showToast("Server error.", "❌");
            };
            xhr.onerror = () => {
                btn.innerHTML = `Create Drop`; btn.disabled = false;
                showToast("Connection error.", "📡");
            };
            xhr.send(fd);
        }

        async function unlockContent() {
            const pass = document.getElementById('unlockPass').value;
            if (!pass) return showToast("Password required.", "🔒");

            const fd = new FormData();
            fd.append('action', 'unlock');
            fd.append('id', new URLSearchParams(window.location.search).get('id'));
            fd.append('password', pass);

            try {
                const res = await fetch('index.php', { method: 'POST', body: fd }).then(r => r.json());
                if (res.success) {
                    document.getElementById('lockScreen').classList.add('hidden');
                    document.getElementById('unlockedContent').classList.remove('hidden');
                    document.getElementById('unlockedContent').classList.add('flex');
                    
                    const metaStatsBar = document.getElementById('metaStatsBar');
                    metaStatsBar.classList.remove('hidden');
                    document.getElementById('unlockDate').innerText = res.created_date;

                    if(!res.view_once) {
                        document.getElementById('unlockViews').innerHTML = `<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg> Views: ${res.views}`;
                    } else {
                        document.getElementById('unlockViews').classList.add('text-red-400', 'border-red-800/50');
                        document.getElementById('unlockViews').innerHTML = `<svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M12.395 2.553a1 1 0 00-1.45-.385c-.345.23-.614.558-.822.88-.214.33-.403.713-.57 1.116-.334.804-.614 1.768-.84 2.734a31.365 31.365 0 00-.613 3.58 2.64 2.64 0 012.49-2.33c.247 0 .48.048.697.14.254.106.493.256.712.448.463.408.81.9 1.05 1.395.25.514.39 1.035.39 1.532 0 1.27-.63 2.331-1.429 3.132-.797.8-1.786 1.33-2.697 1.577m1.219-1.497a1.527 1.527 0 01-.021-.12c-.018-.109-.01-.228.024-.349 0 0 .011-.037.031-.087.051-.14.15-.381.28-.664.25-.547.655-1.264 1.021-1.933.192-.35.398-.662.611-.931.21-.266.439-.474.69-.61a1.64 1.64 0 012.228.616l.186.322c.272.473.199 1.037-.106 1.43z" clip-rule="evenodd"></path></svg> Burning after view`;
                    }
                    
                    if(res.image) {
                        document.getElementById('imgWrapper').classList.remove('hidden');
                        const img = document.getElementById('unlockedImg');
                        img.src = res.image;
                        document.getElementById('unlockedImgSize').innerText = res.image_size;
                    }
                    
                    if(res.text.trim() !== '') {
                        const txtArea = document.getElementById('unlockedText');
                        txtArea.value = res.text;
                        const codeEl = document.getElementById('unlockedCode');
                        codeEl.textContent = res.text;
                        document.getElementById('codeBlock').classList.remove('hidden');
                        hljs.highlightElement(codeEl);
                    }
                    
                    let shareLinkBtnHtml = '';
                    if(!res.view_once) {
                        shareLinkBtnHtml = `<button onclick="copyCurrentUrl()" class="col-span-2 sm:col-span-1 w-full bg-zinc-900 hover:bg-zinc-800 text-white text-center text-sm font-semibold py-3.5 sm:py-4 rounded-xl transition border border-zinc-800 flex justify-center items-center gap-1.5"><svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg> Copy Link</button>`;
                    }

                    const actionArea = document.getElementById('actionArea');
                    actionArea.innerHTML = `
                    <div class="grid grid-cols-2 sm:flex sm:flex-row gap-2 sm:gap-4 mt-2 sm:mt-4 w-full">
                        <button onclick="copyText('unlockedText')" class="w-full bg-zinc-800 hover:bg-zinc-700 text-white text-xs sm:text-sm font-semibold py-3.5 sm:py-4 rounded-xl transition border border-zinc-700 flex justify-center items-center gap-1.5"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"></path></svg> Copy Note</button>
                        <button onclick="downloadText('unlockedText', 'unlockedCode')" class="w-full bg-zinc-800 hover:bg-zinc-700 text-white text-xs sm:text-sm font-semibold py-3.5 sm:py-4 rounded-xl transition border border-zinc-700 flex justify-center items-center gap-1.5"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg> Download</button>
                        <a href="index.php" class="col-span-2 sm:col-span-1 w-full bg-white text-black hover:bg-zinc-200 text-center text-sm font-semibold py-3.5 sm:py-4 rounded-xl transition shadow flex justify-center items-center gap-1.5">New Drop</a>
                        ${shareLinkBtnHtml}
                    </div>`;

                    showToast("Drop Unlocked", "🔓");
                } else showToast(res.message, "❌");
            } catch(e) { showToast("Error connecting.", "⚠️"); }
        }

        function downloadText(textId, codeBlockId) {
            const textValue = document.getElementById(textId).value;
            if (!textValue || !textValue.trim()) return showToast("No text to download.", "⚠️");

            const codeBlock = document.getElementById(codeBlockId);
            const isHighlighted = codeBlock && codeBlock.classList.contains('hljs');

            if (isHighlighted) {
                const highlightedHtml = codeBlock.innerHTML;
                const htmlTemplate = `<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>PasteShare Export</title><style>body{background:#0d1117;color:#c9d1d9;font-family:monospace;padding:25px;white-space:pre-wrap;word-break:break-word;}.hljs-comment,.hljs-punctuation{color:#8b949e}.hljs-keyword{color:#ff7b72}.hljs-string{color:#a5d6ff}.hljs-title{color:#d2a8ff}</style></head><body><pre><code class="hljs">${highlightedHtml}</code></pre></body></html>`;
                triggerDownload(htmlTemplate, `pasteshare_${Date.now()}.html`, 'text/html');
                showToast("Downloading HTML format...", "📥");
            } else {
                triggerDownload(textValue, `pasteshare_${Date.now()}.txt`, 'text/plain');
                showToast("Downloading Text file...", "📥");
            }
        }

        function downloadImage(imgId) {
            const imgElement = document.getElementById(imgId);
            if(!imgElement || !imgElement.src) return showToast("Image not found", "❌");
            
            showToast("Starting download...", "📥");
            fetch(imgElement.src)
                .then(response => response.blob())
                .then(blob => {
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.style.display = 'none';
                    a.href = url;
                    a.download = `pasteshare_img_${Date.now()}.png`;
                    document.body.appendChild(a);
                    a.click();
                    window.URL.revokeObjectURL(url);
                    
                    <?php if (($isReadMode && $isViewOnceRead) || (!$isReadMode)): ?>
                        cleanupViewOnceImage(imgElement.src);
                    <?php endif; ?>
                })
                .catch(() => showToast("Download blocked.", "❌"));
        }

        async function cleanupViewOnceImage(urlPath) {
            const urlObj = new URL(urlPath, window.location.origin);
            const pathSegments = urlObj.pathname.split('/');
            const filename = pathSegments[pathSegments.length - 1];
            const relativePath = 'saved_images/' + filename;
            
            const fd = new FormData();
            fd.append('action', 'cleanup_image');
            fd.append('path', relativePath);
            fetch('index.php', { method: 'POST', body: fd }).then(r => r.json()).then(res => {
                if(res.success) {
                    document.getElementById('imgWrapper')?.classList.add('hidden');
                }
            });
        }

        function copyText(id) {
            const el = document.getElementById(id);
            if(el) {
                navigator.clipboard.writeText(el.value);
                showToast("Copied to Clipboard", "📋");
            }
        }
        
        function copyFinalLink() {
            navigator.clipboard.writeText(document.getElementById('finalLink').value);
            showToast("Share Link Copied", "🔗");
        }

        // New function to copy current URL in read mode
        function copyCurrentUrl() {
            navigator.clipboard.writeText(window.location.href);
            showToast("Link Copied to Share", "🔗");
        }

        async function nativeShare() {
            const link = document.getElementById('finalLink').value;
            try {
                await navigator.share({
                    title: 'PasteShare Drop',
                    text: 'I securely shared text/image with you via PasteShare.',
                    url: link
                });
            } catch (err) {}
        }

        function showToast(msg, icon) {
            const t = document.getElementById('toast');
            t.innerHTML = `<span>${icon}</span> <span>${msg}</span>`;
            t.classList.remove('translate-x-[150%]', 'opacity-0');
            setTimeout(() => t.classList.add('translate-x-[150%]', 'opacity-0'), 3000);
        }
    </script>
</body>
</html>