<?php
/**
 * POINT PLAY - Earning & Ad Platform
 * Architecture: Users -> Ads -> Tasks -> Referrals -> XP -> USD Balance -> Wallet
 * Storage: JSON Flat-file with locking
 */

define('BOT_TOKEN', 'YOUR_BOT_TOKEN_HERE'); // Set your actual Bot Token here for strict validation
define('DATA_DIR', __DIR__ . '/data');
define('USERS_DIR', DATA_DIR . '/users');

// 1. Storage Initialization
if (!file_exists(DATA_DIR)) mkdir(DATA_DIR, 0777, true);
if (!file_exists(USERS_DIR)) mkdir(USERS_DIR, 0777, true);

// 2. Core Functions
function get_user_file($tg_id) {
    return USERS_DIR . '/' . preg_replace('/[^0-9]/', '', $tg_id) . '.json';
}

function lock_and_read($file) {
    if (!file_exists($file)) return null;
    $fp = fopen($file, 'r');
    if (flock($fp, LOCK_SH)) {
        $size = filesize($file);
        $data = $size > 0 ? fread($fp, $size) : null;
        flock($fp, LOCK_UN);
        fclose($fp);
        return $data ? json_decode($data, true) : null;
    }
    fclose($fp);
    return null;
}

function lock_and_write($file, $data) {
    $fp = fopen($file, 'c');
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }
    fclose($fp);
    return false;
}

function validate_telegram_data($init_data) {
    // Basic structural parse
    parse_str($init_data, $parsed_data);
    if (!isset($parsed_data['hash'])) return false;
    
    // Strict Hash Validation (if BOT_TOKEN is set)
    if (BOT_TOKEN !== 'YOUR_BOT_TOKEN_HERE' && !empty(BOT_TOKEN)) {
        $hash = $parsed_data['hash'];
        unset($parsed_data['hash']);
        ksort($parsed_data);
        $data_check_arr = [];
        foreach ($parsed_data as $key => $value) {
            $data_check_arr[] = $key . '=' . $value;
        }
        $data_check_string = implode("\n", $data_check_arr);
        $secret_key = hash_hmac('sha256', BOT_TOKEN, 'WebAppData', true);
        $calculated_hash = bin2hex(hash_hmac('sha256', $data_check_string, $secret_key, true));
        if (!hash_equals($calculated_hash, $hash)) return false;
    }

    return isset($parsed_data['user']) ? json_decode($parsed_data['user'], true) : false;
}

function create_user_template($tg_user, $referrer_id = null) {
    return [
        'telegram_id' => (string)$tg_user['id'],
        'username' => $tg_user['username'] ?? '',
        'first_name' => $tg_user['first_name'] ?? 'User',
        'last_name' => $tg_user['last_name'] ?? '',
        'created_at' => date('Y-m-d H:i:s'),
        'last_active_at' => date('Y-m-d H:i:s'),
        'xp' => 0,
        'usd_balance' => 0.00,
        'total_ads' => 0,
        'daily_ads' => 0,
        'last_ad_date' => '',
        'completed_tasks' => 0,
        'task_ids' => [],
        'daily_login_date' => '',
        'azx_crypto_completed' => false,
        'referrer_id' => $referrer_id,
        'referrals' => [], // List of referred users
        'pending_referrals' => 0,
        'approved_referrals' => 0,
        'referral_rewards' => [],
        'withdrawals' => []
    ];
}

function update_referrer_progress($referrer_id, $child_id, $child_name, $child_username, $ad_count, $task_count) {
    if (!$referrer_id) return;
    $ref_file = get_user_file($referrer_id);
    $ref_data = lock_and_read($ref_file);
    if (!$ref_data) return;

    $found = false;
    foreach ($ref_data['referrals'] as &$ref) {
        if ($ref['uid'] === $child_id) {
            $found = true;
            $ref['ad_progress'] = $ad_count;
            $ref['task_progress'] = $task_count;
            $ref['name'] = $child_name;
            $ref['username'] = $child_username;

            // Check Approval Condition (25 Ads AND 5 Tasks)
            if ($ref['status'] === 'pending' && $ad_count >= 25 && $task_count >= 5) {
                $ref['status'] = 'approved';
                $ref_data['pending_referrals'] = max(0, $ref_data['pending_referrals'] - 1);
                $ref_data['approved_referrals']++;
                
                // Reward Referrer
                $ref_data['xp'] += 250;
                $ref_data['usd_balance'] += 0.025;
                
                array_unshift($ref_data['referral_rewards'], [
                    'date' => date('Y-m-d H:i:s'),
                    'child_name' => $child_name,
                    'xp' => 250,
                    'usd' => 0.025
                ]);
            }
            break;
        }
    }

    // Register missing referral mapping safely
    if (!$found) {
        $ref_data['referrals'][] = [
            'uid' => $child_id,
            'name' => $child_name,
            'username' => $child_username,
            'status' => 'pending',
            'ad_progress' => $ad_count,
            'task_progress' => $task_count,
            'date' => date('Y-m-d')
        ];
        $ref_data['pending_referrals']++;
    }

    lock_and_write($ref_file, $ref_data);
}

// 3. API Logic (Server-Side Endpoints)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['api'])) {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['initData'])) {
        echo json_encode(['success' => false, 'error' => 'No Auth Data']);
        exit;
    }

    $tg_user = validate_telegram_data($input['initData']);
    if (!$tg_user) {
        echo json_encode(['success' => false, 'error' => 'Invalid Telegram Identity']);
        exit;
    }

    $tg_id = (string)$tg_user['id'];
    $user_file = get_user_file($tg_id);
    $action = $_GET['api'];

    // Global file lock for user updates
    $fp = fopen($user_file, 'c+');
    if (flock($fp, LOCK_EX)) {
        $size = filesize($user_file);
        $user = $size > 0 ? json_decode(fread($fp, $size), true) : null;
        $today = date('Y-m-d');

        if (!$user) {
            // Check for valid referrer
            $referrer_id = null;
            if (isset($input['start_param']) && !empty($input['start_param'])) {
                $pot_ref = preg_replace('/[^0-9]/', '', $input['start_param']);
                if ($pot_ref !== $tg_id && file_exists(get_user_file($pot_ref))) {
                    $referrer_id = $pot_ref;
                }
            }
            $user = create_user_template($tg_user, $referrer_id);
            if ($referrer_id) {
                update_referrer_progress($referrer_id, $tg_id, $user['first_name'], $user['username'], 0, 0);
            }
        }

        // Reset daily stats
        if ($user['last_ad_date'] !== $today) {
            $user['daily_ads'] = 0;
            $user['last_ad_date'] = $today;
        }

        $user['last_active_at'] = date('Y-m-d H:i:s');
        $user['username'] = $tg_user['username'] ?? '';
        $user['first_name'] = $tg_user['first_name'] ?? 'User';
        $user['last_name'] = $tg_user['last_name'] ?? '';

        // Routing
        if ($action === 'init') {
            // Just returns the loaded user
        } 
        elseif ($action === 'watch_ad') {
            $user['xp'] += 20;
            $user['total_ads'] += 1;
            $user['daily_ads'] += 1;
            update_referrer_progress($user['referrer_id'], $tg_id, $user['first_name'], $user['username'], $user['total_ads'], $user['completed_tasks']);
        } 
        elseif ($action === 'claim_daily') {
            if ($user['daily_login_date'] !== $today) {
                $user['xp'] += 50;
                $user['daily_login_date'] = $today;
            } else {
                echo json_encode(['success' => false, 'error' => 'Already claimed today']);
                flock($fp, LOCK_UN); fclose($fp); exit;
            }
        } 
        elseif ($action === 'complete_task') {
            $task_id = $input['task_id'] ?? '';
            $tasks_config = [
                'watch_5' => ['target' => 5, 'xp' => 100],
                'watch_30' => ['target' => 30, 'xp' => 300]
            ];

            if (isset($tasks_config[$task_id]) && !in_array($task_id, $user['task_ids'])) {
                if ($user['daily_ads'] >= $tasks_config[$task_id]['target']) {
                    $user['xp'] += $tasks_config[$task_id]['xp'];
                    $user['task_ids'][] = $task_id;
                    $user['completed_tasks'] += 1;
                    update_referrer_progress($user['referrer_id'], $tg_id, $user['first_name'], $user['username'], $user['total_ads'], $user['completed_tasks']);
                }
            }
        } 
        elseif ($action === 'azx_crypto') {
            if ($user['azx_crypto_completed'] === false) {
                $user['xp'] += 200;
                $user['azx_crypto_completed'] = true;
                $user['completed_tasks'] += 1;
                update_referrer_progress($user['referrer_id'], $tg_id, $user['first_name'], $user['username'], $user['total_ads'], $user['completed_tasks']);
            }
        }
        elseif ($action === 'withdraw') {
            $amount = floatval($input['amount'] ?? 0);
            $address = trim(strip_tags($input['address'] ?? ''));
            if ($amount >= 10 && $amount <= $user['usd_balance'] && !empty($address)) {
                $user['usd_balance'] -= $amount;
                array_unshift($user['withdrawals'], [
                    'id' => strtoupper(uniqid('WD-')),
                    'date' => date('Y-m-d H:i'),
                    'amount' => $amount,
                    'address' => $address,
                    'status' => 'Pending'
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid withdrawal request']);
                flock($fp, LOCK_UN); fclose($fp); exit;
            }
        }

        // Save State
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($user, JSON_PRETTY_PRINT));
        flock($fp, LOCK_UN);
        fclose($fp);

        echo json_encode(['success' => true, 'user' => $user]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Resource busy']);
        fclose($fp);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>Point Play</title>
  <script src="https://telegram.org/js/telegram-web-app.js"></script>
  <script src="https://sad.adsgram.ai/js/sad.min.js"></script>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800;900&display=swap" rel="stylesheet">
  
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { sans: ['Outfit', 'sans-serif'] },
          colors: { app: { dark: '#050511', card: '#0a0b1a', primary: '#3b82f6', glow: '#00f0ff' } }
        }
      }
    }
  </script>

  <style>
    body { background-color: #050511; color: #f8fafc; -webkit-tap-highlight-color: transparent; }
    ::-webkit-scrollbar { display: none; }
    
    .glass-card {
      background: linear-gradient(145deg, rgba(20, 22, 45, 0.6) 0%, rgba(10, 11, 26, 0.8) 100%);
      backdrop-filter: blur(16px);
      border: 1px solid rgba(255, 255, 255, 0.05);
      box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.3);
    }
    .fade-in { animation: fadeIn 0.3s ease-out forwards; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    
    .nav-active { color: #00f0ff !important; transform: translateY(-2px); }
    .nav-active i { filter: drop-shadow(0 0 8px rgba(0, 240, 255, 0.6)); }

    #toast-container {
      position: fixed; top: 1.5rem; left: 50%; transform: translate(-50%, -150%) scale(0.9);
      width: 90%; max-width: 380px; z-index: 999999; transition: all 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55);
      opacity: 0; pointer-events: none;
    }
    .toast-show { transform: translate(-50%, 0) scale(1) !important; opacity: 1 !important; }
  </style>
</head>
<body class="flex flex-col min-h-screen pb-32">

  <!-- Toast Notification -->
  <div id="toast-container" class="glass-card rounded-2xl p-4 flex items-center gap-4 border border-slate-700">
    <div id="toast-icon" class="w-12 h-12 rounded-xl flex shrink-0 items-center justify-center text-xl bg-blue-500/20 text-app-glow">
      <i class="fa-solid fa-bell"></i>
    </div>
    <div class="flex-1">
      <h4 id="toast-title" class="text-sm font-black text-white tracking-wide">Notification</h4>
      <p id="toast-message" class="text-xs text-slate-300 mt-0.5 leading-tight">Message</p>
    </div>
  </div>

  <!-- Header -->
  <header id="main-header" class="fixed top-0 left-0 right-0 z-50 w-full p-4 glass-card rounded-b-3xl border-b-0 shadow-lg">
    <div class="flex justify-between items-center max-w-md mx-auto">
      <div class="flex items-center gap-3">
        <div class="relative w-11 h-11 rounded-full p-[2px] bg-gradient-to-tr from-blue-600 to-app-glow">
          <img id="ui-avatar" src="https://via.placeholder.com/150" class="w-full h-full rounded-full object-cover border-2 border-app-dark">
        </div>
        <div class="flex flex-col">
          <span id="ui-name" class="font-bold text-white text-sm tracking-wide">Loading...</span>
          <span class="text-[10px] text-slate-400 font-bold uppercase">Point Play User</span>
        </div>
      </div>
      <div class="flex flex-col items-end gap-1.5">
        <div class="bg-blue-900/40 border border-app-glow/30 px-3 py-1.5 rounded-xl flex items-center gap-2">
          <i class="fa-solid fa-bolt text-app-glow text-xs"></i>
          <span id="ui-xp" class="text-white font-black text-sm tracking-wider">0 XP</span>
        </div>
        <div class="bg-emerald-900/40 border border-emerald-500/30 px-3 py-1 rounded-xl flex items-center gap-2">
          <i class="fa-solid fa-dollar-sign text-emerald-400 text-[10px]"></i>
          <span id="ui-usd" class="text-emerald-400 font-black text-xs tracking-wider">0.00</span>
        </div>
      </div>
    </div>
  </header>

  <!-- App Content -->
  <main class="flex-1 max-w-md w-full mx-auto p-4 pt-24 relative" id="app-content">
    
    <!-- HOME VIEW -->
    <div id="view-home" class="view-section fade-in space-y-6">
      <div class="glass-card rounded-[2rem] p-6 text-center flex flex-col items-center">
        <div class="w-16 h-16 rounded-full bg-blue-900/40 border border-blue-400/30 flex items-center justify-center mb-4">
             <i class="fa-solid fa-star text-3xl text-app-glow"></i>
        </div>
        <h1 class="text-5xl font-black text-white tracking-tighter" id="ui-main-xp">0 XP</h1>
        <div class="w-full mt-6 grid grid-cols-2 gap-3">
          <div class="bg-app-dark/50 border border-slate-700/50 p-3 rounded-2xl flex items-center gap-3">
            <div class="bg-blue-500/10 p-2.5 rounded-xl"><i class="fa-solid fa-clapperboard text-blue-400"></i></div>
            <div class="text-left">
              <p class="text-[9px] text-slate-500 uppercase font-bold tracking-wider">Total Ads</p>
              <p class="text-sm font-black text-white" id="ui-total-ads">0</p>
            </div>
          </div>
          <div class="bg-app-dark/50 border border-slate-700/50 p-3 rounded-2xl flex items-center gap-3">
            <div class="bg-purple-500/10 p-2.5 rounded-xl"><i class="fa-solid fa-check text-purple-400"></i></div>
            <div class="text-left">
              <p class="text-[9px] text-slate-500 uppercase font-bold tracking-wider">Daily Login</p>
              <button id="ui-daily-btn" onclick="claimDaily()" class="text-xs font-black text-purple-400 mt-1 uppercase">Claim</button>
            </div>
          </div>
        </div>
      </div>

      <button onclick="watchAd()" id="btn-watch-ad" class="w-full py-4 rounded-2xl bg-gradient-to-r from-blue-600 to-cyan-500 text-white font-black tracking-widest uppercase flex items-center justify-center gap-3 shadow-[0_10px_30px_rgba(0,240,255,0.3)] active:scale-95 transition-all">
        <i class="fa-solid fa-play bg-white/20 p-2 rounded-full text-xs"></i> 
        <span>Watch Ad <span class="text-cyan-200">+20 XP</span></span>
      </button>
    </div>

    <!-- TASKS VIEW -->
    <div id="view-tasks" class="view-section hidden fade-in space-y-6">
      <div class="text-center pt-2">
        <h2 class="text-3xl font-black text-white tracking-tight">Tasks</h2>
        <p class="text-xs text-app-glow mt-1 uppercase font-bold">Complete to Earn XP</p>
      </div>

      <!-- AZX Crypto Sponsor Task -->
      <div class="glass-card rounded-2xl overflow-hidden border-2 border-amber-500/30">
        <img src="https://i.postimg.cc/rsz7NnZp/IMG-20260903-114036-951.jpg" alt="AZX Crypto" class="w-full h-32 object-cover opacity-90 pointer-events-none">
        <div class="p-4 flex justify-between items-center bg-gradient-to-b from-transparent to-app-dark">
          <div>
            <h3 class="text-sm font-black text-white uppercase tracking-wider">AZX Crypto</h3>
            <p class="text-[10px] text-amber-400 font-bold mt-0.5">Sponsor Task • Only Once</p>
          </div>
          <button id="btn-azx" onclick="completeAzxTask()" class="bg-gradient-to-r from-amber-500 to-orange-500 text-white px-4 py-2 rounded-xl text-xs font-black uppercase shadow-lg active:scale-95 transition-all">+200 XP</button>
        </div>
      </div>

      <!-- Standard Tasks -->
      <div id="tasks-list" class="space-y-3">
        <!-- Injected dynamically -->
      </div>
    </div>

    <!-- REFERRALS VIEW -->
    <div id="view-referrals" class="view-section hidden fade-in space-y-6">
      <div class="text-center pt-2">
        <h2 class="text-3xl font-black text-white tracking-tight">Referrals</h2>
        <p class="text-xs text-blue-400 mt-1 uppercase font-bold">Earn 250 XP + $0.025</p>
      </div>

      <div class="grid grid-cols-3 gap-2">
        <div class="glass-card p-3 rounded-xl text-center border-t-2 border-t-blue-500">
          <p class="text-[9px] text-slate-400 uppercase font-black">Total</p>
          <p id="ui-ref-total" class="text-lg font-black text-white mt-1">0</p>
        </div>
        <div class="glass-card p-3 rounded-xl text-center border-t-2 border-t-amber-500">
          <p class="text-[9px] text-slate-400 uppercase font-black">Pending</p>
          <p id="ui-ref-pending" class="text-lg font-black text-white mt-1">0</p>
        </div>
        <div class="glass-card p-3 rounded-xl text-center border-t-2 border-t-emerald-500">
          <p class="text-[9px] text-slate-400 uppercase font-black">Approved</p>
          <p id="ui-ref-approved" class="text-lg font-black text-white mt-1">0</p>
        </div>
      </div>

      <div class="glass-card rounded-2xl p-4 border border-slate-700">
        <div class="flex justify-between items-center mb-3">
          <h3 class="text-xs font-black text-white uppercase">Your Invite Link</h3>
          <button onclick="showRefInfo()" class="text-slate-400 hover:text-white"><i class="fa-solid fa-circle-info"></i></button>
        </div>
        <div class="flex gap-2">
          <button onclick="copyRefLink()" class="flex-1 bg-slate-800 text-white py-2.5 rounded-xl text-xs font-black uppercase active:scale-95 transition-all"><i class="fa-regular fa-copy mr-1"></i> Copy</button>
          <button onclick="shareRefLink()" class="flex-1 bg-blue-600 text-white py-2.5 rounded-xl text-xs font-black uppercase active:scale-95 transition-all"><i class="fa-solid fa-share-nodes mr-1"></i> Share</button>
        </div>
      </div>

      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-widest pl-2 mb-3">Your Referrals</h3>
        <div id="referrals-list" class="space-y-2"></div>
      </div>

      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-widest pl-2 mb-3">Reward History</h3>
        <div id="referrals-history" class="space-y-2"></div>
      </div>
    </div>

    <!-- WALLET VIEW -->
    <div id="view-wallet" class="view-section hidden fade-in space-y-6">
      <div class="glass-card rounded-[2rem] p-6 text-center border-t-2 border-emerald-500/40 bg-gradient-to-b from-emerald-900/30 to-app-dark">
        <div class="w-14 h-14 mx-auto bg-emerald-500/10 rounded-full flex items-center justify-center mb-3 border border-emerald-500/30">
          <i class="fa-solid fa-wallet text-xl text-emerald-400"></i>
        </div>
        <p class="text-[10px] font-black text-emerald-400 uppercase tracking-widest mb-1 opacity-80">USD Balance</p>
        <h1 class="text-5xl font-black text-white tracking-tighter mb-2">$<span id="ui-main-usd">0.00</span></h1>
        <p class="text-[9px] font-bold text-slate-400 uppercase bg-app-dark px-3 py-1 rounded-full border border-slate-700 inline-block">Minimum: $10.00</p>
      </div>

      <div class="glass-card rounded-[1.5rem] p-5 space-y-4">
        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">TON Wallet Address</label>
          <input type="text" id="input-address" placeholder="UQ..." class="w-full bg-app-dark/50 border-2 border-slate-700/50 rounded-xl py-3 px-4 text-sm font-medium text-white focus:outline-none focus:border-blue-500 transition-colors">
        </div>
        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">Amount (USD)</label>
          <input type="number" id="input-amount" placeholder="10.00" min="10" step="0.5" class="w-full bg-app-dark/50 border-2 border-slate-700/50 rounded-xl py-3 px-4 text-sm font-bold text-emerald-400 focus:outline-none focus:border-emerald-500 transition-colors">
        </div>
        <button onclick="requestWithdraw()" id="btn-withdraw" class="w-full py-3.5 mt-2 bg-gradient-to-r from-emerald-600 to-teal-500 text-white font-black rounded-xl text-sm uppercase tracking-widest active:scale-95 transition-all">
          Request Withdrawal
        </button>
      </div>

      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-widest pl-2 mb-3">Withdrawal History</h3>
        <div id="wallet-history" class="space-y-3"></div>
      </div>
    </div>

  </main>

  <!-- Bottom Navigation -->
  <nav class="fixed bottom-6 left-1/2 -translate-x-1/2 w-[calc(100%-2rem)] max-w-[400px] glass-card rounded-2xl z-50">
    <div class="flex justify-between items-center px-4 py-3">
      <button onclick="switchTab('home')" class="nav-btn nav-active text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all" data-target="home">
        <i class="fa-solid fa-house text-lg"></i><span class="text-[8px] font-black uppercase">Home</span>
      </button>
      <button onclick="switchTab('tasks')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all" data-target="tasks">
        <i class="fa-solid fa-list-check text-lg"></i><span class="text-[8px] font-black uppercase">Tasks</span>
      </button>
      <button onclick="switchTab('referrals')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all" data-target="referrals">
        <i class="fa-solid fa-users text-lg"></i><span class="text-[8px] font-black uppercase">Referrals</span>
      </button>
      <button onclick="switchTab('wallet')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all" data-target="wallet">
        <i class="fa-solid fa-wallet text-lg"></i><span class="text-[8px] font-black uppercase">Wallet</span>
      </button>
    </div>
  </nav>

  <!-- Modal for Referral Info -->
  <div id="ref-modal" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-[999999] flex items-center justify-center opacity-0 pointer-events-none transition-opacity">
    <div class="glass-card w-[90%] max-w-sm rounded-2xl p-6 text-center transform scale-95 transition-transform" id="ref-modal-content">
      <i class="fa-solid fa-users-gear text-4xl text-blue-400 mb-4"></i>
      <h3 class="text-xl font-black text-white mb-2">Referral Rules</h3>
      <ul class="text-sm text-slate-300 text-left space-y-2 mb-6">
        <li><i class="fa-solid fa-check text-emerald-400 mr-2"></i> Referred user must watch <strong>25 ads</strong>.</li>
        <li><i class="fa-solid fa-check text-emerald-400 mr-2"></i> Referred user must complete <strong>5 tasks</strong>.</li>
        <li><i class="fa-solid fa-infinity text-blue-400 mr-2"></i> No deadline to complete conditions.</li>
        <li><i class="fa-solid fa-gift text-purple-400 mr-2"></i> You receive <strong>250 XP + $0.025</strong> upon completion.</li>
      </ul>
      <button onclick="closeRefInfo()" class="w-full bg-slate-800 text-white font-black py-3 rounded-xl uppercase text-xs tracking-wider">Close</button>
    </div>
  </div>

  <script>
    const tg = window.Telegram.WebApp;
    tg.expand();
    tg.ready();
    tg.setHeaderColor('#0a0b1a');
    tg.setBackgroundColor('#050511');

    let currentUser = null;
    let isFetching = false;
    const startParam = tg.initDataUnsafe?.start_param || '';

    // --- API HELPER ---
    async function apiCall(endpoint, payload = {}) {
      try {
        const response = await fetch(`index.php?api=${endpoint}`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ initData: tg.initData, start_param: startParam, ...payload })
        });
        const data = await response.json();
        if (data.success && data.user) {
          currentUser = data.user;
          renderUI();
        } else if (data.error) {
          showToast('Error', data.error, 'error');
        }
        return data.success;
      } catch (err) {
        showToast('Connection Error', 'Failed to reach server.', 'error');
        return false;
      }
    }

    // --- UI RENDERING ---
    function renderUI() {
      if (!currentUser) return;

      // Header & Global
      document.getElementById('ui-name').innerText = currentUser.first_name;
      document.getElementById('ui-xp').innerHTML = `${currentUser.xp} <span class="text-[10px] text-app-glow">XP</span>`;
      document.getElementById('ui-usd').innerText = currentUser.usd_balance.toFixed(4);
      if (tg.initDataUnsafe?.user?.photo_url) {
        document.getElementById('ui-avatar').src = tg.initDataUnsafe.user.photo_url;
      }

      // Home
      document.getElementById('ui-main-xp').innerText = `${currentUser.xp} XP`;
      document.getElementById('ui-total-ads').innerText = currentUser.total_ads;
      const dailyBtn = document.getElementById('ui-daily-btn');
      if (currentUser.daily_login_date === new Date().toISOString().split('T')[0]) {
        dailyBtn.innerText = 'Claimed';
        dailyBtn.classList.replace('text-purple-400', 'text-slate-500');
        dailyBtn.classList.add('pointer-events-none');
      }

      // Wallet
      document.getElementById('ui-main-usd').innerText = currentUser.usd_balance.toFixed(2);
      renderWithdrawHistory();

      // Referrals
      document.getElementById('ui-ref-total').innerText = currentUser.referrals.length;
      document.getElementById('ui-ref-pending').innerText = currentUser.pending_referrals;
      document.getElementById('ui-ref-approved').innerText = currentUser.approved_referrals;
      renderReferrals();

      // Tasks
      renderTasks();
    }

    function renderTasks() {
      const tasksConfig = [
        { id: 'watch_5', title: 'Watch 5 Ads Today', req: 5, reward: 100, icon: 'fa-video', color: 'text-blue-400' },
        { id: 'watch_30', title: 'Watch 30 Ads Today', req: 30, reward: 300, icon: 'fa-film', color: 'text-indigo-400' }
      ];

      const list = document.getElementById('tasks-list');
      list.innerHTML = '';
      
      tasksConfig.forEach(t => {
        const isCompleted = currentUser.task_ids.includes(t.id);
        const progress = Math.min(currentUser.daily_ads, t.req);
        const canClaim = !isCompleted && progress >= t.req;

        let btn = isCompleted 
          ? `<span class="text-[10px] font-black bg-emerald-500/10 text-emerald-400 px-3 py-1.5 rounded-xl border border-emerald-500/30"><i class="fa-solid fa-check"></i> Claimed</span>`
          : (canClaim 
              ? `<button onclick="claimTask('${t.id}')" class="text-[10px] font-black bg-app-primary text-white px-4 py-1.5 rounded-xl uppercase shadow-lg">Claim</button>`
              : `<span class="text-[10px] font-black bg-slate-800 text-slate-300 px-3 py-1.5 rounded-xl">+${t.reward} XP</span>`);

        list.innerHTML += `
          <div class="glass-card rounded-2xl p-3 flex justify-between items-center">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl bg-slate-800 flex items-center justify-center"><i class="fa-solid ${t.icon} ${t.color}"></i></div>
              <div>
                <p class="text-xs font-black text-white">${t.title}</p>
                <p class="text-[10px] text-slate-400 font-bold uppercase mt-0.5">Progress: ${progress}/${t.req}</p>
              </div>
            </div>
            ${btn}
          </div>
        `;
      });

      // Update AZX UI
      const azxBtn = document.getElementById('btn-azx');
      if (currentUser.azx_crypto_completed) {
        azxBtn.innerText = 'Completed';
        azxBtn.className = 'bg-slate-800 text-slate-500 px-4 py-2 rounded-xl text-xs font-black uppercase pointer-events-none';
      }
    }

    function renderReferrals() {
      const refList = document.getElementById('referrals-list');
      const histList = document.getElementById('referrals-history');
      
      refList.innerHTML = currentUser.referrals.length === 0 ? '<p class="text-xs text-slate-500 text-center py-2">No referrals yet.</p>' : '';
      
      currentUser.referrals.forEach(r => {
        const isApproved = r.status === 'approved';
        refList.innerHTML += `
          <div class="glass-card p-3 rounded-xl flex justify-between items-center mb-2">
            <div>
              <p class="text-xs font-black text-white">${r.name} <span class="text-[9px] text-slate-400">${r.username ? '@'+r.username : ''}</span></p>
              <p class="text-[9px] text-slate-400 mt-1 uppercase">Ads: <span class="${r.ad_progress>=25?'text-emerald-400':'text-blue-400'}">${r.ad_progress}/25</span> | Tasks: <span class="${r.task_progress>=5?'text-emerald-400':'text-blue-400'}">${r.task_progress}/5</span></p>
            </div>
            <span class="text-[9px] font-black px-2 py-1 rounded-md uppercase ${isApproved ? 'bg-emerald-500/20 text-emerald-400' : 'bg-amber-500/20 text-amber-400'}">${r.status}</span>
          </div>`;
      });

      histList.innerHTML = currentUser.referral_rewards.length === 0 ? '<p class="text-xs text-slate-500 text-center py-2">No rewards yet.</p>' : '';
      currentUser.referral_rewards.forEach(h => {
        histList.innerHTML += `
          <div class="glass-card p-3 rounded-xl flex justify-between items-center mb-2 border border-slate-700">
            <div>
              <p class="text-xs font-black text-white">Referral Bonus</p>
              <p class="text-[9px] text-slate-400 uppercase mt-0.5">User: ${h.child_name} | ${h.date}</p>
            </div>
            <div class="text-right">
              <p class="text-xs font-black text-app-glow">+${h.xp} XP</p>
              <p class="text-[10px] font-black text-emerald-400">+$${h.usd.toFixed(3)}</p>
            </div>
          </div>`;
      });
    }

    function renderWithdrawHistory() {
      const container = document.getElementById('wallet-history');
      container.innerHTML = currentUser.withdrawals.length === 0 ? '<p class="text-xs text-slate-500 text-center py-2">No history.</p>' : '';
      
      currentUser.withdrawals.forEach(w => {
        container.innerHTML += `
          <div class="glass-card p-3 rounded-xl flex justify-between items-center">
            <div>
              <p class="text-xs font-black text-white">${w.id}</p>
              <p class="text-[9px] text-slate-400 mt-0.5 font-mono">${w.address.substring(0,6)}...${w.address.substring(w.address.length-4)}</p>
            </div>
            <div class="text-right">
              <p class="text-sm font-black text-emerald-400">-$${w.amount.toFixed(2)}</p>
              <p class="text-[9px] font-black text-amber-400 uppercase mt-0.5">${w.status}</p>
            </div>
          </div>`;
      });
    }

    // --- ACTIONS ---
    async function watchAd() {
      if (isFetching) return;
      const btn = document.getElementById('btn-watch-ad');
      btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> <span>Loading Ad...</span>`;
      
      if (window.Adsgram) {
        try {
          const adController = window.Adsgram.init({ blockId: "int-35545" });
          await adController.show();
          isFetching = true;
          await apiCall('watch_ad');
          isFetching = false;
          showToast('Reward Granted!', '+20 XP earned.', 'success');
        } catch (e) {
          showToast('Notice', 'Ad skipped or unavailable.', 'error');
        }
      } else {
        showToast('Error', 'Ad system offline.', 'error');
      }
      btn.innerHTML = `<i class="fa-solid fa-play bg-white/20 p-2 rounded-full text-xs"></i> <span>Watch Ad <span class="text-cyan-200">+20 XP</span></span>`;
    }

    async function claimDaily() {
      if(isFetching) return;
      isFetching = true;
      const success = await apiCall('claim_daily');
      isFetching = false;
      if(success) showToast('Daily Login', 'Claimed +50 XP successfully!', 'success');
    }

    async function claimTask(taskId) {
      if(isFetching) return;
      isFetching = true;
      const success = await apiCall('complete_task', { task_id: taskId });
      isFetching = false;
      if(success) showToast('Task Complete', 'XP Rewarded!', 'success');
    }

    async function completeAzxTask() {
      if(isFetching) return;
      tg.openTelegramLink('https://t.me/azxcrypto');
      isFetching = true;
      setTimeout(async () => {
        const success = await apiCall('azx_crypto');
        isFetching = false;
        if(success) showToast('Task Complete', '+200 XP from AZX Crypto!', 'success');
      }, 2000);
    }

    async function requestWithdraw() {
      if(isFetching) return;
      const address = document.getElementById('input-address').value;
      const amount = parseFloat(document.getElementById('input-amount').value);
      
      if (!address || address.length < 10) return showToast('Error', 'Invalid TON Address', 'error');
      if (isNaN(amount) || amount < 10) return showToast('Error', 'Minimum withdrawal is $10.00', 'error');
      if (amount > currentUser.usd_balance) return showToast('Error', 'Insufficient balance', 'error');

      isFetching = true;
      const success = await apiCall('withdraw', { amount: amount, address: address });
      isFetching = false;
      
      if(success) {
        showToast('Success', 'Withdrawal requested successfully.', 'success');
        document.getElementById('input-address').value = '';
        document.getElementById('input-amount').value = '';
      }
    }

    // --- REFERRAL HELPERS ---
    function getRefLink() {
      return `https://t.me/pointplayappbot?startapp=${currentUser.telegram_id}`;
    }
    function copyRefLink() {
      navigator.clipboard.writeText(getRefLink());
      showToast('Copied', 'Referral link copied!', 'success');
    }
    function shareRefLink() {
      const text = encodeURIComponent("🎯 Play games, complete tasks, earn XP, and collect exciting rewards 🚀 I’m already playing on Point Play now it’s your turn to join the adventure👇\n\n");
      tg.openTelegramLink(`https://t.me/share/url?url=${encodeURIComponent(getRefLink())}&text=${text}`);
    }
    function showRefInfo() {
      document.getElementById('ref-modal').classList.remove('opacity-0', 'pointer-events-none');
      document.getElementById('ref-modal-content').classList.remove('scale-95');
    }
    function closeRefInfo() {
      document.getElementById('ref-modal').classList.add('opacity-0', 'pointer-events-none');
      document.getElementById('ref-modal-content').classList.add('scale-95');
    }

    // --- NAVIGATION ---
    function switchTab(tabId) {
      document.querySelectorAll('.view-section').forEach(el => el.classList.add('hidden'));
      document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('nav-active'));
      document.getElementById(`view-${tabId}`).classList.remove('hidden');
      document.querySelector(`[data-target="${tabId}"]`).classList.add('nav-active');
      if (tg.HapticFeedback) tg.HapticFeedback.selectionChanged();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function showToast(title, message, type) {
      const toast = document.getElementById('toast-container');
      const icon = document.getElementById('toast-icon');
      document.getElementById('toast-title').innerText = title;
      document.getElementById('toast-message').innerText = message;
      
      if (type === 'success') {
        icon.innerHTML = '<i class="fa-solid fa-check"></i>';
        icon.className = 'w-12 h-12 rounded-xl flex shrink-0 items-center justify-center text-xl bg-emerald-500/20 text-emerald-400';
      } else {
        icon.innerHTML = '<i class="fa-solid fa-xmark"></i>';
        icon.className = 'w-12 h-12 rounded-xl flex shrink-0 items-center justify-center text-xl bg-red-500/20 text-red-400';
      }
      
      toast.classList.add('toast-show');
      if (tg.HapticFeedback) tg.HapticFeedback.notificationOccurred(type);
      setTimeout(() => toast.classList.remove('toast-show'), 3000);
    }

    // Initialize App
    apiCall('init');
  </script>
</body>
</html>
