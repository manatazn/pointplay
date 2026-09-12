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
            'referrer' => null,
            'azxCryptoTaskCompleted' => false
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
                if ($rewardXp > 0 && $rewardXp <= 100) { // basic security cap for daily missions
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

        case 'claim_azx':
            if (empty($users[$uid]['azxCryptoTaskCompleted'])) {
                $users[$uid]['azxCryptoTaskCompleted'] = true;
                $users[$uid]['xp'] += 200;
                $users[$uid]['totalXp'] += 200;
                $users[$uid]['level'] = calcLevel($users[$uid]['totalXp']);
                evaluateReferralProgress($uid, $users, $referrals, $rewards);
            } else {
                $response['error'] = 'Task already claimed';
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
            
            // Validate minimum $10 and valid address
            if ($amount >= 10 && $amount <= $users[$uid]['usd'] && strlen($address) > 10) {
                $users[$uid]['usd'] -= $amount;
                
                if (!isset($withdrawals[$uid])) $withdrawals[$uid] = [];
                array_unshift($withdrawals[$uid], [
                    'id' => '#' . strtoupper(substr(md5(uniqid()), 0, 6)),
                    'amount' => $amount,
                    'address' => substr($address, 0, 6) . '...' . substr($address, -4),
                    'date' => date('M j, Y'),
                    'status' => 'Pending',
                    'currency' => 'USDT',
                    'network' => 'TON'
                ]);
                writeDB('withdrawals.json', $withdrawals);
            } else {
                $response['error'] = 'Invalid withdrawal request. Check minimum amount and wallet address.';
            }
            break;
            
        case 'game_level_clear':
            $rewardXp = 50; 
            $users[$uid]['xp'] += $rewardXp;
            $users[$uid]['totalXp'] += $rewardXp;
            $users[$uid]['level'] = calcLevel($users[$uid]['totalXp']);
            break;
    }

    writeDB('users.json', $users);
    
    $response['user'] = $users[$uid];
    $response['referrals'] = $referrals[$uid] ?? [];
    $response['rewards'] = $rewards[$uid] ?? [];
    $response['withdrawals'] = $withdrawals[$uid] ?? [];
    $response['tasks'] = $tasks[$uid] ?? [];
    
    echo json_encode($response);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
  <title>Point Play - Telegram Mini App</title>
  
  <script src="https://telegram.org/js/telegram-web-app.js"></script>
  <script src="https://sad.adsgram.ai/js/sad.min.js"></script>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { sans: ['Outfit', 'sans-serif'] },
          colors: {
            crypto: {
              bg: '#03040b',
              card: '#0d1021',
              border: '#1a1d36',
              primary: '#3b82f6',
              glow: '#00f0ff',
              usdt: '#26A17B',
              ton: '#0098EA',
              gold: '#FFD700',
              silver: '#E0E0E0',
              bronze: '#CD7F32'
            }
          },
          animation: {
            'blob': 'blob 10s infinite alternate',
            'float-slow': 'floatSlow 4s ease-in-out infinite',
            'pulse-glow': 'pulseGlow 2s cubic-bezier(0.4, 0, 0.6, 1) infinite',
            'shimmer': 'shimmer 2.5s infinite linear'
          },
          keyframes: {
            blob: {
              '0%': { transform: 'translate(0px, 0px) scale(1)' },
              '50%': { transform: 'translate(20px, -30px) scale(1.05)' },
              '100%': { transform: 'translate(-20px, 20px) scale(0.95)' }
            },
            floatSlow: {
              '0%, 100%': { transform: 'translateY(0)' },
              '50%': { transform: 'translateY(-10px)' }
            },
            pulseGlow: {
              '0%, 100%': { opacity: 1, filter: 'drop-shadow(0 0 10px rgba(0,240,255,0.8))' },
              '50%': { opacity: .5, filter: 'drop-shadow(0 0 2px rgba(0,240,255,0.4))' }
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
      background-color: #03040b;
      color: #f8fafc;
      overflow-x: hidden;
      -webkit-touch-callout: none;
      -webkit-user-select: none;
      user-select: none;
      padding-bottom: env(safe-area-inset-bottom);
    }
    input { user-select: auto !important; }
    img { pointer-events: none; }
    ::-webkit-scrollbar { width: 0px; background: transparent; }

    /* Animated Background Orbs */
    .bg-orb {
      position: fixed; border-radius: 50%; filter: blur(60px); z-index: -1; pointer-events: none;
    }
    .orb-1 { top: -10%; left: -20%; width: 70vw; height: 70vw; background: radial-gradient(circle, rgba(59,130,246,0.12) 0%, transparent 70%); }
    .orb-2 { bottom: -10%; right: -20%; width: 80vw; height: 80vw; background: radial-gradient(circle, rgba(0,240,255,0.08) 0%, transparent 70%); animation-delay: -5s; }

    /* Glassmorphism */
    .glass-card {
      background: linear-gradient(160deg, rgba(13,16,33,0.7) 0%, rgba(8,10,21,0.9) 100%);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      border: 1px solid rgba(255, 255, 255, 0.04);
      box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
    }
    
    .glass-btn {
      background: linear-gradient(135deg, rgba(59,130,246,0.15), rgba(0,240,255,0.05));
      border: 1px solid rgba(0,240,255,0.2);
      box-shadow: 0 0 20px rgba(0,240,255,0.05) inset;
    }

    .fade-in { animation: fadeIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(15px) scale(0.98); } to { opacity: 1; transform: translateY(0) scale(1); } }

    /* Bottom Navigation Active State */
    .nav-active { color: #00f0ff !important; transform: translateY(-3px); }
    .nav-active i { filter: drop-shadow(0 0 10px rgba(0, 240, 255, 0.7)); }
    .nav-active::before {
      content: ''; position: absolute; top: -12px; left: 50%; transform: translateX(-50%);
      width: 24px; height: 4px; background: #00f0ff; border-radius: 4px;
      box-shadow: 0 0 12px #00f0ff, 0 0 20px #3b82f6;
    }

    /* 3D Premium Button */
    .btn-3d {
      background: linear-gradient(to bottom, #3b82f6, #1d4ed8);
      border-bottom: 4px solid #1e3a8a;
      transition: all 0.1s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .btn-3d:active {
      transform: translateY(4px);
      border-bottom-width: 0px;
      margin-bottom: 4px;
    }

    /* Toasts */
    #toast-container {
      position: fixed; top: env(safe-area-inset-top, 20px); left: 50%; transform: translate(-50%, -150%) scale(0.9);
      width: 92%; max-width: 400px; z-index: 999999;
      transition: all 0.6s cubic-bezier(0.68, -0.55, 0.265, 1.55);
      opacity: 0; pointer-events: none;
    }
    .toast-show { transform: translate(-50%, 15px) scale(1) !important; opacity: 1 !important; }

    /* Boxes */
    .box-card {
      transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.2s;
    }
    .box-card:active { transform: scale(0.96); }
    .box-bronze { background: linear-gradient(135deg, rgba(205,127,50,0.1), rgba(139,69,19,0.15)); border: 1px solid rgba(205,127,50,0.3); }
    .box-silver { background: linear-gradient(135deg, rgba(224,224,224,0.08), rgba(158,158,158,0.15)); border: 1px solid rgba(224,224,224,0.3); }
    .box-gold { background: linear-gradient(135deg, rgba(255,215,0,0.12), rgba(218,165,32,0.2)); border: 1px solid rgba(255,215,0,0.4); }

    .modal-overlay { background: rgba(3, 4, 11, 0.9); backdrop-filter: blur(12px); z-index: 10000; }
  </style>
</head>
<body class="flex flex-col min-h-screen">
  
  <div class="bg-orb orb-1 animate-blob"></div>
  <div class="bg-orb orb-2 animate-blob"></div>

  <!-- Loading Screen -->
  <div id="loading-overlay" class="bg-crypto-bg flex flex-col items-center justify-center z-[100000] fixed inset-0 transition-opacity duration-700">
    <div class="relative w-28 h-28 mb-8">
      <div class="absolute inset-0 rounded-full border-t-4 border-crypto-glow animate-[spin_1.2s_cubic-bezier(0.5,0,0.5,1)_infinite] shadow-[0_0_30px_rgba(0,240,255,0.4)]"></div>
      <div class="absolute inset-4 rounded-full border-b-4 border-blue-500 animate-[spin_1.8s_linear_infinite_reverse]"></div>
      <div class="absolute inset-0 flex items-center justify-center">
        <i class="fa-solid fa-gem text-crypto-glow text-4xl animate-pulse-glow"></i>
      </div>
    </div>
    <h2 class="text-white font-black tracking-[0.3em] text-3xl uppercase bg-clip-text text-transparent bg-gradient-to-r from-crypto-glow via-blue-400 to-indigo-500 drop-shadow-2xl">Point Play</h2>
    <p class="text-slate-500 text-xs font-bold tracking-widest mt-4 animate-pulse">LOADING EXPERIENCE</p>
  </div>

  <!-- Notification Toast -->
  <div id="toast-container" class="glass-card rounded-2xl p-4 flex items-center gap-4 border border-crypto-border shadow-2xl">
    <div id="toast-icon" class="w-12 h-12 rounded-xl flex shrink-0 items-center justify-center text-xl shadow-inner">
      <i class="fa-solid fa-bell"></i>
    </div>
    <div class="flex-1">
      <h4 id="toast-title" class="text-sm font-black text-white tracking-wide">Notification</h4>
      <p id="toast-message" class="text-xs text-slate-300 mt-0.5 leading-tight font-medium">Message goes here</p>
    </div>
  </div>

  <!-- Header -->
  <header id="main-header" class="fixed top-0 left-0 right-0 z-50 w-full p-4 glass-card rounded-b-[2rem] border-b border-white/5 shadow-2xl transition-transform duration-300" style="padding-top: max(1rem, env(safe-area-inset-top));">
    <div class="flex justify-between items-center max-w-md mx-auto">
      <div class="flex items-center gap-3">
        <div class="relative w-12 h-12 rounded-full p-[2px] bg-gradient-to-tr from-blue-600 via-crypto-glow to-indigo-500 shadow-[0_0_20px_rgba(0,240,255,0.25)]">
          <img id="user-photo" src="https://via.placeholder.com/150/0d1021/00f0ff?text=PP" alt="Profile" class="w-full h-full rounded-full object-cover border-2 border-crypto-bg">
        </div>
        <div class="flex flex-col">
          <span id="user-name" class="font-black text-white text-sm tracking-wide drop-shadow-md">Loading...</span>
          <div class="flex items-center gap-1.5 mt-0.5">
            <span class="w-2 h-2 rounded-full bg-crypto-usdt shadow-[0_0_8px_#26A17B] animate-pulse"></span>
            <span class="text-[9px] text-slate-400 uppercase font-black tracking-widest">Connected</span>
          </div>
        </div>
      </div>
      <div class="flex flex-col items-end gap-2">
        <div class="glass-btn px-3 py-1.5 rounded-xl flex items-center gap-2">
          <i class="fa-solid fa-bolt text-crypto-glow text-xs animate-pulse-glow"></i>
          <span id="user-xp" class="text-white font-black text-sm tracking-wide">0</span>
        </div>
        <div class="bg-crypto-usdt/10 border border-crypto-usdt/30 px-3 py-1.5 rounded-xl flex items-center gap-1.5 shadow-[0_0_10px_rgba(38,161,123,0.1)inset]">
          <i class="fa-solid fa-dollar-sign text-crypto-usdt text-[10px]"></i>
          <span id="user-usd" class="text-crypto-usdt font-black text-xs tracking-wider">0</span>
        </div>
      </div>
    </div>
  </header>

  <!-- Main Content Area -->
  <main class="flex-1 max-w-md w-full mx-auto p-4 pt-[130px] pb-32 relative" id="app-content">
    
    <!-- HOME PAGE -->
    <div id="view-home" class="view-section fade-in space-y-6">
      <div class="relative glass-card rounded-[2.5rem] p-8 text-center border-t border-t-blue-500/20 overflow-hidden flex flex-col items-center justify-center min-h-[260px] shadow-[0_20px_50px_rgba(0,0,0,0.4)]">
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-56 h-56 bg-blue-500/15 rounded-full filter blur-[50px] pointer-events-none animate-pulse"></div>
        
        <div class="relative z-10 flex flex-col items-center">
          <div class="w-16 h-16 rounded-2xl bg-blue-500/10 border border-blue-400/20 flex items-center justify-center mb-4 shadow-[0_0_30px_rgba(59,130,246,0.2)] backdrop-blur-md transform rotate-3 hover:rotate-0 transition-transform">
             <i class="fa-solid fa-layer-group text-3xl text-crypto-glow drop-shadow-[0_0_15px_rgba(0,240,255,0.6)]"></i>
          </div>
          <p class="text-[10px] font-black text-blue-400 uppercase tracking-[0.4em] mb-2 opacity-90">Current Balance</p>
          <h1 class="text-6xl font-black text-transparent bg-clip-text bg-gradient-to-b from-white via-cyan-100 to-blue-400 tracking-tighter drop-shadow-2xl" id="main-xp-display">0</h1>
          <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mt-1">Experience Points</p>
        </div>

        <div class="w-full mt-8 grid grid-cols-2 gap-4">
          <div class="bg-crypto-bg/60 border border-white/5 p-4 rounded-2xl flex items-center gap-3 backdrop-blur-md">
            <div class="bg-blue-500/10 p-2.5 rounded-xl border border-blue-500/20"><i class="fa-solid fa-video text-blue-400"></i></div>
            <div class="text-left">
              <p class="text-[9px] text-slate-500 uppercase font-bold tracking-wider">Ad Limit</p>
              <p class="text-sm font-black text-white"><span id="ads-watched" class="text-blue-400">0</span> / 30</p>
            </div>
          </div>
          <div class="bg-crypto-bg/60 border border-white/5 p-4 rounded-2xl flex items-center gap-3 backdrop-blur-md">
            <div class="bg-amber-500/10 p-2.5 rounded-xl border border-amber-500/20"><i class="fa-solid fa-fire text-amber-400"></i></div>
            <div class="text-left">
              <p class="text-[9px] text-slate-500 uppercase font-bold tracking-wider">Streak</p>
              <p class="text-sm font-black text-white"><span id="streak-days" class="text-amber-400">1</span> Days</p>
            </div>
          </div>
        </div>
      </div>

      <button onclick="watchAd()" id="watch-ad-btn" class="w-full py-4 rounded-[1.5rem] text-white font-black text-sm tracking-[0.2em] uppercase flex items-center justify-center gap-3 shadow-[0_15px_40px_rgba(59,130,246,0.4)] btn-3d relative overflow-hidden group">
        <div class="absolute inset-0 bg-gradient-to-r from-transparent via-white/20 to-transparent -translate-x-full group-hover:animate-shimmer"></div>
        <i class="fa-solid fa-play bg-white/20 p-2.5 rounded-full text-xs"></i> 
        <span>Watch Ad <span class="text-cyan-200 ml-1">+20 XP</span></span>
      </button>

      <div class="glass-card rounded-2xl p-4 flex justify-between items-center border border-white/5">
        <div class="flex items-center gap-2.5 text-slate-400 text-xs font-bold uppercase tracking-wider">
          <i class="fa-solid fa-clock-rotate-left text-blue-400 animate-spin-slow"></i> <span>Server Reset In:</span>
        </div>
        <span class="text-white font-mono font-bold tracking-widest text-sm bg-crypto-bg/80 px-3 py-1 rounded-lg border border-slate-800" id="reset-timer">--:--:--</span>
      </div>
    </div>

    <!-- TASKS PAGE -->
    <div id="view-tasks" class="view-section hidden fade-in space-y-6 pb-4">
      <div class="text-center pt-2">
        <h2 class="text-4xl font-black text-white tracking-tight drop-shadow-lg">Tasks</h2>
        <p class="text-xs text-crypto-glow mt-2 uppercase tracking-[0.2em] font-bold">Complete & Earn</p>
      </div>
      
      <!-- ONLY ONE DAILY LOGIN TASK AT THE TOP -->
      <div class="glass-card rounded-[2rem] p-6 relative overflow-hidden border-t border-t-emerald-500/30 shadow-[0_10px_30px_rgba(16,185,129,0.15)]">
        <div class="absolute -right-10 -top-10 w-40 h-40 bg-emerald-600/10 rounded-full blur-3xl pointer-events-none"></div>
        
        <div class="flex justify-between items-start mb-6 relative z-10">
          <div>
            <h3 class="text-sm font-black text-white uppercase tracking-widest flex items-center gap-2">
              <i class="fa-solid fa-calendar-day text-emerald-400 text-lg drop-shadow-[0_0_10px_#34d399]"></i> 
              Daily Login
            </h3>
            <p class="text-[10px] text-slate-400 font-medium mt-1">Return every day to increase your reward.</p>
          </div>
          <div id="daily-login-btn-container">
             <!-- Claim button injected here via JS -->
          </div>
        </div>

        <div class="relative flex justify-between items-center" id="streak-tracker-container">
           <!-- Streak tracker visually showing progress -->
        </div>
      </div>

      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-4 mb-3 border-l-2 border-blue-500 ml-1 rounded-sm">Missions</h3>
        <div id="missions-container" class="space-y-3">
          <!-- Populated by JS. "Daily Share" is strictly removed. -->
        </div>
      </div>
      
      <!-- SPONSORED TASKS -->
      <div class="mt-6">
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-4 mb-3 border-l-2 border-crypto-glow ml-1 rounded-sm">Sponsored</h3>
        <div id="azx-task-container">
          <!-- Populated by JS -->
        </div>
      </div>
    </div>

    <!-- BOXES PAGE -->
    <div id="view-boxes" class="view-section hidden fade-in space-y-5 pb-4">
      <div class="text-center pt-2 mb-6">
        <h2 class="text-4xl font-black text-white tracking-tight drop-shadow-lg">Mystery Boxes</h2>
        <p class="text-xs text-amber-400 mt-2 uppercase tracking-[0.2em] font-bold">Exchange XP for USD</p>
      </div>
      
      <div class="box-card box-bronze glass-card rounded-[2rem] p-5 relative overflow-hidden flex justify-between items-center">
        <div class="absolute -left-10 -bottom-10 w-32 h-32 bg-crypto-bronze/10 rounded-full blur-2xl"></div>
        <div class="flex items-center gap-4 relative z-10">
          <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-orange-900 to-crypto-bg border border-crypto-bronze flex items-center justify-center shadow-[0_0_20px_rgba(205,127,50,0.3)]">
            <i class="fa-solid fa-box text-2xl text-crypto-bronze"></i>
          </div>
          <div>
            <h3 class="text-xl font-black text-white tracking-wide">Bronze</h3>
            <div class="flex flex-col mt-1">
              <span class="text-[10px] text-slate-300 font-bold tracking-widest uppercase bg-black/30 px-2 py-0.5 rounded-md w-max"><i class="fa-solid fa-bolt text-crypto-glow mr-1"></i> 10,000 XP</span>
              <span class="text-[10px] text-emerald-400 font-black mt-1 uppercase tracking-wider">Win up to $1.00</span>
            </div>
          </div>
        </div>
        <button onclick="openBox('bronze')" class="relative z-10 bg-gradient-to-b from-orange-600 to-orange-800 text-white shadow-[0_5px_20px_rgba(205,127,50,0.4)] hover:brightness-110 active:scale-95 transition-all px-5 py-2.5 rounded-xl text-xs font-black uppercase tracking-wider">Open</button>
      </div>

      <div class="box-card box-silver glass-card rounded-[2rem] p-5 relative overflow-hidden flex justify-between items-center">
        <div class="absolute -left-10 -bottom-10 w-32 h-32 bg-crypto-silver/5 rounded-full blur-2xl"></div>
        <div class="flex items-center gap-4 relative z-10">
          <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-slate-600 to-crypto-bg border border-crypto-silver flex items-center justify-center shadow-[0_0_20px_rgba(226,232,240,0.15)]">
            <i class="fa-solid fa-box-open text-2xl text-crypto-silver"></i>
          </div>
          <div>
            <h3 class="text-xl font-black text-white tracking-wide">Silver</h3>
            <div class="flex flex-col mt-1">
              <span class="text-[10px] text-slate-300 font-bold tracking-widest uppercase bg-black/30 px-2 py-0.5 rounded-md w-max"><i class="fa-solid fa-bolt text-crypto-glow mr-1"></i> 50,000 XP</span>
              <span class="text-[10px] text-emerald-400 font-black mt-1 uppercase tracking-wider">Win up to $7.00</span>
            </div>
          </div>
        </div>
        <button onclick="openBox('silver')" class="relative z-10 bg-gradient-to-b from-slate-300 to-slate-500 text-slate-900 shadow-[0_5px_20px_rgba(226,232,240,0.3)] hover:brightness-110 active:scale-95 transition-all px-5 py-2.5 rounded-xl text-xs font-black uppercase tracking-wider">Open</button>
      </div>

      <div class="box-card box-gold glass-card rounded-[2rem] p-5 relative overflow-hidden flex justify-between items-center border border-crypto-gold/50 shadow-[0_0_30px_rgba(255,215,0,0.15)]">
        <div class="absolute right-0 top-1/2 -translate-y-1/2 w-40 h-40 bg-crypto-gold/15 rounded-full blur-3xl animate-pulse"></div>
        <div class="flex items-center gap-4 relative z-10">
          <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-amber-500 to-crypto-bg border border-crypto-gold flex items-center justify-center shadow-[0_0_30px_rgba(255,215,0,0.4)]">
            <i class="fa-solid fa-gem text-2xl text-crypto-gold drop-shadow-[0_0_10px_#FFD700]"></i>
          </div>
          <div>
            <h3 class="text-xl font-black text-crypto-gold tracking-wide drop-shadow-[0_0_5px_rgba(255,215,0,0.3)]">Gold Box</h3>
            <div class="flex flex-col mt-1">
              <span class="text-[10px] text-slate-300 font-bold tracking-widest uppercase bg-black/30 px-2 py-0.5 rounded-md w-max"><i class="fa-solid fa-bolt text-crypto-glow mr-1"></i> 100,000 XP</span>
              <span class="text-[10px] text-emerald-400 font-black mt-1 uppercase tracking-wider">Win up to $15.00</span>
            </div>
          </div>
        </div>
        <button onclick="openBox('gold')" class="relative z-10 bg-gradient-to-b from-yellow-400 to-amber-600 text-slate-900 shadow-[0_5px_25px_rgba(255,215,0,0.5)] hover:brightness-110 active:scale-95 transition-all px-5 py-2.5 rounded-xl text-xs font-black uppercase tracking-wider">Open</button>
      </div>
    </div>

    <!-- WALLET PAGE (Redesigned) -->
    <div id="view-wallet" class="view-section hidden fade-in space-y-6 pb-4">
      <div class="text-center pt-2 mb-4">
        <h2 class="text-4xl font-black text-white tracking-tight drop-shadow-lg">Wallet</h2>
        <p class="text-xs text-crypto-usdt mt-2 uppercase tracking-[0.2em] font-bold">Secure Withdrawals</p>
      </div>

      <div class="glass-card rounded-[2.5rem] p-8 text-center border-t border-crypto-usdt/40 bg-gradient-to-b from-crypto-usdt/10 to-transparent shadow-[0_15px_40px_rgba(38,161,123,0.15)] relative overflow-hidden">
        <div class="absolute -left-10 top-0 w-32 h-32 bg-crypto-usdt/20 rounded-full blur-3xl pointer-events-none"></div>
        
        <div class="w-16 h-16 mx-auto bg-crypto-usdt/10 rounded-2xl flex items-center justify-center mb-4 border border-crypto-usdt/30 shadow-[0_0_30px_rgba(38,161,123,0.2)] relative z-10 backdrop-blur-sm">
          <img src="https://cryptologos.cc/logos/tether-usdt-logo.png" alt="USDT" class="w-8 h-8 drop-shadow-md">
        </div>
        <p class="text-[10px] font-black text-crypto-usdt uppercase tracking-[0.3em] mb-2 opacity-90 relative z-10">Available Balance</p>
        <h1 class="text-6xl font-black text-white tracking-tighter mb-4 relative z-10 drop-shadow-lg"><span class="text-3xl text-slate-400 mr-1">$</span><span id="withdraw-balance-display">0</span></h1>
        
        <div class="inline-flex items-center gap-2 bg-crypto-bg/80 backdrop-blur-md px-4 py-2 rounded-xl border border-white/5 relative z-10">
          <i class="fa-solid fa-shield-halved text-blue-400 text-xs"></i>
          <p class="text-[10px] font-bold text-slate-300 uppercase tracking-widest">Minimum Withdrawal: $10</p>
        </div>
      </div>

      <div class="glass-card rounded-[2rem] p-6 space-y-5 border-white/5 shadow-lg">
        
        <!-- Network Info Banner -->
        <div class="bg-blue-500/10 border border-blue-500/20 rounded-xl p-3 flex items-start gap-3">
            <i class="fa-solid fa-circle-info text-blue-400 mt-0.5"></i>
            <div>
                <p class="text-xs font-black text-white uppercase tracking-wider">Network Requirement</p>
                <p class="text-[10px] font-medium text-slate-400 mt-1 leading-relaxed">Withdrawals are processed exclusively in <strong class="text-crypto-usdt">USDT</strong> via the <strong class="text-crypto-ton">TON Network</strong>. Do not use ERC20, TRC20, or BEP20.</p>
            </div>
        </div>

        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-2 ml-1">TON Wallet Address <span class="text-red-400">*</span></label>
          <div class="relative group">
            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
              <img src="https://cryptologos.cc/logos/toncoin-ton-logo.png" class="w-5 h-5 opacity-60 group-focus-within:opacity-100 transition-opacity drop-shadow-md" alt="TON">
            </div>
            <input type="text" id="wallet-address" placeholder="Enter TON Address (e.g. UQ...)" class="w-full bg-crypto-bg/80 border border-white/10 rounded-2xl py-4 pl-12 pr-4 text-sm font-medium text-white focus:outline-none focus:border-crypto-ton transition-all placeholder-slate-600 shadow-inner">
          </div>
        </div>
        
        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-2 ml-1">Amount (USDT) <span class="text-red-400">*</span></label>
          <div class="relative group">
            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
              <img src="https://cryptologos.cc/logos/tether-usdt-logo.png" class="w-5 h-5 opacity-60 group-focus-within:opacity-100 transition-opacity" alt="USDT">
            </div>
            <input type="number" id="withdraw-amount" placeholder="Min $10" min="10" step="0.1" class="w-full bg-crypto-bg/80 border border-white/10 rounded-2xl py-4 pl-12 pr-4 text-sm font-black text-crypto-usdt focus:outline-none focus:border-crypto-usdt transition-all placeholder-slate-600 shadow-inner">
          </div>
        </div>

        <button onclick="requestWithdrawal()" id="withdraw-btn" class="w-full py-4 mt-2 bg-gradient-to-r from-crypto-usdt to-teal-600 hover:brightness-110 active:scale-95 transition-all text-white font-black rounded-2xl text-sm uppercase tracking-[0.2em] flex items-center justify-center gap-2 shadow-[0_15px_30px_rgba(38,161,123,0.3)] border border-crypto-usdt/50">
          <i class="fa-solid fa-paper-plane"></i> Submit Request
        </button>
      </div>

      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-4 mb-3 border-l-2 border-slate-600 ml-1 rounded-sm">Transaction History</h3>
        <div id="withdraw-history-container" class="space-y-3">
          <!-- Populated via JS -->
        </div>
      </div>
    </div>

    <!-- PROFILE PAGE (Redesigned) -->
    <div id="view-profile" class="view-section hidden fade-in space-y-6 pb-4">
      <div class="text-center pt-2">
        <h2 class="text-4xl font-black text-white tracking-tight drop-shadow-lg">Profile</h2>
        <p class="text-xs text-blue-400 mt-2 uppercase tracking-[0.2em] font-bold">User Dashboard</p>
      </div>

      <div class="glass-card rounded-[2.5rem] p-8 text-center border-t border-blue-500/30 relative overflow-hidden shadow-[0_15px_40px_rgba(0,0,0,0.4)]">
        <div class="absolute -right-20 -top-20 w-48 h-48 bg-blue-600/10 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute -left-20 -bottom-20 w-48 h-48 bg-crypto-glow/5 rounded-full blur-3xl pointer-events-none"></div>

        <div class="relative w-28 h-28 mx-auto rounded-full p-1 bg-gradient-to-tr from-blue-600 via-crypto-glow to-indigo-500 shadow-[0_0_30px_rgba(0,240,255,0.3)] mb-5">
          <div id="profile-avatar-container" class="w-full h-full rounded-full border-4 border-crypto-bg overflow-hidden bg-slate-800 flex items-center justify-center">
             <!-- Avatar Image or Initials injected via JS -->
             <img id="profile-photo" src="" alt="Profile" class="w-full h-full object-cover hidden">
             <span id="profile-initials" class="text-4xl font-black text-white hidden"></span>
          </div>
          <div class="absolute bottom-0 right-0 w-8 h-8 bg-blue-500 rounded-full border-4 border-crypto-bg flex items-center justify-center shadow-lg">
             <i class="fa-brands fa-telegram text-white text-xs"></i>
          </div>
        </div>

        <h3 id="profile-fullname" class="text-2xl font-black text-white tracking-wide">Loading Name...</h3>
        <p id="profile-username" class="text-sm font-medium text-blue-400 mt-1">@username</p>
        
        <div class="inline-flex items-center gap-2 bg-black/40 px-3 py-1.5 rounded-lg border border-white/5 mt-3">
            <i class="fa-solid fa-id-card text-slate-500 text-[10px]"></i>
            <p class="text-xs text-slate-400 font-mono font-bold tracking-widest">ID: <span id="profile-id" class="text-white">00000</span></p>
        </div>

        <div class="mt-8 bg-crypto-bg/80 rounded-[1.5rem] p-5 border border-white/5 text-left relative z-10 shadow-inner">
          <div class="flex justify-between items-end mb-3">
            <div>
              <p class="text-[10px] font-black text-crypto-glow uppercase tracking-[0.2em] flex items-center gap-1"><i class="fa-solid fa-ranking-star"></i> Level <span id="profile-level">1</span></p>
              <p class="text-sm font-black text-white mt-1 tracking-wider"><span id="profile-current-xp">0</span> <span class="text-slate-500 text-xs">/ <span id="profile-next-xp">250</span> XP</span></p>
            </div>
            <span id="profile-percent" class="text-sm font-black text-crypto-glow bg-blue-500/10 px-2 py-1 rounded-md border border-blue-500/20">0%</span>
          </div>
          <div class="w-full bg-slate-800/80 rounded-full h-3 overflow-hidden shadow-inner p-[1px]">
            <div id="profile-progress-bar" class="bg-gradient-to-r from-blue-500 via-crypto-glow to-blue-400 h-full rounded-full shadow-[0_0_10px_#00f0ff]" style="width: 0%"></div>
          </div>
        </div>
      </div>

      <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-4 mb-2 mt-2 border-l-2 border-crypto-glow ml-1 rounded-sm">Your Statistics</h3>
      <div class="grid grid-cols-2 gap-3">
        <div class="glass-card border border-white/5 p-4 rounded-2xl flex flex-col items-center justify-center gap-1 shadow-lg">
          <div class="w-8 h-8 rounded-full bg-blue-500/10 text-blue-400 flex items-center justify-center mb-1 border border-blue-500/20"><i class="fa-solid fa-chart-line"></i></div>
          <p class="text-[9px] text-slate-400 uppercase font-bold tracking-[0.15em]">Total Earned</p>
          <p id="profile-total-xp" class="text-sm font-black text-white">0 XP</p>
        </div>
        <div class="glass-card border border-white/5 p-4 rounded-2xl flex flex-col items-center justify-center gap-1 shadow-lg">
          <div class="w-8 h-8 rounded-full bg-emerald-500/10 text-emerald-400 flex items-center justify-center mb-1 border border-emerald-500/20"><i class="fa-solid fa-list-check"></i></div>
          <p class="text-[9px] text-slate-400 uppercase font-bold tracking-[0.15em]">Tasks Done</p>
          <p id="profile-tasks-completed" class="text-sm font-black text-white">0</p>
        </div>
        <div class="glass-card border border-white/5 p-4 rounded-2xl flex flex-col items-center justify-center gap-1 shadow-lg">
          <div class="w-8 h-8 rounded-full bg-amber-500/10 text-amber-400 flex items-center justify-center mb-1 border border-amber-500/20"><i class="fa-solid fa-box-open"></i></div>
          <p class="text-[9px] text-slate-400 uppercase font-bold tracking-[0.15em]">Boxes Opened</p>
          <p id="profile-boxes" class="text-sm font-black text-white">0</p>
        </div>
        <div class="glass-card border border-white/5 p-4 rounded-2xl flex flex-col items-center justify-center gap-1 shadow-lg">
          <div class="w-8 h-8 rounded-full bg-purple-500/10 text-purple-400 flex items-center justify-center mb-1 border border-purple-500/20"><i class="fa-solid fa-users"></i></div>
          <p class="text-[9px] text-slate-400 uppercase font-bold tracking-[0.15em]">Referrals</p>
          <p id="profile-referrals-count" class="text-sm font-black text-white">0</p>
        </div>
      </div>
    </div>

  </main>

  <!-- Premium Bottom Navigation -->
  <nav id="bottom-nav" class="fixed bottom-6 left-1/2 -translate-x-1/2 w-[calc(100%-2rem)] max-w-[400px] glass-card rounded-[1.5rem] z-50 shadow-[0_20px_50px_rgba(0,0,0,0.8)] border border-white/10 backdrop-blur-2xl">
    <div class="flex justify-between items-center px-2 py-3 relative">
      <button onclick="switchTab('home')" class="nav-btn nav-active text-slate-500 flex flex-col items-center gap-1.5 flex-1 transition-all duration-300 relative group" data-target="home">
        <i class="fa-solid fa-house text-xl transition-transform group-active:scale-75"></i>
        <span class="text-[8px] font-black uppercase tracking-[0.15em]">Home</span>
      </button>
      <button onclick="switchTab('tasks')" class="nav-btn text-slate-500 flex flex-col items-center gap-1.5 flex-1 transition-all duration-300 relative group" data-target="tasks">
        <i class="fa-solid fa-list-check text-xl transition-transform group-active:scale-75"></i>
        <span class="text-[8px] font-black uppercase tracking-[0.15em]">Tasks</span>
      </button>
      <button onclick="switchTab('boxes')" class="nav-btn text-slate-500 flex flex-col items-center gap-1.5 flex-1 transition-all duration-300 relative group" data-target="boxes">
        <i class="fa-solid fa-box-open text-xl transition-transform group-active:scale-75"></i>
        <span class="text-[8px] font-black uppercase tracking-[0.15em]">Box</span>
      </button>
      <button onclick="switchTab('wallet')" class="nav-btn text-slate-500 flex flex-col items-center gap-1.5 flex-1 transition-all duration-300 relative group" data-target="wallet">
        <i class="fa-solid fa-wallet text-xl transition-transform group-active:scale-75"></i>
        <span class="text-[8px] font-black uppercase tracking-[0.15em]">Wallet</span>
      </button>
      <button onclick="switchTab('profile')" class="nav-btn text-slate-500 flex flex-col items-center gap-1.5 flex-1 transition-all duration-300 relative group" data-target="profile">
        <i class="fa-solid fa-user text-xl transition-transform group-active:scale-75"></i>
        <span class="text-[8px] font-black uppercase tracking-[0.15em]">Profile</span>
      </button>
    </div>
  </nav>

  <script>
    const tg = window.Telegram.WebApp;
    tg.expand(); 
    tg.ready();
    tg.setHeaderColor('#080a15');
    tg.setBackgroundColor('#03040b');

    // Extract TG User Data safely handling all cases
    const rawTgUser = tg.initDataUnsafe?.user || {};
    const tgUser = {
      id: rawTgUser.id || Math.floor(Math.random() * 10000000), 
      first_name: rawTgUser.first_name || "User",
      last_name: rawTgUser.last_name || "",
      username: rawTgUser.username || "",
      photo_url: rawTgUser.photo_url || ""
    };
    
    const startParam = tg.initDataUnsafe?.start_param || null;

    let appState = {
      user: {},
      referrals: [],
      rewards: [],
      withdrawals: [],
      tasks: []
    };

    // Number formatter to remove unnecessary trailing zeros naturally
    function formatNumber(num) {
        return Number(Number(num).toFixed(4)).toString();
    }

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
        iconClass = 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30 shadow-[0_0_15px_rgba(16,185,129,0.2)]';
        borderStyle = '1px solid rgba(16, 185, 129, 0.4)';
      } else if (type === 'error') {
        iconHtml = '<i class="fa-solid fa-xmark"></i>';
        iconClass = 'bg-red-500/10 text-red-400 border border-red-500/30 shadow-[0_0_15px_rgba(239,68,68,0.2)]';
        borderStyle = '1px solid rgba(239, 68, 68, 0.4)';
      } else if (type === 'jackpot') {
        iconHtml = '<i class="fa-solid fa-sack-dollar animate-bounce"></i>';
        iconClass = 'bg-amber-500/10 text-amber-400 border border-amber-500/30 shadow-[0_0_20px_rgba(251,191,36,0.3)]';
        borderStyle = '1px solid rgba(251, 191, 36, 0.6)';
      } else {
        iconHtml = '<i class="fa-solid fa-bell animate-pulse"></i>';
        iconClass = 'bg-blue-500/10 text-crypto-glow border border-crypto-glow/30 shadow-[0_0_15px_rgba(0,240,255,0.2)]';
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
      document.getElementById('user-xp').innerHTML = `${u.xp.toLocaleString()}`;
      document.getElementById('user-usd').innerText = formatNumber(u.usd);
      if (tgUser.photo_url) document.getElementById('user-photo').src = tgUser.photo_url;

      // Profile Page Logic
      const xpThresholds = [0, 250, 750, 1500, 3000, 20000];
      let nextTarget = 20000;
      let prevTarget = 0;
      for (let i = 0; i < xpThresholds.length; i++) {
          if (u.totalXp < xpThresholds[i]) {
              nextTarget = xpThresholds[i];
              prevTarget = xpThresholds[i-1] || 0;
              break;
          }
      }
      if (u.totalXp >= 20000) { nextTarget = 20000; prevTarget = 20000; }
      
      let progressPercent = 100;
      if (nextTarget > prevTarget) {
          progressPercent = ((u.totalXp - prevTarget) / (nextTarget - prevTarget)) * 100;
      }
      
      // Safe full name generation
      const fullName = `${tgUser.first_name} ${tgUser.last_name}`.trim();
      const displayUsername = tgUser.username ? `@${tgUser.username}` : 'No username';
      
      document.getElementById('profile-fullname').innerText = fullName;
      document.getElementById('profile-username').innerText = displayUsername;
      document.getElementById('profile-id').innerText = u.tgId;
      document.getElementById('profile-level').innerText = u.level;
      document.getElementById('profile-current-xp').innerText = u.totalXp.toLocaleString();
      document.getElementById('profile-next-xp').innerText = nextTarget.toLocaleString();
      document.getElementById('profile-percent').innerText = `${Math.min(100, Math.max(0, progressPercent)).toFixed(0)}%`;
      document.getElementById('profile-progress-bar').style.width = `${Math.min(100, Math.max(0, progressPercent))}%`;
      
      // Profile Stats
      document.getElementById('profile-total-xp').innerText = `${u.totalXp.toLocaleString()} XP`;
      document.getElementById('profile-tasks-completed').innerText = u.tasksCompleted || 0;
      document.getElementById('profile-boxes').innerText = u.boxesOpened || 0;
      document.getElementById('profile-referrals-count').innerText = appState.referrals.length || 0;

      // Profile Avatar Fallback Handling
      const photoEl = document.getElementById('profile-photo');
      const initialsEl = document.getElementById('profile-initials');
      if (tgUser.photo_url) {
         photoEl.src = tgUser.photo_url;
         photoEl.classList.remove('hidden');
         initialsEl.classList.add('hidden');
      } else {
         photoEl.classList.add('hidden');
         initialsEl.classList.remove('hidden');
         initialsEl.innerText = tgUser.first_name.charAt(0).toUpperCase();
      }

      // Home Page
      document.getElementById('main-xp-display').innerText = `${u.xp.toLocaleString()}`;
      document.getElementById('ads-watched').innerText = u.adsWatchedToday;
      document.getElementById('streak-days').innerText = u.streak;

      // Wallet Page
      document.getElementById('withdraw-balance-display').innerText = formatNumber(u.usd);
      renderWithdrawHistory();

      // Tasks Page
      renderStreakTracker();
      renderMissions();
    }

    function renderStreakTracker() {
      const container = document.getElementById('streak-tracker-container');
      const btnContainer = document.getElementById('daily-login-btn-container');
      container.innerHTML = '';
      btnContainer.innerHTML = '';

      const rewards = [5, 10, 15, 20, 25, 30, 50];
      const streak = appState.user.streak || 1;
      const claimedToday = appState.tasks.includes('streakLogin');
      
      // Central Claim Button for the Daily Task
      if (claimedToday) {
         btnContainer.innerHTML = `<div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest flex items-center gap-1.5 shadow-inner"><i class="fa-solid fa-check-double"></i> Claimed</div>`;
      } else {
         const currentReward = rewards[streak - 1] || 5;
         btnContainer.innerHTML = `<button onclick="claimTask('streakLogin', ${currentReward})" class="bg-gradient-to-r from-emerald-500 to-teal-500 text-white px-5 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest shadow-[0_5px_15px_rgba(16,185,129,0.4)] active:scale-95 transition-transform hover:brightness-110 flex items-center gap-2">Claim <span class="bg-white/20 px-1.5 py-0.5 rounded-md text-[10px]">+${currentReward} XP</span></button>`;
      }

      // Visual Tracker below
      for (let i = 1; i <= 7; i++) {
        const isPast = i < streak || (i === streak && claimedToday);
        const isToday = i === streak && !claimedToday;
        
        let styles = "bg-crypto-bg border-white/10 text-slate-600 opacity-60";
        let icon = `<span class="text-[10px] font-black">${rewards[i-1]}</span>`;
        let lineStyle = "bg-slate-800";
        
        if (isPast) {
          styles = "bg-emerald-500/10 border-emerald-500/40 text-emerald-400 shadow-[0_0_15px_rgba(16,185,129,0.2)]";
          icon = `<i class="fa-solid fa-check text-sm"></i>`;
          lineStyle = "bg-emerald-500/40 shadow-[0_0_5px_#34d399]";
        } else if (isToday) {
          styles = "bg-blue-600/20 border-crypto-glow shadow-[0_0_20px_rgba(0,240,255,0.4)] text-white transform scale-110";
        }

        container.innerHTML += `
          <div class="relative flex flex-col items-center gap-2 z-10 flex-1">
            <div class="w-10 h-10 rounded-2xl border flex items-center justify-center transition-all duration-300 ${styles} z-10 relative backdrop-blur-sm">
              ${icon}
            </div>
            <span class="text-[8px] font-black tracking-[0.2em] uppercase ${isToday ? 'text-crypto-glow drop-shadow-[0_0_5px_#00f0ff]' : 'text-slate-500'}">Day ${i}</span>
            ${i < 7 ? `<div class="absolute top-5 left-[50%] w-full h-1 -z-0 ${lineStyle} rounded-full"></div>` : ''}
          </div>
        `;
      }
    }

    function renderMissions() {
      const container = document.getElementById('missions-container');
      container.innerHTML = '';
      
      // Removed mission_share completely. mission_login is handled above in the visual streak section.
      const missionsList = [
        { id: 'watch5', label: 'Watch 5 Ads', icon: 'fa-video', color: 'text-blue-400', bg: 'bg-blue-500/10 border-blue-500/20', reward: 20, target: 5, current: appState.user.adsWatchedToday },
        { id: 'watch30', label: 'Watch 30 Ads', icon: 'fa-layer-group', color: 'text-purple-400', bg: 'bg-purple-500/10 border-purple-500/20', reward: 50, target: 30, current: appState.user.adsWatchedToday }
      ];
      
      let allCompleted = true;
      missionsList.forEach(m => {
          if (!appState.tasks.includes(m.id)) allCompleted = false;
      });
      missionsList.push({ id: 'mission_all', label: 'Complete All Tasks', icon: 'fa-trophy', color: 'text-amber-400', bg: 'bg-amber-500/10 border-amber-500/20', reward: 100, target: 1, current: allCompleted ? 1 : 0 });

      missionsList.forEach(m => {
        const claimed = appState.tasks.includes(m.id);
        const canClaim = !claimed && m.current >= m.target;
        
        let btnHtml = '';
        if (claimed) {
            btnHtml = `<span class="text-[10px] font-black bg-emerald-500/10 text-emerald-400 px-3 py-1.5 rounded-xl border border-emerald-500/30 flex items-center gap-1 shadow-inner"><i class="fa-solid fa-check-double"></i> Done</span>`;
        } else if (canClaim) {
            btnHtml = `<button onclick="claimTask('${m.id}', ${m.reward})" class="text-[10px] font-black bg-gradient-to-r from-blue-600 to-cyan-500 text-white px-5 py-2 rounded-xl shadow-[0_5px_15px_rgba(0,240,255,0.3)] active:scale-95 transition-all uppercase tracking-wider">Claim</button>`;
        } else {
            btnHtml = `<span class="text-[10px] font-black bg-white/5 text-slate-300 px-3 py-1.5 rounded-xl border border-white/10 shadow-inner">+${m.reward} XP</span>`;
        }

        container.innerHTML += `
          <div class="glass-card rounded-2xl p-3.5 flex justify-between items-center transition-transform hover:scale-[1.01] border border-white/5">
            <div class="flex items-center gap-3.5">
              <div class="w-12 h-12 rounded-xl border ${m.bg} flex items-center justify-center shadow-sm">
                 <i class="fa-solid ${m.icon} ${m.color} text-xl drop-shadow-md"></i>
              </div>
              <div class="flex flex-col">
                <span class="text-sm font-black text-white tracking-wide">${m.label}</span>
                <span class="text-crypto-glow text-[10px] font-bold tracking-[0.2em] uppercase opacity-80 mt-0.5">Progress: ${Math.min(m.current, m.target)}/${m.target}</span>
              </div>
            </div>
            ${btnHtml}
          </div>
        `;
      });
      
      // Render AZX Sponsored Task
      const azxContainer = document.getElementById('azx-task-container');
      if (azxContainer) {
          const azxClaimed = appState.user.azxCryptoTaskCompleted;
          let azxBtn = azxClaimed
              ? `<span class="text-[10px] font-black bg-emerald-500/10 text-emerald-400 px-3 py-1.5 rounded-xl border border-emerald-500/30 flex items-center gap-1 shadow-inner"><i class="fa-solid fa-check-double"></i> Done</span>`
              : `<button onclick="claimAZXTask()" class="text-[10px] font-black bg-gradient-to-r from-blue-600 to-cyan-500 text-white px-5 py-2 rounded-xl shadow-[0_5px_15px_rgba(0,240,255,0.3)] active:scale-95 transition-all uppercase tracking-wider">Join</button>`;

          azxContainer.innerHTML = `
            <div class="glass-card rounded-2xl p-3.5 flex justify-between items-center transition-transform hover:scale-[1.01] border border-white/5">
              <div class="flex items-center gap-3.5">
                <div class="w-12 h-12 rounded-xl border bg-blue-500/10 border-blue-500/20 flex items-center justify-center shadow-sm">
                   <i class="fa-brands fa-telegram text-blue-400 text-2xl drop-shadow-md"></i>
                </div>
                <div class="flex flex-col">
                  <span class="text-sm font-black text-white tracking-wide">Join AZX Crypto</span>
                  <span class="text-crypto-glow text-[10px] font-bold tracking-[0.2em] uppercase opacity-80 mt-0.5">+200 XP Reward</span>
                </div>
              </div>
              ${azxBtn}
            </div>
          `;
      }
    }

    async function claimTask(taskId, reward) {
        if(tg.HapticFeedback) tg.HapticFeedback.impactOccurred('medium');
        const res = await apiCall('claim_task', { taskId, reward });
        if(res && !res.error) showToast('Task Completed!', `You earned +${reward} XP!`, 'success');
    }
    
    async function claimAZXTask() {
        if(tg.HapticFeedback) tg.HapticFeedback.impactOccurred('medium');
        tg.openTelegramLink('https://t.me/azxcrypto');
        
        setTimeout(async () => {
            const res = await apiCall('claim_azx');
            if(res && !res.error) showToast('Task Completed!', `You earned +200 XP!`, 'success');
        }, 2000);
    }

    async function watchAd() {
      const btn = document.getElementById('watch-ad-btn');
      const originalHTML = btn.innerHTML;
      btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin text-lg"></i> <span class="tracking-widest">LOADING AD...</span>`;
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
            showToast('HUGE JACKPOT! 💸', `Incredible! You won $${formatNumber(res.reward)} USDT!`, 'jackpot');
        } else {
            showToast('Box Opened!', `Congratulations! You won $${formatNumber(res.reward)} USDT!`, 'success');
        }
      }
    }

    async function requestWithdrawal() {
      const address = document.getElementById('wallet-address').value.trim();
      const amount = parseFloat(document.getElementById('withdraw-amount').value);

      if (!address || address.length < 10) {
        showToast('Invalid Address', 'Please provide a valid TON wallet address.', 'error');
        return;
      }
      if (isNaN(amount) || amount < 10) {
        showToast('Invalid Amount', 'The minimum withdrawal is $10 USDT.', 'error');
        return;
      }
      if (amount > appState.user.usd) {
        showToast('Insufficient Balance', 'You do not have enough USDT available.', 'error');
        return;
      }

      if(tg.HapticFeedback) tg.HapticFeedback.impactOccurred('medium');
      const res = await apiCall('withdraw', { amount, address });
      if(res && !res.error) {
          showToast('Request Submitted', `Your withdrawal of $${formatNumber(amount)} USDT is processing.`, 'success');
          document.getElementById('wallet-address').value = '';
          document.getElementById('withdraw-amount').value = '';
      }
    }

    function renderWithdrawHistory() {
      const container = document.getElementById('withdraw-history-container');
      const history = appState.withdrawals;
      
      if (history.length === 0) {
        container.innerHTML = `
          <div class="glass-card rounded-2xl p-8 text-center border-dashed border-2 border-white/10">
            <div class="w-16 h-16 mx-auto bg-white/5 rounded-full flex items-center justify-center mb-3">
               <i class="fa-solid fa-clock-rotate-left text-2xl text-slate-500"></i>
            </div>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">No History Found</p>
          </div>`;
        return;
      }

      container.innerHTML = history.map(r => `
          <div class="glass-card rounded-2xl p-4 flex justify-between items-center border border-white/5 hover:bg-white/5 transition-colors">
            <div class="flex items-center gap-4">
              <div class="w-12 h-12 rounded-xl bg-crypto-bg border border-white/10 flex items-center justify-center shadow-inner">
                 <img src="https://cryptologos.cc/logos/tether-usdt-logo.png" class="w-6 h-6 opacity-80" alt="USDT">
              </div>
              <div>
                <p class="text-sm font-black text-white">${r.id} <span class="text-[9px] text-slate-400 ml-2 font-bold tracking-wider">${r.date}</span></p>
                <div class="flex items-center gap-2 mt-1">
                   <p class="text-[10px] text-crypto-ton font-mono bg-crypto-ton/10 border border-crypto-ton/20 px-2 py-0.5 rounded-md">${r.address}</p>
                </div>
              </div>
            </div>
            <div class="text-right flex flex-col items-end gap-1">
              <p class="text-base font-black text-white">-$${formatNumber(r.amount)}</p>
              <p class="text-[9px] font-black ${r.status === 'Pending' ? 'text-amber-400 bg-amber-500/10 border-amber-500/20' : 'text-emerald-400 bg-emerald-500/10 border-emerald-500/20'} uppercase tracking-[0.2em] px-2.5 py-1 rounded-md border shadow-sm">${r.status}</p>
            </div>
          </div>
      `).join('');
    }

    let headerTimeout;
    function switchTab(tabId) {
      document.querySelectorAll('.view-section').forEach(el => el.classList.add('hidden'));
      document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('nav-active'));

      document.getElementById(`view-${tabId}`).classList.remove('hidden');
      document.querySelector(`[data-target="${tabId}"]`).classList.add('nav-active');
      
      const header = document.getElementById('main-header');
      const mainContent = document.getElementById('app-content');

      clearTimeout(headerTimeout);

      // Hide header completely on wallet and profile pages for a cleaner dashboard look
      if (tabId === 'wallet' || tabId === 'profile') {
        header.style.transform = 'translateY(-120%)';
        headerTimeout = setTimeout(() => header.style.display = 'none', 300);
        mainContent.classList.remove('pt-[130px]');
        mainContent.classList.add('pt-4');
      } else {
        header.style.display = 'block'; 
        setTimeout(() => header.style.transform = 'translateY(0)', 10);
        mainContent.classList.remove('pt-4');
        mainContent.classList.add('pt-[130px]');
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
        
        const el = document.getElementById('reset-timer');
        if(el) {
          el.innerText = `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
        }
    }
    
    async function initApp() {
      const res = await apiCall('init');
      
      setTimeout(() => {
        document.getElementById('loading-overlay').style.opacity = '0';
        setTimeout(() => { document.getElementById('loading-overlay').style.display = 'none'; }, 700); 
      }, 800);
      
      setInterval(updateTimer, 1000);
      updateTimer();
    }

    window.addEventListener('load', initApp);
  </script>
</body>
</html>
