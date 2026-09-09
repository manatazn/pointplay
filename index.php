<?php
// ==========================================
// POINT PLAY - TELEGRAM MINI APP (ALL IN ONE)
// ==========================================

// 1. SYSTEM INITIALIZATION & DATA FOLDER
$data_dir = __DIR__ . '/data';
if (!file_exists($data_dir)) {
    mkdir($data_dir, 0755, true);
}

$files = ['users.json', 'tasks.json', 'referrals.json', 'withdrawals.json', 'ads.json', 'settings.json'];
foreach ($files as $file) {
    $path = $data_dir . '/' . $file;
    if (!file_exists($path)) {
        file_put_contents($path, json_encode([], JSON_PRETTY_PRINT));
    }
}

// 2. HELPER FUNCTIONS (TRANSACTIONAL JSON DB)
function db_transaction($filename, $callback, $default = []) {
    $path = __DIR__ . "/data/" . $filename;
    $fp = fopen($path, 'c+');
    if (!$fp) return null;
    
    $new_data = null;
    if (flock($fp, LOCK_EX)) {
        clearstatcache(true, $path);
        $size = filesize($path);
        $current_data = ($size > 0) ? json_decode(fread($fp, $size), true) : $default;
        if (!is_array($current_data)) $current_data = $default;
        
        $new_data = $callback($current_data);
        
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($new_data, JSON_PRETTY_PRINT));
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    return $new_data;
}

function db_read($filename, $default = []) {
    $path = __DIR__ . "/data/" . $filename;
    if (!file_exists($path)) return $default;
    $content = file_get_contents($path);
    $data = json_decode($content, true);
    return is_array($data) ? $data : $default;
}

// 3. CORE LOGIC (REFERRAL APPROVAL)
function check_and_approve_referral($uid) {
    db_transaction('users.json', function($users) use ($uid) {
        if (!isset($users[$uid])) return $users;
        
        $user = $users[$uid];
        $referrer_uid = $user['referrer_uid'] ?? null;
        $reward_given = $user['referral_reward_given'] ?? false;
        
        // Şərtlər yoxlanılır: 25 reklam, 5 task, və reward hələ verilməyib
        if ($referrer_uid && !$reward_given && $user['ads_watched'] >= 25 && $user['tasks_completed'] >= 5) {
            
            if (isset($users[$referrer_uid])) {
                // Referrer-ə mükafat verilir
                $users[$referrer_uid]['xp'] += 250;
                $users[$referrer_uid]['total_xp'] += 250;
                $users[$referrer_uid]['balance_usd'] += 0.025;
                $users[$referrer_uid]['approved_referrals'] += 1;
                $users[$referrer_uid]['pending_referrals'] = max(0, $users[$referrer_uid]['pending_referrals'] - 1);
                
                // Cari istifadəçi (referral) qeyd olunur
                $users[$uid]['referral_reward_given'] = true;
                
                // History yazılır
                db_transaction('referrals.json', function($refs) use ($referrer_uid, $uid, $user) {
                    if (!isset($refs[$referrer_uid])) {
                        $refs[$referrer_uid] = ['list' => [], 'history' => []];
                    }
                    $refs[$referrer_uid]['history'][] = [
                        'type' => 'Referral Bonus',
                        'reward' => '+250 XP / +$0.025',
                        'date' => date('Y-m-d H:i'),
                        'ref_name' => $user['first_name']
                    ];
                    return $refs;
                });
            }
        }
        return $users;
    });
}

// 4. API / ACTION HANDLERS
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];
    $data = json_decode(file_get_contents("php://input"), true) ?? $_POST;
    
    $uid = $data['uid'] ?? null;
    
    if (!$uid) {
        echo json_encode(['error' => 'Missing UID']);
        exit;
    }

    if ($action === 'sync') {
        $first_name = $data['first_name'] ?? 'User';
        $username = $data['username'] ?? '';
        $startapp_uid = $data['startapp_uid'] ?? null;
        
        $users = db_transaction('users.json', function($users) use ($uid, $first_name, $username, $startapp_uid) {
            if (!isset($users[$uid])) {
                // Yeni istifadəçi
                $users[$uid] = [
                    'uid' => $uid,
                    'first_name' => $first_name,
                    'username' => $username,
                    'xp' => 0,
                    'total_xp' => 0,
                    'balance_usd' => 0.000,
                    'ads_watched' => 0,
                    'tasks_completed' => 0,
                    'level' => 1,
                    'boxes_opened' => 0,
                    'referrer_uid' => null,
                    'referrals_count' => 0,
                    'pending_referrals' => 0,
                    'approved_referrals' => 0,
                    'referral_reward_given' => false,
                    'created_at' => date('Y-m-d H:i:s')
                ];
                
                // Referral əlaqəsi qurulur
                if ($startapp_uid && $startapp_uid !== $uid && isset($users[$startapp_uid])) {
                    $users[$uid]['referrer_uid'] = $startapp_uid;
                    $users[$startapp_uid]['referrals_count'] += 1;
                    $users[$startapp_uid]['pending_referrals'] += 1;
                    
                    db_transaction('referrals.json', function($refs) use ($startapp_uid, $uid) {
                        if (!isset($refs[$startapp_uid])) $refs[$startapp_uid] = ['list' => [], 'history' => []];
                        if (!in_array($uid, $refs[$startapp_uid]['list'])) {
                            $refs[$startapp_uid]['list'][] = $uid;
                        }
                        return $refs;
                    });
                }
            } else {
                $users[$uid]['first_name'] = $first_name;
                $users[$uid]['username'] = $username;
            }
            return $users;
        });

        // Referral məlumatlarını topla (istifadəçiyə göndərmək üçün)
        $refs_db = db_read('referrals.json');
        $user_refs = $refs_db[$uid] ?? ['list' => [], 'history' => []];
        $referrals_detailed = [];
        
        foreach ($user_refs['list'] as $ref_uid) {
            if (isset($users[$ref_uid])) {
                $ru = $users[$ref_uid];
                $status = ($ru['ads_watched'] >= 25 && $ru['tasks_completed'] >= 5 && $ru['referral_reward_given']) ? 'Approved' : 'Pending';
                $referrals_detailed[] = [
                    'uid' => $ru['uid'],
                    'first_name' => $ru['first_name'],
                    'username' => $ru['username'],
                    'ads' => $ru['ads_watched'],
                    'tasks' => $ru['tasks_completed'],
                    'status' => $status
                ];
            }
        }
        
        $withdraws = db_read('withdrawals.json');
        $user_withdraws = $withdraws[$uid] ?? [];

        echo json_encode([
            'success' => true,
            'user' => $users[$uid],
            'referrals_detailed' => $referrals_detailed,
            'referral_history' => $user_refs['history'],
            'withdrawals' => $user_withdraws
        ]);
        exit;
    }

    if ($action === 'watch_ad') {
        db_transaction('users.json', function($users) use ($uid) {
            if (isset($users[$uid])) {
                $users[$uid]['ads_watched'] += 1;
                $users[$uid]['xp'] += 10;
                $users[$uid]['total_xp'] += 10;
                $users[$uid]['balance_usd'] += 0.0015;
            }
            return $users;
        });
        check_and_approve_referral($uid);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'complete_task') {
        $task_id = $data['task_id'] ?? '';
        $reward_xp = (int)($data['reward_xp'] ?? 0);
        
        db_transaction('users.json', function($users) use ($uid, $reward_xp) {
            if (isset($users[$uid])) {
                $users[$uid]['tasks_completed'] += 1;
                $users[$uid]['xp'] += $reward_xp;
                $users[$uid]['total_xp'] += $reward_xp;
            }
            return $users;
        });
        check_and_approve_referral($uid);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'open_box') {
        $type = $data['type'] ?? 'bronze';
        $costs = ['bronze' => 10000, 'silver' => 50000, 'gold' => 100000];
        $cost = $costs[$type] ?? 9999999;
        
        $result = ['success' => false];
        db_transaction('users.json', function($users) use ($uid, $type, $cost, &$result) {
            if (isset($users[$uid]) && $users[$uid]['xp'] >= $cost) {
                $users[$uid]['xp'] -= $cost;
                $users[$uid]['boxes_opened'] += 1;
                
                $isJackpot = (rand(1, 10000) === 1);
                $reward = 0;
                if ($type === 'bronze') $reward = $isJackpot ? 1.00 : 0.10;
                else if ($type === 'silver') $reward = $isJackpot ? 7.00 : 0.50;
                else if ($type === 'gold') $reward = $isJackpot ? 15.00 : 1.00;
                
                $users[$uid]['balance_usd'] += $reward;
                $result = ['success' => true, 'reward' => $reward, 'isJackpot' => $isJackpot];
            }
            return $users;
        });
        echo json_encode($result);
        exit;
    }

    if ($action === 'withdraw') {
        $amount = (float)($data['amount'] ?? 0);
        $address = $data['address'] ?? '';
        
        if ($amount < 10) {
            echo json_encode(['success' => false, 'error' => 'Minimum withdrawal is $10']);
            exit;
        }
        
        $result = ['success' => false];
        db_transaction('users.json', function($users) use ($uid, $amount, &$result) {
            if (isset($users[$uid]) && $users[$uid]['balance_usd'] >= $amount) {
                $users[$uid]['balance_usd'] -= $amount;
                $result = ['success' => true];
            }
            return $users;
        });
        
        if ($result['success']) {
            db_transaction('withdrawals.json', function($withdraws) use ($uid, $amount, $address) {
                if (!isset($withdraws[$uid])) $withdraws[$uid] = [];
                array_unshift($withdraws[$uid], [
                    'id' => '#' . strtoupper(substr(md5(uniqid()), 0, 6)),
                    'date' => date('Y-m-d H:i'),
                    'amount' => $amount,
                    'address' => substr($address, 0, 6) . '...' . substr($address, -4),
                    'status' => 'Pending'
                ]);
                return $withdraws;
            });
        }
        echo json_encode($result);
        exit;
    }
}

// FRONTEND HTML & JS SERVER-RENDERED
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>Point Play - Telegram Mini App</title>
  
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
            crypto: { dark: '#050511', card: '#0a0b1a', primary: '#3b82f6', glow: '#00f0ff', gold: '#ffb800', silver: '#e2e8f0', bronze: '#cd7f32' }
          },
          animation: {
            'blob': 'blob 7s infinite',
            'pulse-fast': 'pulse 1.5s cubic-bezier(0.4, 0, 0.6, 1) infinite',
            'float-up': 'floatUp 2s ease-out forwards',
            'pop': 'pop 0.3s ease-out forwards',
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
    body { background-color: #050511; color: #f8fafc; overflow-x: hidden; user-select: none; -webkit-user-select: none; }
    ::-webkit-scrollbar { width: 0px; background: transparent; }
    .bg-orb-1 { position: fixed; top: -10%; left: -10%; width: 50vw; height: 50vw; background: radial-gradient(circle, rgba(59, 130, 246, 0.15) 0%, rgba(0, 0, 0, 0) 70%); z-index: -1; filter: blur(40px); }
    .bg-orb-2 { position: fixed; bottom: -10%; right: -10%; width: 60vw; height: 60vw; background: radial-gradient(circle, rgba(0, 240, 255, 0.1) 0%, rgba(0, 0, 0, 0) 70%); z-index: -1; filter: blur(50px); }
    .glass-card { background: linear-gradient(145deg, rgba(20, 22, 45, 0.6) 0%, rgba(10, 11, 26, 0.8) 100%); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.05); box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.3); }
    .glass-button { background: linear-gradient(135deg, rgba(59,130,246,0.2) 0%, rgba(0,240,255,0.1) 100%); border: 1px solid rgba(0,240,255,0.3); box-shadow: 0 0 15px rgba(0,240,255,0.1) inset; }
    .fade-in { animation: fadeIn 0.3s ease-out forwards; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    .nav-active { color: #00f0ff !important; transform: translateY(-2px); }
    .nav-active i { filter: drop-shadow(0 0 8px rgba(0, 240, 255, 0.6)); }
    .nav-active::before { content: ''; position: absolute; top: -10px; left: 50%; transform: translateX(-50%); width: 20px; height: 4px; background: #00f0ff; border-radius: 4px; box-shadow: 0 0 12px #00f0ff, 0 0 20px #3b82f6; }
    .btn-3d { background: linear-gradient(to bottom, #3b82f6, #2563eb); border-bottom: 3px solid #1e3a8a; transition: all 0.1s; }
    .btn-3d:active { transform: translateY(3px); border-bottom-width: 0px; margin-bottom: 3px; }
    #toast-container { position: fixed; top: 1.5rem; left: 50%; transform: translate(-50%, -150%) scale(0.9); width: 90%; max-width: 380px; z-index: 999999; transition: all 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55); opacity: 0; pointer-events: none; }
    .toast-show { transform: translate(-50%, 0) scale(1) !important; opacity: 1 !important; }
    .box-bronze { background: linear-gradient(135deg, rgba(205,127,50,0.1), rgba(139,69,19,0.2)); border: 1px solid rgba(205,127,50,0.4); }
    .box-silver { background: linear-gradient(135deg, rgba(226,232,240,0.1), rgba(148,163,184,0.2)); border: 1px solid rgba(226,232,240,0.4); }
    .box-gold { background: linear-gradient(135deg, rgba(255,184,0,0.15), rgba(217,119,6,0.25)); border: 1px solid rgba(255,184,0,0.5); }
    .modal-overlay { background: rgba(5, 5, 17, 0.9); backdrop-filter: blur(5px); }
  </style>
</head>
<body class="flex flex-col min-h-screen pb-32">
  <div class="bg-orb-1 animate-blob"></div>
  <div class="bg-orb-2 animate-blob" style="animation-delay: 2s"></div>

  <div id="loading-overlay" class="bg-[#050511] flex flex-col items-center justify-center z-[100000] fixed inset-0 transition-opacity duration-500">
    <div class="relative w-24 h-24 mb-8">
      <div class="absolute inset-0 rounded-full border-t-4 border-crypto-glow animate-[spin_1s_linear_infinite] shadow-[0_0_20px_rgba(0,240,255,0.5)]"></div>
      <div class="absolute inset-3 rounded-full border-b-4 border-blue-500 animate-[spin_1.5s_linear_infinite_reverse]"></div>
      <div class="absolute inset-0 flex items-center justify-center">
        <i class="fa-solid fa-play text-crypto-glow text-3xl animate-pulse"></i>
      </div>
    </div>
    <h2 class="text-white font-black tracking-[0.2em] text-2xl uppercase bg-clip-text text-transparent bg-gradient-to-r from-crypto-glow via-blue-400 to-indigo-500 mb-2 drop-shadow-lg">Point Play</h2>
    <p class="text-[10px] text-blue-400 font-bold tracking-widest mt-6 animate-pulse uppercase">Syncing Server Data...</p>
  </div>

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
              <p class="text-sm font-black text-white"><span id="ads-watched" class="text-blue-400">0</span></p>
            </div>
          </div>
          <div class="bg-[#050511]/50 border border-slate-700/50 p-3 rounded-2xl flex items-center gap-3 backdrop-blur-md">
            <div class="bg-amber-500/10 p-2.5 rounded-xl border border-amber-500/20"><i class="fa-solid fa-list-check text-amber-400"></i></div>
            <div class="text-left">
              <p class="text-[9px] text-slate-500 uppercase font-bold tracking-wider">Tasks Done</p>
              <p class="text-sm font-black text-white"><span id="tasks-completed" class="text-amber-400">0</span></p>
            </div>
          </div>
        </div>
      </div>

      <button onclick="watchAd()" id="watch-ad-btn" class="w-full py-4 rounded-2xl text-white font-black text-sm tracking-[0.15em] uppercase flex items-center justify-center gap-3 shadow-[0_10px_30px_rgba(59,130,246,0.3)] btn-3d relative overflow-hidden group">
        <i class="fa-solid fa-play bg-white/20 p-2 rounded-full text-[10px]"></i> 
        <span>Watch Ad <span class="text-cyan-200">+10 XP & $0.0015</span></span>
      </button>
    </div>

    <!-- TASKS VIEW -->
    <div id="view-tasks" class="view-section hidden fade-in space-y-6 pb-4">
      <div class="text-center pt-2">
        <h2 class="text-3xl font-black text-white tracking-tight drop-shadow-lg">Tasks</h2>
        <p class="text-xs text-crypto-glow mt-1 uppercase tracking-widest font-bold">Complete to Earn XP</p>
      </div>
      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-4 mb-3">Missions</h3>
        <div class="space-y-3">
          <!-- Tasks list without share button[span_1](start_span)[span_1](end_span) -->
          <div class="glass-card rounded-2xl p-3 flex justify-between items-center transition-transform hover:-translate-y-0.5 border border-slate-800">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl border bg-blue-500/10 border-blue-500/20 flex items-center justify-center">
                 <i class="fa-brands fa-telegram text-blue-400 text-lg"></i>
              </div>
              <div class="flex flex-col">
                <span class="text-xs font-black text-white tracking-wide">Join Point Play Channel</span>
                <span class="text-crypto-glow text-[10px] font-bold tracking-widest uppercase opacity-80 mt-0.5">Community</span>
              </div>
            </div>
            <button onclick="claimTask('join_tg', 250)" class="text-[10px] font-black bg-gradient-to-r from-blue-600 to-cyan-500 text-white px-4 py-1.5 rounded-xl shadow-[0_0_15px_rgba(0,240,255,0.4)] active:scale-95 transition-all uppercase tracking-wider">Join & Claim</button>
          </div>

          <div class="glass-card rounded-2xl p-3 flex justify-between items-center transition-transform hover:-translate-y-0.5 border border-slate-800">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl border bg-purple-500/10 border-purple-500/20 flex items-center justify-center">
                 <i class="fa-brands fa-x-twitter text-purple-400 text-lg"></i>
              </div>
              <div class="flex flex-col">
                <span class="text-xs font-black text-white tracking-wide">Follow on X</span>
                <span class="text-crypto-glow text-[10px] font-bold tracking-widest uppercase opacity-80 mt-0.5">Social</span>
              </div>
            </div>
            <button onclick="claimTask('follow_x', 200)" class="text-[10px] font-black bg-gradient-to-r from-blue-600 to-cyan-500 text-white px-4 py-1.5 rounded-xl shadow-[0_0_15px_rgba(0,240,255,0.4)] active:scale-95 transition-all uppercase tracking-wider">Follow & Claim</button>
          </div>
        </div>
      </div>
    </div>

    <!-- REFERRALS VIEW -->
    <div id="view-referrals" class="view-section hidden fade-in space-y-5 pb-4">
      <div class="text-center pt-2 flex justify-between items-center mb-6">
        <h2 class="text-3xl font-black text-white tracking-tight drop-shadow-lg mx-auto">Referrals</h2>
        <button onclick="showReferralInfo()" class="absolute right-4 w-8 h-8 rounded-full bg-blue-500/20 border border-blue-500/40 text-blue-400 flex items-center justify-center hover:bg-blue-500/40 transition-colors">
          <i class="fa-solid fa-info"></i>
        </button>
      </div>

      <div class="grid grid-cols-3 gap-2">
        <div class="glass-card p-3 rounded-2xl text-center border-t border-t-blue-500/30">
          <p class="text-[9px] text-slate-400 uppercase font-black tracking-widest mb-1">Total</p>
          <p id="ref-total" class="text-lg font-black text-white">0</p>
        </div>
        <div class="glass-card p-3 rounded-2xl text-center border-t border-t-amber-500/30">
          <p class="text-[9px] text-slate-400 uppercase font-black tracking-widest mb-1">Pending</p>
          <p id="ref-pending" class="text-lg font-black text-amber-400">0</p>
        </div>
        <div class="glass-card p-3 rounded-2xl text-center border-t border-t-emerald-500/30">
          <p class="text-[9px] text-slate-400 uppercase font-black tracking-widest mb-1">Approved</p>
          <p id="ref-approved" class="text-lg font-black text-emerald-400">0</p>
        </div>
      </div>

      <div class="glass-card rounded-[1.5rem] p-5 border border-slate-700/50 relative">
        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">Your Referral Link</p>
        <div class="flex gap-2">
          <input type="text" id="ref-link-input" readonly class="w-full bg-[#050511]/50 border-2 border-slate-700/50 rounded-xl py-3 px-4 text-xs font-medium text-slate-300 focus:outline-none">
          <button onclick="copyReferralLink()" class="bg-slate-700 hover:bg-slate-600 text-white px-4 rounded-xl transition-colors shrink-0">
            <i class="fa-solid fa-copy"></i>
          </button>
        </div>
        <button onclick="shareOnTelegram()" class="w-full py-3 mt-3 bg-gradient-to-r from-blue-600 to-blue-500 text-white font-black rounded-xl text-xs uppercase tracking-widest flex items-center justify-center gap-2 shadow-[0_5px_15px_rgba(59,130,246,0.3)] active:scale-95 transition-all">
          <i class="fa-brands fa-telegram text-lg"></i> Share on Telegram
        </button>
      </div>

      <!-- Referral Tabs -->
      <div class="flex bg-[#050511] rounded-xl p-1 border border-slate-800">
        <button onclick="switchRefTab('list')" id="tab-ref-list" class="flex-1 py-2.5 rounded-lg text-sm font-black transition-all duration-300 bg-gradient-to-r from-blue-600 to-cyan-500 text-white shadow-[0_0_15px_rgba(0,240,255,0.3)]">My Referrals</button>
        <button onclick="switchRefTab('history')" id="tab-ref-history" class="flex-1 py-2.5 rounded-lg text-sm font-black transition-all duration-300 text-slate-500 hover:text-white">Reward History</button>
      </div>

      <div id="ref-content-list" class="space-y-3"></div>
      <div id="ref-content-history" class="space-y-3 hidden"></div>
    </div>

    <!-- BOXES VIEW -->
    <div id="view-boxes" class="view-section hidden fade-in space-y-5 pb-4">
      <div class="text-center pt-2 mb-6">
        <h2 class="text-3xl font-black text-white tracking-tight drop-shadow-lg">Boxes</h2>
        <p class="text-xs text-amber-400 mt-1 uppercase tracking-widest font-bold">Try Your Luck, Win Dollars</p>
      </div>
      
      <div class="box-bronze glass-card rounded-[1.5rem] p-5 relative overflow-hidden flex justify-between items-center transition-transform hover:scale-[1.02]">
        <div class="flex items-center gap-4 relative z-10">
          <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-orange-900 to-[#050511] border border-crypto-bronze flex items-center justify-center shadow-[0_0_15px_rgba(205,127,50,0.3)]">
            <i class="fa-solid fa-box text-2xl text-crypto-bronze"></i>
          </div>
          <div>
            <h3 class="text-lg font-black text-white tracking-wide">Bronze Box</h3>
            <div class="flex flex-col mt-0.5">
              <span class="text-[10px] text-slate-400 font-bold tracking-wider uppercase"><i class="fa-solid fa-bolt text-crypto-glow mr-1"></i> 10,000 XP</span>
              <span class="text-[11px] text-emerald-400 font-bold mt-0.5">Maximum: $1.00</span>
            </div>
          </div>
        </div>
        <button onclick="openBox('bronze')" class="relative z-10 bg-gradient-to-b from-orange-600 to-orange-800 text-white shadow-[0_4px_15px_rgba(205,127,50,0.4)] hover:brightness-110 active:scale-95 transition-all px-4 py-2 rounded-xl text-xs font-black uppercase tracking-wider">Open</button>
      </div>

      <div class="box-silver glass-card rounded-[1.5rem] p-5 relative overflow-hidden flex justify-between items-center transition-transform hover:scale-[1.02]">
        <div class="flex items-center gap-4 relative z-10">
          <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-slate-600 to-[#050511] border border-crypto-silver flex items-center justify-center shadow-[0_0_15px_rgba(226,232,240,0.2)]">
            <i class="fa-solid fa-box-open text-2xl text-crypto-silver"></i>
          </div>
          <div>
            <h3 class="text-lg font-black text-white tracking-wide">Silver Box</h3>
            <div class="flex flex-col mt-0.5">
              <span class="text-[10px] text-slate-400 font-bold tracking-wider uppercase"><i class="fa-solid fa-bolt text-crypto-glow mr-1"></i> 50,000 XP</span>
              <span class="text-[11px] text-emerald-400 font-bold mt-0.5">Maximum: $7.00</span>
            </div>
          </div>
        </div>
        <button onclick="openBox('silver')" class="relative z-10 bg-gradient-to-b from-slate-400 to-slate-600 text-crypto-dark shadow-[0_4px_15px_rgba(226,232,240,0.3)] hover:brightness-110 active:scale-95 transition-all px-4 py-2 rounded-xl text-xs font-black uppercase tracking-wider">Open</button>
      </div>

      <div class="box-gold glass-card rounded-[1.5rem] p-5 relative overflow-hidden flex justify-between items-center border border-crypto-gold shadow-[0_0_20px_rgba(255,184,0,0.15)] transition-transform hover:scale-[1.02]">
        <div class="flex items-center gap-4 relative z-10">
          <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-500 to-[#050511] border border-crypto-gold flex items-center justify-center shadow-[0_0_25px_rgba(255,184,0,0.5)]">
            <i class="fa-solid fa-gem text-2xl text-crypto-gold drop-shadow-[0_0_10px_#ffb800]"></i>
          </div>
          <div>
            <h3 class="text-lg font-black text-crypto-gold tracking-wide drop-shadow-[0_0_5px_rgba(255,184,0,0.5)]">Gold Box</h3>
            <div class="flex flex-col mt-0.5">
              <span class="text-[10px] text-slate-400 font-bold tracking-wider uppercase"><i class="fa-solid fa-bolt text-crypto-glow mr-1"></i> 100,000 XP</span>
              <span class="text-[11px] text-emerald-400 font-bold mt-0.5">Maximum: $15.00</span>
            </div>
          </div>
        </div>
        <button onclick="openBox('gold')" class="relative z-10 bg-gradient-to-b from-yellow-400 to-amber-600 text-crypto-dark shadow-[0_4px_20px_rgba(255,184,0,0.6)] hover:brightness-110 active:scale-95 transition-all px-4 py-2 rounded-xl text-xs font-black uppercase tracking-wider">Open</button>
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
            <input type="number" id="withdraw-amount" placeholder="10" min="10" step="1" class="w-full bg-[#050511]/50 border-2 border-slate-700/50 rounded-xl py-3.5 pl-12 pr-4 text-sm font-bold text-emerald-400 focus:outline-none focus:border-emerald-500 transition-colors placeholder-slate-600 shadow-inner">
          </div>
        </div>

        <button onclick="requestWithdraw()" id="withdraw-btn" class="w-full py-3.5 mt-2 bg-gradient-to-r from-emerald-600 to-teal-500 hover:brightness-110 active:scale-95 transition-all text-white font-black rounded-xl text-sm uppercase tracking-[0.15em] flex items-center justify-center gap-2 shadow-[0_10px_20px_rgba(16,185,129,0.3)]">
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
          <p id="profile-total-xp" class="text-xl font-black text-white">0</p>
        </div>
        <div class="glass-card p-5 rounded-2xl text-center border-t-2 border-t-amber-500/30">
          <div class="w-10 h-10 mx-auto bg-amber-500/10 border border-amber-500/20 text-amber-400 rounded-xl flex items-center justify-center mb-2 text-lg shadow-[0_0_15px_rgba(245,158,11,0.2)inset]"><i class="fa-solid fa-box-open"></i></div>
          <p class="text-[9px] text-slate-400 uppercase font-black tracking-[0.15em] mb-1">Boxes Opened</p>
          <p id="profile-boxes" class="text-xl font-black text-white">0</p>
        </div>
      </div>
    </div>

  </main>

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
        <span class="text-[7.5px] font-black uppercase tracking-widest">Ref</span>
      </button>
      <button onclick="switchTab('boxes')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all duration-300 relative group" data-target="boxes">
        <i class="fa-solid fa-box-open text-lg transition-transform group-active:scale-90"></i>
        <span class="text-[7.5px] font-black uppercase tracking-widest">Box</span>
      </button>
      <button onclick="switchTab('withdraw')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all duration-300 relative group" data-target="withdraw">
        <i class="fa-solid fa-wallet text-lg transition-transform group-active:scale-90"></i>
        <span class="text-[7.5px] font-black uppercase tracking-widest">Withdraw</span>
      </button>
      <button onclick="switchTab('profile')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all duration-300 relative group" data-target="profile">
        <i class="fa-solid fa-user-astronaut text-lg transition-transform group-active:scale-90"></i>
        <span class="text-[7.5px] font-black uppercase tracking-widest">Profile</span>
      </button>
    </div>
  </nav>

  <!-- Referral Info Modal -->
  <div id="modal-ref-info" class="fixed inset-0 z-[100000] hidden flex items-center justify-center p-4 modal-overlay fade-in">
    <div class="glass-card rounded-[2rem] p-6 max-w-sm w-full border border-blue-500/30 relative">
      <button onclick="closeReferralInfo()" class="absolute top-4 right-4 w-8 h-8 rounded-full bg-slate-800 text-slate-400 flex items-center justify-center">
        <i class="fa-solid fa-xmark"></i>
      </button>
      <div class="w-14 h-14 bg-blue-500/10 rounded-full flex items-center justify-center mb-4 border border-blue-500/30 mx-auto text-blue-400 text-2xl">
        <i class="fa-solid fa-users"></i>
      </div>
      <h3 class="text-xl font-black text-white text-center mb-4">Referral Rules</h3>
      <p class="text-sm text-slate-300 mb-4 text-center">To approve a referral, the invited user must:</p>
      <ul class="text-sm font-bold text-slate-300 space-y-2 mb-6 bg-[#050511]/50 p-4 rounded-xl border border-slate-700">
        <li><i class="fa-solid fa-video text-blue-400 w-5"></i> Watch 25 ads</li>
        <li><i class="fa-solid fa-list-check text-amber-400 w-5"></i> Complete 5 tasks</li>
      </ul>
      <p class="text-xs text-slate-400 text-center mb-4">There is no time limit. The referral remains pending until all requirements are completed.</p>
      <div class="bg-gradient-to-r from-emerald-600/20 to-teal-500/20 border border-emerald-500/30 p-3 rounded-xl text-center">
        <p class="text-[10px] text-emerald-400 uppercase font-black tracking-widest mb-1">Approved Referral Reward</p>
        <p class="text-lg font-black text-white">250 XP + $0.025</p>
      </div>
    </div>
  </div>

  <script>
    const tg = window.Telegram.WebApp;
    tg.expand();
    tg.ready();
    tg.setHeaderColor('#0a0b1a');
    tg.setBackgroundColor('#050511');

    const tgUser = tg.initDataUnsafe?.user || { id: 123456789, first_name: "Test User", username: "testuser" };
    const uid = tgUser.id.toString();
    const startappUid = tg.initDataUnsafe?.start_param || null;
    
    let isRequesting = false;

    // Show Toast
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
        iconHtml = '<i class="fa-solid fa-bell animate-pulse"></i>';
        iconClass = 'bg-blue-500/20 text-crypto-glow border border-crypto-glow/50';
      }

      icon.innerHTML = iconHtml;
      icon.className = `w-12 h-12 rounded-xl flex shrink-0 items-center justify-center text-xl ${iconClass}`;
      toast.classList.add('toast-show');
      
      if (tg.HapticFeedback) {
        if (type === 'success') tg.HapticFeedback.notificationOccurred('success');
        else if (type === 'error') tg.HapticFeedback.notificationOccurred('error');
      }

      setTimeout(() => { toast.classList.remove('toast-show'); }, 3000); 
    }

    // API Call wrapper
    async function apiCall(action, payload = {}) {
        if (isRequesting) return null;
        isRequesting = true;
        
        payload.uid = uid;
        try {
            const res = await fetch(`index.php?action=${action}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            isRequesting = false;
            return data;
        } catch(e) {
            isRequesting = false;
            showToast("Error", "Server connection failed.", "error");
            return null;
        }
    }

    // Sync Data
    async function syncUser() {
        const data = await apiCall('sync', {
            first_name: tgUser.first_name,
            username: tgUser.username,
            startapp_uid: startappUid
        });
        
        if (data && data.success) {
            updateUI(data.user);
            renderReferrals(data.user, data.referrals_detailed, data.referral_history);
            renderWithdrawals(data.withdrawals);
            document.getElementById('loading-overlay').style.opacity = '0';
            setTimeout(() => document.getElementById('loading-overlay').style.display = 'none', 500);
        }
    }

    function updateUI(user) {
        document.getElementById('user-name').innerText = user.first_name;
        document.getElementById('user-xp').innerHTML = `${user.xp.toLocaleString()} <span class="text-[10px] text-crypto-glow font-bold">XP</span>`;
        document.getElementById('user-usd').innerText = user.balance_usd.toFixed(4);
        
        document.getElementById('main-xp-display').innerText = `${user.xp.toLocaleString()} XP`;
        document.getElementById('ads-watched').innerText = user.ads_watched;
        document.getElementById('tasks-completed').innerText = user.tasks_completed;
        
        document.getElementById('withdraw-balance-display').innerText = user.balance_usd.toFixed(2);
        
        document.getElementById('profile-name').innerText = user.first_name;
        document.getElementById('profile-tgid').innerText = `ID: ${user.uid}`;
        document.getElementById('profile-total-xp').innerText = user.total_xp.toLocaleString();
        document.getElementById('profile-boxes').innerText = user.boxes_opened;
        
        if (tgUser.photo_url) {
            document.getElementById('user-photo').src = tgUser.photo_url;
            document.getElementById('profile-photo-large').src = tgUser.photo_url;
        }

        const refLink = `https://t.me/pointplayappbot?startapp=${user.uid}`;
        document.getElementById('ref-link-input').value = refLink;
    }

    function renderReferrals(user, list, history) {
        document.getElementById('ref-total').innerText = user.referrals_count;
        document.getElementById('ref-pending').innerText = user.pending_referrals;
        document.getElementById('ref-approved').innerText = user.approved_referrals;

        const listContainer = document.getElementById('ref-content-list');
        listContainer.innerHTML = '';
        if(list.length === 0) {
            listContainer.innerHTML = `<div class="text-center p-4 text-slate-500 text-sm">No referrals yet.</div>`;
        } else {
            list.forEach(ref => {
                const isApproved = ref.status === 'Approved';
                const statusColor = isApproved ? 'text-emerald-400 bg-emerald-500/10 border-emerald-500/30' : 'text-amber-400 bg-amber-500/10 border-amber-500/30';
                listContainer.innerHTML += `
                    <div class="glass-card rounded-2xl p-4 flex justify-between items-center border border-slate-800">
                        <div>
                            <p class="text-sm font-black text-white">${ref.first_name} <span class="text-[10px] text-slate-500 ml-1">ID: ${ref.uid}</span></p>
                            <div class="flex gap-3 mt-1">
                                <span class="text-[10px] text-slate-400"><i class="fa-solid fa-video text-blue-400"></i> ${ref.ads} / 25</span>
                                <span class="text-[10px] text-slate-400"><i class="fa-solid fa-list-check text-amber-400"></i> ${ref.tasks} / 5</span>
                            </div>
                        </div>
                        <span class="text-[9px] font-black uppercase px-2 py-1 rounded-lg border ${statusColor}">${ref.status}</span>
                    </div>
                `;
            });
        }

        const histContainer = document.getElementById('ref-content-history');
        histContainer.innerHTML = '';
        if(history.length === 0) {
            histContainer.innerHTML = `<div class="text-center p-4 text-slate-500 text-sm">No rewards history.</div>`;
        } else {
            history.reverse().forEach(h => {
                histContainer.innerHTML += `
                    <div class="glass-card rounded-2xl p-3 flex justify-between items-center border border-slate-800">
                        <div>
                            <p class="text-xs font-black text-white">${h.type} <span class="text-[9px] font-normal text-slate-400 ml-1">(${h.ref_name})</span></p>
                            <p class="text-[9px] text-slate-500 mt-0.5">${h.date}</p>
                        </div>
                        <span class="text-xs font-black text-emerald-400">${h.reward}</span>
                    </div>
                `;
            });
        }
    }

    function renderWithdrawals(withdrawals) {
        const container = document.getElementById('withdraw-history-container');
        if (withdrawals.length === 0) {
            container.innerHTML = `
              <div class="glass-card rounded-2xl p-6 text-center border-dashed border-2 border-slate-700">
                <i class="fa-solid fa-clock-rotate-left text-3xl text-slate-600 mb-2"></i>
                <p class="text-xs font-bold text-slate-500 uppercase tracking-widest">History is Empty</p>
              </div>`;
            return;
        }
        container.innerHTML = '';
        withdrawals.forEach(record => {
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
                  <p class="text-sm font-black text-emerald-400">-$${record.amount.toFixed(2)}</p>
                  <p class="text-[9px] font-black text-amber-400 uppercase tracking-widest mt-0.5 bg-amber-500/10 inline-block px-2 py-0.5 rounded-full border border-amber-500/20">${record.status}</p>
                </div>
              </div>
            `;
        });
    }

    // Actions
    async function watchAd() {
        const btn = document.getElementById('watch-ad-btn');
        const originalHTML = btn.innerHTML;
        btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> <span>Loading Ad...</span>`;
        btn.classList.add('opacity-80', 'pointer-events-none');

        if (window.Adsgram) {
            const AdController = window.Adsgram.init({ blockId: "int-35545" });
            AdController.show().then(async () => {
                await apiCall('watch_ad');
                showToast("Reward Granted!", "You earned +10 XP and $0.0015.", "success");
                syncUser();
                resetBtn();
            }).catch(() => { resetBtn(); });
        } else {
            showToast("Error", "Ad system is currently offline.", "error");
            resetBtn();
        }
        
        function resetBtn() {
            btn.innerHTML = originalHTML;
            btn.classList.remove('opacity-80', 'pointer-events-none');
        }
    }

    async function claimTask(taskId, rewardXp) {
        if(confirm("Are you sure you completed this task?")) {
            const res = await apiCall('complete_task', { task_id: taskId, reward_xp: rewardXp });
            if(res && res.success) {
                showToast("Task Completed!", `You earned +${rewardXp} XP!`, "success");
                syncUser();
            }
        }
    }

    async function openBox(type) {
        const res = await apiCall('open_box', { type: type });
        if(res && res.success) {
            if (res.isJackpot) {
                showToast("HUGE JACKPOT! 💸", `Incredible! You won $${res.reward.toFixed(2)}!`, "success");
            } else {
                showToast("Box Opened!", `Congratulations! You won $${res.reward.toFixed(2)}!`, "success");
            }
            syncUser();
        } else if(res && res.error) {
            showToast("Error", res.error, "error");
        } else {
            showToast("Not enough XP", "You need more XP to open this box.", "error");
        }
    }

    async function requestWithdraw() {
        const address = document.getElementById('wallet-address').value;
        const amount = parseFloat(document.getElementById('withdraw-amount').value);

        if (!address || address.length < 10) {
            showToast("Invalid Address", "Please enter a valid TON wallet address.", "error");
            return;
        }

        if (isNaN(amount) || amount < 10) {
            showToast("Invalid Amount", "Minimum withdrawal is $10.", "error");
            return;
        }

        const res = await apiCall('withdraw', { amount: amount, address: address });
        if (res && res.success) {
            showToast("Withdrawal Requested", `Your request for $${amount.toFixed(2)} has been submitted.`, "success");
            document.getElementById('wallet-address').value = '';
            document.getElementById('withdraw-amount').value = '';
            syncUser();
        } else {
            showToast("Error", res?.error || "Insufficient balance.", "error");
        }
    }

    // Referrals Functions
    function copyReferralLink() {
        const link = document.getElementById('ref-link-input').value;
        navigator.clipboard.writeText(link).then(() => {
            showToast("Copied", "Referral link copied to clipboard!", "success");
        });
    }

    function shareOnTelegram() {
        const link = document.getElementById('ref-link-input').value;
        const text = `🎯 Play games, complete tasks, earn XP, and collect exciting rewards 🚀 I’m already playing on Point Play now it’s your turn to join the adventure👇\n\n${link}`;
        const shareUrl = `https://t.me/share/url?url=&text=${encodeURIComponent(text)}`;
        tg.openTelegramLink(shareUrl);
    }

    function showReferralInfo() { document.getElementById('modal-ref-info').classList.remove('hidden'); }
    function closeReferralInfo() { document.getElementById('modal-ref-info').classList.add('hidden'); }

    function switchRefTab(tab) {
        document.getElementById('tab-ref-list').className = "flex-1 py-2.5 rounded-lg text-sm font-black transition-all duration-300 text-slate-500 hover:text-white";
        document.getElementById('tab-ref-history').className = "flex-1 py-2.5 rounded-lg text-sm font-black transition-all duration-300 text-slate-500 hover:text-white";
        
        const activeClass = "bg-gradient-to-r from-blue-600 to-cyan-500 text-white shadow-[0_0_15px_rgba(0,240,255,0.3)]";
        document.getElementById(`tab-ref-${tab}`).className = `flex-1 py-2.5 rounded-lg text-sm font-black transition-all duration-300 ${activeClass}`;

        if(tab === 'list') {
            document.getElementById('ref-content-list').classList.remove('hidden');
            document.getElementById('ref-content-history').classList.add('hidden');
        } else {
            document.getElementById('ref-content-list').classList.add('hidden');
            document.getElementById('ref-content-history').classList.remove('hidden');
        }
    }

    // Navigation Logic
    function switchTab(tabId) {
        document.querySelectorAll('.view-section').forEach(el => el.classList.add('hidden'));
        document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('nav-active'));
        document.getElementById(`view-${tabId}`).classList.remove('hidden');
        document.querySelector(`[data-target="${tabId}"]`).classList.add('nav-active');
        
        if (tg.HapticFeedback) tg.HapticFeedback.selectionChanged();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // Initialize
    syncUser();

  </script>
</body>
</html>
