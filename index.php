<?php
// POINT PLAY - SINGLE FILE ARCHITECTURE
// All server-side logic and persistence is handled here.

error_reporting(0); // Suppress errors for clean JSON API responses in production
$dataDir = __DIR__ . '/data';

// Create data directory if it doesn't exist
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

// Helper: Safe read JSON with shared lock
function readDB($filename) {
    global $dataDir;
    $path = "$dataDir/$filename";
    if (!file_exists($path)) return [];
    
    $fp = fopen($path, 'r');
    if (!$fp) return [];
    flock($fp, LOCK_SH);
    $size = filesize($path);
    $json = $size > 0 ? fread($fp, $size) : '[]';
    flock($fp, LOCK_UN);
    fclose($fp);
    
    return json_decode($json, true) ?: [];
}

// Helper: Safe write JSON with exclusive lock
function writeDB($filename, $data) {
    global $dataDir;
    $path = "$dataDir/$filename";
    $fp = fopen($path, 'c');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

// Handle API requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['action']) || !isset($input['tgId'])) {
        echo json_encode(['error' => 'Invalid request']);
        exit;
    }

    $action = $input['action'];
    $uid = (string)$input['tgId'];
    $today = date('Y-m-d');
    
    $users = readDB('users.json');
    $referrals = readDB('referrals.json');
    $rewards = readDB('rewards.json');
    $withdrawals = readDB('withdrawals.json');
    $tasks = readDB('tasks.json'); // Daily tasks tracking
    
    // 1. User Initialization & Restoration
    if (!isset($users[$uid])) {
        // Create new user
        $users[$uid] = [
            'tgId' => $uid,
            'firstName' => $input['firstName'] ?? 'User',
            'username' => $input['username'] ?? '',
            'photoUrl' => $input['photoUrl'] ?? '',
            'xp' => 0,
            'totalXp' => 0,
            'usd' => 0.00,
            'level' => 1,
            'adsWatchedToday' => 0,
            'totalAdsWatched' => 0,
            'tasksCompleted' => 0,
            'boxesOpened' => 0,
            'streak' => 1,
            'lastResetDay' => $today,
            'referrer' => null
        ];

        // Process referral joining
        if (!empty($input['referrer']) && $input['referrer'] !== $uid) {
            $refId = (string)$input['referrer'];
            if (isset($users[$refId])) {
                $users[$uid]['referrer'] = $refId;
                if (!isset($referrals[$refId])) $referrals[$refId] = [];
                
                // Add to referrer's list
                $referrals[$refId][] = [
                    'uid' => $uid,
                    'name' => $users[$uid]['firstName'],
                    'username' => $users[$uid]['username'],
                    'status' => 'Pending',
                    'ads' => 0,
                    'tasks' => 0,
                    'joinDate' => date('M j, Y')
                ];
                writeDB('referrals.json', $referrals);
            }
        }
    }

    // Daily Reset Logic
    if ($users[$uid]['lastResetDay'] !== $today) {
        $lastDay = strtotime($users[$uid]['lastResetDay']);
        $currDay = strtotime($today);
        $diff = round(($currDay - $lastDay) / 86400);
        
        if ($diff === 1) {
            $users[$uid]['streak'] = min($users[$uid]['streak'] + 1, 7);
        } else {
            $users[$uid]['streak'] = 1;
        }
        
        $users[$uid]['adsWatchedToday'] = 0;
        $users[$uid]['lastResetDay'] = $today;
        
        // Reset daily tasks
        if(isset($tasks[$uid])) {
            $tasks[$uid] = [];
        }
    }

    // Helper: Level Calculation
    function calcLevel($xp) {
        if ($xp >= 20000) return 10;
        if ($xp >= 3000) return 5;
        if ($xp >= 1500) return 4;
        if ($xp >= 750) return 3;
        if ($xp >= 250) return 2;
        return 1;
    }

    // Helper: Evaluate Referral Approval
    function evaluateReferralProgress($refUid, &$users, &$referrals, &$rewards) {
        $refUser = $users[$refUid];
        if (empty($refUser['referrer'])) return;
        
        $referrerId = $refUser['referrer'];
        if (!isset($referrals[$referrerId])) return;
        
        $changed = false;
        foreach ($referrals[$referrerId] as &$r) {
            if ($r['uid'] === $refUid && $r['status'] === 'Pending') {
                $r['ads'] = $refUser['totalAdsWatched'];
                $r['tasks'] = $refUser['tasksCompleted'];
                
                if ($r['ads'] >= 25 && $r['tasks'] >= 5) {
                    $r['status'] = 'Approved';
                    $r['approvedAt'] = date('M j, Y');
                    $changed = true;
                    
                    // Grant 250 XP and $0.025 USD to referrer exactly once
                    $users[$referrerId]['xp'] += 250;
                    $users[$referrerId]['totalXp'] += 250;
                    $users[$referrerId]['level'] = calcLevel($users[$referrerId]['totalXp']);
                    $users[$referrerId]['usd'] += 0.025;
                    
                    // Log Reward
                    if (!isset($rewards[$referrerId])) $rewards[$referrerId] = [];
                    array_unshift($rewards[$referrerId], [
                        'title' => 'Referral Bonus',
                        'desc' => "Referral: " . $refUser['firstName'],
                        'xp' => 250,
                        'usd' => 0.025,
                        'date' => date('M j, Y')
                    ]);
                }
                break;
            }
        }
        if ($changed) {
            writeDB('referrals.json', $referrals);
            writeDB('rewards.json', $rewards);
        }
    }

    // Process Specific Actions
    $response = ['success' => true];

    switch ($action) {
        case 'watch_ad':
            if ($users[$uid]['adsWatchedToday'] < 30) {
                $users[$uid]['adsWatchedToday'] += 1;
                $users[$uid]['totalAdsWatched'] += 1;
                $users[$uid]['xp'] += 20;
                $users[$uid]['totalXp'] += 20;
                $users[$uid]['level'] = calcLevel($users[$uid]['totalXp']);
                evaluateReferralProgress($uid, $users, $referrals, $rewards);
            } else {
                $response['error'] = 'Ad limit reached';
            }
            break;

        case 'claim_task':
            $taskId = $input['taskId'] ?? '';
            if (!isset($tasks[$uid])) $tasks[$uid] = [];
            
            if (!in_array($taskId, $tasks[$uid])) {
                $rewardXp = (int)($input['reward'] ?? 0);
                if ($rewardXp > 0 && $rewardXp <= 100) { // basic security cap
                    $tasks[$uid][] = $taskId;
                    $users[$uid]['tasksCompleted'] += 1;
                    $users[$uid]['xp'] += $rewardXp;
                    $users[$uid]['totalXp'] += $rewardXp;
                    $users[$uid]['level'] = calcLevel($users[$uid]['totalXp']);
                    evaluateReferralProgress($uid, $users, $referrals, $rewards);
                    writeDB('tasks.json', $tasks);
                }
            }
            break;

        case 'open_box':
            $type = $input['boxType'] ?? '';
            $costs = ['bronze' => 10000, 'silver' => 50000, 'gold' => 100000];
            
            if (isset($costs[$type]) && $users[$uid]['xp'] >= $costs[$type]) {
                $users[$uid]['xp'] -= $costs[$type];
                $users[$uid]['boxesOpened'] += 1;
                
                $isJackpot = (rand(1, 10000) === 1); 
                $rewardUsd = 0;
                
                if ($type === 'bronze') $rewardUsd = $isJackpot ? 1.00 : 0.10;
                if ($type === 'silver') $rewardUsd = $isJackpot ? 7.00 : 0.50;
                if ($type === 'gold') $rewardUsd = $isJackpot ? 15.00 : 1.00;
                
                $users[$uid]['usd'] += $rewardUsd;
                $response['reward'] = $rewardUsd;
                $response['jackpot'] = $isJackpot;
            } else {
                $response['error'] = 'Not enough XP';
            }
            break;

        case 'withdraw':
            $amount = (float)($input['amount'] ?? 0);
            $address = $input['address'] ?? '';
            
            // Minimum withdrawal validation -> EXACTLY 10 USD
            if ($amount >= 10 && $amount <= $users[$uid]['usd'] && strlen($address) > 5) {
                $users[$uid]['usd'] -= $amount;
                
                if (!isset($withdrawals[$uid])) $withdrawals[$uid] = [];
                array_unshift($withdrawals[$uid], [
                    'id' => '#' . strtoupper(substr(md5(uniqid()), 0, 6)),
                    'amount' => $amount,
                    'address' => substr($address, 0, 6) . '...' . substr($address, -4),
                    'date' => date('M j, Y'),
                    'status' => 'Pending'
                ]);
                writeDB('withdrawals.json', $withdrawals);
            } else {
                $response['error'] = 'Invalid withdrawal request';
            }
            break;
            
        case 'game_level_clear':
            // Add fixed reward for clearing a level
            $rewardXp = 50; 
            $users[$uid]['xp'] += $rewardXp;
            $users[$uid]['totalXp'] += $rewardXp;
            $users[$uid]['level'] = calcLevel($users[$uid]['totalXp']);
            break;
    }

    // Save user state
    writeDB('users.json', $users);
    
    // Compile full updated state for client
    $response['user'] = $users[$uid];
    $response['referrals'] = $referrals[$uid] ?? [];
    $response['rewards'] = $rewards[$uid] ?? [];
    $response['withdrawals'] = $withdrawals[$uid] ?? [];
    $response['tasks'] = $tasks[$uid] ?? [];
    
    echo json_encode($response);
    exit;
}

// -----------------------------------------------------------------------------------------
// FRONTEND - HTML / JS / CSS (Served directly from index.php)
// -----------------------------------------------------------------------------------------
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
            crypto: {
              dark: '#050511',     
              card: '#0a0b1a',     
              primary: '#3b82f6',  
              glow: '#00f0ff',     
              gold: '#ffb800',     
              silver: '#e2e8f0',   
              bronze: '#cd7f32'    
            }
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
            floatUp: {
              '0%': { transform: 'translateY(0) scale(1)', opacity: 1 },
              '100%': { transform: 'translateY(-100px) scale(0.5)', opacity: 0 }
            },
            pop: {
              '0%': { transform: 'scale(1)' },
              '50%': { transform: 'scale(1.3)' },
              '100%': { transform: 'scale(0)', opacity: 0 }
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
    body.in-game {
      overflow: hidden !important;
      touch-action: none !important;
      position: fixed;
      inset: 0;
      width: 100vw;
      height: 100vh;
    }
    body.in-game #main-header,
    body.in-game #bottom-nav { display: none !important; }
    body.in-game #app-content { padding: 0 !important; margin: 0 !important; }

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

    #bottom-nav { transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s ease-in-out; }
    .nav-hidden {
      transition: none !important;
      transform: translate(-50%, 150px) !important;
      opacity: 0 !important;
      pointer-events: none !important;
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

    .box-bronze { background: linear-gradient(135deg, rgba(205,127,50,0.1), rgba(139,69,19,0.2)); border: 1px solid rgba(205,127,50,0.4); }
    .box-silver { background: linear-gradient(135deg, rgba(226,232,240,0.1), rgba(148,163,184,0.2)); border: 1px solid rgba(226,232,240,0.4); }
    .box-gold { background: linear-gradient(135deg, rgba(255,184,0,0.15), rgba(217,119,6,0.25)); border: 1px solid rgba(255,184,0,0.5); }

    /* Modal Styles */
    .modal-overlay {
      background: rgba(5, 5, 17, 0.85);
      backdrop-filter: blur(8px);
      z-index: 10000;
    }
  </style>
</head>
<body class="flex flex-col min-h-screen pb-32">
  
  <div class="bg-orb-1 animate-blob"></div>
  <div class="bg-orb-2 animate-blob animation-delay-2000"></div>

  <!-- Start Screen -->
  <div id="loading-overlay" class="bg-[#050511] flex flex-col items-center justify-center z-[100000] fixed inset-0 transition-opacity duration-500">
    <div class="relative w-24 h-24 mb-8">
      <div class="absolute inset-0 rounded-full border-t-4 border-crypto-glow animate-[spin_1s_linear_infinite] shadow-[0_0_20px_rgba(0,240,255,0.5)]"></div>
      <div class="absolute inset-3 rounded-full border-b-4 border-blue-500 animate-[spin_1.5s_linear_infinite_reverse]"></div>
      <div class="absolute inset-0 flex items-center justify-center">
        <i class="fa-solid fa-gamepad text-crypto-glow text-3xl animate-pulse"></i>
      </div>
    </div>
    <h2 class="text-white font-black tracking-[0.25em] text-2xl uppercase bg-clip-text text-transparent bg-gradient-to-r from-crypto-glow via-blue-400 to-indigo-500 mb-2 drop-shadow-lg">Point Play</h2>
    <div class="flex gap-1 mt-2">
      <div class="w-1.5 h-1.5 bg-crypto-glow rounded-full animate-bounce"></div>
      <div class="w-1.5 h-1.5 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 0.1s"></div>
      <div class="w-1.5 h-1.5 bg-indigo-500 rounded-full animate-bounce" style="animation-delay: 0.2s"></div>
    </div>
  </div>

  <!-- Notification Toast -->
  <div id="toast-container" class="glass-card rounded-2xl p-4 flex items-center gap-4">
    <div id="toast-icon" class="w-12 h-12 rounded-full flex shrink-0 items-center justify-center text-xl shadow-inner">
      <i class="fa-solid fa-bell"></i>
    </div>
    <div class="flex-1">
      <h4 id="toast-title" class="text-sm font-black text-white tracking-wide">Notification</h4>
      <p id="toast-message" class="text-xs text-slate-300 mt-0.5 leading-tight">Message goes here</p>
    </div>
  </div>

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
          <span id="user-usd" class="text-emerald-400 font-black text-xs tracking-wider">0.0000</span>
        </div>
      </div>
    </div>
  </header>

  <main class="flex-1 max-w-md w-full mx-auto p-4 pt-24 relative" id="app-content">
    
    <!-- HOME PAGE -->
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
              <p class="text-[9px] text-slate-500 uppercase font-bold tracking-wider">Daily Limit</p>
              <p class="text-sm font-black text-white"><span id="ads-watched" class="text-blue-400">0</span> / 30</p>
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

      <div class="glass-card rounded-xl p-3 flex justify-between items-center">
        <div class="flex items-center gap-2 text-slate-400 text-xs">
          <i class="fa-solid fa-rotate text-blue-400"></i> <span>Resets in:</span>
        </div>
        <span class="text-white font-mono font-bold tracking-widest" id="reset-timer">--:--:--</span>
      </div>
    </div>

    <!-- TASKS PAGE -->
    <div id="view-tasks" class="view-section hidden fade-in space-y-6 pb-4">
      <div class="text-center pt-2">
        <h2 class="text-3xl font-black text-white tracking-tight drop-shadow-lg">Tasks</h2>
        <p class="text-xs text-crypto-glow mt-1 uppercase tracking-widest font-bold">Complete to Earn XP</p>
      </div>
      
      <div class="glass-card rounded-3xl p-5 relative overflow-hidden border-t-2 border-t-blue-500/30">
        <div class="absolute -right-10 -top-10 w-32 h-32 bg-blue-600/20 rounded-full blur-2xl"></div>
        <h3 class="text-xs font-black text-white uppercase tracking-widest mb-5 flex items-center gap-2">
          <i class="fa-solid fa-calendar-check text-blue-400 text-lg"></i> <span>7-Day Streak</span>
        </h3>
        <div class="relative flex justify-between items-center" id="streak-tracker-container"></div>
      </div>

      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-4 mb-3">Daily Missions</h3>
        <div id="missions-container" class="space-y-3">
          <!-- Populated by JS -->
        </div>
      </div>
    </div>

    <!-- REFERRALS PAGE (NEW) -->
    <div id="view-referrals" class="view-section hidden fade-in space-y-6 pb-4">
      <div class="text-center pt-2 relative">
        <h2 class="text-3xl font-black text-white tracking-tight drop-shadow-lg">Referrals</h2>
        <p class="text-xs text-blue-400 mt-1 uppercase tracking-widest font-bold">Invite & Earn Rewards</p>
        <button onclick="toggleRefInfo()" class="absolute top-2 right-2 w-8 h-8 rounded-full bg-blue-500/20 border border-blue-500/50 flex items-center justify-center text-blue-400 active:scale-90 transition-transform">
          <i class="fa-solid fa-info text-sm"></i>
        </button>
      </div>

      <div class="grid grid-cols-3 gap-2">
        <div class="glass-card p-4 rounded-2xl text-center border-t-2 border-t-blue-500/30">
          <p class="text-[9px] text-slate-400 uppercase font-black tracking-widest mb-1">Total</p>
          <p id="ref-total" class="text-xl font-black text-white">0</p>
        </div>
        <div class="glass-card p-4 rounded-2xl text-center border-t-2 border-t-amber-500/30">
          <p class="text-[9px] text-slate-400 uppercase font-black tracking-widest mb-1">Pending</p>
          <p id="ref-pending" class="text-xl font-black text-amber-400">0</p>
        </div>
        <div class="glass-card p-4 rounded-2xl text-center border-t-2 border-t-emerald-500/30">
          <p class="text-[9px] text-slate-400 uppercase font-black tracking-widest mb-1">Approved</p>
          <p id="ref-approved" class="text-xl font-black text-emerald-400">0</p>
        </div>
      </div>

      <div class="glass-card rounded-[1.5rem] p-5 space-y-4">
        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase tracking-[0.15em] mb-2 ml-1">Your Referral Link</label>
          <div class="flex items-center gap-2">
            <input type="text" id="ref-link-input" readonly class="flex-1 bg-[#050511]/50 border border-slate-700/50 rounded-xl py-3 px-4 text-xs font-medium text-slate-300 focus:outline-none">
            <button onclick="copyRefLink()" class="bg-slate-800 text-white w-12 h-12 rounded-xl flex items-center justify-center active:scale-95 transition-transform border border-slate-700">
              <i class="fa-regular fa-copy"></i>
            </button>
          </div>
        </div>
        <button onclick="shareReferralTelegram()" class="w-full py-3.5 bg-gradient-to-r from-blue-600 to-cyan-500 text-white font-black rounded-xl text-sm uppercase tracking-wider flex items-center justify-center gap-2 shadow-[0_5px_15px_rgba(0,240,255,0.3)] active:scale-95 transition-transform">
          <i class="fa-brands fa-telegram text-lg"></i> Share on Telegram
        </button>
      </div>

      <!-- Referral List -->
      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-4 mb-3">Your Referrals</h3>
        <div id="referral-list-container" class="space-y-3">
          <!-- Populated dynamically -->
        </div>
      </div>

      <!-- Reward History -->
      <div class="mt-6">
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-4 mb-3 border-t border-slate-800 pt-4">Referral Reward History</h3>
        <div id="referral-rewards-container" class="space-y-3">
          <!-- Populated dynamically -->
        </div>
      </div>
    </div>

    <!-- BOXES PAGE -->
    <div id="view-boxes" class="view-section hidden fade-in space-y-5 pb-4">
      <div class="text-center pt-2 mb-6">
        <h2 class="text-3xl font-black text-white tracking-tight drop-shadow-lg">Boxes</h2>
        <p class="text-xs text-amber-400 mt-1 uppercase tracking-widest font-bold">Try Your Luck, Win Dollars</p>
      </div>
      
      <div class="box-bronze glass-card rounded-[1.5rem] p-5 relative overflow-hidden flex justify-between items-center transition-transform hover:scale-[1.02]">
        <div class="absolute -right-4 top-1/2 -translate-y-1/2 w-32 h-32 bg-crypto-bronze/10 rounded-full blur-2xl"></div>
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
        <div class="absolute -right-4 top-1/2 -translate-y-1/2 w-32 h-32 bg-crypto-silver/10 rounded-full blur-2xl"></div>
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
        <div class="absolute -right-4 top-1/2 -translate-y-1/2 w-32 h-32 bg-crypto-gold/20 rounded-full blur-xl animate-pulse"></div>
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

    <!-- WITHDRAW PAGE -->
    <div id="view-withdraw" class="view-section hidden fade-in space-y-6 pb-4">
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
            <input type="number" id="withdraw-amount" placeholder="10" min="10" step="0.5" class="w-full bg-[#050511]/50 border-2 border-slate-700/50 rounded-xl py-3.5 pl-12 pr-4 text-sm font-bold text-emerald-400 focus:outline-none focus:border-emerald-500 transition-colors placeholder-slate-600 shadow-inner">
          </div>
        </div>

        <button onclick="requestWithdrawal()" id="withdraw-btn" class="w-full py-3.5 mt-2 bg-gradient-to-r from-emerald-600 to-teal-500 hover:brightness-110 active:scale-95 transition-all text-white font-black rounded-xl text-sm uppercase tracking-[0.15em] flex items-center justify-center gap-2 shadow-[0_10px_20px_rgba(16,185,129,0.3)]">
          <i class="fa-solid fa-money-bill-transfer"></i> Request Withdrawal
        </button>
      </div>

      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-4 mb-3">Wallet History</h3>
        <div id="withdraw-history-container" class="space-y-3">
          <!-- Populated via JS -->
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
        <span class="text-[7.5px] font-black uppercase tracking-widest">Referrals</span>
      </button>
      <button onclick="switchTab('boxes')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all duration-300 relative group" data-target="boxes">
        <i class="fa-solid fa-box-open text-lg transition-transform group-active:scale-90"></i>
        <span class="text-[7.5px] font-black uppercase tracking-widest">Box</span>
      </button>
      <button onclick="switchTab('withdraw')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all duration-300 relative group" data-target="withdraw">
        <i class="fa-solid fa-wallet text-lg transition-transform group-active:scale-90"></i>
        <span class="text-[7.5px] font-black uppercase tracking-widest">Withdraw</span>
      </button>
    </div>
  </nav>

  <!-- Referral Information Modal -->
  <div id="ref-info-modal" class="fixed inset-0 modal-overlay hidden flex-col items-center justify-center p-4 transition-opacity fade-in">
    <div class="glass-card w-full max-w-sm rounded-[2rem] p-6 relative border border-blue-500/30 shadow-[0_0_40px_rgba(0,0,0,0.8)]">
      <button onclick="toggleRefInfo()" class="absolute top-4 right-4 w-8 h-8 rounded-full bg-slate-800 text-slate-400 flex items-center justify-center hover:text-white active:scale-90 transition-transform border border-slate-700">
        <i class="fa-solid fa-xmark"></i>
      </button>
      
      <div class="w-14 h-14 mx-auto bg-blue-500/10 rounded-full flex items-center justify-center mb-4 border border-blue-500/30 text-blue-400 text-2xl shadow-[0_0_20px_rgba(59,130,246,0.2)]">
        <i class="fa-solid fa-users"></i>
      </div>
      
      <h3 class="text-xl font-black text-white text-center mb-2 tracking-wide">Referral Rules</h3>
      <p class="text-xs text-slate-400 text-center mb-6 leading-relaxed">Invite your friends and earn rewards! A referral becomes <span class="text-emerald-400 font-bold">Approved</span> only when they complete the following requirements.</p>
      
      <ul class="space-y-3 mb-6">
        <li class="flex items-start gap-3 bg-[#050511]/50 p-3 rounded-xl border border-slate-800">
          <i class="fa-solid fa-play text-blue-400 mt-1 drop-shadow-[0_0_5px_#3b82f6]"></i>
          <div>
            <p class="text-sm font-black text-white">Watch 25 Ads</p>
            <p class="text-[10px] text-slate-500 mt-0.5">The user must watch a total of 25 ads.</p>
          </div>
        </li>
        <li class="flex items-start gap-3 bg-[#050511]/50 p-3 rounded-xl border border-slate-800">
          <i class="fa-solid fa-list-check text-crypto-glow mt-1 drop-shadow-[0_0_5px_#00f0ff]"></i>
          <div>
            <p class="text-sm font-black text-white">Complete 5 Tasks</p>
            <p class="text-[10px] text-slate-500 mt-0.5">The user must complete at least 5 daily missions.</p>
          </div>
        </li>
        <li class="flex items-start gap-3 bg-[#050511]/50 p-3 rounded-xl border border-slate-800">
          <i class="fa-solid fa-clock text-amber-400 mt-1 drop-shadow-[0_0_5px_#fbbf24]"></i>
          <div>
            <p class="text-sm font-black text-white">No Deadline</p>
            <p class="text-[10px] text-slate-500 mt-0.5">The referral remains pending until all requirements are met.</p>
          </div>
        </li>
      </ul>

      <div class="bg-emerald-500/10 border border-emerald-500/30 p-3 rounded-xl text-center">
        <p class="text-[10px] text-emerald-400 font-bold uppercase tracking-widest mb-1">Approval Reward</p>
        <p class="text-lg font-black text-white">+250 XP & $0.025</p>
      </div>
    </div>
  </div>

  <script>
    const tg = window.Telegram.WebApp;
    tg.expand(); 
    tg.ready();
    tg.setHeaderColor('#0a0b1a');
    tg.setBackgroundColor('#050511');

    // Extract TG User Data safely
    const tgUser = tg.initDataUnsafe?.user || {
      id: Math.floor(Math.random() * 10000000), // Fallback for local testing
      first_name: "Demo User",
      username: "demouser",
      photo_url: ""
    };
    
    const startParam = tg.initDataUnsafe?.start_param || null;

    let appState = {
      user: {},
      referrals: [],
      rewards: [],
      withdrawals: [],
      tasks: []
    };

    // Central API Caller
    async function apiCall(action, payload = {}) {
      try {
        const body = {
          action: action,
          tgId: tgUser.id,
          firstName: tgUser.first_name,
          username: tgUser.username,
          photoUrl: tgUser.photo_url,
          referrer: startParam,
          ...payload
        };

        const res = await fetch(window.location.href, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body)
        });
        
        const data = await res.json();
        
        if(data.error) {
          showToast("Error", data.error, "error");
          return false;
        }

        // Sync local state
        if(data.user) appState.user = data.user;
        if(data.referrals) appState.referrals = data.referrals;
        if(data.rewards) appState.rewards = data.rewards;
        if(data.withdrawals) appState.withdrawals = data.withdrawals;
        if(data.tasks) appState.tasks = data.tasks;

        updateUI();
        return data;
      } catch (err) {
        showToast("Connection Error", "Could not reach server.", "error");
        return false;
      }
    }

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
      } else if (type === 'jackpot') {
        iconHtml = '<i class="fa-solid fa-sack-dollar animate-bounce"></i>';
        iconClass = 'bg-amber-500/20 text-amber-400 border border-amber-500/50 shadow-[0_0_20px_rgba(251,191,36,0.6)]';
        borderStyle = '1px solid rgba(251, 191, 36, 0.8)';
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
        if (type === 'success' || type === 'jackpot') tg.HapticFeedback.notificationOccurred('success');
        else if (type === 'error') tg.HapticFeedback.notificationOccurred('error');
        else tg.HapticFeedback.notificationOccurred('warning');
      }

      setTimeout(() => { toast.classList.remove('toast-show'); }, type === 'jackpot' ? 5000 : 3000); 
    }

    function updateUI() {
      const u = appState.user;
      
      // Header
      document.getElementById('user-name').innerText = u.firstName;
      document.getElementById('user-xp').innerHTML = `${u.xp.toLocaleString()} <span class="text-[10px] text-crypto-glow font-bold">XP</span>`;
      document.getElementById('user-usd').innerText = u.usd.toFixed(4);
      if (u.photoUrl) document.getElementById('user-photo').src = u.photoUrl;

      // Home Page
      document.getElementById('main-xp-display').innerText = `${u.xp.toLocaleString()} XP`;
      document.getElementById('ads-watched').innerText = u.adsWatchedToday;
      document.getElementById('streak-days').innerText = u.streak;

      // Referrals Page
      const pending = appState.referrals.filter(r => r.status === 'Pending').length;
      const approved = appState.referrals.filter(r => r.status === 'Approved').length;
      document.getElementById('ref-total').innerText = appState.referrals.length;
      document.getElementById('ref-pending').innerText = pending;
      document.getElementById('ref-approved').innerText = approved;
      document.getElementById('ref-link-input').value = `https://t.me/pointplayappbot?startapp=${u.tgId}`;
      
      renderReferrals();
      renderRewardHistory();

      // Withdraw Page
      document.getElementById('withdraw-balance-display').innerText = u.usd.toFixed(2);
      renderWithdrawHistory();

      // Tasks
      renderStreakTracker();
      renderMissions();
    }

    function renderStreakTracker() {
      const container = document.getElementById('streak-tracker-container');
      container.innerHTML = '';
      const rewards = [5, 10, 15, 20, 25, 30, 50];
      const streak = appState.user.streak || 1;
      const claimedToday = appState.tasks.includes('dailyLogin');
      
      for (let i = 1; i <= 7; i++) {
        const isPast = i < streak || (i === streak && claimedToday);
        const isToday = i === streak && !claimedToday;
        
        let styles = "bg-[#050511] border-slate-700 text-slate-600";
        let icon = `<span class="text-[9px] font-black">${rewards[i-1]}</span>`;
        let lineStyle = "bg-slate-800";
        
        if (isPast) {
          styles = "bg-emerald-500/20 border-emerald-500/50 text-emerald-400 shadow-[0_0_15px_rgba(16,185,129,0.3)]";
          icon = `<i class="fa-solid fa-check text-xs"></i>`;
          lineStyle = "bg-emerald-500/50 shadow-[0_0_5px_#34d399]";
        } else if (isToday) {
          styles = "bg-blue-600/30 border-crypto-glow shadow-[0_0_20px_rgba(0,240,255,0.5)] text-white cursor-pointer";
        }

        const onClick = isToday ? `onclick="claimTask('dailyLogin', ${rewards[i-1]})"` : '';

        container.innerHTML += `
          <div class="relative flex flex-col items-center gap-1.5 z-10 flex-1">
            <div ${onClick} class="w-9 h-9 rounded-xl border-2 flex items-center justify-center transition-all duration-300 ${styles} z-10 relative bg-[#0a0b1a]">
              ${icon}
            </div>
            <span class="text-[8px] font-black tracking-widest ${isToday ? 'text-crypto-glow drop-shadow-[0_0_5px_#00f0ff]' : 'text-slate-500'}">DAY ${i}</span>
            ${i < 7 ? `<div class="absolute top-4 left-[50%] w-full h-1 -z-0 ${lineStyle} rounded-full"></div>` : ''}
          </div>
        `;
      }
    }

    function renderMissions() {
      const container = document.getElementById('missions-container');
      container.innerHTML = '';
      
      const missionsList = [
        { id: 'watch5', label: 'Watch 5 Ads', icon: 'fa-video', color: 'text-blue-400', bg: 'bg-blue-500/10 border-blue-500/20', reward: 20, target: 5, current: appState.user.adsWatchedToday },
        { id: 'watch15', label: 'Watch 15 Ads', icon: 'fa-film', color: 'text-indigo-400', bg: 'bg-indigo-500/10 border-indigo-500/20', reward: 40, target: 15, current: appState.user.adsWatchedToday },
        { id: 'watch30', label: 'Watch 30 Ads', icon: 'fa-clapperboard', color: 'text-purple-400', bg: 'bg-purple-500/10 border-purple-500/20', reward: 80, target: 30, current: appState.user.adsWatchedToday }
      ];

      missionsList.forEach(m => {
        const claimed = appState.tasks.includes(m.id);
        const canClaim = !claimed && m.current >= m.target;
        
        let btnHtml = '';
        if (claimed) {
            btnHtml = `<span class="text-[10px] font-black bg-emerald-500/10 text-emerald-400 px-3 py-1.5 rounded-xl border border-emerald-500/30 flex items-center gap-1 shadow-[0_0_10px_rgba(16,185,129,0.1)inset]"><i class="fa-solid fa-check-double"></i> Claimed</span>`;
        } else if (canClaim) {
            btnHtml = `<button onclick="claimTask('${m.id}', ${m.reward})" class="text-[10px] font-black bg-gradient-to-r from-blue-600 to-cyan-500 text-white px-4 py-1.5 rounded-xl shadow-[0_0_15px_rgba(0,240,255,0.4)] active:scale-95 transition-all uppercase tracking-wider">Claim</button>`;
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
                <span class="text-crypto-glow text-[10px] font-bold tracking-widest uppercase opacity-80 mt-0.5">(${Math.min(m.current, m.target)}/${m.target})</span>
              </div>
            </div>
            ${btnHtml}
          </div>
        `;
      });
    }

    async function claimTask(taskId, reward) {
        if(tg.HapticFeedback) tg.HapticFeedback.impactOccurred('medium');
        const res = await apiCall('claim_task', { taskId, reward });
        if(res && !res.error) showToast('Task Completed!', `You earned ${reward} XP!`, 'success');
    }

    async function watchAd() {
      const btn = document.getElementById('watch-ad-btn');
      const originalHTML = btn.innerHTML;
      btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> <span>Loading Ad...</span>`;
      btn.classList.add('opacity-80', 'pointer-events-none');

      if (window.Adsgram) {
        const AdController = window.Adsgram.init({ blockId: "int-35545" });
        AdController.show().then(async () => {
          const res = await apiCall('watch_ad');
          if(res && !res.error) showToast('Reward Granted!', 'You earned +20 XP.', 'success');
          
          btn.innerHTML = originalHTML;
          btn.classList.remove('opacity-80', 'pointer-events-none');
        }).catch((e) => {
          btn.innerHTML = originalHTML;
          btn.classList.remove('opacity-80', 'pointer-events-none');
        });
      } else {
        showToast('Error', 'Ad system is currently unavailable.', 'error');
        btn.innerHTML = originalHTML;
        btn.classList.remove('opacity-80', 'pointer-events-none');
      }
    }

    async function openBox(type) {
      if(tg.HapticFeedback) tg.HapticFeedback.impactOccurred('heavy');
      const res = await apiCall('open_box', { boxType: type });
      
      if(res && !res.error) {
        if(res.jackpot) {
            showToast('HUGE JACKPOT! 💸', `Incredible! You won $${res.reward.toFixed(2)}!`, 'jackpot');
        } else {
            showToast('Box Opened!', `Congratulations! You won $${res.reward.toFixed(2)}!`, 'success');
        }
      }
    }

    async function requestWithdrawal() {
      const address = document.getElementById('wallet-address').value;
      const amount = parseFloat(document.getElementById('withdraw-amount').value);

      if (!address || address.length < 10) {
        showToast('Invalid Address', 'Please enter a valid TON wallet address.', 'error');
        return;
      }
      if (isNaN(amount) || amount < 10) {
        showToast('Invalid Amount', 'The minimum withdrawal amount is $10.', 'error');
        return;
      }
      if (amount > appState.user.usd) {
        showToast('Insufficient Balance', 'You do not have enough funds.', 'error');
        return;
      }

      const res = await apiCall('withdraw', { amount, address });
      if(res && !res.error) {
          showToast('Withdrawal Requested', `Your request for $${amount.toFixed(2)} has been submitted.`, 'success');
          document.getElementById('wallet-address').value = '';
          document.getElementById('withdraw-amount').value = '';
      }
    }

    function renderWithdrawHistory() {
      const container = document.getElementById('withdraw-history-container');
      const history = appState.withdrawals;
      
      if (history.length === 0) {
        container.innerHTML = `
          <div class="glass-card rounded-2xl p-6 text-center border-dashed border-2 border-slate-700">
            <i class="fa-solid fa-clock-rotate-left text-3xl text-slate-600 mb-2"></i>
            <p class="text-xs font-bold text-slate-500 uppercase tracking-widest">History is Empty</p>
          </div>`;
        return;
      }

      container.innerHTML = history.map(r => `
          <div class="glass-card rounded-2xl p-4 flex justify-between items-center">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-full bg-slate-800 border border-slate-700 flex items-center justify-center">
                 <i class="fa-solid fa-arrow-right-arrow-left text-slate-400"></i>
              </div>
              <div>
                <p class="text-xs font-black text-white">${r.id} <span class="text-[9px] text-slate-400 ml-1 font-bold">${r.date}</span></p>
                <p class="text-[10px] text-blue-400 mt-0.5 font-mono bg-blue-500/10 inline-block px-1.5 py-0.5 rounded">${r.address}</p>
              </div>
            </div>
            <div class="text-right">
              <p class="text-sm font-black text-emerald-400">-$${r.amount.toFixed(2)}</p>
              <p class="text-[9px] font-black text-amber-400 uppercase tracking-widest mt-0.5 bg-amber-500/10 inline-block px-2 py-0.5 rounded-full border border-amber-500/20">${r.status}</p>
            </div>
          </div>
      `).join('');
    }

    // Referrals Functions
    function copyRefLink() {
      const link = document.getElementById('ref-link-input').value;
      navigator.clipboard.writeText(link).then(() => {
        showToast("Success", "Referral link copied!", "success");
      });
    }

    function shareReferralTelegram() {
      const link = `https://t.me/pointplayappbot?startapp=${appState.user.tgId}`;
      const text = `🎯 Play games, complete tasks, earn XP, and collect exciting rewards 🚀 I’m already playing on Point Play now it’s your turn to join the adventure👇`;
      const shareUrl = `https://t.me/share/url?url=${encodeURIComponent(link)}&text=${encodeURIComponent(text)}`;
      tg.openTelegramLink(shareUrl);
    }

    function toggleRefInfo() {
      document.getElementById('ref-info-modal').classList.toggle('hidden');
      document.getElementById('ref-info-modal').classList.toggle('flex');
    }

    function renderReferrals() {
      const container = document.getElementById('referral-list-container');
      const list = appState.referrals;
      
      if (list.length === 0) {
        container.innerHTML = `
          <div class="glass-card rounded-2xl p-6 text-center border-dashed border-2 border-slate-700">
            <i class="fa-solid fa-user-plus text-3xl text-slate-600 mb-2"></i>
            <p class="text-xs font-bold text-slate-500 uppercase tracking-widest">No referrals yet</p>
          </div>`;
        return;
      }

      container.innerHTML = list.map(r => {
        const isAppr = r.status === 'Approved';
        const statusClass = isAppr ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30' : 'bg-amber-500/10 text-amber-400 border-amber-500/30';
        const displayUsername = r.username ? `@${r.username}` : '';
        const initial = r.name ? r.name.charAt(0).toUpperCase() : 'U';

        return `
          <div class="glass-card rounded-2xl p-4 flex flex-col gap-3">
            <div class="flex justify-between items-center">
              <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-600 to-indigo-600 flex items-center justify-center font-black text-white shadow-inner">${initial}</div>
                <div class="flex flex-col">
                  <span class="text-sm font-black text-white tracking-wide">${r.name}</span>
                  ${displayUsername ? `<span class="text-[10px] text-slate-400 font-mono">${displayUsername}</span>` : ''}
                </div>
              </div>
              <span class="text-[9px] font-black uppercase tracking-widest px-2 py-1 rounded border ${statusClass}">${r.status}</span>
            </div>
            
            ${!isAppr ? `
            <div class="bg-[#050511]/50 rounded-xl p-3 border border-slate-800 grid grid-cols-2 gap-2">
              <div class="text-center">
                <p class="text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1">Ads Watched</p>
                <div class="w-full bg-slate-800 rounded-full h-1.5 mb-1 overflow-hidden">
                  <div class="bg-blue-500 h-full rounded-full" style="width: ${Math.min((r.ads/25)*100, 100)}%"></div>
                </div>
                <p class="text-[10px] font-black text-white">${r.ads} <span class="text-slate-500">/ 25</span></p>
              </div>
              <div class="text-center">
                <p class="text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1">Tasks Done</p>
                <div class="w-full bg-slate-800 rounded-full h-1.5 mb-1 overflow-hidden">
                  <div class="bg-crypto-glow h-full rounded-full" style="width: ${Math.min((r.tasks/5)*100, 100)}%"></div>
                </div>
                <p class="text-[10px] font-black text-white">${r.tasks} <span class="text-slate-500">/ 5</span></p>
              </div>
            </div>` : ''}
          </div>
        `;
      }).join('');
    }

    function renderRewardHistory() {
      const container = document.getElementById('referral-rewards-container');
      const rewards = appState.rewards;
      
      if (rewards.length === 0) {
        container.innerHTML = `
          <div class="glass-card rounded-2xl p-4 text-center border-dashed border border-slate-700">
            <p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">No rewards yet</p>
          </div>`;
        return;
      }

      container.innerHTML = rewards.map(r => `
        <div class="glass-card rounded-xl p-3 flex justify-between items-center border border-slate-800">
          <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-emerald-500/20 border border-emerald-500/50 flex items-center justify-center text-emerald-400">
               <i class="fa-solid fa-gift text-sm"></i>
            </div>
            <div>
              <p class="text-xs font-black text-white">${r.title}</p>
              <p class="text-[9px] text-slate-400">${r.desc} • ${r.date}</p>
            </div>
          </div>
          <div class="text-right flex flex-col items-end">
            <span class="text-[10px] font-black text-crypto-glow">+${r.xp} XP</span>
            <span class="text-[10px] font-black text-emerald-400">+$${r.usd.toFixed(3)}</span>
          </div>
        </div>
      `).join('');
    }

    // UI Navigation
    let headerTimeout;
    function switchTab(tabId) {
      document.querySelectorAll('.view-section').forEach(el => el.classList.add('hidden'));
      document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('nav-active'));

      document.getElementById(`view-${tabId}`).classList.remove('hidden');
      document.querySelector(`[data-target="${tabId}"]`).classList.add('nav-active');
      
      const header = document.getElementById('main-header');
      const mainContent = document.getElementById('app-content');

      clearTimeout(headerTimeout);

      if (tabId === 'withdraw') {
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
      
      if (tg.HapticFeedback) tg.HapticFeedback.selectionChanged();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function updateTimer() {
        const now = new Date();
        const tomorrow = new Date(now);
        tomorrow.setUTCHours(24, 0, 0, 0); 
        
        const diff = tomorrow.getTime() - now.getTime();
        const h = Math.floor(diff / 1000 / 60 / 60);
        const m = Math.floor((diff / 1000 / 60) % 60);
        const s = Math.floor((diff / 1000) % 60);
        
        document.getElementById('reset-timer').innerText = 
          `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
    }
    
    async function initApp() {
      const res = await apiCall('init');
      
      setTimeout(() => {
        document.getElementById('loading-overlay').style.opacity = '0';
        setTimeout(() => { document.getElementById('loading-overlay').style.display = 'none'; }, 500); 
      }, 500);
      
      setInterval(updateTimer, 1000);
      updateTimer();
    }

    // Start
    window.addEventListener('load', initApp);
  </script>
</body>
</html>
