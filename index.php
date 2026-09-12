<?php
// =========================================================================
// POINT PLAY TELEGRAM MINI APP - SERVER SIDE LOGIC
// =========================================================================

$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

// Helper: Safely read/update JSON using file locking
function updateJsonSafe($file, $callback, $default = []) {
    if (!file_exists($file)) {
        file_put_contents($file, json_encode($default));
    }
    $fp = fopen($file, 'c+');
    $data = $default;
    if (flock($fp, LOCK_EX)) {
        $content = stream_get_contents($fp);
        if ($content) {
            $data = json_decode($content, true) ?: $default;
        }
        $data = $callback($data);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    return $data;
}

// Helper: Read JSON (No lock needed for read-only)
function readJson($file, $default = []) {
    if (!file_exists($file)) return $default;
    $content = file_get_contents($file);
    return $content ? json_decode($content, true) : $default;
}

// Helper: Evaluate Referral Approval
function evaluateReferral(&$user, $dataDir) {
    if ($user['referralApproved']) return;
    if (empty($user['referrerUid'])) return;
    
    // Requirements: 25 valid ads AND 5 completed tasks
    if ($user['adsWatched'] >= 25 && $user['tasksCompleted'] >= 5) {
        $user['referralApproved'] = true;
        
        $referrerFile = $dataDir . "/user_{$user['referrerUid']}.json";
        if (file_exists($referrerFile)) {
            updateJsonSafe($referrerFile, function($refData) use ($user) {
                $refData['xp'] += 250;
                $refData['totalXp'] += 250;
                $refData['usd'] += 0.025;
                $refData['referralRewards'][] = [
                    'referralName' => $user['firstName'] ?? 'User',
                    'xp' => 250,
                    'usd' => 0.025,
                    'date' => date('M j, Y')
                ];
                return $refData;
            }, []);
        }
    }
}

// Default User Schema
function getDefaultUser($uid, $firstName, $username) {
    return [
        'uid' => $uid,
        'firstName' => $firstName,
        'username' => $username,
        'xp' => 0,
        'usd' => 0.00,
        'totalXp' => 0,
        'adsWatched' => 0,
        'adsWatchedToday' => 0,
        'tasksCompleted' => 0,
        'referrerUid' => null,
        'referralApproved' => false,
        'myReferrals' => [],
        'withdrawals' => [],
        'referralRewards' => [],
        'azx_crypto' => false,
        'daily' => [
            'lastResetDay' => '',
            'streak' => 1,
            'claimedToday' => false
        ],
        'missions' => [
            'watch5' => ['claimed' => false, 'date' => ''],
            'watch30' => ['claimed' => false, 'date' => '']
        ]
    ];
}

// Process API Actions
$action = $_GET['action'] ?? null;
if ($action) {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $uid = $input['uid'] ?? $_GET['uid'] ?? null;
    
    if (!$uid) {
        echo json_encode(['error' => 'No UID provided']);
        exit;
    }
    
    $userFile = $dataDir . "/user_{$uid}.json";
    
    // Action: Sync State
    if ($action === 'sync') {
        $startapp = $input['startapp'] ?? null;
        $firstName = $input['firstName'] ?? 'User';
        $username = $input['username'] ?? '';
        
        $user = updateJsonSafe($userFile, function($data) use ($uid, $firstName, $username, $startapp, $dataDir) {
            $today = date('Y-m-d');
            
            // Initialization
            if (empty($data['uid'])) {
                $data = getDefaultUser($uid, $firstName, $username);
                
                // Referral linking
                if ($startapp && $startapp != $uid) {
                    $referrerFile = $dataDir . "/user_{$startapp}.json";
                    if (file_exists($referrerFile)) {
                        $data['referrerUid'] = $startapp;
                        updateJsonSafe($referrerFile, function($refData) use ($uid) {
                            if (!in_array($uid, $refData['myReferrals'])) {
                                $refData['myReferrals'][] = $uid;
                            }
                            return $refData;
                        });
                    }
                }
            }
            
            // Daily Reset Logic
            if (($data['daily']['lastResetDay'] ?? '') !== $today) {
                $lastDay = $data['daily']['lastResetDay'] ?? '';
                if ($lastDay === date('Y-m-d', strtotime('-1 day'))) {
                    $data['daily']['streak'] = min(7, ($data['daily']['streak'] ?? 1) + 1);
                } else if ($lastDay !== '') {
                    $data['daily']['streak'] = 1;
                }
                $data['daily']['lastResetDay'] = $today;
                $data['daily']['claimedToday'] = false;
                $data['adsWatchedToday'] = 0;
                $data['missions']['watch5']['claimed'] = false;
                $data['missions']['watch5']['date'] = $today;
                $data['missions']['watch30']['claimed'] = false;
                $data['missions']['watch30']['date'] = $today;
            }
            
            return $data;
        }, getDefaultUser($uid, $firstName, $username));
        
        echo json_encode(['success' => true, 'user' => $user]);
        exit;
    }
    
    // Action: Watch Ad
    if ($action === 'watch_ad') {
        $user = updateJsonSafe($userFile, function($data) use ($dataDir) {
            $data['xp'] += 20;
            $data['totalXp'] += 20;
            $data['adsWatched']++;
            $data['adsWatchedToday']++;
            evaluateReferral($data, $dataDir);
            return $data;
        });
        echo json_encode(['success' => true, 'user' => $user]);
        exit;
    }
    
    // Action: Claim Daily Login
    if ($action === 'claim_daily') {
        $user = updateJsonSafe($userFile, function($data) use ($dataDir) {
            if (!$data['daily']['claimedToday']) {
                $streakRewards = [5, 10, 15, 20, 25, 30, 50];
                $idx = min(6, max(0, $data['daily']['streak'] - 1));
                $reward = $streakRewards[$idx];
                
                $data['xp'] += $reward;
                $data['totalXp'] += $reward;
                $data['daily']['claimedToday'] = true;
                $data['tasksCompleted']++;
                evaluateReferral($data, $dataDir);
            }
            return $data;
        });
        echo json_encode(['success' => true, 'user' => $user]);
        exit;
    }
    
    // Action: Claim Task
    if ($action === 'claim_task') {
        $taskId = $input['taskId'] ?? '';
        $user = updateJsonSafe($userFile, function($data) use ($taskId, $dataDir) {
            if ($taskId === 'azx_crypto' && empty($data['azx_crypto'])) {
                $data['azx_crypto'] = true;
                $data['xp'] += 200;
                $data['totalXp'] += 200;
                $data['tasksCompleted']++;
                evaluateReferral($data, $dataDir);
            } else if (in_array($taskId, ['watch5', 'watch30'])) {
                $target = ($taskId === 'watch5') ? 5 : 30;
                $reward = ($taskId === 'watch5') ? 20 : 50;
                if (!$data['missions'][$taskId]['claimed'] && $data['adsWatchedToday'] >= $target) {
                    $data['missions'][$taskId]['claimed'] = true;
                    $data['xp'] += $reward;
                    $data['totalXp'] += $reward;
                    $data['tasksCompleted']++;
                    evaluateReferral($data, $dataDir);
                }
            }
            return $data;
        });
        echo json_encode(['success' => true, 'user' => $user]);
        exit;
    }
    
    // Action: Get Referrals
    if ($action === 'get_referrals') {
        $user = readJson($userFile);
        $referrals = [];
        $total = 0; $pending = 0; $approved = 0;
        
        foreach (($user['myReferrals'] ?? []) as $refUid) {
            $refData = readJson($dataDir . "/user_{$refUid}.json");
            if ($refData) {
                $total++;
                if ($refData['referralApproved']) {
                    $approved++;
                } else {
                    $pending++;
                }
                $referrals[] = [
                    'name' => $refData['firstName'],
                    'username' => $refData['username'],
                    'status' => $refData['referralApproved'] ? 'Approved' : 'Pending',
                    'ads' => min(25, $refData['adsWatched']),
                    'tasks' => min(5, $refData['tasksCompleted'])
                ];
            }
        }
        
        echo json_encode([
            'success' => true,
            'stats' => ['total' => $total, 'pending' => $pending, 'approved' => $approved],
            'list' => $referrals,
            'history' => $user['referralRewards'] ?? []
        ]);
        exit;
    }
    
    // Action: Withdraw
    if ($action === 'withdraw') {
        $amount = (float)($input['amount'] ?? 0);
        $address = $input['address'] ?? '';
        
        if ($amount < 10) {
            echo json_encode(['error' => 'Minimum withdrawal is $10']);
            exit;
        }
        
        $user = updateJsonSafe($userFile, function($data) use ($amount, $address) {
            if ($data['usd'] >= $amount && $amount >= 10 && strlen($address) > 5) {
                $data['usd'] -= $amount;
                array_unshift($data['withdrawals'], [
                    'id' => '#' . strtoupper(substr(md5(uniqid()), 0, 6)),
                    'date' => date('Y-m-d H:i'),
                    'amount' => $amount,
                    'address' => substr($address, 0, 6) . '...' . substr($address, -4),
                    'status' => 'Pending'
                ]);
            }
            return $data;
        });
        echo json_encode(['success' => true, 'user' => $user]);
        exit;
    }
    
    echo json_encode(['error' => 'Invalid action']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>Point Play - Telegram Mini App</title>
  
  <script src="https://telegram.org/js/telegram-web-app.js"></script>
  <!-- Simulating the ads library as requested in constraints/original code format -->
  <script src="https://sad.adsgram.ai/js/sad.min.js"></script>
  
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800;900&display=swap" rel="stylesheet">

  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { sans: ['Outfit', 'sans-serif'] },
          colors: {
            crypto: {
              dark: '#050511',     
              card: '#0a0b1a',     
              primary: '#3b82f6',  
              glow: '#00f0ff'
            }
          },
          animation: {
            'blob': 'blob 7s infinite',
            'pulse-fast': 'pulse 1.5s cubic-bezier(0.4, 0, 0.6, 1) infinite',
            'shimmer': 'shimmer 2s infinite'
          },
          keyframes: {
            blob: {
              '0%': { transform: 'translate(0px, 0px) scale(1)' },
              '33%': { transform: 'translate(30px, -50px) scale(1.1)' },
              '66%': { transform: 'translate(-20px, 20px) scale(0.9)' },
              '100%': { transform: 'translate(0px, 0px) scale(1)' },
            },
            shimmer: {
              '0%': { transform: 'translateX(-100%)' },
              '100%': { transform: 'translateX(100%)' }
            }
          }
        }
      }
    }
  </script>

  <style>
    body {
      background-color: #050511;
      color: #f8fafc;
      overflow-x: hidden;
      -webkit-touch-callout: none;
      -webkit-user-select: none;
      user-select: none;
    }
    .bg-orb-1 {
      position: fixed; top: -10%; left: -10%; width: 50vw; height: 50vw;
      background: radial-gradient(circle, rgba(59, 130, 246, 0.15) 0%, rgba(0, 0, 0, 0) 70%);
      z-index: -1; filter: blur(40px);
    }
    .bg-orb-2 {
      position: fixed; bottom: -10%; right: -10%; width: 60vw; height: 60vw;
      background: radial-gradient(circle, rgba(0, 240, 255, 0.1) 0%, rgba(0, 0, 0, 0) 70%);
      z-index: -1; filter: blur(50px);
    }
    input { user-select: auto !important; }
    img { pointer-events: none; }
    ::-webkit-scrollbar { width: 0px; background: transparent; }

    .glass-card {
      background: linear-gradient(145deg, rgba(20, 22, 45, 0.6) 0%, rgba(10, 11, 26, 0.8) 100%);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      border: 1px solid rgba(255, 255, 255, 0.05);
      box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.3);
    }
    .glass-button {
      background: linear-gradient(135deg, rgba(59,130,246,0.2) 0%, rgba(0,240,255,0.1) 100%);
      border: 1px solid rgba(0,240,255,0.3);
      box-shadow: 0 0 15px rgba(0,240,255,0.1) inset;
    }
    .fade-in { animation: fadeIn 0.3s ease-out forwards; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

    .nav-active { color: #00f0ff !important; transform: translateY(-2px); }
    .nav-active i { filter: drop-shadow(0 0 8px rgba(0, 240, 255, 0.6)); }
    .nav-active::before {
      content: ''; position: absolute; top: -10px; left: 50%; transform: translateX(-50%);
      width: 20px; height: 4px; background: #00f0ff; border-radius: 4px;
      box-shadow: 0 0 12px #00f0ff, 0 0 20px #3b82f6;
    }

    .btn-3d {
      background: linear-gradient(to bottom, #3b82f6, #2563eb);
      border-bottom: 3px solid #1e3a8a;
      transition: all 0.1s;
    }
    .btn-3d:active {
      transform: translateY(3px);
      border-bottom-width: 0px;
      margin-bottom: 3px;
    }

    #toast-container {
      position: fixed; top: 1.5rem; left: 50%; transform: translate(-50%, -150%) scale(0.9);
      width: 90%; max-width: 380px; z-index: 999999;
      transition: all 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55);
      opacity: 0; pointer-events: none;
    }
    .toast-show { transform: translate(-50%, 0) scale(1) !important; opacity: 1 !important; }
    
    /* Referral Info Modal */
    #info-modal {
      backdrop-filter: blur(8px);
      transition: opacity 0.3s ease;
    }
  </style>
</head>
<body class="flex flex-col min-h-screen pb-32">
  
  <div class="bg-orb-1 animate-blob"></div>
  <div class="bg-orb-2 animate-blob animation-delay-2000"></div>

  <!-- Loading Overlay -->
  <div id="loading-overlay" class="bg-[#050511] flex flex-col items-center justify-center z-[100000] fixed inset-0 transition-opacity duration-500">
    <div class="relative w-24 h-24 mb-8">
      <div class="absolute inset-0 rounded-full border-t-4 border-crypto-glow animate-[spin_1s_linear_infinite] shadow-[0_0_20px_rgba(0,240,255,0.5)]"></div>
      <div class="absolute inset-3 rounded-full border-b-4 border-blue-500 animate-[spin_1.5s_linear_infinite_reverse]"></div>
      <div class="absolute inset-0 flex items-center justify-center">
        <i class="fa-solid fa-play text-crypto-glow text-3xl animate-pulse"></i>
      </div>
    </div>
    <h2 class="text-white font-black tracking-[0.25em] text-2xl uppercase bg-clip-text text-transparent bg-gradient-to-r from-crypto-glow via-blue-400 to-indigo-500 mb-2 drop-shadow-lg">Point Play</h2>
    <div class="flex gap-1 mt-2">
      <div class="w-1.5 h-1.5 bg-crypto-glow rounded-full animate-bounce"></div>
      <div class="w-1.5 h-1.5 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 0.1s"></div>
      <div class="w-1.5 h-1.5 bg-indigo-500 rounded-full animate-bounce" style="animation-delay: 0.2s"></div>
    </div>
    <p class="text-[10px] text-blue-400 font-bold tracking-widest mt-6 animate-pulse uppercase">Connecting...</p>
  </div>

  <!-- Toast Notification -->
  <div id="toast-container" class="glass-card rounded-2xl p-4 flex items-center gap-4">
    <div id="toast-icon" class="w-12 h-12 rounded-full flex shrink-0 items-center justify-center text-xl shadow-inner">
      <i class="fa-solid fa-bell"></i>
    </div>
    <div class="flex-1">
      <h4 id="toast-title" class="text-sm font-black text-white tracking-wide">Notification</h4>
      <p id="toast-message" class="text-xs text-slate-300 mt-0.5 leading-tight">Message goes here</p>
    </div>
  </div>

  <!-- Main Header -->
  <header id="main-header" class="fixed top-0 left-0 right-0 z-50 w-full p-4 glass-card rounded-b-3xl border-b-0 shadow-lg transition-transform duration-300">
    <div class="flex justify-between items-center max-w-md mx-auto">
      <div class="flex items-center gap-3 cursor-pointer" onclick="switchTab('profile')">
        <div class="relative w-11 h-11 rounded-full p-[2px] bg-gradient-to-tr from-blue-600 via-crypto-glow to-indigo-500 shadow-[0_0_15px_rgba(0,240,255,0.3)]">
          <img id="user-photo" src="https://via.placeholder.com/150/0a0b1a/00f0ff?text=PP" alt="Profile" class="w-full h-full rounded-full object-cover border-2 border-[#050511]">
        </div>
        <div class="flex flex-col">
          <span id="user-name" class="font-bold text-white text-sm tracking-wide">Loading...</span>
          <div class="flex items-center gap-1">
            <span class="w-2 h-2 rounded-full bg-emerald-400 shadow-[0_0_8px_#34d399] animate-pulse"></span>
            <span class="text-[10px] text-slate-400 uppercase font-bold tracking-wider">Online</span>
          </div>
        </div>
      </div>
      <div class="flex flex-col items-end gap-1.5">
        <div class="glass-button px-3 py-1.5 rounded-xl flex items-center gap-2">
          <i class="fa-solid fa-bolt text-crypto-glow text-xs drop-shadow-[0_0_5px_#00f0ff]"></i>
          <span id="user-xp" class="text-white font-black text-sm tracking-wider">0 <span class="text-[10px] text-crypto-glow">XP</span></span>
        </div>
        <div class="bg-emerald-900/40 border border-emerald-500/30 px-3 py-1 rounded-xl flex items-center gap-2 shadow-[0_0_10px_rgba(16,185,129,0.1)inset]">
          <i class="fa-solid fa-dollar-sign text-emerald-400 text-[10px]"></i>
          <span id="user-usd" class="text-emerald-400 font-black text-xs tracking-wider">0.00</span>
        </div>
      </div>
    </div>
  </header>

  <!-- App Content -->
  <main class="flex-1 max-w-md w-full mx-auto p-4 pt-24 relative" id="app-content">
    
    <!-- HOME VIEW -->
    <div id="view-home" class="view-section fade-in space-y-6">
      <div class="relative glass-card rounded-[2rem] p-6 text-center border-t border-t-blue-400/20 overflow-hidden flex flex-col items-center justify-center min-h-[240px]">
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-48 h-48 bg-blue-500/20 rounded-full filter blur-[40px] pointer-events-none animate-pulse-fast"></div>
        <div class="relative z-10 flex flex-col items-center">
          <div class="w-14 h-14 rounded-full bg-blue-900/40 border border-blue-400/30 flex items-center justify-center mb-3 shadow-[0_0_20px_rgba(59,130,246,0.3)]">
             <i class="fa-solid fa-gem text-2xl text-crypto-glow drop-shadow-[0_0_10px_rgba(0,240,255,0.8)]"></i>
          </div>
          <p class="text-[10px] font-black text-blue-400 uppercase tracking-[0.3em] mb-1 opacity-80">Balance</p>
          <h1 class="text-5xl font-black text-transparent bg-clip-text bg-gradient-to-b from-white via-cyan-100 to-blue-400 tracking-tighter drop-shadow-2xl" id="main-xp-display">0 XP</h1>
        </div>
        <div class="w-full mt-6 grid grid-cols-2 gap-3">
          <div class="bg-[#050511]/50 border border-slate-700/50 p-3 rounded-2xl flex items-center gap-3 backdrop-blur-md">
            <div class="bg-blue-500/10 p-2.5 rounded-xl border border-blue-500/20"><i class="fa-solid fa-clapperboard text-blue-400"></i></div>
            <div class="text-left">
              <p class="text-[9px] text-slate-500 uppercase font-bold tracking-wider">Ads Watched</p>
              <p class="text-sm font-black text-white" id="ads-watched">0</p>
            </div>
          </div>
          <div class="bg-[#050511]/50 border border-slate-700/50 p-3 rounded-2xl flex items-center gap-3 backdrop-blur-md">
            <div class="bg-amber-500/10 p-2.5 rounded-xl border border-amber-500/20"><i class="fa-solid fa-fire-flame-curved text-amber-400"></i></div>
            <div class="text-left">
              <p class="text-[9px] text-slate-500 uppercase font-bold tracking-wider">Streak</p>
              <p class="text-sm font-black text-white"><span id="streak-days" class="text-amber-400">1</span> Days</p>
            </div>
          </div>
        </div>
      </div>

      <button onclick="watchAd()" id="watch-ad-btn" class="w-full py-4 rounded-2xl text-white font-black text-sm tracking-[0.15em] uppercase flex items-center justify-center gap-3 shadow-[0_10px_30px_rgba(59,130,246,0.3)] btn-3d relative overflow-hidden group">
        <div class="absolute inset-0 bg-gradient-to-r from-transparent via-white/20 to-transparent -translate-x-full group-hover:animate-[shimmer_1.5s_infinite]"></div>
        <i class="fa-solid fa-play bg-white/20 p-2 rounded-full text-[10px]"></i> 
        <span>Watch Ad <span class="text-cyan-200">+20 XP</span></span>
      </button>

      <div class="glass-card rounded-xl p-3 flex justify-center items-center">
        <span class="text-slate-400 font-mono font-bold text-xs tracking-widest">More Ads = More XP</span>
      </div>
    </div>

    <!-- TASKS VIEW -->
    <div id="view-tasks" class="view-section hidden fade-in space-y-6 pb-4">
      <div class="text-center pt-2">
        <h2 class="text-3xl font-black text-white tracking-tight drop-shadow-lg">Tasks</h2>
        <p class="text-xs text-crypto-glow mt-1 uppercase tracking-widest font-bold">Complete to Earn XP</p>
      </div>
      
      <!-- Streak Track -->
      <div class="glass-card rounded-3xl p-5 relative overflow-hidden border-t-2 border-t-blue-500/30">
        <div class="absolute -right-10 -top-10 w-32 h-32 bg-blue-600/20 rounded-full blur-2xl"></div>
        <h3 class="text-xs font-black text-white uppercase tracking-widest mb-5 flex items-center gap-2">
          <i class="fa-solid fa-calendar-check text-blue-400 text-lg"></i> 7-Day Streak
        </h3>
        <div class="relative flex justify-between items-center" id="streak-tracker-container"></div>
      </div>

      <!-- Sponsor Task -->
      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-4 mb-3">Special Sponsors</h3>
        <div id="sponsor-container" class="space-y-3">
          <!-- AZX Crypto Card -->
          <div class="glass-card rounded-2xl p-4 flex flex-col transition-transform border border-slate-800">
             <div class="flex items-center gap-4 mb-3">
               <img src="https://i.postimg.cc/rsz7NnZp/IMG-20260903-114036-951.jpg" alt="AZX Crypto" class="w-12 h-12 rounded-xl object-cover border border-slate-700 shadow-md">
               <div class="flex flex-col">
                 <span class="text-sm font-black text-white tracking-wide">AZX Crypto</span>
                 <span class="text-crypto-glow text-[10px] font-bold tracking-widest uppercase opacity-80 mt-0.5">Sponsor Channel</span>
               </div>
               <div class="ml-auto text-right">
                  <span class="text-[12px] font-black bg-slate-800/50 text-slate-200 px-3 py-1.5 rounded-xl border border-slate-600 shadow-inner">+200 XP</span>
               </div>
             </div>
             <div id="azx-btn-container" class="mt-2 w-full">
               <button onclick="claimAZXCrypto()" class="w-full text-xs font-black bg-gradient-to-r from-blue-600 to-cyan-500 text-white px-4 py-3 rounded-xl shadow-[0_0_15px_rgba(0,240,255,0.4)] active:scale-95 transition-all uppercase tracking-wider flex justify-center items-center gap-2">
                 Join Telegram & Claim
               </button>
             </div>
          </div>
        </div>
      </div>

      <!-- Daily Missions -->
      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-4 mb-3">Daily Missions</h3>
        <div id="missions-container" class="space-y-3"></div>
      </div>
    </div>

    <!-- REFERRALS VIEW -->
    <div id="view-referrals" class="view-section hidden fade-in space-y-6 pb-4">
      <div class="text-center pt-2">
        <h2 class="text-3xl font-black text-white tracking-tight drop-shadow-lg">Referrals</h2>
        <p class="text-xs text-emerald-400 mt-1 uppercase tracking-widest font-bold">Invite friends, earn cash!</p>
      </div>

      <!-- Stats Grid -->
      <div class="grid grid-cols-3 gap-3">
        <div class="glass-card p-3 rounded-xl text-center border-t-2 border-blue-500/30">
          <p class="text-[9px] text-slate-400 uppercase font-black tracking-[0.1em] mb-1">Total</p>
          <p id="ref-stat-total" class="text-lg font-black text-white">0</p>
        </div>
        <div class="glass-card p-3 rounded-xl text-center border-t-2 border-amber-500/30">
          <p class="text-[9px] text-slate-400 uppercase font-black tracking-[0.1em] mb-1">Pending</p>
          <p id="ref-stat-pending" class="text-lg font-black text-amber-400">0</p>
        </div>
        <div class="glass-card p-3 rounded-xl text-center border-t-2 border-emerald-500/30">
          <p class="text-[9px] text-slate-400 uppercase font-black tracking-[0.1em] mb-1">Approved</p>
          <p id="ref-stat-approved" class="text-lg font-black text-emerald-400">0</p>
        </div>
      </div>

      <!-- Link Box -->
      <div class="glass-card rounded-2xl p-5 relative border border-slate-700/50">
        <div class="flex justify-between items-center mb-4">
           <h3 class="text-xs font-black text-white uppercase tracking-widest">Your Link</h3>
           <button onclick="showRefInfo()" class="w-6 h-6 rounded-full bg-slate-800 text-slate-400 flex items-center justify-center hover:text-white transition-colors">
              <i class="fa-solid fa-info text-xs"></i>
           </button>
        </div>
        <div class="flex bg-[#050511] border border-slate-700 p-2 rounded-xl mb-4 overflow-hidden">
           <span id="ref-link-display" class="text-xs text-slate-400 whitespace-nowrap overflow-hidden text-ellipsis flex-1 flex items-center pl-2 font-mono">https://t.me/pointplayappbot?startapp=UID</span>
        </div>
        <div class="flex gap-3">
           <button onclick="copyReferralLink()" class="flex-1 py-3 rounded-xl bg-slate-800 text-white font-black uppercase text-[10px] tracking-wider shadow-lg active:scale-95 transition-all border border-slate-600 flex items-center justify-center gap-2">
             <i class="fa-solid fa-copy"></i> Copy Link
           </button>
           <button onclick="shareReferralLink()" class="flex-1 py-3 rounded-xl bg-gradient-to-r from-blue-600 to-cyan-500 text-white font-black uppercase text-[10px] tracking-wider shadow-[0_0_15px_rgba(0,240,255,0.4)] active:scale-95 transition-all flex items-center justify-center gap-2">
             <i class="fa-brands fa-telegram"></i> Share
           </button>
        </div>
      </div>

      <!-- Referral List -->
      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-4 mb-3">Referral List</h3>
        <div id="referrals-list-container" class="space-y-3">
            <div class="text-center py-4 text-xs text-slate-500 font-bold">Loading referrals...</div>
        </div>
      </div>

      <!-- Reward History -->
      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-4 mb-3">Reward History</h3>
        <div id="referral-history-container" class="space-y-3">
            <div class="text-center py-4 text-xs text-slate-500 font-bold">No history yet</div>
        </div>
      </div>
    </div>

    <!-- WITHDRAW VIEW -->
    <div id="view-withdraw" class="view-section hidden fade-in space-y-6">
      <div class="glass-card rounded-[2rem] p-6 text-center border-t-2 border-emerald-500/40 bg-gradient-to-b from-emerald-900/30 to-[#050511]">
        <div class="w-14 h-14 mx-auto bg-emerald-500/10 rounded-full flex items-center justify-center mb-3 border border-emerald-500/30 shadow-[0_0_20px_rgba(16,185,129,0.2)]">
          <i class="fa-solid fa-wallet text-xl text-emerald-400"></i>
        </div>
        <p class="text-[10px] font-black text-emerald-400 uppercase tracking-[0.2em] mb-1 opacity-80">Withdrawal Balance</p>
        <h1 class="text-5xl font-black text-white tracking-tighter mb-3">$<span id="withdraw-balance-display">0.00</span></h1>
        <div class="inline-block bg-[#050511]/80 backdrop-blur-md px-3 py-1.5 rounded-full border border-slate-700/50">
          <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest"><i class="fa-solid fa-circle-info text-blue-400 mr-1"></i> Minimum: $10</p>
        </div>
      </div>

      <div class="glass-card rounded-[1.5rem] p-5 space-y-4 border-slate-800">
        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase tracking-[0.15em] mb-2 ml-1">TON Wallet Address</label>
          <div class="relative group">
            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
              <img src="https://cryptologos.cc/logos/toncoin-ton-logo.png" class="w-5 h-5 opacity-70 group-focus-within:opacity-100 transition-opacity" alt="TON">
            </div>
            <input type="text" id="wallet-address" placeholder="UQ..." class="w-full bg-[#050511]/50 border-2 border-slate-700/50 rounded-xl py-3.5 pl-12 pr-4 text-sm font-medium text-white focus:outline-none focus:border-blue-500 transition-colors placeholder-slate-600 shadow-inner">
          </div>
        </div>
        
        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase tracking-[0.15em] mb-2 ml-1">Amount (USD)</label>
          <div class="relative group">
            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
              <i class="fa-solid fa-dollar-sign text-slate-500 group-focus-within:text-emerald-400 transition-colors text-lg"></i>
            </div>
            <input type="number" id="withdraw-amount" placeholder="10.00" min="10" step="0.5" class="w-full bg-[#050511]/50 border-2 border-slate-700/50 rounded-xl py-3.5 pl-12 pr-4 text-sm font-bold text-emerald-400 focus:outline-none focus:border-emerald-500 transition-colors placeholder-slate-600 shadow-inner">
          </div>
        </div>

        <button onclick="requestWithdrawal()" id="withdraw-btn" class="w-full py-3.5 mt-2 bg-gradient-to-r from-emerald-600 to-teal-500 hover:brightness-110 active:scale-95 transition-all text-white font-black rounded-xl text-sm uppercase tracking-[0.15em] flex items-center justify-center gap-2 shadow-[0_10px_20px_rgba(16,185,129,0.3)]">
          <i class="fa-solid fa-money-bill-transfer"></i> Request Withdrawal
        </button>
      </div>

      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-4 mb-3">History</h3>
        <div id="withdraw-history-container" class="space-y-3"></div>
      </div>
    </div>

    <!-- PROFILE VIEW -->
    <div id="view-profile" class="view-section hidden fade-in space-y-6">
      <div class="glass-card rounded-[2.5rem] p-1 text-center relative overflow-hidden border border-slate-700/50">
        <div class="h-32 bg-gradient-to-r from-blue-900 via-indigo-900 to-purple-900 rounded-t-[2.3rem] relative overflow-hidden">
           <div class="absolute inset-0 bg-[url('https://www.transparenttextures.com/patterns/cubes.png')] opacity-20"></div>
           <div class="absolute -bottom-10 left-1/2 -translate-x-1/2 w-full h-20 bg-gradient-to-t from-[#0a0b1a] to-transparent"></div>
        </div>
        
        <div class="px-6 pb-8 -mt-16 relative z-10">
          <div class="relative w-24 h-24 mx-auto rounded-full bg-gradient-to-b from-crypto-glow to-blue-600 p-1 mb-3 shadow-[0_0_30px_rgba(0,240,255,0.4)]">
             <img id="profile-photo-large" src="https://via.placeholder.com/150/0a0b1a/00f0ff?text=PP" class="w-full h-full rounded-full border-4 border-[#0a0b1a] object-cover">
          </div>
          
          <h2 id="profile-name" class="text-2xl font-black text-white tracking-tight">User</h2>
          <p id="profile-tgid" class="text-[10px] text-blue-400 font-mono mt-1 tracking-widest bg-blue-500/10 inline-block px-3 py-1 rounded-full border border-blue-500/20">ID: 00000000</p>
        </div>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div class="glass-card p-5 rounded-2xl text-center border-t-2 border-t-blue-500/30">
          <div class="w-10 h-10 mx-auto bg-blue-500/10 border border-blue-500/20 text-blue-400 rounded-xl flex items-center justify-center mb-2 text-lg shadow-[0_0_15px_rgba(59,130,246,0.2)inset]"><i class="fa-solid fa-chart-line"></i></div>
          <p class="text-[9px] text-slate-400 uppercase font-black tracking-[0.15em] mb-1">Total Earned</p>
          <p id="profile-total-xp" class="text-xl font-black text-white">0 XP</p>
        </div>
        <div class="glass-card p-5 rounded-2xl text-center border-t-2 border-t-emerald-500/30">
          <div class="w-10 h-10 mx-auto bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 rounded-xl flex items-center justify-center mb-2 text-lg shadow-[0_0_15px_rgba(16,185,129,0.2)inset]"><i class="fa-solid fa-money-bill"></i></div>
          <p class="text-[9px] text-slate-400 uppercase font-black tracking-[0.15em] mb-1">Tasks Completed</p>
          <p id="profile-tasks-count" class="text-xl font-black text-white">0</p>
        </div>
      </div>
    </div>

  </main>

  <!-- Navigation -->
  <nav id="bottom-nav" class="fixed bottom-6 left-1/2 -translate-x-1/2 w-[calc(100%-1rem)] max-w-[420px] glass-card rounded-2xl pb-safe z-50 shadow-[0_20px_40px_rgba(0,0,0,0.8)] border border-slate-700/50 backdrop-blur-xl">
    <div class="flex justify-between items-center px-2 py-2.5 relative">
      <button onclick="switchTab('home')" class="nav-btn nav-active text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all duration-300 relative group" data-target="home">
        <i class="fa-solid fa-house text-lg transition-transform group-active:scale-90"></i>
        <span class="text-[7.5px] font-black uppercase tracking-widest">Home</span>
      </button>
      <button onclick="switchTab('tasks')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all duration-300 relative group" data-target="tasks">
        <i class="fa-solid fa-list-check text-lg transition-transform group-active:scale-90"></i>
        <span class="text-[7.5px] font-black uppercase tracking-widest">Tasks</span>
      </button>
      <button onclick="switchTab('referrals')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all duration-300 relative group" data-target="referrals">
        <i class="fa-solid fa-users text-lg transition-transform group-active:scale-90"></i>
        <span class="text-[7.5px] font-black uppercase tracking-widest">Referrals</span>
      </button>
      <button onclick="switchTab('withdraw')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all duration-300 relative group" data-target="withdraw">
        <i class="fa-solid fa-wallet text-lg transition-transform group-active:scale-90"></i>
        <span class="text-[7.5px] font-black uppercase tracking-widest">Wallet</span>
      </button>
    </div>
  </nav>

  <!-- Referral Info Modal -->
  <div id="info-modal" class="fixed inset-0 bg-black/80 z-[9999] hidden flex items-center justify-center p-4">
      <div class="glass-card rounded-2xl p-6 max-w-sm w-full border border-blue-500/30">
          <h3 class="text-lg font-black text-white mb-4 text-center">Referral Requirements</h3>
          <ul class="space-y-3 text-sm text-slate-300 mb-6 font-medium">
              <li class="flex items-start gap-2"><i class="fa-solid fa-check text-crypto-glow mt-1"></i> Watch 25 ads.</li>
              <li class="flex items-start gap-2"><i class="fa-solid fa-check text-crypto-glow mt-1"></i> Complete 5 tasks.</li>
              <li class="flex items-start gap-2"><i class="fa-solid fa-clock text-crypto-glow mt-1"></i> There is no deadline.</li>
              <li class="flex items-start gap-2"><i class="fa-solid fa-hourglass-half text-crypto-glow mt-1"></i> The referral stays pending until both requirements are completed.</li>
              <li class="flex items-start gap-2"><i class="fa-solid fa-thumbs-up text-crypto-glow mt-1"></i> After completing both requirements, the referral becomes approved.</li>
              <li class="flex items-start gap-2"><i class="fa-solid fa-gift text-crypto-glow mt-1"></i> The referrer receives the referral reward after approval.</li>
          </ul>
          <button onclick="hideRefInfo()" class="w-full py-3 bg-slate-800 rounded-xl text-white font-black uppercase text-xs tracking-wider border border-slate-600">Close</button>
      </div>
  </div>

  <script>
    const tg = window.Telegram.WebApp;
    tg.expand();
    tg.ready();
    tg.setHeaderColor('#0a0b1a');
    tg.setBackgroundColor('#050511');

    // Extract user data from Telegram safely
    const tgUser = tg.initDataUnsafe?.user || { id: 12345678, first_name: "Test User", username: "testuser", photo_url: "" };
    const startapp = tg.initDataUnsafe?.start_param || null;
    let userState = null;

    // Toast functionality
    function showToast(title, message, type = 'info') {
      const toast = document.getElementById('toast-container');
      const icon = document.getElementById('toast-icon');
      document.getElementById('toast-title').innerText = title;
      document.getElementById('toast-message').innerText = message;
      
      let iconClass, iconHtml, borderStyle;
      if (type === 'success') {
        iconHtml = '<i class="fa-solid fa-check"></i>';
        iconClass = 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/50 shadow-[0_0_15px_rgba(16,185,129,0.3)]';
        borderStyle = '1px solid rgba(16, 185, 129, 0.4)';
      } else if (type === 'error') {
        iconHtml = '<i class="fa-solid fa-xmark"></i>';
        iconClass = 'bg-red-500/20 text-red-400 border border-red-500/50 shadow-[0_0_15px_rgba(239,68,68,0.3)]';
        borderStyle = '1px solid rgba(239, 68, 68, 0.4)';
      } else {
        iconHtml = '<i class="fa-solid fa-bell animate-pulse"></i>';
        iconClass = 'bg-blue-500/20 text-crypto-glow border border-crypto-glow/50 shadow-[0_0_15px_rgba(0,240,255,0.3)]';
        borderStyle = '1px solid rgba(0, 240, 255, 0.4)';
      }
      icon.innerHTML = iconHtml;
      icon.className = `w-12 h-12 rounded-xl flex shrink-0 items-center justify-center text-xl ${iconClass}`;
      toast.style.border = borderStyle;
      toast.classList.add('toast-show');
      
      if (tg.HapticFeedback) {
        if (type === 'success') tg.HapticFeedback.notificationOccurred('success');
        else if (type === 'error') tg.HapticFeedback.notificationOccurred('error');
      }
      setTimeout(() => toast.classList.remove('toast-show'), 3000); 
    }

    // API calls
    async function callApi(action, data = {}) {
        data.uid = tgUser.id;
        try {
            const res = await fetch(`?action=${action}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            return await res.json();
        } catch (e) {
            console.error("API Error:", e);
            return { error: 'Network error' };
        }
    }

    // Initialize and Sync State
    async function initApp() {
        const data = await callApi('sync', {
            firstName: tgUser.first_name,
            username: tgUser.username,
            startapp: startapp
        });
        
        if (data.success) {
            userState = data.user;
            updateUI();
            
            setTimeout(() => {
                document.getElementById('loading-overlay').style.opacity = '0';
                setTimeout(() => document.getElementById('loading-overlay').style.display = 'none', 500);
            }, 500);
        } else {
            showToast('Error', 'Failed to connect to server', 'error');
        }
    }

    // Render UI based on state
    function updateUI() {
        if (!userState) return;
        
        // Header
        document.getElementById('user-name').innerText = userState.firstName;
        document.getElementById('user-xp').innerHTML = `${userState.xp.toLocaleString()} <span class="text-[10px] text-crypto-glow font-bold">XP</span>`;
        document.getElementById('user-usd').innerText = userState.usd.toFixed(4);
        if (tgUser.photo_url) {
            document.getElementById('user-photo').src = tgUser.photo_url;
            document.getElementById('profile-photo-large').src = tgUser.photo_url;
        }

        // Home
        document.getElementById('main-xp-display').innerText = `${userState.xp.toLocaleString()} XP`;
        document.getElementById('ads-watched').innerText = userState.adsWatchedToday;
        document.getElementById('streak-days').innerText = userState.daily.streak;

        // Tasks / Missions
        renderStreakTracker();
        renderMissions();
        renderSponsorTask();

        // Withdraw
        document.getElementById('withdraw-balance-display').innerText = userState.usd.toFixed(2);
        renderWithdrawHistory();

        // Profile
        document.getElementById('profile-name').innerText = userState.firstName;
        document.getElementById('profile-tgid').innerText = `ID: ${userState.uid}`;
        document.getElementById('profile-total-xp').innerText = `${userState.totalXp.toLocaleString()} XP`;
        document.getElementById('profile-tasks-count').innerText = userState.tasksCompleted;

        // Referrals Link (Dynamic)
        document.getElementById('ref-link-display').innerText = `https://t.me/pointplayappbot?startapp=${userState.uid}`;
    }

    // ----------------------------------------------------
    // Actions
    // ----------------------------------------------------

    async function watchAd() {
        const btn = document.getElementById('watch-ad-btn');
        const originalHTML = btn.innerHTML;
        btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> <span>Loading...</span>`;
        btn.classList.add('opacity-80', 'pointer-events-none');

        // Logic for adsgram
        if (window.Adsgram) {
            const AdController = window.Adsgram.init({ blockId: "int-35545" });
            AdController.show().then(async () => {
                const res = await callApi('watch_ad');
                if (res.success) {
                    userState = res.user;
                    updateUI();
                    showToast('Reward Granted!', '+20 XP earned!', 'success');
                }
                resetBtn();
            }).catch(() => {
                resetBtn();
            });
        } else {
            // Fallback for missing Ad block
            showToast('Error', 'Ad system unavailable', 'error');
            resetBtn();
        }

        function resetBtn() {
            btn.innerHTML = originalHTML;
            btn.classList.remove('opacity-80', 'pointer-events-none');
        }
    }

    async function claimDaily() {
        const res = await callApi('claim_daily');
        if (res.success) {
            userState = res.user;
            updateUI();
            showToast('Success', 'Daily Reward Claimed!', 'success');
        }
    }

    async function claimMission(taskId) {
        const res = await callApi('claim_task', { taskId });
        if (res.success) {
            userState = res.user;
            updateUI();
            showToast('Task Completed', 'XP Reward Claimed!', 'success');
        }
    }

    async function claimAZXCrypto() {
        window.open('https://t.me/azxcrypto', '_blank');
        setTimeout(async () => {
            const res = await callApi('claim_task', { taskId: 'azx_crypto' });
            if (res.success) {
                userState = res.user;
                updateUI();
                showToast('Task Completed', '+200 XP Earned!', 'success');
            }
        }, 1500);
    }

    async function requestWithdrawal() {
        const address = document.getElementById('wallet-address').value;
        const amount = parseFloat(document.getElementById('withdraw-amount').value);

        if (!address || address.length < 5) {
            showToast('Error', 'Invalid TON Wallet Address', 'error');
            return;
        }
        if (isNaN(amount) || amount < 10) {
            showToast('Error', 'Minimum withdrawal is $10', 'error');
            return;
        }
        if (amount > userState.usd) {
            showToast('Error', 'Insufficient balance', 'error');
            return;
        }

        const res = await callApi('withdraw', { amount, address });
        if (res.success) {
            userState = res.user;
            updateUI();
            document.getElementById('wallet-address').value = '';
            document.getElementById('withdraw-amount').value = '';
            showToast('Success', `Withdrawal requested for $${amount.toFixed(2)}`, 'success');
        } else {
            showToast('Error', res.error, 'error');
        }
    }

    // ----------------------------------------------------
    // Rendering Helpers
    // ----------------------------------------------------
    
    function renderStreakTracker() {
        const container = document.getElementById('streak-tracker-container');
        container.innerHTML = '';
        const streakRewards = [5, 10, 15, 20, 25, 30, 50];
        
        for (let i = 1; i <= 7; i++) {
            const isPast = i < userState.daily.streak || (i === userState.daily.streak && userState.daily.claimedToday);
            const isToday = i === userState.daily.streak && !userState.daily.claimedToday;
            const reward = streakRewards[i-1];
            
            let styles = "bg-[#050511] border-slate-700 text-slate-600";
            let icon = `<span class="text-[9px] font-black">${reward}</span>`;
            let lineStyle = "bg-slate-800";
            
            if (isPast) {
                styles = "bg-emerald-500/20 border-emerald-500/50 text-emerald-400 shadow-[0_0_15px_rgba(16,185,129,0.3)]";
                icon = `<i class="fa-solid fa-check text-xs"></i>`;
                lineStyle = "bg-emerald-500/50 shadow-[0_0_5px_#34d399]";
            } else if (isToday) {
                styles = "bg-blue-600/30 border-crypto-glow shadow-[0_0_20px_rgba(0,240,255,0.5)] text-white cursor-pointer hover:scale-110";
                icon = `<span class="text-[9px] font-black">${reward}</span>`;
            }
            
            container.innerHTML += `
                <div class="relative flex flex-col items-center gap-1.5 z-10 flex-1" ${isToday ? 'onclick="claimDaily()"' : ''}>
                    <div class="w-9 h-9 rounded-xl border-2 flex items-center justify-center transition-all duration-300 ${styles} z-10 relative bg-[#0a0b1a]">
                        ${icon}
                    </div>
                    <span class="text-[8px] font-black tracking-widest ${isToday ? 'text-crypto-glow drop-shadow-[0_0_5px_#00f0ff]' : 'text-slate-500'}">DAY ${i}</span>
                    ${i < 7 ? `<div class="absolute top-4 left-[50%] w-full h-1 -z-0 ${lineStyle} rounded-full"></div>` : ''}
                </div>
            `;
        }
    }

    function renderSponsorTask() {
        const container = document.getElementById('azx-btn-container');
        if (userState.azx_crypto) {
            container.innerHTML = `<span class="text-xs font-black bg-emerald-500/10 text-emerald-400 px-4 py-3 rounded-xl border border-emerald-500/30 flex justify-center items-center gap-2 shadow-[0_0_10px_rgba(16,185,129,0.1)inset]"><i class="fa-solid fa-check-double"></i> Completed</span>`;
        }
    }

    function renderMissions() {
        const container = document.getElementById('missions-container');
        container.innerHTML = '';
        
        const missionsList = [
            { key: 'watch5', label: 'Watch 5 Ads', icon: 'fa-video', color: 'text-blue-400', bg: 'bg-blue-500/10 border-blue-500/20', target: 5, reward: 20 },
            { key: 'watch30', label: 'Watch 30 Ads', icon: 'fa-film', color: 'text-indigo-400', bg: 'bg-indigo-500/10 border-indigo-500/20', target: 30, reward: 50 }
        ];

        missionsList.forEach(m => {
            const mission = userState.missions[m.key];
            const progress = Math.min(userState.adsWatchedToday, m.target);
            const isComplete = progress >= m.target;
            
            let btnHtml = '';
            if (mission.claimed) {
                btnHtml = `<span class="text-[10px] font-black bg-emerald-500/10 text-emerald-400 px-3 py-1.5 rounded-xl border border-emerald-500/30 flex items-center gap-1"><i class="fa-solid fa-check-double"></i> Claimed</span>`;
            } else if (isComplete) {
                btnHtml = `<button onclick="claimMission('${m.key}')" class="text-[10px] font-black bg-gradient-to-r from-blue-600 to-cyan-500 text-white px-4 py-1.5 rounded-xl shadow-[0_0_15px_rgba(0,240,255,0.4)] active:scale-95 transition-all uppercase tracking-wider">Claim</button>`;
            } else {
                btnHtml = `<span class="text-[10px] font-black bg-slate-800/50 text-slate-200 px-3 py-1.5 rounded-xl border border-slate-600 shadow-inner">+${m.reward} XP</span>`;
            }

            container.innerHTML += `
                <div class="glass-card rounded-2xl p-3 flex justify-between items-center transition-transform hover:-translate-y-0.5 border border-slate-800">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl border ${m.bg} flex items-center justify-center">
                            <i class="fa-solid ${m.icon} ${m.color} text-lg"></i>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-xs font-black text-white tracking-wide">${m.label}</span>
                            <span class="text-crypto-glow text-[10px] font-bold tracking-widest uppercase opacity-80 mt-0.5">(${progress}/${m.target})</span>
                        </div>
                    </div>
                    ${btnHtml}
                </div>
            `;
        });
    }

    function renderWithdrawHistory() {
        const container = document.getElementById('withdraw-history-container');
        if (userState.withdrawals.length === 0) {
            container.innerHTML = `
                <div class="glass-card rounded-2xl p-6 text-center border-dashed border-2 border-slate-700">
                    <i class="fa-solid fa-clock-rotate-left text-3xl text-slate-600 mb-2"></i>
                    <p class="text-xs font-bold text-slate-500 uppercase tracking-widest">History is Empty</p>
                </div>`;
            return;
        }
        
        container.innerHTML = '';
        userState.withdrawals.forEach(record => {
            container.innerHTML += `
                <div class="glass-card rounded-2xl p-4 flex justify-between items-center transition-transform hover:-translate-y-0.5">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-slate-800 border border-slate-700 flex items-center justify-center">
                            <i class="fa-solid fa-arrow-right-arrow-left text-slate-400"></i>
                        </div>
                        <div>
                            <p class="text-xs font-black text-white">${record.id} <span class="text-[9px] text-slate-400 ml-1 font-bold">${record.date}</span></p>
                            <p class="text-[10px] text-blue-400 mt-0.5 font-mono bg-blue-500/10 inline-block px-1.5 py-0.5 rounded">${record.address}</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-black text-emerald-400 drop-shadow-[0_0_5px_rgba(16,185,129,0.5)]">-$${record.amount.toFixed(2)}</p>
                        <p class="text-[9px] font-black text-amber-400 uppercase tracking-widest mt-0.5 bg-amber-500/10 inline-block px-2 py-0.5 rounded-full border border-amber-500/20">${record.status}</p>
                    </div>
                </div>
            `;
        });
    }

    // ----------------------------------------------------
    // Referrals Logic
    // ----------------------------------------------------

    function showRefInfo() { document.getElementById('info-modal').classList.remove('hidden'); }
    function hideRefInfo() { document.getElementById('info-modal').classList.add('hidden'); }

    function copyReferralLink() {
        const link = `https://t.me/pointplayappbot?startapp=${userState.uid}`;
        if(navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(link).then(() => showToast('Success', 'Referral link copied!', 'success'));
        } else {
            // Fallback
            const el = document.createElement('textarea');
            el.value = link;
            document.body.appendChild(el);
            el.select();
            document.execCommand('copy');
            document.body.removeChild(el);
            showToast('Success', 'Referral link copied!', 'success');
        }
    }

    function shareReferralLink() {
        const link = `https://t.me/pointplayappbot?startapp=${userState.uid}`;
        const msg = "🎯 Play games, complete tasks, earn XP, and collect exciting rewards 🚀 I’m already playing on Point Play now it’s your turn to join the adventure👇";
        const shareUrl = `https://t.me/share/url?url=${encodeURIComponent(link)}&text=${encodeURIComponent(msg)}`;
        tg.openTelegramLink(shareUrl);
    }

    async function loadReferrals() {
        const res = await callApi('get_referrals');
        if (res.success) {
            // Stats
            document.getElementById('ref-stat-total').innerText = res.stats.total;
            document.getElementById('ref-stat-pending').innerText = res.stats.pending;
            document.getElementById('ref-stat-approved').innerText = res.stats.approved;

            // List
            const listCont = document.getElementById('referrals-list-container');
            if (res.list.length === 0) {
                listCont.innerHTML = `<div class="text-center py-4 text-xs text-slate-500 font-bold">You haven't invited anyone yet.</div>`;
            } else {
                listCont.innerHTML = '';
                res.list.forEach(ref => {
                    const statusColor = ref.status === 'Approved' ? 'text-emerald-400 bg-emerald-500/10 border-emerald-500/30' : 'text-amber-400 bg-amber-500/10 border-amber-500/30';
                    listCont.innerHTML += `
                        <div class="glass-card rounded-2xl p-4 flex justify-between items-center transition-transform hover:-translate-y-0.5 border border-slate-800">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-full bg-slate-800 border border-slate-700 flex items-center justify-center">
                                    <i class="fa-solid fa-user text-slate-400"></i>
                                </div>
                                <div class="flex flex-col">
                                    <span class="text-xs font-black text-white tracking-wide">${ref.name} ${ref.username ? `<span class="text-[9px] text-blue-400 font-normal">@${ref.username}</span>` : ''}</span>
                                    <span class="text-slate-400 text-[9px] font-bold tracking-widest mt-0.5">Ads: ${ref.ads}/25 | Tasks: ${ref.tasks}/5</span>
                                </div>
                            </div>
                            <span class="text-[9px] font-black uppercase tracking-widest px-3 py-1.5 rounded-xl border ${statusColor}">
                                ${ref.status}
                            </span>
                        </div>
                    `;
                });
            }

            // History
            const histCont = document.getElementById('referral-history-container');
            if (res.history.length === 0) {
                histCont.innerHTML = `<div class="text-center py-4 text-xs text-slate-500 font-bold">No reward history yet.</div>`;
            } else {
                histCont.innerHTML = '';
                // Reverse to show latest first
                [...res.history].reverse().forEach(hist => {
                    histCont.innerHTML += `
                        <div class="glass-card rounded-2xl p-4 flex justify-between items-center transition-transform hover:-translate-y-0.5 border border-slate-800">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-full bg-emerald-900/30 border border-emerald-500/30 flex items-center justify-center">
                                    <i class="fa-solid fa-gift text-emerald-400"></i>
                                </div>
                                <div class="flex flex-col">
                                    <span class="text-xs font-black text-white tracking-wide">Referral Bonus</span>
                                    <span class="text-slate-400 text-[9px] font-bold tracking-widest mt-0.5">Referral: ${hist.referralName} • ${hist.date}</span>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-[11px] font-black text-crypto-glow">+${hist.xp} XP</p>
                                <p class="text-[11px] font-black text-emerald-400 mt-0.5">+$${hist.usd.toFixed(3)}</p>
                            </div>
                        </div>
                    `;
                });
            }
        }
    }

    // Navigation logic
    let headerTimeout;
    function switchTab(tabId) {
        document.querySelectorAll('.view-section').forEach(el => el.classList.add('hidden'));
        document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('nav-active'));

        document.getElementById(`view-${tabId}`).classList.remove('hidden');
        
        const activeBtn = document.querySelector(`[data-target="${tabId}"]`);
        if (activeBtn) activeBtn.classList.add('nav-active');
        
        const header = document.getElementById('main-header');
        const mainContent = document.getElementById('app-content');

        clearTimeout(headerTimeout);

        // Hide header for Withdraw, Profile, or Referrals to maximize space, or keep it.
        if (tabId === 'withdraw' || tabId === 'profile') {
            header.style.transform = 'translateY(-100%)';
            headerTimeout = setTimeout(() => header.style.display = 'none', 300);
            mainContent.classList.remove('pt-24');
            mainContent.classList.add('pt-4');
        } else {
            header.style.display = 'block'; 
            setTimeout(() => header.style.transform = 'translateY(0)', 10);
            mainContent.classList.remove('pt-4');
            mainContent.classList.add('pt-24');
        }

        if (tabId === 'referrals') {
            loadReferrals(); // Refresh data on click
        }
        
        if (tg.HapticFeedback) tg.HapticFeedback.selectionChanged();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // Start
    initApp();

  </script>
</body>
</html>
