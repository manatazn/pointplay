<?php
/**
 * POINT PLAY - EARNING PLATFORM
 * Core Architecture: Users → Ads → Tasks → Referrals → XP → USD Balance → Wallet
 */

// --- 1. SERVER-SIDE CONFIGURATION & STORAGE SETUP ---
error_reporting(E_ALL);
ini_set('display_errors', 0);
date_default_timezone_set('Asia/Baku');

define('DATA_DIR', __DIR__ . '/data');
define('USERS_DIR', DATA_DIR . '/users');

if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);
if (!is_dir(USERS_DIR)) mkdir(USERS_DIR, 0755, true);

// --- 2. CORE JSON DATABASE FUNCTIONS ---
function getUserFile($uid) {
    return USERS_DIR . '/' . preg_replace('/[^a-zA-Z0-9_]/', '', $uid) . '.json';
}

function readUser($uid) {
    $file = getUserFile($uid);
    if (!file_exists($file)) return null;
    $content = file_get_contents($file);
    return json_decode($content, true);
}

function saveUser($uid, $data) {
    $file = getUserFile($uid);
    $fp = fopen($file, 'c');
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

function processReferralProgress($referrer_id, $ref_uid, $type, $ads, $tasks) {
    $ref = readUser($referrer_id);
    if (!$ref) return;
    
    $approved = false;
    foreach ($ref['referrals_list'] as &$r) {
        if ($r['uid'] == $ref_uid) {
            if ($type === 'ads') $r['ads'] = $ads;
            if ($type === 'tasks') $r['tasks'] = $tasks;
            
            if ($r['status'] === 'pending' && $r['ads'] >= 25 && $r['tasks'] >= 5) {
                $r['status'] = 'approved';
                $approved = true;
            }
            break;
        }
    }
    
    if ($approved) {
        $ref['pending_referrals'] = max(0, $ref['pending_referrals'] - 1);
        $ref['approved_referrals']++;
        $ref['xp'] += 250;
        $ref['usd_balance'] += 0.025;
        
        $child = readUser($ref_uid);
        $child_name = $child ? trim(($child['first_name'] ?? '') . ' ' . ($child['last_name'] ?? '')) : 'User';
        
        $ref['referral_rewards'][] = [
            'uid' => $ref_uid,
            'name' => $child_name,
            'date' => date('M j, Y'),
            'xp' => 250,
            'usd' => 0.025
        ];
        
        if ($child) {
            $child['referral_status'] = 'approved';
            saveUser($ref_uid, $child);
        }
    }
    saveUser($referrer_id, $ref);
}

// --- 3. API ROUTING & CONTROLLERS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['api'])) {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $_GET['api'];
    
    $tgData = $input['tg_data'] ?? [];
    $uid = $tgData['id'] ?? null;
    
    if (!$uid) {
        echo json_encode(['error' => 'Unauthorized or missing Telegram UID.']);
        exit;
    }

    $today = date('Y-m-d');

    if ($action === 'init') {
        $user = readUser($uid);
        $startapp = $input['startapp'] ?? null;
        
        if (!$user) {
            $referrer_id = $startapp;
            if ($referrer_id == $uid) $referrer_id = null;
            if ($referrer_id && !file_exists(getUserFile($referrer_id))) $referrer_id = null;
            
            $user = [
                'telegram_id' => $uid,
                'username' => $tgData['username'] ?? '',
                'first_name' => $tgData['first_name'] ?? '',
                'last_name' => $tgData['last_name'] ?? '',
                'created_at' => date('c'),
                'last_active_at' => date('c'),
                'last_reset_date' => $today,
                'xp' => 0,
                'usd_balance' => 0.0,
                'total_ads' => 0,
                'daily_ads' => 0,
                'completed_tasks' => 0,
                'daily_login' => ['last_claim_date' => '', 'streak' => 1],
                'azx_crypto_completed' => false,
                'referrer_id' => $referrer_id,
                'referral_status' => $referrer_id ? 'pending' : 'none',
                'referrals' => 0,
                'pending_referrals' => 0,
                'approved_referrals' => 0,
                'referrals_list' => [],
                'referral_rewards' => [],
                'withdrawals' => [],
                'tasks_progress' => []
            ];
            
            if ($referrer_id) {
                $referrer = readUser($referrer_id);
                if ($referrer) {
                    $referrer['referrals']++;
                    $referrer['pending_referrals']++;
                    $referrer['referrals_list'][] = [
                        'uid' => $uid,
                        'name' => trim(($tgData['first_name']??'').' '.($tgData['last_name']??'')),
                        'username' => $tgData['username'] ?? '',
                        'status' => 'pending',
                        'ads' => 0,
                        'tasks' => 0,
                        'joined_at' => date('c')
                    ];
                    saveUser($referrer_id, $referrer);
                }
            }
        } else {
            $user['last_active_at'] = date('c');
            $user['first_name'] = $tgData['first_name'] ?? $user['first_name'];
            $user['username'] = $tgData['username'] ?? $user['username'];
            $user['last_name'] = $tgData['last_name'] ?? $user['last_name'];
            
            if (($user['last_reset_date'] ?? '') !== $today) {
                $user['daily_ads'] = 0;
                $user['last_reset_date'] = $today;
                
                $last_claim = $user['daily_login']['last_claim_date'] ?? '';
                if ($last_claim) {
                    $diff = (strtotime($today) - strtotime($last_claim)) / 86400;
                    if ($diff > 1) {
                        $user['daily_login']['streak'] = 1;
                    }
                }
            }
        }
        
        saveUser($uid, $user);
        echo json_encode(['success' => true, 'user' => $user]);
        exit;
    }
    
    $user = readUser($uid);
    if (!$user) {
        echo json_encode(['error' => 'User not found.']);
        exit;
    }

    if ($action === 'watch_ad') {
        $user['xp'] += 20;
        $user['usd_balance'] += 0;
        $user['total_ads']++;
        $user['daily_ads']++;
        saveUser($uid, $user);
        
        if ($user['referrer_id'] && $user['referral_status'] === 'pending') {
            processReferralProgress($user['referrer_id'], $uid, 'ads', $user['total_ads'], $user['completed_tasks']);
        }
        
        echo json_encode(['success' => true, 'user' => readUser($uid)]);
        exit;
    }

    if ($action === 'claim_daily') {
        $last_claim = $user['daily_login']['last_claim_date'];
        if ($last_claim !== $today) {
            $streak = $user['daily_login']['streak'];
            $rewards = [5, 10, 15, 20, 25, 30, 50];
            $reward = $rewards[min($streak - 1, 6)];
            
            $user['xp'] += $reward;
            $user['daily_login']['last_claim_date'] = $today;
            if ($streak < 7) $user['daily_login']['streak']++;
            saveUser($uid, $user);
        }
        echo json_encode(['success' => true, 'user' => readUser($uid)]);
        exit;
    }

    if ($action === 'complete_task') {
        $taskId = $input['task_id'] ?? '';
        
        if ($taskId === 'azx_crypto' && !$user['azx_crypto_completed']) {
            $user['azx_crypto_completed'] = true;
            $user['xp'] += 200;
            $user['usd_balance'] += 0;
            $user['completed_tasks']++;
            saveUser($uid, $user);
            
            if ($user['referrer_id'] && $user['referral_status'] === 'pending') {
                processReferralProgress($user['referrer_id'], $uid, 'tasks', $user['total_ads'], $user['completed_tasks']);
            }
        } elseif ($taskId !== 'azx_crypto') {
            if (!isset($user['tasks_progress'][$taskId])) {
                $user['tasks_progress'][$taskId] = true;
                $user['xp'] += 50; 
                $user['completed_tasks']++;
                saveUser($uid, $user);
                
                if ($user['referrer_id'] && $user['referral_status'] === 'pending') {
                    processReferralProgress($user['referrer_id'], $uid, 'tasks', $user['total_ads'], $user['completed_tasks']);
                }
            }
        }
        
        echo json_encode(['success' => true, 'user' => readUser($uid)]);
        exit;
    }

    if ($action === 'withdraw') {
        $amount = (float)($input['amount'] ?? 0);
        $address = $input['address'] ?? '';
        
        if ($amount >= 10 && $amount <= $user['usd_balance'] && strlen($address) > 5) {
            $user['usd_balance'] -= $amount;
            $user['withdrawals'][] = [
                'id' => '#' . strtoupper(substr(md5(uniqid()), 0, 8)),
                'date' => date('M j, Y'),
                'amount' => $amount,
                'address' => $address,
                'status' => 'Pending'
            ];
            saveUser($uid, $user);
            echo json_encode(['success' => true, 'user' => readUser($uid)]);
            exit;
        }
        echo json_encode(['error' => 'Invalid withdrawal request.']);
        exit;
    }

    echo json_encode(['error' => 'Invalid action.']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>Point Play - Earning Platform</title>
  
  <script src="https://telegram.org/js/telegram-web-app.js"></script>
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
    ::-webkit-scrollbar { width: 0px; background: transparent; }

    .glass-card {
      background: linear-gradient(145deg, rgba(20, 22, 45, 0.6) 0%, rgba(10, 11, 26, 0.8) 100%);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      border: 1px solid rgba(255, 255, 255, 0.05);
      box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.3);
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
    
    .modal-overlay {
      background: rgba(0,0,0,0.8);
      backdrop-filter: blur(5px);
      position: fixed; inset: 0; z-index: 99999;
      display: none; justify-content: center; items-center;
    }
    .modal-overlay.active { display: flex; align-items: center; }
  </style>
</head>
<body class="flex flex-col min-h-screen pb-32">
  
  <div class="bg-orb-1"></div>
  <div class="bg-orb-2"></div>

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
    <p id="loading-status" class="text-[10px] text-blue-400 font-bold tracking-widest mt-6 animate-pulse uppercase">Authenticating...</p>
  </div>

  <!-- Toast Notification -->
  <div id="toast-container" class="glass-card rounded-2xl p-4 flex items-center gap-4">
    <div id="toast-icon" class="w-12 h-12 rounded-full flex shrink-0 items-center justify-center text-xl shadow-inner">
      <i class="fa-solid fa-bell"></i>
    </div>
    <div class="flex-1">
      <h4 id="toast-title" class="text-sm font-black text-white tracking-wide">Notification</h4>
      <p id="toast-message" class="text-xs text-slate-300 mt-0.5 leading-tight"></p>
    </div>
  </div>

  <!-- Header -->
  <header id="main-header" class="fixed top-0 left-0 right-0 z-50 w-full p-4 glass-card rounded-b-3xl border-b-0 shadow-lg transition-transform duration-300">
    <div class="flex justify-between items-center max-w-md mx-auto">
      <div class="flex items-center gap-3">
        <div class="flex flex-col">
          <span id="user-name" class="font-bold text-white text-sm tracking-wide">Loading...</span>
          <div class="flex items-center gap-1">
            <span class="w-2 h-2 rounded-full bg-emerald-400 shadow-[0_0_8px_#34d399] animate-pulse"></span>
            <span class="text-[10px] text-slate-400 uppercase font-bold tracking-wider">Online</span>
          </div>
        </div>
      </div>
      <div class="flex flex-col items-end gap-1.5">
        <div class="px-3 py-1.5 rounded-xl flex items-center gap-2" style="background: linear-gradient(135deg, rgba(59,130,246,0.2) 0%, rgba(0,240,255,0.1) 100%); border: 1px solid rgba(0,240,255,0.3);">
          <i class="fa-solid fa-bolt text-crypto-glow text-xs drop-shadow-[0_0_5px_#00f0ff]"></i>
          <span id="user-xp" class="text-white font-black text-sm tracking-wider">0 <span class="text-[10px] text-crypto-glow">XP</span></span>
        </div>
        <div class="bg-emerald-900/40 border border-emerald-500/30 px-3 py-1 rounded-xl flex items-center gap-2 shadow-[0_0_10px_rgba(16,185,129,0.1)inset]">
          <i class="fa-solid fa-dollar-sign text-emerald-400 text-[10px]"></i>
          <span id="user-usd" class="text-emerald-400 font-black text-xs tracking-wider">0.000</span>
        </div>
      </div>
    </div>
  </header>

  <main class="flex-1 max-w-md w-full mx-auto p-4 pt-24 relative" id="app-content">
    
    <!-- HOME VIEW -->
    <div id="view-home" class="view-section fade-in space-y-6">
      <div class="relative glass-card rounded-[2rem] p-6 text-center overflow-hidden flex flex-col items-center justify-center min-h-[200px]">
        <div class="relative z-10 flex flex-col items-center">
          <div class="w-14 h-14 rounded-full bg-blue-900/40 border border-blue-400/30 flex items-center justify-center mb-3 shadow-[0_0_20px_rgba(59,130,246,0.3)]">
             <i class="fa-solid fa-gem text-2xl text-crypto-glow"></i>
          </div>
          <p class="text-[10px] font-black text-blue-400 uppercase tracking-[0.3em] mb-1 opacity-80">Total Balance</p>
          <h1 class="text-5xl font-black text-transparent bg-clip-text bg-gradient-to-b from-white via-cyan-100 to-blue-400 tracking-tighter drop-shadow-2xl" id="main-xp-display">0 XP</h1>
        </div>
        <div class="w-full mt-6 grid grid-cols-2 gap-3">
          <div class="bg-[#050511]/50 border border-slate-700/50 p-3 rounded-2xl flex items-center gap-3 backdrop-blur-md">
            <div class="bg-blue-500/10 p-2.5 rounded-xl border border-blue-500/20"><i class="fa-solid fa-clapperboard text-blue-400"></i></div>
            <div class="text-left">
              <p class="text-[9px] text-slate-500 uppercase font-bold tracking-wider">Ads Watched</p>
              <p class="text-sm font-black text-white" id="main-ads-total">0</p>
            </div>
          </div>
          <div class="bg-[#050511]/50 border border-slate-700/50 p-3 rounded-2xl flex items-center gap-3 backdrop-blur-md">
            <div class="bg-amber-500/10 p-2.5 rounded-xl border border-amber-500/20"><i class="fa-solid fa-fire-flame-curved text-amber-400"></i></div>
            <div class="text-left">
              <p class="text-[9px] text-slate-500 uppercase font-bold tracking-wider">Daily Streak</p>
              <p class="text-sm font-black text-white"><span id="main-streak">1</span> Days</p>
            </div>
          </div>
        </div>
      </div>

      <button onclick="watchAd()" id="watch-ad-btn" class="w-full py-4 rounded-2xl text-white font-black text-sm tracking-[0.15em] uppercase flex items-center justify-center gap-3 shadow-[0_10px_30px_rgba(59,130,246,0.3)] btn-3d relative overflow-hidden">
        <i class="fa-solid fa-play bg-white/20 p-2 rounded-full text-[10px]"></i> 
        <span>Watch Ad <span class="text-cyan-200 ml-1">+20 XP & $0</span></span>
      </button>

      <!-- Daily Login Tracker -->
      <div class="glass-card rounded-2xl p-4 mt-4">
        <h3 class="text-xs font-black text-white uppercase tracking-widest mb-4 flex items-center gap-2">
          <i class="fa-solid fa-calendar-check text-blue-400"></i> Daily Login
        </h3>
        <div class="relative flex justify-between items-center" id="streak-tracker-container"></div>
        <button onclick="claimDaily()" id="btn-claim-daily" class="w-full mt-4 py-3 bg-slate-800 text-slate-400 font-black rounded-xl text-xs uppercase tracking-wider transition-all" disabled>Claimed</button>
      </div>
    </div>

    <!-- TASKS VIEW -->
    <div id="view-tasks" class="view-section hidden fade-in space-y-6 pb-4">
      <div class="text-center pt-2">
        <h2 class="text-3xl font-black text-white tracking-tight drop-shadow-lg">Tasks</h2>
        <p class="text-xs text-crypto-glow mt-1 uppercase tracking-widest font-bold">Complete to Earn XP</p>
      </div>

      <div class="space-y-4" id="tasks-container">
        <!-- AZX Crypto Task -->
        <div class="glass-card rounded-2xl overflow-hidden border border-slate-700/50">
          <img src="https://i.postimg.cc/rsz7NnZp/IMG-20260903-114036-951.jpg" alt="AZX Crypto Banner" class="w-full h-32 object-cover border-b border-slate-700/50" style="pointer-events: none;">
          <div class="p-4 flex justify-between items-center">
            <div>
              <h3 class="text-sm font-black text-white tracking-wide">AZX Crypto Sponsor</h3>
              <p class="text-[10px] text-slate-400 font-bold uppercase tracking-wider mt-1"><i class="fa-solid fa-bolt text-crypto-glow"></i> 200 XP | $0 USD</p>
            </div>
            <button onclick="completeAZXTask()" id="btn-azx-task" class="bg-gradient-to-r from-blue-600 to-cyan-500 text-white px-4 py-2 rounded-xl text-xs font-black uppercase tracking-wider shadow-[0_0_15px_rgba(0,240,255,0.4)] active:scale-95 transition-all">Go</button>
          </div>
        </div>

        <!-- Default Tasks -->
        <div class="glass-card rounded-2xl p-4 flex justify-between items-center border border-slate-700/50">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center text-indigo-400"><i class="fa-solid fa-paper-plane text-lg"></i></div>
            <div>
              <h3 class="text-xs font-black text-white tracking-wide">Join TG Channel</h3>
              <p class="text-[10px] text-slate-400 font-bold uppercase tracking-wider mt-0.5"><i class="fa-solid fa-bolt text-crypto-glow"></i> 50 XP</p>
            </div>
          </div>
          <button onclick="completeGenericTask('tg_channel')" id="btn-task-tg_channel" class="bg-slate-800 text-white px-4 py-2 rounded-xl text-xs font-black uppercase tracking-wider active:scale-95 transition-all border border-slate-600">Go</button>
        </div>
      </div>
    </div>

    <!-- REFERRALS VIEW -->
    <div id="view-referrals" class="view-section hidden fade-in space-y-6 pb-4">
      <div class="text-center pt-2">
        <h2 class="text-3xl font-black text-white tracking-tight drop-shadow-lg">Referrals</h2>
        <p class="text-xs text-emerald-400 mt-1 uppercase tracking-widest font-bold">Invite friends, Earn USD</p>
      </div>

      <div class="grid grid-cols-3 gap-2">
        <div class="glass-card p-3 rounded-2xl text-center border-t-2 border-t-blue-500/30">
          <p class="text-[9px] text-slate-400 uppercase font-black tracking-widest mb-1">Total</p>
          <p class="text-xl font-black text-white" id="ref-total">0</p>
        </div>
        <div class="glass-card p-3 rounded-2xl text-center border-t-2 border-t-amber-500/30">
          <p class="text-[9px] text-slate-400 uppercase font-black tracking-widest mb-1">Pending</p>
          <p class="text-xl font-black text-white" id="ref-pending">0</p>
        </div>
        <div class="glass-card p-3 rounded-2xl text-center border-t-2 border-t-emerald-500/30">
          <p class="text-[9px] text-slate-400 uppercase font-black tracking-widest mb-1">Approved</p>
          <p class="text-xl font-black text-white" id="ref-approved">0</p>
        </div>
      </div>

      <div class="glass-card rounded-2xl p-4 border border-slate-700/50">
        <div class="flex justify-between items-center mb-3">
          <h3 class="text-xs font-black text-white uppercase tracking-widest">Your Invite Link</h3>
          <button onclick="showInfoModal()" class="text-blue-400 hover:text-crypto-glow transition-colors"><i class="fa-solid fa-circle-info text-lg"></i></button>
        </div>
        <div class="flex bg-[#050511] p-2 rounded-xl border border-slate-800 items-center gap-2 mb-3">
          <input type="text" id="ref-link-input" readonly class="w-full bg-transparent text-xs text-slate-300 font-mono focus:outline-none px-2" value="">
          <button onclick="copyRefLink()" class="bg-slate-800 px-3 py-2 rounded-lg text-white text-xs font-bold shrink-0 hover:bg-slate-700"><i class="fa-regular fa-copy"></i></button>
        </div>
        <button onclick="shareRefLink()" class="w-full py-3 bg-gradient-to-r from-blue-600 to-cyan-500 text-white font-black rounded-xl text-xs uppercase tracking-widest shadow-[0_4px_15px_rgba(0,240,255,0.3)] active:scale-95 transition-all"><i class="fa-brands fa-telegram mr-2"></i> Share on Telegram</button>
      </div>

      <!-- Referral List -->
      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-2 mb-3">My Referrals</h3>
        <div id="referrals-list-container" class="space-y-2">
          <!-- Populated by JS -->
        </div>
      </div>

      <!-- Reward History -->
      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-2 mb-3">Reward History</h3>
        <div id="referral-history-container" class="space-y-2">
           <!-- Populated by JS -->
        </div>
      </div>
    </div>

    <!-- WALLET VIEW -->
    <div id="view-withdraw" class="view-section hidden fade-in space-y-6">
      <div class="glass-card rounded-[2rem] p-6 text-center border-t-2 border-emerald-500/40 bg-gradient-to-b from-emerald-900/30 to-[#050511]">
        <div class="w-14 h-14 mx-auto bg-emerald-500/10 rounded-full flex items-center justify-center mb-3 border border-emerald-500/30 shadow-[0_0_20px_rgba(16,185,129,0.2)]">
          <i class="fa-solid fa-wallet text-xl text-emerald-400"></i>
        </div>
        <p class="text-[10px] font-black text-emerald-400 uppercase tracking-[0.2em] mb-1 opacity-80">USD Balance</p>
        <h1 class="text-5xl font-black text-white tracking-tighter mb-3">$<span id="withdraw-balance-display">0.00</span></h1>
        <div class="inline-block bg-[#050511]/80 backdrop-blur-md px-3 py-1.5 rounded-full border border-slate-700/50">
          <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest"><i class="fa-solid fa-circle-info text-blue-400 mr-1"></i> Minimum: $10.00</p>
        </div>
      </div>

      <div class="glass-card rounded-[1.5rem] p-5 space-y-4 border-slate-800">
        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase tracking-[0.15em] mb-2 ml-1">TON Wallet Address</label>
          <input type="text" id="wallet-address" placeholder="UQ..." class="w-full bg-[#050511]/50 border-2 border-slate-700/50 rounded-xl py-3.5 px-4 text-sm font-medium text-white focus:outline-none focus:border-blue-500 transition-colors placeholder-slate-600 shadow-inner">
        </div>
        
        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase tracking-[0.15em] mb-2 ml-1">Amount (USD)</label>
          <input type="number" id="withdraw-amount" placeholder="10.00" min="10" step="1" class="w-full bg-[#050511]/50 border-2 border-slate-700/50 rounded-xl py-3.5 px-4 text-sm font-bold text-emerald-400 focus:outline-none focus:border-emerald-500 transition-colors placeholder-slate-600 shadow-inner">
        </div>

        <button onclick="requestWithdrawal()" id="withdraw-btn" class="w-full py-3.5 mt-2 bg-gradient-to-r from-emerald-600 to-teal-500 hover:brightness-110 active:scale-95 transition-all text-white font-black rounded-xl text-sm uppercase tracking-[0.15em] shadow-[0_10px_20px_rgba(16,185,129,0.3)]">
          Request Withdrawal
        </button>
      </div>

      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-4 mb-3">Withdrawal History</h3>
        <div id="withdraw-history-container" class="space-y-3"></div>
      </div>
    </div>

  </main>

  <!-- Bottom Navigation -->
  <nav id="bottom-nav" class="fixed bottom-6 left-1/2 -translate-x-1/2 w-[calc(100%-2rem)] max-w-[400px] glass-card rounded-2xl pb-safe z-50 shadow-[0_20px_40px_rgba(0,0,0,0.8)] border border-slate-700/50 backdrop-blur-xl">
    <div class="flex justify-between items-center px-4 py-3 relative">
      <button onclick="switchTab('home')" class="nav-btn nav-active text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all duration-300 group" data-target="home">
        <i class="fa-solid fa-house text-xl transition-transform group-active:scale-90"></i>
        <span class="text-[8px] font-black uppercase tracking-widest">Home</span>
      </button>
      <button onclick="switchTab('tasks')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all duration-300 group" data-target="tasks">
        <i class="fa-solid fa-list-check text-xl transition-transform group-active:scale-90"></i>
        <span class="text-[8px] font-black uppercase tracking-widest">Task</span>
      </button>
      <button onclick="switchTab('referrals')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all duration-300 group" data-target="referrals">
        <i class="fa-solid fa-users text-xl transition-transform group-active:scale-90"></i>
        <span class="text-[8px] font-black uppercase tracking-widest">Referrals</span>
      </button>
      <button onclick="switchTab('withdraw')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all duration-300 group" data-target="withdraw">
        <i class="fa-solid fa-wallet text-xl transition-transform group-active:scale-90"></i>
        <span class="text-[8px] font-black uppercase tracking-widest">Wallet</span>
      </button>
    </div>
  </nav>

  <!-- Referral Info Modal -->
  <div id="ref-info-modal" class="modal-overlay">
    <div class="glass-card p-6 rounded-2xl max-w-[320px] w-full mx-4 border border-blue-500/30">
      <h2 class="text-xl font-black text-white mb-4 text-center">Referral Rules</h2>
      <ul class="text-sm text-slate-300 space-y-3 mb-6 list-disc pl-4">
        <li>A referred user must watch <strong>25 valid ads</strong>.</li>
        <li>A referred user must complete <strong>5 tasks</strong>.</li>
        <li>There is <strong>no deadline</strong>.</li>
        <li>The referral remains pending until both conditions are completed.</li>
        <li>After approval, you automatically receive <span class="text-crypto-glow font-bold">250 XP</span> + <span class="text-emerald-400 font-bold">$0.025 USD</span>.</li>
      </ul>
      <button onclick="closeInfoModal()" class="w-full py-3 bg-slate-800 text-white rounded-xl font-black uppercase tracking-wider text-xs">Got it</button>
    </div>
  </div>

  <script>
    const tg = window.Telegram.WebApp;
    tg.expand();
    tg.ready();
    tg.setHeaderColor('#0a0b1a');
    tg.setBackgroundColor('#050511');

    let currentUserData = null;
    let isRequestInProgress = false;

    // Utility: Toast
    function showToast(title, message, type = 'info') {
      const toast = document.getElementById('toast-container');
      const icon = document.getElementById('toast-icon');
      
      document.getElementById('toast-title').innerText = title;
      document.getElementById('toast-message').innerText = message;
      
      let iconClass, iconHtml;
      if (type === 'success') {
        iconHtml = '<i class="fa-solid fa-check"></i>';
        iconClass = 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/50';
      } else if (type === 'error') {
        iconHtml = '<i class="fa-solid fa-xmark"></i>';
        iconClass = 'bg-red-500/20 text-red-400 border border-red-500/50';
      } else {
        iconHtml = '<i class="fa-solid fa-bell"></i>';
        iconClass = 'bg-blue-500/20 text-crypto-glow border border-crypto-glow/50';
      }

      icon.innerHTML = iconHtml;
      icon.className = `w-12 h-12 rounded-xl flex shrink-0 items-center justify-center text-xl ${iconClass}`;
      toast.classList.add('toast-show');
      
      if (tg.HapticFeedback) {
        if (type === 'success') tg.HapticFeedback.notificationOccurred('success');
        else if (type === 'error') tg.HapticFeedback.notificationOccurred('error');
      }
      setTimeout(() => toast.classList.remove('toast-show'), 3000); 
    }

    // Utility: API Caller
    async function apiCall(action, payload = {}) {
      try {
        const initDataUnsafe = tg.initDataUnsafe || {};
        const response = await fetch(`?api=${action}`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ tg_data: initDataUnsafe.user, startapp: initDataUnsafe.start_param, ...payload })
        });
        const result = await response.json();
        if (result.error) {
            showToast('Error', result.error, 'error');
            return null;
        }
        if (result.user) {
            currentUserData = result.user;
            renderUI();
        }
        return result;
      } catch (e) {
        showToast('Network Error', 'Could not connect to the server.', 'error');
        return null;
      }
    }

    // Initialize App
    async function initApp() {
      const tgUser = tg.initDataUnsafe?.user;
      if (!tgUser) {
          document.getElementById('loading-status').innerText = 'Error: Open in Telegram.';
          return;
      }
      
      const res = await apiCall('init');
      if (res) {
          document.getElementById('loading-overlay').style.opacity = '0';
          setTimeout(() => document.getElementById('loading-overlay').style.display = 'none', 500);
          
          document.getElementById('ref-link-input').value = `https://t.me/pointplayappbot?startapp=${currentUserData.telegram_id}`;
      }
    }

    // Rendering Engine
    function renderUI() {
        if (!currentUserData) return;

        // Header & Home
        document.getElementById('user-name').innerText = currentUserData.first_name || 'User';
        document.getElementById('user-xp').innerHTML = `${currentUserData.xp} <span class="text-[10px] text-crypto-glow font-bold">XP</span>`;
        document.getElementById('user-usd').innerText = parseFloat(currentUserData.usd_balance).toFixed(3);
        
        document.getElementById('main-xp-display').innerText = `${currentUserData.xp} XP`;
        document.getElementById('main-ads-total').innerText = currentUserData.total_ads;
        document.getElementById('main-streak').innerText = currentUserData.daily_login.streak;
        
        renderDailyLogin();
        renderTasks();
        renderReferrals();
        
        // Wallet
        document.getElementById('withdraw-balance-display').innerText = parseFloat(currentUserData.usd_balance).toFixed(3);
        renderWithdrawals();
    }

    function renderDailyLogin() {
        const container = document.getElementById('streak-tracker-container');
        const btn = document.getElementById('btn-claim-daily');
        container.innerHTML = '';
        
        const streak = currentUserData.daily_login.streak;
        const lastClaim = currentUserData.daily_login.last_claim_date;
        const today = new Date().toISOString().split('T')[0];
        const canClaim = (lastClaim !== today);
        const rewards = [5, 10, 15, 20, 25, 30, 50];

        for (let i = 1; i <= 7; i++) {
            const isPast = (i < streak) || (i === streak && !canClaim);
            const isToday = (i === streak && canClaim);
            
            let styles = "bg-[#050511] border-slate-700 text-slate-600";
            let icon = `<span class="text-[9px] font-black">+${rewards[i-1]}</span>`;
            
            if (isPast) {
                styles = "bg-emerald-500/20 border-emerald-500/50 text-emerald-400";
                icon = `<i class="fa-solid fa-check text-xs"></i>`;
            } else if (isToday) {
                styles = "bg-blue-600/30 border-crypto-glow shadow-[0_0_10px_rgba(0,240,255,0.5)] text-white";
            }

            container.innerHTML += `
              <div class="flex flex-col items-center gap-1">
                <div class="w-8 h-8 rounded-lg border-2 flex items-center justify-center ${styles}">${icon}</div>
                <span class="text-[8px] font-black tracking-widest text-slate-500">D${i}</span>
              </div>
            `;
        }

        if (canClaim) {
            btn.disabled = false;
            btn.className = "w-full mt-4 py-3 bg-gradient-to-r from-blue-600 to-cyan-500 text-white font-black rounded-xl text-xs uppercase tracking-wider shadow-[0_4px_15px_rgba(0,240,255,0.3)] active:scale-95 transition-all";
            btn.innerText = "Claim Daily Reward";
        } else {
            btn.disabled = true;
            btn.className = "w-full mt-4 py-3 bg-slate-800 text-slate-400 font-black rounded-xl text-xs uppercase tracking-wider";
            btn.innerText = "Come back tomorrow";
        }
    }

    async function claimDaily() {
        if (isRequestInProgress) return;
        isRequestInProgress = true;
        const res = await apiCall('claim_daily');
        if (res) showToast('Daily Reward', 'Reward claimed successfully!', 'success');
        isRequestInProgress = false;
    }

    async function watchAd() {
      if (isRequestInProgress) return;
      isRequestInProgress = true;
      const btn = document.getElementById('watch-ad-btn');
      const originalHTML = btn.innerHTML;
      btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> <span>Loading Ad...</span>`;
      
      if (window.Adsgram) {
        const AdController = window.Adsgram.init({ blockId: "int-35545" });
        AdController.show().then(async (result) => {
          const res = await apiCall('watch_ad');
          if(res) showToast('Reward Granted!', 'You earned +20 XP.', 'success');
          btn.innerHTML = originalHTML;
          isRequestInProgress = false;
        }).catch((error) => {
          showToast('Error', 'Ad playback failed.', 'error');
          btn.innerHTML = originalHTML;
          isRequestInProgress = false;
        });
      } else {
        showToast('Error', 'Ad system not loaded.', 'error');
        btn.innerHTML = originalHTML;
        isRequestInProgress = false;
      }
    }

    function renderTasks() {
        if (!currentUserData) return;
        const btnAzx = document.getElementById('btn-azx-task');
        if (currentUserData.azx_crypto_completed) {
            btnAzx.innerText = "Completed";
            btnAzx.className = "bg-emerald-500/10 text-emerald-400 border border-emerald-500/30 px-4 py-2 rounded-xl text-xs font-black uppercase";
            btnAzx.disabled = true;
            btnAzx.onclick = null;
        }

        const btnGen = document.getElementById('btn-task-tg_channel');
        if (currentUserData.tasks_progress && currentUserData.tasks_progress['tg_channel']) {
            btnGen.innerText = "Completed";
            btnGen.className = "bg-emerald-500/10 text-emerald-400 border border-emerald-500/30 px-4 py-2 rounded-xl text-xs font-black uppercase";
            btnGen.disabled = true;
            btnGen.onclick = null;
        }
    }

    async function completeAZXTask() {
        tg.openTelegramLink('https://t.me/azxcrypto');
        setTimeout(async () => {
            if (!currentUserData.azx_crypto_completed) {
                const res = await apiCall('complete_task', { task_id: 'azx_crypto' });
                if (res) showToast('Task Completed', 'You earned 200 XP!', 'success');
            }
        }, 3000);
    }

    async function completeGenericTask(taskId) {
        if(taskId === 'tg_channel') tg.openTelegramLink('https://t.me/pointplayapp');
        setTimeout(async () => {
            if (!currentUserData.tasks_progress || !currentUserData.tasks_progress[taskId]) {
                const res = await apiCall('complete_task', { task_id: taskId });
                if (res) showToast('Task Completed', 'You earned 50 XP!', 'success');
            }
        }, 3000);
    }

    function renderReferrals() {
        document.getElementById('ref-total').innerText = currentUserData.referrals;
        document.getElementById('ref-pending').innerText = currentUserData.pending_referrals;
        document.getElementById('ref-approved').innerText = currentUserData.approved_referrals;

        const listContainer = document.getElementById('referrals-list-container');
        listContainer.innerHTML = '';
        if (!currentUserData.referrals_list || currentUserData.referrals_list.length === 0) {
            listContainer.innerHTML = `<div class="p-4 text-center text-xs text-slate-500 font-bold bg-[#050511] rounded-xl border border-slate-800">No referrals yet</div>`;
        } else {
            currentUserData.referrals_list.reverse().forEach(ref => {
                const isApproved = ref.status === 'approved';
                const statusBadge = isApproved 
                    ? `<span class="bg-emerald-500/10 text-emerald-400 px-2 py-0.5 rounded text-[9px] border border-emerald-500/20 uppercase tracking-widest">Approved</span>`
                    : `<span class="bg-amber-500/10 text-amber-400 px-2 py-0.5 rounded text-[9px] border border-amber-500/20 uppercase tracking-widest">Pending</span>`;
                
                listContainer.innerHTML += `
                <div class="glass-card p-3 rounded-xl flex justify-between items-center border border-slate-700/30">
                    <div>
                        <p class="text-xs font-black text-white">${ref.name} ${ref.username ? `<span class="text-[9px] font-normal text-blue-400">@${ref.username}</span>` : ''}</p>
                        <p class="text-[9px] text-slate-400 mt-1">Ads: ${ref.ads}/25 | Tasks: ${ref.tasks}/5</p>
                    </div>
                    <div>${statusBadge}</div>
                </div>`;
            });
        }

        const histContainer = document.getElementById('referral-history-container');
        histContainer.innerHTML = '';
        if (!currentUserData.referral_rewards || currentUserData.referral_rewards.length === 0) {
            histContainer.innerHTML = `<div class="p-4 text-center text-xs text-slate-500 font-bold bg-[#050511] rounded-xl border border-slate-800">No rewards yet</div>`;
        } else {
            currentUserData.referral_rewards.reverse().forEach(rew => {
                histContainer.innerHTML += `
                <div class="glass-card p-3 rounded-xl flex justify-between items-center border border-slate-700/30">
                    <div>
                        <p class="text-[10px] font-black text-white uppercase tracking-wider">Referral Bonus</p>
                        <p class="text-[9px] text-slate-400 mt-0.5">From: ${rew.name} • ${rew.date}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs font-black text-crypto-glow">+${rew.xp} XP</p>
                        <p class="text-[10px] font-black text-emerald-400">+$${rew.usd} USD</p>
                    </div>
                </div>`;
            });
        }
    }

    function copyRefLink() {
        const link = document.getElementById('ref-link-input').value;
        navigator.clipboard.writeText(link);
        showToast('Success', 'Referral link copied!', 'success');
    }

    function shareRefLink() {
        const link = document.getElementById('ref-link-input').value;
        const text = "🎯 Play games, complete tasks, earn XP, and collect exciting rewards 🚀 I’m already playing on Point Play now it’s your turn to join the adventure👇";
        tg.openTelegramLink(`https://t.me/share/url?url=${encodeURIComponent(link)}&text=${encodeURIComponent(text)}`);
    }

    function showInfoModal() { document.getElementById('ref-info-modal').classList.add('active'); }
    function closeInfoModal() { document.getElementById('ref-info-modal').classList.remove('active'); }

    function renderWithdrawals() {
        const container = document.getElementById('withdraw-history-container');
        container.innerHTML = '';
        if (!currentUserData.withdrawals || currentUserData.withdrawals.length === 0) {
            container.innerHTML = `<div class="glass-card rounded-2xl p-6 text-center border-dashed border-2 border-slate-700">
                <i class="fa-solid fa-clock-rotate-left text-2xl text-slate-600 mb-2"></i>
                <p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">History is Empty</p>
            </div>`;
        } else {
            currentUserData.withdrawals.reverse().forEach(w => {
                container.innerHTML += `
                <div class="glass-card rounded-2xl p-4 flex justify-between items-center">
                    <div>
                        <p class="text-xs font-black text-white">${w.id}</p>
                        <p class="text-[9px] text-slate-400 mt-0.5">${w.date} • ${w.address}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-black text-white">-$${parseFloat(w.amount).toFixed(2)}</p>
                        <p class="text-[9px] font-black text-amber-400 uppercase tracking-widest mt-0.5">${w.status}</p>
                    </div>
                </div>`;
            });
        }
    }

    async function requestWithdrawal() {
        if (isRequestInProgress) return;
        const addr = document.getElementById('wallet-address').value;
        const amount = parseFloat(document.getElementById('withdraw-amount').value);
        
        if (addr.length < 5) return showToast('Invalid Address', 'Enter a valid TON wallet address.', 'error');
        if (isNaN(amount) || amount < 10) return showToast('Invalid Amount', 'Minimum withdrawal is $10.00.', 'error');
        if (amount > currentUserData.usd_balance) return showToast('Insufficient Balance', 'Not enough USD balance.', 'error');

        isRequestInProgress = true;
        const res = await apiCall('withdraw', { address: addr, amount: amount });
        if (res) {
            showToast('Success', 'Withdrawal requested successfully!', 'success');
            document.getElementById('wallet-address').value = '';
            document.getElementById('withdraw-amount').value = '';
        }
        isRequestInProgress = false;
    }

    function switchTab(tabId) {
      document.querySelectorAll('.view-section').forEach(el => el.classList.add('hidden'));
      document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('nav-active'));

      document.getElementById(`view-${tabId}`).classList.remove('hidden');
      document.querySelector(`[data-target="${tabId}"]`).classList.add('nav-active');
      
      const header = document.getElementById('main-header');
      const mainContent = document.getElementById('app-content');

      if (tabId === 'withdraw') {
        header.style.transform = 'translateY(-100%)';
        setTimeout(() => header.style.display = 'none', 300);
        mainContent.classList.remove('pt-24');
        mainContent.classList.add('pt-4');
      } else {
        header.style.display = 'block';
        setTimeout(() => header.style.transform = 'translateY(0)', 10);
        mainContent.classList.remove('pt-4');
        mainContent.classList.add('pt-24');
      }
      
      if (tg.HapticFeedback) tg.HapticFeedback.selectionChanged();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // Start
    initApp();
  </script>
</body>
</html>
