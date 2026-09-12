<?php
/**
 * POINT PLAY - CORE BACKEND ARCHITECTURE
 * Single Source of Truth / Server-Side Validation
 */

// Define safe storage paths
$dataDir = __DIR__ . '/data';
$usersDir = $dataDir . '/users';

// Automatically create JSON storage architecture
if (!is_dir($dataDir)) mkdir($dataDir, 0777, true);
if (!is_dir($usersDir)) mkdir($usersDir, 0777, true);

// -----------------------------------------------------------------------------
// SERVER-SIDE UTILITY & STORAGE FUNCTIONS
// -----------------------------------------------------------------------------
function sanitizeUid($uid) {
    return preg_replace('/[^0-9]/', '', (string)$uid);
}

function getUserFile($uid) {
    global $usersDir;
    return $usersDir . '/' . sanitizeUid($uid) . '.json';
}

function getUser($uid) {
    $file = getUserFile($uid);
    if (file_exists($file)) {
        $json = file_get_contents($file);
        return json_decode($json, true);
    }
    return null;
}

function saveUser($uid, $data) {
    $file = getUserFile($uid);
    $fp = fopen($file, 'c');
    if ($fp && flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }
    if ($fp) fclose($fp);
    return false;
}

function checkReferralProgress($user) {
    if (!empty($user['referrer_id']) && $user['referral_status'] === 'pending') {
        if ($user['total_ads'] >= 25 && $user['completed_tasks'] >= 5) {
            $user['referral_status'] = 'approved';
            
            // Reward Referrer
            $referrer = getUser($user['referrer_id']);
            if ($referrer) {
                $referrer['xp'] += 250;
                $referrer['usd_balance'] += 0.025;
                $referrer['pending_referrals'] = max(0, $referrer['pending_referrals'] - 1);
                $referrer['approved_referrals'] += 1;
                
                $referrer['referral_rewards'][] = [
                    'date' => date('Y-m-d H:i:s'),
                    'referral_uid' => $user['telegram_id'],
                    'name' => $user['first_name'],
                    'xp' => 250,
                    'usd' => 0.025
                ];
                saveUser($user['referrer_id'], $referrer);
            }
        }
    }
    return $user;
}

// -----------------------------------------------------------------------------
// API ROUTER (Handled within index.php)
// -----------------------------------------------------------------------------
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? '';
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    
    // Secure Telegram Data Extraction
    $tgData = $input['tg_data'] ?? [];
    $uid = sanitizeUid($tgData['id'] ?? '');
    
    if (empty($uid)) {
        echo json_encode(['error' => 'Invalid Telegram Identity']);
        exit;
    }

    $response = ['success' => false];
    $today = date('Y-m-d');

    // 1. INIT / LOAD / REGISTER USER
    if ($action === 'init') {
        $user = getUser($uid);
        $referrerId = sanitizeUid($input['startapp'] ?? '');
        
        if (!$user) {
            // Register new user record
            $user = [
                'telegram_id' => $uid,
                'username' => htmlspecialchars($tgData['username'] ?? ''),
                'first_name' => htmlspecialchars($tgData['first_name'] ?? 'User'),
                'last_name' => htmlspecialchars($tgData['last_name'] ?? ''),
                'created_at' => date('Y-m-d H:i:s'),
                'last_active_at' => date('Y-m-d H:i:s'),
                'xp' => 0,
                'usd_balance' => 0.00,
                'total_ads' => 0,
                'daily_ads' => 0,
                'completed_tasks' => 0,
                'last_reset_day' => $today,
                'daily_login' => ['last_claim' => '', 'streak' => 1],
                'azx_crypto_completed' => false,
                'referrer_id' => null,
                'referral_status' => 'none',
                'referrals' => 0,
                'pending_referrals' => 0,
                'approved_referrals' => 0,
                'referral_rewards' => [],
                'withdrawals' => [],
                'tasks' => [
                    'watch5' => false,
                    'watch30' => false
                ]
            ];

            // Handle Referral logic strictly
            if (!empty($referrerId) && $referrerId !== $uid) {
                $referrer = getUser($referrerId);
                if ($referrer) {
                    $user['referrer_id'] = $referrerId;
                    $user['referral_status'] = 'pending';
                    
                    $referrer['referrals'] += 1;
                    $referrer['pending_referrals'] += 1;
                    saveUser($referrerId, $referrer);
                }
            }
        }

        // Daily Reset Logic
        if ($user['last_reset_day'] !== $today) {
            $user['daily_ads'] = 0;
            $user['tasks']['watch5'] = false;
            $user['tasks']['watch30'] = false;
            $user['last_reset_day'] = $today;
        }

        $user['last_active_at'] = date('Y-m-d H:i:s');
        saveUser($uid, $user);
        
        $response = ['success' => true, 'user' => $user];
    }
    
    // 2. WATCH AD REWARD (STRICT)
    else if ($action === 'watch_ad') {
        $user = getUser($uid);
        if ($user) {
            $user['xp'] += 20; // Exact requirement: +20 XP
            $user['usd_balance'] += 0; // Exact requirement: +$0 USD
            $user['total_ads'] += 1;
            $user['daily_ads'] += 1;
            
            $user = checkReferralProgress($user);
            saveUser($uid, $user);
            $response = ['success' => true, 'user' => $user];
        }
    }
    
    // 3. DAILY LOGIN CLAIM
    else if ($action === 'claim_daily') {
        $user = getUser($uid);
        if ($user && $user['daily_login']['last_claim'] !== $today) {
            // Calculate streak
            $lastClaim = $user['daily_login']['last_claim'];
            $streak = $user['daily_login']['streak'];
            
            if (!empty($lastClaim)) {
                $diff = (strtotime($today) - strtotime($lastClaim)) / (60 * 60 * 24);
                if ($diff == 1) $streak = ($streak >= 7) ? 1 : $streak + 1;
                else $streak = 1;
            }
            
            $reward = [5, 10, 15, 20, 25, 30, 50][$streak - 1];
            
            $user['xp'] += $reward;
            $user['daily_login']['last_claim'] = $today;
            $user['daily_login']['streak'] = $streak;
            
            saveUser($uid, $user);
            $response = ['success' => true, 'user' => $user, 'reward' => $reward];
        }
    }
    
    // 4. COMPLETE TASKS
    else if ($action === 'complete_task') {
        $taskId = $input['task_id'] ?? '';
        $user = getUser($uid);
        
        if ($user) {
            if ($taskId === 'azx_crypto' && !$user['azx_crypto_completed']) {
                $user['azx_crypto_completed'] = true;
                $user['xp'] += 200;
                $user['completed_tasks'] += 1;
            } 
            else if ($taskId === 'watch5' && !$user['tasks']['watch5'] && $user['daily_ads'] >= 5) {
                $user['tasks']['watch5'] = true;
                $user['xp'] += 20;
                $user['completed_tasks'] += 1;
            }
            else if ($taskId === 'watch30' && !$user['tasks']['watch30'] && $user['daily_ads'] >= 30) {
                $user['tasks']['watch30'] = true;
                $user['xp'] += 50;
                $user['completed_tasks'] += 1;
            }

            $user = checkReferralProgress($user);
            saveUser($uid, $user);
            $response = ['success' => true, 'user' => $user];
        }
    }

    // 5. WITHDRAW
    else if ($action === 'withdraw') {
        $user = getUser($uid);
        $amount = (float)($input['amount'] ?? 0);
        $address = htmlspecialchars($input['address'] ?? '');
        
        // Validation: Min withdrawal is $10
        if ($user && $amount >= 10 && $user['usd_balance'] >= $amount && !empty($address)) {
            $user['usd_balance'] -= $amount;
            $user['withdrawals'][] = [
                'id' => '#' . strtoupper(substr(uniqid(), -6)),
                'date' => date('Y-m-d H:i:s'),
                'amount' => $amount,
                'address' => $address,
                'status' => 'Pending'
            ];
            saveUser($uid, $user);
            $response = ['success' => true, 'user' => $user];
        } else {
            $response = ['success' => false, 'error' => 'Invalid amount (Min $10) or balance.'];
        }
    }

    // 6. GET REFERRALS LIST
    else if ($action === 'get_referrals') {
        $user = getUser($uid);
        $list = [];
        
        // Look up users who have this user as referrer
        // In a production environment with millions of users, this should be indexed or mapped.
        // For standard JSON implementations, we scan the users directory.
        if (is_dir($usersDir)) {
            $files = scandir($usersDir);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                $refUser = json_decode(file_get_contents($usersDir . '/' . $file), true);
                if (isset($refUser['referrer_id']) && $refUser['referrer_id'] === $uid) {
                    $list[] = [
                        'name' => $refUser['first_name'],
                        'username' => $refUser['username'],
                        'status' => $refUser['referral_status'],
                        'ads' => $refUser['total_ads'],
                        'tasks' => $refUser['completed_tasks']
                    ];
                }
            }
        }
        $response = ['success' => true, 'list' => $list];
    }

    echo json_encode($response);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>Point Play - Earn & Tasks</title>
  
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
              glow: '#00f0ff',     
            }
          },
          animation: {
            'pulse-fast': 'pulse 1.5s cubic-bezier(0.4, 0, 0.6, 1) infinite',
            'shimmer': 'shimmer 2s infinite'
          },
          keyframes: {
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
    input { user-select: auto !important; }
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
    
    #toast-container {
      position: fixed; top: 1.5rem; left: 50%; transform: translate(-50%, -150%) scale(0.9);
      width: 90%; max-width: 380px; z-index: 999999;
      transition: all 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55);
      opacity: 0; pointer-events: none;
    }
    .toast-show { transform: translate(-50%, 0) scale(1) !important; opacity: 1 !important; }

    /* Modal styling */
    .modal-overlay {
      position: fixed; top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(0,0,0,0.8); backdrop-filter: blur(5px);
      z-index: 100000; display: none; align-items: center; justify-content: center;
    }
    .modal-active { display: flex; }
  </style>
</head>
<body class="flex flex-col min-h-screen pb-32">

  <!-- TOAST NOTIFICATION -->
  <div id="toast-container" class="glass-card rounded-2xl p-4 flex items-center gap-4">
    <div id="toast-icon" class="w-12 h-12 rounded-full flex shrink-0 items-center justify-center text-xl shadow-inner">
      <i class="fa-solid fa-bell"></i>
    </div>
    <div class="flex-1">
      <h4 id="toast-title" class="text-sm font-black text-white tracking-wide">Notification</h4>
      <p id="toast-message" class="text-xs text-slate-300 mt-0.5 leading-tight">Message goes here</p>
    </div>
  </div>

  <!-- LOADING OVERLAY -->
  <div id="loading-overlay" class="bg-[#050511] flex flex-col items-center justify-center z-[100000] fixed inset-0 transition-opacity duration-500">
    <h2 class="text-white font-black tracking-[0.25em] text-2xl uppercase bg-clip-text text-transparent bg-gradient-to-r from-crypto-glow via-blue-400 to-indigo-500 mb-2 drop-shadow-lg">Point Play</h2>
    <p class="text-[10px] text-blue-400 font-bold tracking-widest mt-6 animate-pulse uppercase">Authenticating...</p>
  </div>

  <!-- HEADER -->
  <header id="main-header" class="fixed top-0 left-0 right-0 z-50 w-full p-4 glass-card rounded-b-3xl border-b-0 shadow-lg transition-transform duration-300">
    <div class="flex justify-between items-center max-w-md mx-auto">
      <div class="flex items-center gap-3">
        <div class="relative w-11 h-11 rounded-full p-[2px] bg-gradient-to-tr from-blue-600 via-crypto-glow to-indigo-500 shadow-[0_0_15px_rgba(0,240,255,0.3)]">
          <img id="user-photo" src="https://via.placeholder.com/150/0a0b1a/00f0ff?text=PP" alt="Profile" class="w-full h-full rounded-full object-cover border-2 border-[#050511]">
        </div>
        <div class="flex flex-col">
          <span id="user-name" class="font-bold text-white text-sm tracking-wide">Loading...</span>
          <div class="flex items-center gap-1">
            <span class="text-[10px] text-slate-400 uppercase font-bold tracking-wider" id="user-tg-id">ID: 0</span>
          </div>
        </div>
      </div>
      <div class="flex flex-col items-end gap-1.5">
        <div class="glass-button px-3 py-1.5 rounded-xl flex items-center gap-2">
          <i class="fa-solid fa-bolt text-crypto-glow text-xs"></i>
          <span id="user-xp" class="text-white font-black text-sm tracking-wider">0 <span class="text-[10px] text-crypto-glow">XP</span></span>
        </div>
        <div class="bg-emerald-900/40 border border-emerald-500/30 px-3 py-1 rounded-xl flex items-center gap-2 shadow-[0_0_10px_rgba(16,185,129,0.1)inset]">
          <i class="fa-solid fa-dollar-sign text-emerald-400 text-[10px]"></i>
          <span id="user-usd" class="text-emerald-400 font-black text-xs tracking-wider">0.00</span>
        </div>
      </div>
    </div>
  </header>

  <!-- MAIN CONTENT -->
  <main class="flex-1 max-w-md w-full mx-auto p-4 pt-24 relative" id="app-content">
    
    <!-- HOME TAB -->
    <div id="view-home" class="view-section fade-in space-y-6">
      <div class="relative glass-card rounded-[2rem] p-6 text-center border-t border-t-blue-400/20 overflow-hidden flex flex-col items-center justify-center min-h-[240px]">
        <div class="relative z-10 flex flex-col items-center">
          <div class="w-14 h-14 rounded-full bg-blue-900/40 border border-blue-400/30 flex items-center justify-center mb-3 shadow-[0_0_20px_rgba(59,130,246,0.3)]">
             <i class="fa-solid fa-gem text-2xl text-crypto-glow drop-shadow-[0_0_10px_rgba(0,240,255,0.8)]"></i>
          </div>
          <p class="text-[10px] font-black text-blue-400 uppercase tracking-[0.3em] mb-1 opacity-80">Total Balance</p>
          <h1 class="text-5xl font-black text-transparent bg-clip-text bg-gradient-to-b from-white via-cyan-100 to-blue-400 tracking-tighter drop-shadow-2xl" id="main-xp-display">0 XP</h1>
        </div>
        <div class="w-full mt-6 grid grid-cols-2 gap-3">
          <div class="bg-[#050511]/50 border border-slate-700/50 p-3 rounded-2xl flex items-center gap-3">
            <div class="bg-blue-500/10 p-2.5 rounded-xl border border-blue-500/20"><i class="fa-solid fa-clapperboard text-blue-400"></i></div>
            <div class="text-left">
              <p class="text-[9px] text-slate-500 uppercase font-bold tracking-wider">Total Ads</p>
              <p class="text-sm font-black text-white"><span id="total-ads-watched" class="text-blue-400">0</span></p>
            </div>
          </div>
          <div class="bg-[#050511]/50 border border-slate-700/50 p-3 rounded-2xl flex items-center gap-3">
            <div class="bg-amber-500/10 p-2.5 rounded-xl border border-amber-500/20"><i class="fa-solid fa-fire-flame-curved text-amber-400"></i></div>
            <div class="text-left">
              <p class="text-[9px] text-slate-500 uppercase font-bold tracking-wider">Streak</p>
              <p class="text-sm font-black text-white"><span id="streak-days" class="text-amber-400">0</span> Days</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Watch Ad Button -->
      <button onclick="watchAd()" id="watch-ad-btn" class="w-full py-4 rounded-2xl text-white font-black text-sm tracking-[0.15em] uppercase flex items-center justify-center gap-3 bg-gradient-to-b from-blue-600 to-blue-800 shadow-[0_10px_30px_rgba(59,130,246,0.3)] relative overflow-hidden active:scale-95 transition-all">
        <i class="fa-solid fa-play bg-white/20 p-2 rounded-full text-[10px]"></i> 
        <span>Watch Ad <span class="text-cyan-200">+20 XP</span></span>
      </button>

      <!-- Daily Login Section -->
      <div class="glass-card rounded-xl p-4 border border-blue-500/30">
        <div class="flex justify-between items-center mb-3">
          <h3 class="text-xs font-black text-white uppercase tracking-widest"><i class="fa-solid fa-calendar-check text-blue-400 mr-2"></i> Daily Login Reward</h3>
        </div>
        <button onclick="claimDailyLogin()" id="btn-daily-claim" class="w-full py-2.5 rounded-xl text-xs font-black uppercase bg-slate-800 text-white border border-slate-600 active:scale-95 transition-all">Claim Daily XP</button>
      </div>
    </div>

    <!-- TASKS TAB -->
    <div id="view-tasks" class="view-section hidden fade-in space-y-6 pb-4">
      <div class="text-center pt-2">
        <h2 class="text-3xl font-black text-white tracking-tight drop-shadow-lg">Tasks</h2>
        <p class="text-xs text-crypto-glow mt-1 uppercase tracking-widest font-bold">Complete to Earn XP</p>
      </div>

      <!-- AZX Crypto Sponsor Task -->
      <div class="glass-card rounded-2xl p-4 border-2 border-crypto-glow shadow-[0_0_15px_rgba(0,240,255,0.2)]">
        <!-- Strictly Visual Image (Not Clickable) -->
        <div class="w-full h-32 rounded-xl mb-4 overflow-hidden bg-slate-900 border border-slate-700 pointer-events-none">
          <img src="https://i.postimg.cc/rsz7NnZp/IMG-20260903-114036-951.jpg" class="w-full h-full object-cover">
        </div>
        
        <div class="flex justify-between items-center">
          <div>
            <h3 class="text-sm font-black text-white tracking-wide">AZX Crypto Sponsor</h3>
            <p class="text-[10px] text-crypto-glow font-bold mt-1 uppercase">+200 XP</p>
          </div>
          <button id="btn-azx-task" onclick="completeAZXTask()" class="text-[10px] font-black bg-gradient-to-r from-blue-600 to-cyan-500 text-white px-4 py-2 rounded-xl active:scale-95 transition-all uppercase tracking-wider">Join Channel</button>
        </div>
      </div>

      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-4 mb-3">Daily Missions</h3>
        <div id="missions-container" class="space-y-3">
            <!-- Populated dynamically via JS -->
        </div>
      </div>
    </div>

    <!-- REFERRALS TAB -->
    <div id="view-referrals" class="view-section hidden fade-in space-y-6 pb-4">
      <div class="text-center pt-2">
        <h2 class="text-3xl font-black text-white tracking-tight drop-shadow-lg">Referrals</h2>
        <p class="text-xs text-crypto-glow mt-1 uppercase tracking-widest font-bold">Invite Friends, Earn Rewards</p>
      </div>

      <div class="grid grid-cols-3 gap-2">
        <div class="glass-card p-3 rounded-2xl text-center border-t-2 border-t-blue-500/30">
          <p class="text-[9px] text-slate-400 uppercase font-black mb-1">Total</p>
          <p id="ref-total" class="text-lg font-black text-white">0</p>
        </div>
        <div class="glass-card p-3 rounded-2xl text-center border-t-2 border-t-amber-500/30">
          <p class="text-[9px] text-slate-400 uppercase font-black mb-1">Pending</p>
          <p id="ref-pending" class="text-lg font-black text-amber-400">0</p>
        </div>
        <div class="glass-card p-3 rounded-2xl text-center border-t-2 border-t-emerald-500/30">
          <p class="text-[9px] text-slate-400 uppercase font-black mb-1">Approved</p>
          <p id="ref-approved" class="text-lg font-black text-emerald-400">0</p>
        </div>
      </div>

      <div class="glass-card rounded-2xl p-4">
        <div class="flex justify-between items-center mb-2">
          <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Your Referral Link</p>
          <button onclick="openInfoModal()" class="text-blue-400"><i class="fa-solid fa-circle-info"></i></button>
        </div>
        <div class="flex bg-[#050511]/50 border border-slate-700/50 rounded-xl overflow-hidden mb-3">
          <input type="text" id="ref-link-input" readonly class="w-full bg-transparent py-2 px-3 text-[10px] text-slate-300 outline-none font-mono">
          <button onclick="copyRefLink()" class="px-4 bg-blue-600/20 text-blue-400 border-l border-slate-700/50 hover:bg-blue-600/30 transition-colors"><i class="fa-regular fa-copy"></i></button>
        </div>
        <button onclick="shareRefLink()" class="w-full py-2.5 rounded-xl text-xs font-black uppercase bg-gradient-to-r from-blue-600 to-indigo-600 text-white shadow-lg active:scale-95 transition-all flex items-center justify-center gap-2">
          <i class="fa-solid fa-share-nodes"></i> Share with Friends
        </button>
      </div>

      <!-- Referral Progress List -->
      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-4 mb-3">My Referrals</h3>
        <div id="referrals-list" class="space-y-3">
          <p class="text-xs text-slate-500 text-center py-4">No referrals yet.</p>
        </div>
      </div>

      <!-- Referral Rewards History -->
      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-4 mb-3">Reward History</h3>
        <div id="referral-rewards-list" class="space-y-3">
          <p class="text-xs text-slate-500 text-center py-4">No rewards yet.</p>
        </div>
      </div>
    </div>

    <!-- WALLET TAB -->
    <div id="view-withdraw" class="view-section hidden fade-in space-y-6">
      <div class="glass-card rounded-[2rem] p-6 text-center border-t-2 border-emerald-500/40 bg-gradient-to-b from-emerald-900/30 to-[#050511]">
        <div class="w-14 h-14 mx-auto bg-emerald-500/10 rounded-full flex items-center justify-center mb-3 border border-emerald-500/30 shadow-[0_0_20px_rgba(16,185,129,0.2)]">
          <i class="fa-solid fa-wallet text-xl text-emerald-400"></i>
        </div>
        <p class="text-[10px] font-black text-emerald-400 uppercase tracking-[0.2em] mb-1 opacity-80">USD Balance</p>
        <h1 class="text-5xl font-black text-white tracking-tighter mb-3">$<span id="withdraw-balance-display">0.00</span></h1>
        <div class="inline-block bg-[#050511]/80 backdrop-blur-md px-3 py-1.5 rounded-full border border-slate-700/50">
          <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest"><i class="fa-solid fa-circle-info text-blue-400 mr-1"></i> Minimum Withdrawal: $10.00</p>
        </div>
      </div>

      <div class="glass-card rounded-[1.5rem] p-5 space-y-4 border-slate-800">
        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase tracking-[0.15em] mb-2 ml-1">TON Wallet Address</label>
          <div class="relative group">
            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
              <i class="fa-solid fa-wallet text-slate-500"></i>
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
            <input type="number" id="withdraw-amount" placeholder="10.00" min="10" step="1" class="w-full bg-[#050511]/50 border-2 border-slate-700/50 rounded-xl py-3.5 pl-12 pr-4 text-sm font-bold text-emerald-400 focus:outline-none focus:border-emerald-500 transition-colors placeholder-slate-600 shadow-inner">
          </div>
        </div>

        <button onclick="requestWithdrawal()" id="withdraw-btn" class="w-full py-3.5 mt-2 bg-gradient-to-r from-emerald-600 to-teal-500 active:scale-95 transition-all text-white font-black rounded-xl text-sm uppercase tracking-[0.15em] flex items-center justify-center gap-2 shadow-[0_10px_20px_rgba(16,185,129,0.3)]">
          <i class="fa-solid fa-money-bill-transfer"></i> Request Withdrawal
        </button>
      </div>

      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-4 mb-3">Withdrawal History</h3>
        <div id="withdraw-history-container" class="space-y-3">
           <p class="text-xs text-slate-500 text-center py-4">History is Empty</p>
        </div>
      </div>
    </div>

  </main>

  <!-- BOTTOM NAVIGATION -->
  <nav id="bottom-nav" class="fixed bottom-6 left-1/2 -translate-x-1/2 w-[calc(100%-1rem)] max-w-[420px] glass-card rounded-2xl pb-safe z-50 shadow-[0_20px_40px_rgba(0,0,0,0.8)] border border-slate-700/50 backdrop-blur-xl">
    <div class="flex justify-between items-center px-4 py-2.5 relative">
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

  <!-- INFO MODAL (Referrals) -->
  <div id="info-modal" class="modal-overlay">
    <div class="glass-card w-[90%] max-w-sm rounded-2xl p-6 relative">
      <h3 class="text-lg font-black text-white mb-4"><i class="fa-solid fa-circle-info text-blue-400 mr-2"></i> Referral Rules</h3>
      <ul class="text-sm text-slate-300 space-y-2 mb-6 list-disc pl-4">
        <li>A referred user must watch <strong>25 valid ads</strong>.</li>
        <li>A referred user must complete <strong>5 tasks</strong>.</li>
        <li>There is no deadline to complete these conditions.</li>
        <li>The referral remains pending until both conditions are met.</li>
        <li>Upon approval, you receive exactly <strong class="text-crypto-glow">250 XP + $0.025 USD</strong>.</li>
      </ul>
      <button onclick="closeInfoModal()" class="w-full py-2.5 rounded-xl text-sm font-black uppercase bg-slate-700 text-white active:scale-95 transition-all">Understood</button>
    </div>
  </div>

  <script>
    const tg = window.Telegram.WebApp;
    tg.expand();
    tg.ready();
    tg.setHeaderColor('#0a0b1a');
    tg.setBackgroundColor('#050511');

    let currentUser = null;
    let isRequestActive = false;

    // Initialization
    async function initApp() {
        let tgData = tg.initDataUnsafe && tg.initDataUnsafe.user ? tg.initDataUnsafe.user : null;
        let startApp = tg.initDataUnsafe && tg.initDataUnsafe.start_param ? tg.initDataUnsafe.start_param : '';
        
        // Fallback for local testing if outside Telegram
        if (!tgData) {
            tgData = { id: '999999999', first_name: 'Test', username: 'tester' };
        }

        try {
            const res = await apiRequest('init', { tg_data: tgData, startapp: startApp });
            if (res && res.success) {
                currentUser = res.user;
                if(tgData.photo_url) document.getElementById('user-photo').src = tgData.photo_url;
                updateUI();
                document.getElementById('loading-overlay').style.display = 'none';
                
                // Fetch referral list async
                fetchReferralsList();
            } else {
                showToast("Error", "Authentication failed. Please reopen the Mini App.", "error");
            }
        } catch (e) {
            showToast("Network Error", "Could not connect to server.", "error");
        }
    }

    // Server Communication Logic
    async function apiRequest(action, payload = {}) {
        const response = await fetch(`?api=1&action=${action}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        return await response.json();
    }

    function updateUI() {
        if (!currentUser) return;

        // Header Updates
        document.getElementById('user-name').innerText = currentUser.first_name;
        document.getElementById('user-tg-id').innerText = `ID: ${currentUser.telegram_id}`;
        document.getElementById('user-xp').innerHTML = `${currentUser.xp.toLocaleString()} <span class="text-[10px] text-crypto-glow font-bold">XP</span>`;
        document.getElementById('user-usd').innerText = currentUser.usd_balance.toFixed(4);

        // Home Updates
        document.getElementById('main-xp-display').innerText = `${currentUser.xp.toLocaleString()} XP`;
        document.getElementById('total-ads-watched').innerText = currentUser.total_ads.toLocaleString();
        document.getElementById('streak-days').innerText = currentUser.daily_login.streak;

        // Daily Login Button state
        const btnClaim = document.getElementById('btn-daily-claim');
        const today = new Date().toISOString().split('T')[0];
        if (currentUser.daily_login.last_claim === today) {
            btnClaim.innerText = "Claimed Today";
            btnClaim.classList.add('opacity-50', 'pointer-events-none');
            btnClaim.classList.replace('bg-slate-800', 'bg-emerald-900');
        } else {
            btnClaim.innerText = "Claim Daily XP";
            btnClaim.classList.remove('opacity-50', 'pointer-events-none');
            btnClaim.classList.replace('bg-emerald-900', 'bg-slate-800');
        }

        // Tasks Updates
        renderTasks();

        // Referrals Updates
        document.getElementById('ref-link-input').value = `https://t.me/pointplayappbot?startapp=${currentUser.telegram_id}`;
        document.getElementById('ref-total').innerText = currentUser.referrals;
        document.getElementById('ref-pending').innerText = currentUser.pending_referrals;
        document.getElementById('ref-approved').innerText = currentUser.approved_referrals;
        renderReferralRewards(currentUser.referral_rewards);

        // Wallet Updates
        document.getElementById('withdraw-balance-display').innerText = currentUser.usd_balance.toFixed(2);
        renderWithdrawHistory(currentUser.withdrawals);
    }

    // Ad System (Validated via server)
    async function watchAd() {
        if (isRequestActive) return;
        
        const btn = document.getElementById('watch-ad-btn');
        const originalHTML = btn.innerHTML;
        btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> <span>Loading Ad...</span>`;
        btn.classList.add('opacity-80', 'pointer-events-none');

        if (window.Adsgram) {
            const AdController = window.Adsgram.init({ blockId: "int-35545" });
            AdController.show().then(async (result) => {
                isRequestActive = true;
                const res = await apiRequest('watch_ad', { tg_data: { id: currentUser.telegram_id } });
                isRequestActive = false;
                
                if (res.success) {
                    currentUser = res.user;
                    updateUI();
                    showToast("Reward Granted!", "You earned +20 XP.", "success");
                }
                resetAdBtn(btn, originalHTML);
            }).catch((error) => {
                resetAdBtn(btn, originalHTML);
            });
        } else {
            showToast("Error", "Ad system is currently unavailable.", "error");
            resetAdBtn(btn, originalHTML);
        }
    }
    
    function resetAdBtn(btn, html) {
        btn.innerHTML = html;
        btn.classList.remove('opacity-80', 'pointer-events-none');
    }

    // Daily Claim
    async function claimDailyLogin() {
        if (isRequestActive) return;
        isRequestActive = true;
        const res = await apiRequest('claim_daily', { tg_data: { id: currentUser.telegram_id } });
        isRequestActive = false;

        if (res.success) {
            currentUser = res.user;
            updateUI();
            showToast("Daily Claimed", `You received +${res.reward} XP for your streak!`, "success");
        }
    }

    // Tasks Implementation
    function renderTasks() {
        // 1. AZX Crypto Task
        const azxBtn = document.getElementById('btn-azx-task');
        if (currentUser.azx_crypto_completed) {
            azxBtn.innerText = "Completed";
            azxBtn.classList.replace('from-blue-600', 'from-emerald-600');
            azxBtn.classList.replace('to-cyan-500', 'to-emerald-500');
            azxBtn.classList.add('pointer-events-none');
            azxBtn.onclick = null;
        }

        // 2. Daily Missions
        const container = document.getElementById('missions-container');
        container.innerHTML = '';
        
        const missions = [
            { id: 'watch5', label: 'Watch 5 Ads', current: currentUser.daily_ads, target: 5, xp: 20, completed: currentUser.tasks.watch5 },
            { id: 'watch30', label: 'Watch 30 Ads', current: currentUser.daily_ads, target: 30, xp: 50, completed: currentUser.tasks.watch30 }
        ];

        missions.forEach(m => {
            let btnHtml = '';
            if (m.completed) {
                btnHtml = `<span class="text-[10px] font-black bg-emerald-500/10 text-emerald-400 px-3 py-1.5 rounded-xl border border-emerald-500/30"><i class="fa-solid fa-check-double"></i> Claimed</span>`;
            } else if (m.current >= m.target) {
                btnHtml = `<button onclick="completeTask('${m.id}')" class="text-[10px] font-black bg-gradient-to-r from-blue-600 to-cyan-500 text-white px-4 py-1.5 rounded-xl shadow-[0_0_15px_rgba(0,240,255,0.4)] active:scale-95 transition-all">Claim</button>`;
            } else {
                btnHtml = `<span class="text-[10px] font-black bg-slate-800/50 text-slate-200 px-3 py-1.5 rounded-xl border border-slate-600">+${m.xp} XP</span>`;
            }

            container.innerHTML += `
              <div class="glass-card rounded-2xl p-3 flex justify-between items-center border border-slate-800">
                <div class="flex items-center gap-3">
                  <div class="w-10 h-10 rounded-xl border bg-blue-500/10 border-blue-500/20 flex items-center justify-center">
                     <i class="fa-solid fa-video text-blue-400 text-lg"></i>
                  </div>
                  <div class="flex flex-col">
                    <span class="text-xs font-black text-white tracking-wide">${m.label}</span>
                    <span class="text-crypto-glow text-[10px] font-bold tracking-widest uppercase opacity-80 mt-0.5">(${Math.min(m.current, m.target)}/${m.target})</span>
                  </div>
                </div>
                ${btnHtml}
              </div>
            `;
        });
    }

    async function completeTask(taskId) {
        if (isRequestActive) return;
        isRequestActive = true;
        const res = await apiRequest('complete_task', { tg_data: { id: currentUser.telegram_id }, task_id: taskId });
        isRequestActive = false;

        if (res.success) {
            currentUser = res.user;
            updateUI();
            showToast("Task Completed", "Reward added to your balance.", "success");
        }
    }

    function completeAZXTask() {
        // Open the telegram link strictly through button action
        tg.openTelegramLink('https://t.me/azxcrypto');
        setTimeout(() => { completeTask('azx_crypto'); }, 2000);
    }

    // Referrals logic
    function copyRefLink() {
        const input = document.getElementById('ref-link-input');
        input.select();
        document.execCommand("copy");
        showToast("Copied!", "Referral link copied to clipboard.", "success");
    }

    function shareRefLink() {
        const link = document.getElementById('ref-link-input').value;
        const text = "🎯 Play games, complete tasks, earn XP, and collect exciting rewards 🚀 I’m already playing on Point Play now it’s your turn to join the adventure👇";
        const shareUrl = `https://t.me/share/url?url=${encodeURIComponent(link)}&text=${encodeURIComponent(text)}`;
        tg.openTelegramLink(shareUrl);
    }

    async function fetchReferralsList() {
        const res = await apiRequest('get_referrals', { tg_data: { id: currentUser.telegram_id } });
        if (res.success) renderReferralsList(res.list);
    }

    function renderReferralsList(list) {
        const container = document.getElementById('referrals-list');
        if (!list || list.length === 0) {
            container.innerHTML = '<p class="text-xs text-slate-500 text-center py-4">No referrals yet.</p>';
            return;
        }

        container.innerHTML = '';
        list.forEach(ref => {
            const statusColor = ref.status === 'approved' ? 'text-emerald-400' : 'text-amber-400';
            const nameDisplay = ref.username ? `@${ref.username}` : ref.name;
            container.innerHTML += `
              <div class="glass-card rounded-2xl p-3 flex flex-col gap-2 border border-slate-800">
                <div class="flex justify-between items-center">
                    <span class="text-xs font-black text-white">${ref.name} <span class="text-[9px] text-slate-400 font-normal ml-1">${nameDisplay}</span></span>
                    <span class="text-[9px] font-black uppercase tracking-widest ${statusColor}">${ref.status}</span>
                </div>
                <div class="flex gap-4 text-[10px] text-slate-400 font-bold">
                    <span>Ads: <span class="text-white">${ref.ads}/25</span></span>
                    <span>Tasks: <span class="text-white">${ref.tasks}/5</span></span>
                </div>
              </div>
            `;
        });
    }

    function renderReferralRewards(rewards) {
        const container = document.getElementById('referral-rewards-list');
        if (!rewards || rewards.length === 0) {
            container.innerHTML = '<p class="text-xs text-slate-500 text-center py-4">No rewards yet.</p>';
            return;
        }

        container.innerHTML = '';
        [...rewards].reverse().forEach(reward => {
            container.innerHTML += `
              <div class="glass-card rounded-2xl p-3 flex justify-between items-center border border-slate-800">
                <div>
                  <p class="text-[10px] font-black text-white">Referral Bonus</p>
                  <p class="text-[9px] text-slate-400 mt-1">Referral: ${reward.name} | ${reward.date.split(' ')[0]}</p>
                </div>
                <div class="text-right">
                  <p class="text-xs font-black text-crypto-glow">+${reward.xp} XP</p>
                  <p class="text-[10px] font-black text-emerald-400">+$${reward.usd.toFixed(3)}</p>
                </div>
              </div>
            `;
        });
    }

    // Modal
    function openInfoModal() { document.getElementById('info-modal').classList.add('modal-active'); }
    function closeInfoModal() { document.getElementById('info-modal').classList.remove('modal-active'); }

    // Withdrawal Logic
    async function requestWithdrawal() {
        if (isRequestActive) return;

        const address = document.getElementById('wallet-address').value;
        const amount = parseFloat(document.getElementById('withdraw-amount').value);

        if (!address || address.length < 5) {
            showToast("Invalid Address", "Please enter a valid TON wallet address.", "error");
            return;
        }
        if (isNaN(amount) || amount < 10) {
            showToast("Invalid Amount", "Minimum withdrawal is $10.00.", "error");
            return;
        }
        if (amount > currentUser.usd_balance) {
            showToast("Insufficient Balance", "You do not have enough USD.", "error");
            return;
        }

        isRequestActive = true;
        const res = await apiRequest('withdraw', { tg_data: { id: currentUser.telegram_id }, address: address, amount: amount });
        isRequestActive = false;

        if (res.success) {
            currentUser = res.user;
            updateUI();
            document.getElementById('wallet-address').value = '';
            document.getElementById('withdraw-amount').value = '10.00';
            showToast("Success", `Withdrawal request for $${amount.toFixed(2)} submitted.`, "success");
        } else {
            showToast("Error", res.error || "Failed to process withdrawal.", "error");
        }
    }

    function renderWithdrawHistory(history) {
        const container = document.getElementById('withdraw-history-container');
        if (!history || history.length === 0) {
            container.innerHTML = `
              <div class="glass-card rounded-2xl p-6 text-center border-dashed border-2 border-slate-700">
                <i class="fa-solid fa-clock-rotate-left text-3xl text-slate-600 mb-2"></i>
                <p class="text-xs font-bold text-slate-500 uppercase tracking-widest">History is Empty</p>
              </div>`;
            return;
        }

        container.innerHTML = '';
        [...history].reverse().forEach(record => {
            container.innerHTML += `
              <div class="glass-card rounded-2xl p-4 flex justify-between items-center">
                <div class="flex items-center gap-3">
                  <div class="w-10 h-10 rounded-full bg-slate-800 border border-slate-700 flex items-center justify-center">
                     <i class="fa-solid fa-arrow-right-arrow-left text-slate-400"></i>
                  </div>
                  <div>
                    <p class="text-xs font-black text-white">${record.id} <span class="text-[9px] text-slate-400 ml-1 font-bold">${record.date.split(' ')[0]}</span></p>
                    <p class="text-[10px] text-blue-400 mt-0.5 font-mono bg-blue-500/10 inline-block px-1.5 py-0.5 rounded">${record.address.substring(0,6) + '...' + record.address.substring(record.address.length - 4)}</p>
                  </div>
                </div>
                <div class="text-right">
                  <p class="text-sm font-black text-emerald-400">-$${record.amount.toFixed(2)}</p>
                  <p class="text-[9px] font-black text-amber-400 uppercase tracking-widest mt-0.5">${record.status}</p>
                </div>
              </div>
            `;
        });
    }

    // UI Tab Navigation
    function switchTab(tabId) {
        document.querySelectorAll('.view-section').forEach(el => el.classList.add('hidden'));
        document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('nav-active'));

        document.getElementById(`view-${tabId}`).classList.remove('hidden');
        document.querySelector(`[data-target="${tabId}"]`).classList.add('nav-active');
        
        if (tg.HapticFeedback) tg.HapticFeedback.selectionChanged();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // Start App Flow
    window.addEventListener('DOMContentLoaded', initApp);
  </script>
</body>
</html>
