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
            'lastName' => $input['lastName'] ?? '',
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
                    'name' => trim(($input['firstName'] ?? '') . ' ' . ($input['lastName'] ?? '')),
                    'username' => $input['username'] ?? '',
                    'status' => 'Pending',
                    'ads' => 0,
                    'tasks' => 0,
                    'joinDate' => date('M j, Y')
                ];
                writeDB('referrals.json', $referrals);
            }
        }
    } else {
        // Update user profile dynamically if changed
        if (isset($input['firstName'])) $users[$uid]['firstName'] = $input['firstName'];
        if (isset($input['lastName'])) $users[$uid]['lastName'] = $input['lastName'];
        if (isset($input['username'])) $users[$uid]['username'] = $input['username'];
        if (isset($input['photoUrl']) && !empty($input['photoUrl'])) $users[$uid]['photoUrl'] = $input['photoUrl'];
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
                        'desc' => "Referral: " . trim($refUser['firstName'] . ' ' . $refUser['lastName']),
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
            
            if ($amount >= 10 && $amount <= $users[$uid]['usd'] && strlen($address) > 5) {
                $users[$uid]['usd'] -= $amount;
                
                if (!isset($withdrawals[$uid])) $withdrawals[$uid] = [];
                array_unshift($withdrawals[$uid], [
                    'id' => '#' . strtoupper(substr(md5(uniqid()), 0, 6)),
                    'amount' => $amount,
                    'address' => $address, // Store full address, front-end will truncate if needed
                    'date' => date('M j, Y'),
                    'status' => 'Pending',
                    'currency' => 'USDT',
                    'network' => 'TON'
                ]);
                writeDB('withdrawals.json', $withdrawals);
            } else {
                $response['error'] = 'Invalid withdrawal request';
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
  <title>Point Play - Premium Telegram Mini App</title>
  
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
              bg: '#030308',
              card: '#0a0a16',
              primary: '#3b82f6',
              glow: '#00e5ff',
              accent: '#7c3aed',
              gold: '#ffd700',
              silver: '#e2e8f0',
              bronze: '#d48855',
              success: '#10b981',
              danger: '#ef4444'
            }
          },
          animation: {
            'blob': 'blob 10s infinite',
            'float': 'float 6s ease-in-out infinite',
            'pulse-fast': 'pulse 1.5s cubic-bezier(0.4, 0, 0.6, 1) infinite',
            'pop': 'pop 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards',
            'shimmer': 'shimmer 2.5s infinite',
            'slide-up': 'slideUp 0.5s ease-out forwards'
          },
          keyframes: {
            blob: {
              '0%': { transform: 'translate(0px, 0px) scale(1)' },
              '33%': { transform: 'translate(40px, -60px) scale(1.2)' },
              '66%': { transform: 'translate(-30px, 30px) scale(0.8)' },
              '100%': { transform: 'translate(0px, 0px) scale(1)' },
            },
            float: {
              '0%, 100%': { transform: 'translateY(0)' },
              '50%': { transform: 'translateY(-10px)' },
            },
            pop: {
              '0%': { transform: 'scale(0.8)', opacity: 0 },
              '100%': { transform: 'scale(1)', opacity: 1 }
            },
            shimmer: {
              '0%': { transform: 'translateX(-150%) skewX(-15deg)' },
              '100%': { transform: 'translateX(150%) skewX(-15deg)' }
            },
            slideUp: {
              '0%': { transform: 'translateY(20px)', opacity: 0 },
              '100%': { transform: 'translateY(0)', opacity: 1 }
            }
          }
        }
      }
    }
  </script>

  <style>
    :root {
      --safe-area-top: env(safe-area-inset-top, 0px);
      --safe-area-bottom: env(safe-area-inset-bottom, 0px);
    }
    body {
      background-color: #030308;
      color: #f8fafc;
      overflow-x: hidden;
      -webkit-touch-callout: none;
      -webkit-user-select: none;
      user-select: none;
      -webkit-tap-highlight-color: transparent;
    }
    .bg-orb-1 {
      position: fixed; top: -15%; left: -15%; width: 70vw; height: 70vw;
      background: radial-gradient(circle, rgba(124, 58, 237, 0.15) 0%, rgba(0, 0, 0, 0) 60%);
      z-index: -1; filter: blur(50px);
    }
    .bg-orb-2 {
      position: fixed; bottom: -10%; right: -20%; width: 80vw; height: 80vw;
      background: radial-gradient(circle, rgba(0, 229, 255, 0.12) 0%, rgba(0, 0, 0, 0) 65%);
      z-index: -1; filter: blur(60px);
    }

    input { user-select: auto !important; }
    img { pointer-events: none; }
    ::-webkit-scrollbar { width: 0px; background: transparent; }

    .premium-glass {
      background: linear-gradient(145deg, rgba(20, 21, 35, 0.7) 0%, rgba(10, 11, 20, 0.9) 100%);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      border: 1px solid rgba(255, 255, 255, 0.06);
      box-shadow: 0 10px 40px -10px rgba(0, 0, 0, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.1);
    }
    
    .premium-btn {
      background: linear-gradient(135deg, rgba(59,130,246,0.15) 0%, rgba(0,229,255,0.05) 100%);
      border: 1px solid rgba(0,229,255,0.25);
      box-shadow: 0 0 20px rgba(0,229,255,0.05) inset;
      transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .premium-btn:active {
      transform: scale(0.96);
      border-color: rgba(0,229,255,0.6);
      background: rgba(0,229,255,0.1);
    }

    .fade-in { animation: slideUp 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards; }

    /* Navigation styling */
    .nav-active {
      color: #00e5ff !important;
    }
    .nav-active i {
      transform: translateY(-4px);
      filter: drop-shadow(0 4px 6px rgba(0, 229, 255, 0.4));
    }
    .nav-active span {
      opacity: 1 !important;
      transform: translateY(-2px);
    }
    .nav-active::after {
      content: ''; position: absolute; bottom: -8px; left: 50%; transform: translateX(-50%);
      width: 4px; height: 4px; background: #00e5ff; border-radius: 50%;
      box-shadow: 0 0 10px #00e5ff;
    }

    .action-btn {
      background: linear-gradient(to bottom, #3b82f6, #2563eb);
      position: relative;
      overflow: hidden;
      transition: transform 0.1s;
    }
    .action-btn:active { transform: scale(0.97); }
    .action-btn::after {
      content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0;
      background: linear-gradient(rgba(255,255,255,0.2), transparent);
      opacity: 0.5; border-radius: inherit; pointer-events: none;
    }

    #toast-container {
      position: fixed; top: 1.5rem; left: 50%; transform: translate(-50%, -150%) scale(0.9);
      width: calc(100% - 2rem); max-width: 400px; z-index: 999999;
      transition: all 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
      opacity: 0; pointer-events: none;
    }
    .toast-show { transform: translate(-50%, var(--safe-area-top)) scale(1) !important; opacity: 1 !important; }

    .box-bronze { background: linear-gradient(145deg, rgba(35,21,12,0.8), rgba(20,10,5,0.9)); border: 1px solid rgba(212,136,85,0.3); }
    .box-silver { background: linear-gradient(145deg, rgba(30,41,59,0.8), rgba(15,23,42,0.9)); border: 1px solid rgba(226,232,240,0.3); }
    .box-gold { background: linear-gradient(145deg, rgba(69,45,0,0.8), rgba(30,20,0,0.9)); border: 1px solid rgba(255,215,0,0.4); }

    .modal-overlay {
      background: rgba(3, 3, 8, 0.9);
      backdrop-filter: blur(12px);
      z-index: 10000;
    }
    
    .text-truncate {
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
  </style>
</head>
<body class="flex flex-col min-h-screen pb-28">
  
  <div class="bg-orb-1 animate-blob"></div>
  <div class="bg-orb-2 animate-blob" style="animation-delay: 2s;"></div>

  <!-- Start Screen -->
  <div id="loading-overlay" class="bg-[#030308] flex flex-col items-center justify-center z-[100000] fixed inset-0 transition-opacity duration-700">
    <div class="relative w-28 h-28 mb-8 flex items-center justify-center">
      <div class="absolute inset-0 rounded-full border-t-2 border-crypto-glow animate-[spin_1.5s_linear_infinite] shadow-[0_0_30px_rgba(0,229,255,0.3)]"></div>
      <div class="absolute inset-2 rounded-full border-b-2 border-purple-500 animate-[spin_2s_linear_infinite_reverse]"></div>
      <div class="absolute inset-4 rounded-full border-r-2 border-blue-500 animate-[spin_1s_linear_infinite]"></div>
      <div class="relative z-10 w-16 h-16 bg-[#0a0a16] rounded-full flex items-center justify-center premium-glass shadow-[0_0_20px_#00e5ff]">
        <i class="fa-solid fa-gamepad text-crypto-glow text-3xl animate-pulse"></i>
      </div>
    </div>
    <h2 class="text-white font-black tracking-[0.3em] text-2xl uppercase bg-clip-text text-transparent bg-gradient-to-r from-crypto-glow via-blue-400 to-purple-500 mb-3 drop-shadow-2xl">Point Play</h2>
    <p class="text-[10px] text-slate-500 uppercase tracking-widest font-semibold">Premium Experience</p>
  </div>

  <!-- Notification Toast -->
  <div id="toast-container" class="premium-glass rounded-2xl p-4 flex items-center gap-4">
    <div id="toast-icon" class="w-12 h-12 rounded-2xl flex shrink-0 items-center justify-center text-xl shadow-inner border">
      <i class="fa-solid fa-bell"></i>
    </div>
    <div class="flex-1 min-w-0">
      <h4 id="toast-title" class="text-sm font-black text-white tracking-wide truncate">Notification</h4>
      <p id="toast-message" class="text-xs text-slate-400 mt-0.5 leading-snug truncate">Message goes here</p>
    </div>
  </div>

  <!-- HEADER -->
  <header id="main-header" class="fixed top-0 left-0 right-0 z-50 w-full premium-glass rounded-b-3xl border-t-0 shadow-2xl transition-transform duration-300" style="padding-top: max(1rem, var(--safe-area-top));">
    <div class="p-4 flex justify-between items-center max-w-md mx-auto w-full">
      <div class="flex items-center gap-3 min-w-0 flex-1">
        <div class="relative w-11 h-11 rounded-full p-[2px] bg-gradient-to-tr from-purple-500 via-crypto-glow to-blue-500 shadow-[0_0_15px_rgba(0,229,255,0.3)] shrink-0">
          <img id="header-photo" src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=" alt="Profile" class="w-full h-full rounded-full object-cover border-2 border-[#030308] bg-[#0a0a16]">
          <div class="absolute -bottom-1 -right-1 w-4 h-4 bg-[#030308] rounded-full flex items-center justify-center">
            <span class="w-2 h-2 rounded-full bg-crypto-success shadow-[0_0_8px_#10b981] animate-pulse"></span>
          </div>
        </div>
        <div class="flex flex-col min-w-0">
          <span id="header-name" class="font-bold text-white text-sm tracking-wide truncate">Loading...</span>
          <span class="text-[10px] text-slate-400 uppercase font-semibold tracking-widest mt-0.5">Dashboard</span>
        </div>
      </div>
      <div class="flex flex-col items-end gap-1.5 shrink-0 pl-2">
        <div class="premium-btn px-3 py-1.5 rounded-xl flex items-center gap-2">
          <i class="fa-solid fa-bolt text-crypto-glow text-[11px] drop-shadow-[0_0_5px_#00e5ff]"></i>
          <span id="header-xp" class="text-white font-black text-sm tracking-wide">0</span>
        </div>
      </div>
    </div>
  </header>

  <!-- BODY CONTENT -->
  <main class="flex-1 max-w-md w-full mx-auto p-4 pt-[110px] relative w-full overflow-hidden" id="app-content">
    
    <!-- 1. HOME PAGE -->
    <div id="view-home" class="view-section fade-in space-y-6">
      <!-- Balance Card -->
      <div class="relative premium-glass rounded-[2rem] p-6 text-center overflow-hidden flex flex-col items-center justify-center min-h-[260px] animate-pop">
        <div class="absolute top-0 right-0 w-64 h-64 bg-blue-600/10 rounded-full filter blur-[60px] pointer-events-none"></div>
        <div class="absolute bottom-0 left-0 w-64 h-64 bg-purple-600/10 rounded-full filter blur-[60px] pointer-events-none"></div>
        
        <div class="relative z-10 flex flex-col items-center w-full">
          <div class="w-16 h-16 rounded-full premium-glass flex items-center justify-center mb-4 shadow-[0_0_30px_rgba(0,229,255,0.2)]">
             <i class="fa-solid fa-gem text-3xl text-crypto-glow drop-shadow-[0_0_15px_rgba(0,229,255,0.6)]"></i>
          </div>
          <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.3em] mb-2">Total Balance</p>
          <h1 class="text-5xl font-black text-transparent bg-clip-text bg-gradient-to-br from-white via-cyan-50 to-blue-300 tracking-tighter drop-shadow-2xl mb-1" id="home-xp-display">0 XP</h1>
          <p class="text-xs font-bold text-emerald-400 bg-emerald-500/10 px-3 py-1 rounded-full border border-emerald-500/20 mt-2">≈ $<span id="home-usd-display">0.00</span></p>
        </div>

        <div class="w-full mt-6 grid grid-cols-2 gap-3 relative z-10">
          <div class="bg-black/40 border border-white/5 p-3 rounded-2xl flex items-center gap-3 backdrop-blur-md">
            <div class="bg-blue-500/10 p-2.5 rounded-xl border border-blue-500/20 text-blue-400"><i class="fa-solid fa-clapperboard"></i></div>
            <div class="text-left">
              <p class="text-[9px] text-slate-500 uppercase font-bold tracking-wider">Ads</p>
              <p class="text-sm font-black text-white"><span id="home-ads-watched" class="text-blue-400">0</span>/30</p>
            </div>
          </div>
          <div class="bg-black/40 border border-white/5 p-3 rounded-2xl flex items-center gap-3 backdrop-blur-md">
            <div class="bg-amber-500/10 p-2.5 rounded-xl border border-amber-500/20 text-amber-400"><i class="fa-solid fa-fire"></i></div>
            <div class="text-left">
              <p class="text-[9px] text-slate-500 uppercase font-bold tracking-wider">Streak</p>
              <p class="text-sm font-black text-white"><span id="home-streak" class="text-amber-400">1</span> Days</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Action Button -->
      <button onclick="watchAd()" id="watch-ad-btn" class="w-full py-4 rounded-2xl text-white font-black text-sm tracking-widest uppercase flex items-center justify-center gap-3 shadow-[0_10px_30px_rgba(59,130,246,0.4)] action-btn group border border-blue-400/30">
        <div class="absolute inset-0 bg-gradient-to-r from-transparent via-white/20 to-transparent -translate-x-full group-hover:animate-[shimmer_2s_infinite]"></div>
        <div class="bg-white/20 p-2 rounded-full flex items-center justify-center backdrop-blur-sm"><i class="fa-solid fa-play text-[10px]"></i></div> 
        <span>Watch Ad <span class="text-cyan-200 ml-1 font-extrabold">+20 XP</span></span>
      </button>

      <div class="premium-glass rounded-xl p-3.5 flex justify-between items-center text-sm border-white/5">
        <div class="flex items-center gap-2 text-slate-400">
          <i class="fa-solid fa-clock text-crypto-glow/70"></i> <span class="text-xs font-semibold">Next Reset</span>
        </div>
        <span class="text-white font-mono font-bold tracking-widest bg-black/40 px-3 py-1 rounded-lg border border-white/5" id="reset-timer">--:--:--</span>
      </div>
    </div>

    <!-- 2. TASKS PAGE -->
    <div id="view-tasks" class="view-section hidden fade-in space-y-5 pb-4">
      <div class="text-left pt-2 pb-1">
        <h2 class="text-3xl font-black text-white tracking-tight drop-shadow-lg">Earn XP</h2>
        <p class="text-xs text-slate-400 mt-1 uppercase tracking-widest font-semibold">Complete missions & claim rewards</p>
      </div>
      
      <!-- ONLY ONE Daily Login (Streak Tracker) -->
      <div class="premium-glass rounded-[1.5rem] p-5 relative overflow-hidden border-t border-t-crypto-glow/30">
        <div class="absolute -right-10 -top-10 w-40 h-40 bg-blue-600/10 rounded-full blur-3xl pointer-events-none"></div>
        
        <div class="flex justify-between items-center mb-5 relative z-10">
          <h3 class="text-xs font-black text-white uppercase tracking-widest flex items-center gap-2">
            <div class="w-7 h-7 rounded-lg bg-crypto-glow/10 border border-crypto-glow/30 flex items-center justify-center">
              <i class="fa-solid fa-calendar-check text-crypto-glow text-sm"></i>
            </div>
            Daily Login
          </h3>
          <span class="text-[9px] bg-blue-500/20 text-blue-400 px-2 py-1 rounded-lg border border-blue-500/30 font-bold uppercase tracking-wider">Top Task</span>
        </div>
        
        <div class="relative flex justify-between items-center w-full" id="daily-login-container">
          <!-- Populated by JS -->
        </div>
      </div>

      <!-- Other Missions -->
      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-2 mb-3 mt-2 flex items-center gap-2"><i class="fa-solid fa-list text-slate-600"></i> Standard Missions</h3>
        <div id="missions-container" class="space-y-3">
          <!-- Populated by JS (No Daily Share, No duplicate Daily Login) -->
        </div>
      </div>
      
      <!-- Sponsored Tasks -->
      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-2 mb-3 mt-4 flex items-center gap-2"><i class="fa-solid fa-star text-slate-600"></i> Sponsored</h3>
        <div id="azx-task-container">
          <!-- Populated by JS -->
        </div>
      </div>
    </div>

    <!-- 3. BOXES PAGE -->
    <div id="view-boxes" class="view-section hidden fade-in space-y-4 pb-4">
      <div class="text-left pt-2 pb-2">
        <h2 class="text-3xl font-black text-white tracking-tight drop-shadow-lg">Mystery Boxes</h2>
        <p class="text-xs text-crypto-gold mt-1 uppercase tracking-widest font-bold">Exchange XP for USDT</p>
      </div>
      
      <div class="box-bronze rounded-[1.5rem] p-5 relative overflow-hidden flex justify-between items-center transition-all duration-300 hover:scale-[1.02] shadow-[0_10px_30px_rgba(212,136,85,0.1)] group">
        <div class="absolute -right-10 top-1/2 -translate-y-1/2 w-40 h-40 bg-crypto-bronze/10 rounded-full blur-3xl pointer-events-none group-hover:bg-crypto-bronze/20 transition-colors"></div>
        <div class="flex items-center gap-4 relative z-10">
          <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-[#5a3a24] to-[#1a100a] border border-crypto-bronze/50 flex items-center justify-center shadow-inner relative">
            <i class="fa-solid fa-box text-2xl text-crypto-bronze drop-shadow-[0_0_10px_rgba(212,136,85,0.5)]"></i>
          </div>
          <div>
            <h3 class="text-lg font-black text-white tracking-wide">Bronze Box</h3>
            <div class="flex flex-col gap-1 mt-1">
              <span class="text-[10px] text-white/80 font-bold tracking-wider uppercase bg-black/40 px-2 py-0.5 rounded inline-block w-fit border border-white/10"><i class="fa-solid fa-bolt text-crypto-glow mr-1"></i> 10,000 XP</span>
              <span class="text-[10px] text-emerald-400 font-bold uppercase tracking-wider">Win up to $1.00</span>
            </div>
          </div>
        </div>
        <button onclick="openBox('bronze')" class="relative z-10 bg-gradient-to-b from-[#d48855] to-[#a65d2a] text-white shadow-[0_5px_15px_rgba(212,136,85,0.4)] hover:brightness-110 active:scale-95 transition-all px-5 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest border border-[#f5a875]">Open</button>
      </div>

      <div class="box-silver rounded-[1.5rem] p-5 relative overflow-hidden flex justify-between items-center transition-all duration-300 hover:scale-[1.02] shadow-[0_10px_30px_rgba(226,232,240,0.05)] group">
        <div class="absolute -right-10 top-1/2 -translate-y-1/2 w-40 h-40 bg-crypto-silver/10 rounded-full blur-3xl pointer-events-none group-hover:bg-crypto-silver/20 transition-colors"></div>
        <div class="flex items-center gap-4 relative z-10">
          <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-[#475569] to-[#0f172a] border border-crypto-silver/50 flex items-center justify-center shadow-inner relative">
            <i class="fa-solid fa-box-open text-2xl text-crypto-silver drop-shadow-[0_0_10px_rgba(226,232,240,0.5)]"></i>
          </div>
          <div>
            <h3 class="text-lg font-black text-white tracking-wide">Silver Box</h3>
            <div class="flex flex-col gap-1 mt-1">
              <span class="text-[10px] text-white/80 font-bold tracking-wider uppercase bg-black/40 px-2 py-0.5 rounded inline-block w-fit border border-white/10"><i class="fa-solid fa-bolt text-crypto-glow mr-1"></i> 50,000 XP</span>
              <span class="text-[10px] text-emerald-400 font-bold uppercase tracking-wider">Win up to $7.00</span>
            </div>
          </div>
        </div>
        <button onclick="openBox('silver')" class="relative z-10 bg-gradient-to-b from-[#94a3b8] to-[#475569] text-white shadow-[0_5px_15px_rgba(148,163,184,0.4)] hover:brightness-110 active:scale-95 transition-all px-5 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest border border-[#cbd5e1]">Open</button>
      </div>

      <div class="box-gold rounded-[1.5rem] p-5 relative overflow-hidden flex justify-between items-center transition-all duration-300 hover:scale-[1.02] shadow-[0_10px_30px_rgba(255,215,0,0.15)] group">
        <div class="absolute -right-10 top-1/2 -translate-y-1/2 w-40 h-40 bg-crypto-gold/15 rounded-full blur-3xl pointer-events-none group-hover:bg-crypto-gold/25 transition-colors animate-pulse-fast"></div>
        <div class="flex items-center gap-4 relative z-10">
          <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-[#856500] to-[#241a00] border border-crypto-gold/60 flex items-center justify-center shadow-inner relative">
            <i class="fa-solid fa-gem text-2xl text-crypto-gold drop-shadow-[0_0_15px_#ffd700]"></i>
          </div>
          <div>
            <h3 class="text-lg font-black text-crypto-gold tracking-wide drop-shadow-[0_0_5px_rgba(255,215,0,0.5)]">Gold Box</h3>
            <div class="flex flex-col gap-1 mt-1">
              <span class="text-[10px] text-white/80 font-bold tracking-wider uppercase bg-black/40 px-2 py-0.5 rounded inline-block w-fit border border-white/10"><i class="fa-solid fa-bolt text-crypto-glow mr-1"></i> 100,000 XP</span>
              <span class="text-[10px] text-emerald-400 font-bold uppercase tracking-wider">Win up to $15.00</span>
            </div>
          </div>
        </div>
        <button onclick="openBox('gold')" class="relative z-10 bg-gradient-to-b from-[#ffd700] to-[#b38600] text-black shadow-[0_5px_20px_rgba(255,215,0,0.5)] hover:brightness-110 active:scale-95 transition-all px-5 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest border border-[#ffeb73]">Open</button>
      </div>
    </div>

    <!-- 4. WALLET PAGE (Withdraw) -->
    <div id="view-withdraw" class="view-section hidden fade-in space-y-6 pb-4">
      <div class="text-left pt-2 pb-1">
        <h2 class="text-3xl font-black text-white tracking-tight drop-shadow-lg">Wallet</h2>
        <p class="text-xs text-slate-400 mt-1 uppercase tracking-widest font-semibold">Withdraw your earnings</p>
      </div>

      <div class="premium-glass rounded-[2rem] p-6 text-center border-t border-emerald-500/30 bg-gradient-to-b from-emerald-900/20 to-transparent relative overflow-hidden">
        <div class="absolute top-0 right-0 w-48 h-48 bg-emerald-500/10 rounded-full blur-3xl pointer-events-none"></div>
        <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-2 relative z-10">Available Balance</p>
        <h1 class="text-5xl font-black text-white tracking-tighter mb-4 relative z-10 drop-shadow-lg flex items-center justify-center gap-2">
          $<span id="wallet-balance">0</span>
        </h1>
        <div class="inline-flex items-center gap-2 bg-black/50 backdrop-blur-md px-4 py-2 rounded-xl border border-white/10 relative z-10">
          <div class="w-6 h-6 rounded-full bg-[#0098EA] flex items-center justify-center p-1">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" class="w-full h-full text-white"><path d="M12 22C17.5228 22 22 17.5228 22 12C22 6.47715 17.5228 2 12 2C6.47715 2 2 6.47715 2 12C2 17.5228 6.47715 22 12 22Z" fill="#0098EA"/><path d="M16.5 8H7.5C6.67157 8 6 8.67157 6 9.5C6 10.3284 6.67157 11 7.5 11H16.5C17.3284 11 18 10.3284 18 9.5C18 8.67157 17.3284 8 16.5 8Z" fill="white"/><path d="M12 11V17M12 17L9.5 14.5M12 17L14.5 14.5" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </div>
          <span class="text-xs font-bold text-slate-200 uppercase tracking-widest">USDT (TON Network)</span>
        </div>
      </div>

      <div class="premium-glass rounded-[1.5rem] p-5 space-y-5 border-white/5 relative z-10">
        <!-- Minimum Alert -->
        <div class="bg-blue-500/10 border border-blue-500/20 rounded-xl p-3 flex gap-3 items-start">
          <i class="fa-solid fa-circle-info text-blue-400 mt-0.5"></i>
          <div>
            <p class="text-xs font-bold text-white mb-0.5">Withdrawal Requirements</p>
            <p class="text-[10px] text-slate-400 leading-relaxed">Minimum withdrawal is <strong class="text-emerald-400">$10 USDT</strong>. Withdrawals are processed exclusively via the <strong class="text-blue-400">TON Network</strong>. A valid TON wallet address is required.</p>
          </div>
        </div>

        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">TON Wallet Address <span class="text-red-400">*</span></label>
          <div class="relative group">
            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
              <i class="fa-solid fa-wallet text-slate-500 group-focus-within:text-blue-400 transition-colors"></i>
            </div>
            <input type="text" id="wallet-address" placeholder="UQ..." class="w-full bg-black/40 border border-white/10 rounded-xl py-3.5 pl-11 pr-4 text-sm font-medium text-white focus:outline-none focus:border-blue-500 focus:bg-black/60 transition-all placeholder-slate-600 shadow-inner">
          </div>
        </div>
        
        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">Amount (USDT)</label>
          <div class="relative group">
            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
              <i class="fa-solid fa-dollar-sign text-slate-500 group-focus-within:text-emerald-400 transition-colors text-lg"></i>
            </div>
            <input type="number" id="withdraw-amount" placeholder="10" min="10" step="0.5" class="w-full bg-black/40 border border-white/10 rounded-xl py-3.5 pl-11 pr-4 text-sm font-bold text-emerald-400 focus:outline-none focus:border-emerald-500 focus:bg-black/60 transition-all placeholder-slate-600 shadow-inner">
          </div>
        </div>

        <button onclick="requestWithdrawal()" id="withdraw-btn" class="w-full py-4 mt-2 bg-gradient-to-r from-emerald-600 to-teal-500 hover:from-emerald-500 hover:to-teal-400 active:scale-95 transition-all text-white font-black rounded-xl text-sm uppercase tracking-widest flex items-center justify-center gap-2 shadow-[0_10px_20px_rgba(16,185,129,0.2)] border border-emerald-400/30">
          Request Withdrawal <i class="fa-solid fa-arrow-right ml-1"></i>
        </button>
      </div>

      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-2 mb-3"><i class="fa-solid fa-clock-rotate-left mr-1"></i> History</h3>
        <div id="withdraw-history-container" class="space-y-3">
          <!-- Populated via JS -->
        </div>
      </div>
    </div>

    <!-- 5. PROFILE PAGE -->
    <div id="view-profile" class="view-section hidden fade-in space-y-5 pb-4">
      <!-- Profile Header -->
      <div class="premium-glass rounded-[2rem] p-6 text-center border-t border-purple-500/30 relative overflow-hidden">
        <div class="absolute top-0 right-0 w-32 h-32 bg-purple-600/20 rounded-full blur-3xl pointer-events-none"></div>
        
        <div class="w-24 h-24 mx-auto rounded-full p-1 bg-gradient-to-tr from-purple-500 via-crypto-glow to-blue-500 shadow-[0_0_25px_rgba(124,58,237,0.3)] mb-4 relative z-10">
          <img id="profile-photo" src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=" alt="Profile" class="w-full h-full rounded-full object-cover border-4 border-[#030308] bg-[#0a0a16]">
        </div>
        
        <h3 id="profile-full-name" class="text-xl font-black text-white tracking-wide truncate px-4 relative z-10">Name</h3>
        <p id="profile-username" class="text-xs text-crypto-glow font-medium mt-1 truncate px-4 relative z-10">@username</p>
        <p class="text-[10px] text-slate-500 font-mono mt-2 bg-black/40 inline-block px-3 py-1 rounded-lg border border-white/5 relative z-10">ID: <span id="profile-id">00000</span></p>
      </div>

      <!-- Level & Progress -->
      <div class="premium-glass rounded-2xl p-5 border-white/5">
        <div class="flex justify-between items-end mb-3">
          <div>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest flex items-center gap-1.5"><i class="fa-solid fa-ranking-star text-purple-400"></i> Current Level</p>
            <p class="text-lg font-black text-white mt-1">Level <span id="profile-level" class="text-purple-400">1</span></p>
          </div>
          <div class="text-right">
            <span id="profile-percent" class="text-xs font-black text-crypto-glow block mb-1">0%</span>
            <p class="text-[10px] font-bold text-slate-500"><span id="profile-current-xp" class="text-white">0</span> / <span id="profile-next-xp">250</span> XP</p>
          </div>
        </div>
        <div class="w-full bg-black/50 rounded-full h-2 overflow-hidden shadow-inner border border-white/5">
          <div id="profile-progress-bar" class="bg-gradient-to-r from-purple-500 via-blue-500 to-crypto-glow h-full rounded-full shadow-[0_0_10px_#00e5ff] transition-all duration-1000" style="width: 0%"></div>
        </div>
      </div>

      <!-- Stats Grid -->
      <div class="grid grid-cols-2 gap-3">
        <div class="premium-glass border border-white/5 p-4 rounded-2xl flex flex-col items-center justify-center text-center">
          <div class="w-8 h-8 rounded-full bg-blue-500/10 flex items-center justify-center text-blue-400 mb-2 border border-blue-500/20"><i class="fa-solid fa-bolt"></i></div>
          <p class="text-[9px] text-slate-400 uppercase font-bold tracking-widest mb-1">Total Earned</p>
          <p id="profile-total-xp" class="text-sm font-black text-white">0 XP</p>
        </div>
        <div class="premium-glass border border-white/5 p-4 rounded-2xl flex flex-col items-center justify-center text-center">
          <div class="w-8 h-8 rounded-full bg-emerald-500/10 flex items-center justify-center text-emerald-400 mb-2 border border-emerald-500/20"><i class="fa-solid fa-check-double"></i></div>
          <p class="text-[9px] text-slate-400 uppercase font-bold tracking-widest mb-1">Tasks Done</p>
          <p id="profile-tasks" class="text-sm font-black text-white">0</p>
        </div>
        <div class="premium-glass border border-white/5 p-4 rounded-2xl flex flex-col items-center justify-center text-center">
          <div class="w-8 h-8 rounded-full bg-amber-500/10 flex items-center justify-center text-amber-400 mb-2 border border-amber-500/20"><i class="fa-solid fa-box-open"></i></div>
          <p class="text-[9px] text-slate-400 uppercase font-bold tracking-widest mb-1">Boxes Opened</p>
          <p id="profile-boxes" class="text-sm font-black text-white">0</p>
        </div>
        <div class="premium-glass border border-white/5 p-4 rounded-2xl flex flex-col items-center justify-center text-center">
          <div class="w-8 h-8 rounded-full bg-pink-500/10 flex items-center justify-center text-pink-400 mb-2 border border-pink-500/20"><i class="fa-solid fa-users"></i></div>
          <p class="text-[9px] text-slate-400 uppercase font-bold tracking-widest mb-1">Referrals</p>
          <p id="profile-refs" class="text-sm font-black text-white">0</p>
        </div>
      </div>

      <!-- Action Buttons -->
      <div class="space-y-3 pt-2">
        <button onclick="openReferralsView()" class="w-full premium-glass border border-white/10 py-4 rounded-xl text-white font-black text-xs uppercase tracking-widest flex items-center justify-between px-5 active:scale-95 transition-transform">
          <span class="flex items-center gap-3"><i class="fa-solid fa-user-plus text-pink-400 text-lg"></i> My Referrals</span>
          <i class="fa-solid fa-chevron-right text-slate-500 text-[10px]"></i>
        </button>
      </div>
    </div>

    <!-- REFERRALS SUB-VIEW (Not in Bottom Nav) -->
    <div id="view-referrals" class="view-section hidden fade-in space-y-6 pb-4">
      <div class="flex items-center gap-3 pt-2 pb-1">
        <button onclick="switchTab('profile')" class="w-10 h-10 rounded-xl premium-glass border border-white/10 flex items-center justify-center text-slate-400 active:scale-90 transition-transform">
          <i class="fa-solid fa-arrow-left"></i>
        </button>
        <div>
          <h2 class="text-2xl font-black text-white tracking-tight">Referrals</h2>
          <p class="text-[10px] text-pink-400 uppercase tracking-widest font-bold">Invite & Earn</p>
        </div>
        <button onclick="toggleRefInfo()" class="ml-auto w-10 h-10 rounded-xl bg-blue-500/10 border border-blue-500/30 flex items-center justify-center text-blue-400 active:scale-90 transition-transform">
          <i class="fa-solid fa-info text-sm"></i>
        </button>
      </div>

      <div class="grid grid-cols-3 gap-2">
        <div class="premium-glass p-4 rounded-2xl text-center border-t border-white/10">
          <p class="text-[9px] text-slate-400 uppercase font-black tracking-widest mb-1">Total</p>
          <p id="ref-total" class="text-xl font-black text-white">0</p>
        </div>
        <div class="premium-glass p-4 rounded-2xl text-center border-t border-amber-500/30 bg-amber-500/5">
          <p class="text-[9px] text-slate-400 uppercase font-black tracking-widest mb-1">Pending</p>
          <p id="ref-pending" class="text-xl font-black text-amber-400">0</p>
        </div>
        <div class="premium-glass p-4 rounded-2xl text-center border-t border-emerald-500/30 bg-emerald-500/5">
          <p class="text-[9px] text-slate-400 uppercase font-black tracking-widest mb-1">Approved</p>
          <p id="ref-approved" class="text-xl font-black text-emerald-400">0</p>
        </div>
      </div>

      <div class="premium-glass rounded-[1.5rem] p-5 space-y-4 border-white/5">
        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">Your Referral Link</label>
          <div class="flex items-center gap-2">
            <input type="text" id="ref-link-input" readonly class="flex-1 bg-black/40 border border-white/10 rounded-xl py-3.5 px-4 text-xs font-medium text-slate-300 focus:outline-none">
            <button onclick="copyRefLink()" class="bg-black/60 text-white w-12 h-12 rounded-xl flex items-center justify-center active:scale-95 transition-transform border border-white/10 hover:bg-white/5">
              <i class="fa-regular fa-copy"></i>
            </button>
          </div>
        </div>
        <button onclick="shareReferralTelegram()" class="w-full py-4 bg-gradient-to-r from-blue-600 to-blue-400 text-white font-black rounded-xl text-sm uppercase tracking-widest flex items-center justify-center gap-2 shadow-[0_5px_15px_rgba(59,130,246,0.3)] active:scale-95 transition-transform border border-blue-300/30">
          <i class="fa-brands fa-telegram text-lg"></i> Share on Telegram
        </button>
      </div>

      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-2 mb-3"><i class="fa-solid fa-users text-slate-600 mr-1"></i> Your Network</h3>
        <div id="referral-list-container" class="space-y-3"></div>
      </div>

      <div class="mt-6 border-t border-white/5 pt-6">
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-2 mb-3"><i class="fa-solid fa-gift text-slate-600 mr-1"></i> Reward History</h3>
        <div id="referral-rewards-container" class="space-y-3"></div>
      </div>
    </div>

  </main>

  <!-- BOTTOM NAVIGATION (Strict Order: Home, Tasks, Box, Wallet, Profile) -->
  <nav id="bottom-nav" class="fixed bottom-6 left-1/2 -translate-x-1/2 w-[calc(100%-2rem)] max-w-[400px] premium-glass rounded-2xl z-50 shadow-[0_20px_40px_rgba(0,0,0,0.8)] border border-white/10" style="padding-bottom: max(0.5rem, var(--safe-area-bottom));">
    <div class="flex justify-between items-center px-1 py-1.5 relative">
      <button onclick="switchTab('home')" class="nav-btn nav-active text-slate-500 flex flex-col items-center gap-1 flex-1 py-2 transition-all duration-300 relative group" data-target="home">
        <i class="fa-solid fa-house text-lg transition-transform duration-300 group-active:scale-90"></i>
        <span class="text-[8px] font-black uppercase tracking-widest opacity-70 transition-opacity">Home</span>
      </button>
      <button onclick="switchTab('tasks')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 py-2 transition-all duration-300 relative group" data-target="tasks">
        <i class="fa-solid fa-list-check text-lg transition-transform duration-300 group-active:scale-90"></i>
        <span class="text-[8px] font-black uppercase tracking-widest opacity-70 transition-opacity">Tasks</span>
      </button>
      <button onclick="switchTab('boxes')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 py-2 transition-all duration-300 relative group" data-target="boxes">
        <i class="fa-solid fa-box-open text-lg transition-transform duration-300 group-active:scale-90"></i>
        <span class="text-[8px] font-black uppercase tracking-widest opacity-70 transition-opacity">Box</span>
      </button>
      <button onclick="switchTab('withdraw')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 py-2 transition-all duration-300 relative group" data-target="withdraw">
        <i class="fa-solid fa-wallet text-lg transition-transform duration-300 group-active:scale-90"></i>
        <span class="text-[8px] font-black uppercase tracking-widest opacity-70 transition-opacity">Wallet</span>
      </button>
      <button onclick="switchTab('profile')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 py-2 transition-all duration-300 relative group" data-target="profile">
        <i class="fa-solid fa-user text-lg transition-transform duration-300 group-active:scale-90"></i>
        <span class="text-[8px] font-black uppercase tracking-widest opacity-70 transition-opacity">Profile</span>
      </button>
    </div>
  </nav>

  <!-- Referral Information Modal -->
  <div id="ref-info-modal" class="fixed inset-0 modal-overlay hidden flex-col items-center justify-center p-4 transition-opacity fade-in">
    <div class="premium-glass w-full max-w-sm rounded-[2rem] p-6 relative border border-blue-500/30 shadow-[0_0_40px_rgba(0,0,0,0.8)]">
      <button onclick="toggleRefInfo()" class="absolute top-4 right-4 w-8 h-8 rounded-full bg-black/50 text-slate-400 flex items-center justify-center hover:text-white active:scale-90 transition-transform border border-white/10">
        <i class="fa-solid fa-xmark"></i>
      </button>
      
      <div class="w-14 h-14 mx-auto bg-blue-500/10 rounded-full flex items-center justify-center mb-4 border border-blue-500/30 text-blue-400 text-2xl shadow-[0_0_20px_rgba(59,130,246,0.2)]">
        <i class="fa-solid fa-users"></i>
      </div>
      
      <h3 class="text-xl font-black text-white text-center mb-2 tracking-wide">Referral Rules</h3>
      <p class="text-xs text-slate-400 text-center mb-6 leading-relaxed">Invite your friends and earn rewards! A referral becomes <span class="text-emerald-400 font-bold">Approved</span> only when they complete the following requirements.</p>
      
      <ul class="space-y-3 mb-6">
        <li class="flex items-start gap-3 bg-black/40 p-3 rounded-xl border border-white/5">
          <i class="fa-solid fa-play text-blue-400 mt-1"></i>
          <div>
            <p class="text-sm font-black text-white">Watch 25 Ads</p>
            <p class="text-[10px] text-slate-500 mt-0.5">The user must watch a total of 25 ads.</p>
          </div>
        </li>
        <li class="flex items-start gap-3 bg-black/40 p-3 rounded-xl border border-white/5">
          <i class="fa-solid fa-list-check text-crypto-glow mt-1"></i>
          <div>
            <p class="text-sm font-black text-white">Complete 5 Tasks</p>
            <p class="text-[10px] text-slate-500 mt-0.5">The user must complete at least 5 standard missions.</p>
          </div>
        </li>
        <li class="flex items-start gap-3 bg-black/40 p-3 rounded-xl border border-white/5">
          <i class="fa-solid fa-clock text-amber-400 mt-1"></i>
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
    tg.setHeaderColor('#0a0a16');
    tg.setBackgroundColor('#030308');

    // Extract TG User Data safely
    const tgUser = tg.initDataUnsafe?.user || {
      id: Math.floor(Math.random() * 10000000), 
      first_name: "Demo",
      last_name: "User",
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

    // Helper: format numbers precisely to drop trailing zeroes (e.g. 10.00 -> 10, 10.50 -> 10.5)
    const fmtNum = (num) => parseFloat(Number(num).toFixed(4)).toString();

    // Central API Caller
    async function apiCall(action, payload = {}) {
      try {
        const body = {
          action: action,
          tgId: tgUser.id,
          firstName: tgUser.first_name,
          lastName: tgUser.last_name || '',
          username: tgUser.username || '',
          photoUrl: tgUser.photo_url || '',
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
      
      let iconClass, iconHtml;
      if (type === 'success') {
        iconHtml = '<i class="fa-solid fa-check"></i>';
        iconClass = 'bg-emerald-500/20 text-emerald-400 border-emerald-500/50 shadow-[0_0_20px_rgba(16,185,129,0.3)]';
      } else if (type === 'error') {
        iconHtml = '<i class="fa-solid fa-xmark"></i>';
        iconClass = 'bg-red-500/20 text-red-400 border-red-500/50 shadow-[0_0_20px_rgba(239,68,68,0.3)]';
      } else if (type === 'jackpot') {
        iconHtml = '<i class="fa-solid fa-sack-dollar animate-bounce"></i>';
        iconClass = 'bg-amber-500/20 text-amber-400 border-amber-500/50 shadow-[0_0_30px_rgba(251,191,36,0.6)]';
      } else {
        iconHtml = '<i class="fa-solid fa-bell animate-pulse"></i>';
        iconClass = 'bg-blue-500/20 text-crypto-glow border-crypto-glow/50 shadow-[0_0_20px_rgba(0,229,255,0.3)]';
      }

      icon.innerHTML = iconHtml;
      icon.className = `w-12 h-12 rounded-2xl flex shrink-0 items-center justify-center text-xl border ${iconClass}`;

      toast.classList.add('toast-show');
      
      if (tg.HapticFeedback) {
        if (type === 'success' || type === 'jackpot') tg.HapticFeedback.notificationOccurred('success');
        else if (type === 'error') tg.HapticFeedback.notificationOccurred('error');
        else tg.HapticFeedback.notificationOccurred('warning');
      }

      setTimeout(() => { toast.classList.remove('toast-show'); }, type === 'jackpot' ? 5000 : 3000); 
    }

    function getFallbackAvatar(name) {
      const initial = name ? name.charAt(0).toUpperCase() : 'U';
      const canvas = document.createElement('canvas');
      canvas.width = 150; canvas.height = 150;
      const ctx = canvas.getContext('2d');
      const grad = ctx.createLinearGradient(0, 0, 150, 150);
      grad.addColorStop(0, '#7c3aed');
      grad.addColorStop(1, '#3b82f6');
      ctx.fillStyle = grad;
      ctx.fillRect(0, 0, 150, 150);
      ctx.fillStyle = '#ffffff';
      ctx.font = 'bold 70px Outfit, sans-serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText(initial, 75, 75);
      return canvas.toDataURL();
    }

    function updateUI() {
      const u = appState.user;
      
      const fullName = trimString(`${u.firstName} ${u.lastName}`);
      const avatarSrc = u.photoUrl ? u.photoUrl : getFallbackAvatar(fullName);
      
      // Header
      document.getElementById('header-name').innerText = u.firstName;
      document.getElementById('header-xp').innerText = u.xp.toLocaleString();
      document.getElementById('header-photo').src = avatarSrc;

      // Profile Page 
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
      
      document.getElementById('profile-full-name').innerText = fullName;
      document.getElementById('profile-username').innerText = u.username ? `@${u.username}` : 'No username';
      document.getElementById('profile-id').innerText = u.tgId;
      document.getElementById('profile-level').innerText = u.level;
      document.getElementById('profile-current-xp').innerText = u.totalXp.toLocaleString();
      document.getElementById('profile-next-xp').innerText = nextTarget.toLocaleString();
      document.getElementById('profile-percent').innerText = `${Math.min(100, Math.max(0, progressPercent)).toFixed(1)}%`;
      document.getElementById('profile-progress-bar').style.width = `${Math.min(100, Math.max(0, progressPercent))}%`;
      document.getElementById('profile-total-xp').innerText = `${u.totalXp.toLocaleString()} XP`;
      document.getElementById('profile-tasks').innerText = u.tasksCompleted;
      document.getElementById('profile-boxes').innerText = u.boxesOpened;
      document.getElementById('profile-refs').innerText = appState.referrals.length;
      document.getElementById('profile-photo').src = avatarSrc;

      // Home Page
      document.getElementById('home-xp-display').innerText = `${u.xp.toLocaleString()} XP`;
      document.getElementById('home-usd-display').innerText = fmtNum(u.usd);
      document.getElementById('home-ads-watched').innerText = u.adsWatchedToday;
      document.getElementById('home-streak').innerText = u.streak;

      // Referrals Page
      const pending = appState.referrals.filter(r => r.status === 'Pending').length;
      const approved = appState.referrals.filter(r => r.status === 'Approved').length;
      document.getElementById('ref-total').innerText = appState.referrals.length;
      document.getElementById('ref-pending').innerText = pending;
      document.getElementById('ref-approved').innerText = approved;
      document.getElementById('ref-link-input').value = `https://t.me/pointplayappbot?startapp=${u.tgId}`;
      
      renderReferrals();
      renderRewardHistory();

      // Wallet / Withdraw Page
      document.getElementById('wallet-balance').innerText = fmtNum(u.usd);
      renderWithdrawHistory();

      // Tasks
      renderDailyLogin();
      renderMissions();
    }

    function trimString(str) {
      return str.trim() || 'User';
    }

    function renderDailyLogin() {
      const container = document.getElementById('daily-login-container');
      container.innerHTML = '';
      const rewards = [5, 10, 15, 20, 25, 30, 50];
      const streak = appState.user.streak || 1;
      const claimedToday = appState.tasks.includes('streakLogin');
      
      for (let i = 1; i <= 7; i++) {
        const isPast = i < streak || (i === streak && claimedToday);
        const isToday = i === streak && !claimedToday;
        
        let styles = "bg-black/50 border-white/5 text-slate-500";
        let icon = `<span class="text-[10px] font-black">${rewards[i-1]}</span>`;
        let lineStyle = "bg-white/5";
        
        if (isPast) {
          styles = "bg-emerald-500/10 border-emerald-500/30 text-emerald-400 shadow-[0_0_15px_rgba(16,185,129,0.2)]";
          icon = `<i class="fa-solid fa-check text-xs"></i>`;
          lineStyle = "bg-emerald-500/30";
        } else if (isToday) {
          styles = "bg-crypto-glow/10 border-crypto-glow shadow-[0_0_20px_rgba(0,229,255,0.4)] text-white cursor-pointer hover:scale-110";
        }

        const onClick = isToday ? `onclick="claimTask('streakLogin', ${rewards[i-1]})"` : '';
        const claimAnim = isToday ? 'animate-pulse' : '';

        container.innerHTML += `
          <div class="relative flex flex-col items-center gap-2 z-10 flex-1">
            <div ${onClick} class="w-10 h-10 rounded-xl border-2 flex items-center justify-center transition-all duration-300 ${styles} z-10 relative premium-glass ${claimAnim}">
              ${icon}
            </div>
            <span class="text-[8px] font-black tracking-widest ${isToday ? 'text-crypto-glow drop-shadow-[0_0_5px_rgba(0,229,255,0.8)]' : 'text-slate-500'}">DAY ${i}</span>
            ${i < 7 ? `<div class="absolute top-5 left-[50%] w-full h-1 -z-0 ${lineStyle} rounded-full"></div>` : ''}
          </div>
        `;
      }
      
      // If today is claimable, add a large claim button underneath
      if (!claimedToday) {
         const currentReward = rewards[streak - 1];
         container.innerHTML += `
            <div class="absolute inset-0 bg-black/60 backdrop-blur-[2px] flex items-center justify-center z-20 rounded-xl rounded-t-none top-1/2">
               <button onclick="claimTask('streakLogin', ${currentReward})" class="bg-gradient-to-r from-crypto-glow to-blue-500 text-black font-black uppercase text-xs px-6 py-2 rounded-xl shadow-[0_0_20px_rgba(0,229,255,0.4)] active:scale-95 transition-transform flex items-center gap-2">
                 Claim Day ${streak} Reward <i class="fa-solid fa-bolt"></i>
               </button>
            </div>
         `;
      }
    }

    function renderMissions() {
      const container = document.getElementById('missions-container');
      container.innerHTML = '';
      
      // Only Keep non-duplicate tasks (Removed mission_login, mission_share)
      const missionsList = [
        { id: 'watch5', label: 'Watch 5 Ads', icon: 'fa-video', color: 'text-blue-400', bg: 'bg-blue-500/10 border-blue-500/20', reward: 20, target: 5, current: appState.user.adsWatchedToday },
        { id: 'watch30', label: 'Watch 30 Ads', icon: 'fa-clapperboard', color: 'text-purple-400', bg: 'bg-purple-500/10 border-purple-500/20', reward: 50, target: 30, current: appState.user.adsWatchedToday }
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
            btnHtml = `<span class="text-[10px] font-black bg-emerald-500/10 text-emerald-400 px-3 py-1.5 rounded-lg border border-emerald-500/30 flex items-center gap-1 shadow-inner"><i class="fa-solid fa-check-double"></i> Claimed</span>`;
        } else if (canClaim) {
            btnHtml = `<button onclick="claimTask('${m.id}', ${m.reward})" class="text-[10px] font-black bg-gradient-to-r from-blue-500 to-crypto-glow text-black px-4 py-1.5 rounded-lg shadow-[0_0_15px_rgba(0,229,255,0.4)] active:scale-95 transition-all uppercase tracking-wider">Claim</button>`;
        } else {
            btnHtml = `<span class="text-[10px] font-black bg-white/5 text-slate-300 px-3 py-1.5 rounded-lg border border-white/10 shadow-inner">+${m.reward} XP</span>`;
        }

        container.innerHTML += `
          <div class="premium-glass rounded-2xl p-3 flex justify-between items-center transition-transform hover:-translate-y-0.5 border border-white/5">
            <div class="flex items-center gap-3">
              <div class="w-11 h-11 rounded-xl border ${m.bg} flex items-center justify-center shadow-inner">
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
      
      // Render AZX Sponsored Task
      const azxContainer = document.getElementById('azx-task-container');
      if (azxContainer) {
          const azxClaimed = appState.user.azxCryptoTaskCompleted;
          let azxBtn = azxClaimed
              ? `<span class="text-[10px] font-black bg-emerald-500/10 text-emerald-400 px-3 py-1.5 rounded-lg border border-emerald-500/30 flex items-center gap-1 shadow-inner"><i class="fa-solid fa-check-double"></i> Done</span>`
              : `<button onclick="claimAZXTask()" class="text-[10px] font-black bg-gradient-to-r from-blue-600 to-blue-400 text-white px-4 py-1.5 rounded-lg shadow-[0_0_15px_rgba(59,130,246,0.4)] active:scale-95 transition-all uppercase tracking-wider border border-blue-400/30">Join</button>`;

          azxContainer.innerHTML = `
            <div class="premium-glass rounded-2xl p-3 flex justify-between items-center transition-transform hover:-translate-y-0.5 border border-white/5">
              <div class="flex items-center gap-3">
                <div class="w-11 h-11 rounded-xl border bg-[#0088cc]/10 border-[#0088cc]/30 flex items-center justify-center shadow-inner">
                   <i class="fa-brands fa-telegram text-[#0088cc] text-2xl"></i>
                </div>
                <div class="flex flex-col">
                  <span class="text-xs font-black text-white tracking-wide">Join AZX Crypto</span>
                  <span class="text-crypto-glow text-[10px] font-bold tracking-widest uppercase opacity-80 mt-0.5">+200 XP</span>
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
        if(res && !res.error) showToast('Task Completed!', `You earned ${reward} XP!`, 'success');
    }
    
    async function claimAZXTask() {
        if(tg.HapticFeedback) tg.HapticFeedback.impactOccurred('medium');
        tg.openTelegramLink('https://t.me/azxcrypto');
        
        setTimeout(async () => {
            const res = await apiCall('claim_azx');
            if(res && !res.error) showToast('Task Completed!', `You earned 200 XP!`, 'success');
        }, 1500);
    }

    async function watchAd() {
      const btn = document.getElementById('watch-ad-btn');
      const originalHTML = btn.innerHTML;
      btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin text-white"></i> <span class="text-white">Loading Ad...</span>`;
      btn.classList.add('opacity-70', 'pointer-events-none');

      if (window.Adsgram) {
        const AdController = window.Adsgram.init({ blockId: "int-35545" });
        AdController.show().then(async () => {
          const res = await apiCall('watch_ad');
          if(res && !res.error) showToast('Reward Granted!', 'You earned +20 XP.', 'success');
          
          btn.innerHTML = originalHTML;
          btn.classList.remove('opacity-70', 'pointer-events-none');
        }).catch((e) => {
          btn.innerHTML = originalHTML;
          btn.classList.remove('opacity-70', 'pointer-events-none');
        });
      } else {
        showToast('Error', 'Ad system is currently unavailable.', 'error');
        btn.innerHTML = originalHTML;
        btn.classList.remove('opacity-70', 'pointer-events-none');
      }
    }

    async function openBox(type) {
      if(tg.HapticFeedback) tg.HapticFeedback.impactOccurred('heavy');
      const res = await apiCall('open_box', { boxType: type });
      
      if(res && !res.error) {
        if(res.jackpot) {
            showToast('HUGE JACKPOT! 💸', `Incredible! You won $${fmtNum(res.reward)}!`, 'jackpot');
        } else {
            showToast('Box Opened!', `Congratulations! You won $${fmtNum(res.reward)}!`, 'success');
        }
      }
    }

    async function requestWithdrawal() {
      const address = document.getElementById('wallet-address').value.trim();
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
          showToast('Withdrawal Requested', `Your request for $${fmtNum(amount)} has been submitted via TON network.`, 'success');
          document.getElementById('wallet-address').value = '';
          document.getElementById('withdraw-amount').value = '';
      }
    }

    function renderWithdrawHistory() {
      const container = document.getElementById('withdraw-history-container');
      const history = appState.withdrawals;
      
      if (history.length === 0) {
        container.innerHTML = `
          <div class="premium-glass rounded-2xl p-6 text-center border-dashed border-2 border-white/10">
            <i class="fa-solid fa-clock-rotate-left text-3xl text-slate-700 mb-2"></i>
            <p class="text-xs font-bold text-slate-500 uppercase tracking-widest">History is Empty</p>
          </div>`;
        return;
      }

      container.innerHTML = history.map(r => {
          const shortAddress = r.address.length > 10 ? r.address.substring(0,6) + '...' + r.address.substring(r.address.length-4) : r.address;
          return `
          <div class="premium-glass rounded-2xl p-4 flex justify-between items-center border border-white/5">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-full bg-black/40 border border-white/10 flex items-center justify-center">
                 <i class="fa-solid fa-arrow-right-arrow-left text-slate-400"></i>
              </div>
              <div>
                <p class="text-xs font-black text-white">${r.id} <span class="text-[9px] text-slate-500 ml-1 font-bold">${r.date}</span></p>
                <div class="flex gap-1 mt-0.5">
                    <p class="text-[9px] text-blue-400 font-mono bg-blue-500/10 px-1.5 py-0.5 rounded border border-blue-500/20">${shortAddress}</p>
                    <p class="text-[9px] text-slate-400 bg-white/5 px-1.5 py-0.5 rounded border border-white/10">${r.network || 'TON'}</p>
                </div>
              </div>
            </div>
            <div class="text-right flex flex-col items-end">
              <p class="text-sm font-black text-emerald-400">-$${fmtNum(r.amount)}</p>
              <p class="text-[9px] font-black text-amber-400 uppercase tracking-widest mt-0.5 bg-amber-500/10 px-2 py-0.5 rounded-full border border-amber-500/20">${r.status}</p>
            </div>
          </div>
      `}).join('');
    }

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
          <div class="premium-glass rounded-2xl p-6 text-center border-dashed border-2 border-white/10">
            <i class="fa-solid fa-user-plus text-3xl text-slate-700 mb-2"></i>
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
          <div class="premium-glass rounded-2xl p-4 flex flex-col gap-3 border border-white/5">
            <div class="flex justify-between items-center">
              <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-purple-500 to-blue-600 flex items-center justify-center font-black text-white shadow-[0_0_10px_rgba(124,58,237,0.4)]">${initial}</div>
                <div class="flex flex-col max-w-[120px]">
                  <span class="text-sm font-black text-white tracking-wide truncate">${r.name}</span>
                  ${displayUsername ? `<span class="text-[10px] text-slate-400 font-mono truncate">${displayUsername}</span>` : ''}
                </div>
              </div>
              <span class="text-[9px] font-black uppercase tracking-widest px-2 py-1 rounded-lg border ${statusClass}">${r.status}</span>
            </div>
            
            ${!isAppr ? `
            <div class="bg-black/40 rounded-xl p-3 border border-white/5 grid grid-cols-2 gap-3">
              <div class="text-center">
                <p class="text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1.5">Ads Watched</p>
                <div class="w-full bg-white/5 rounded-full h-1.5 mb-1 overflow-hidden">
                  <div class="bg-blue-500 h-full rounded-full shadow-[0_0_5px_#3b82f6]" style="width: ${Math.min((r.ads/25)*100, 100)}%"></div>
                </div>
                <p class="text-[10px] font-black text-white">${r.ads} <span class="text-slate-500">/ 25</span></p>
              </div>
              <div class="text-center">
                <p class="text-[9px] font-bold text-slate-500 uppercase tracking-widest mb-1.5">Tasks Done</p>
                <div class="w-full bg-white/5 rounded-full h-1.5 mb-1 overflow-hidden">
                  <div class="bg-crypto-glow h-full rounded-full shadow-[0_0_5px_#00e5ff]" style="width: ${Math.min((r.tasks/5)*100, 100)}%"></div>
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
          <div class="premium-glass rounded-2xl p-4 text-center border-dashed border border-white/10">
            <p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">No rewards yet</p>
          </div>`;
        return;
      }

      container.innerHTML = rewards.map(r => `
        <div class="premium-glass rounded-xl p-3 flex justify-between items-center border border-white/5">
          <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-emerald-500/10 border border-emerald-500/30 flex items-center justify-center text-emerald-400">
               <i class="fa-solid fa-gift text-sm"></i>
            </div>
            <div class="flex flex-col">
              <span class="text-xs font-black text-white">${r.title}</span>
              <span class="text-[9px] text-slate-400 max-w-[120px] truncate">${r.desc} • ${r.date}</span>
            </div>
          </div>
          <div class="text-right flex flex-col items-end">
            <span class="text-[10px] font-black text-crypto-glow">+${r.xp} XP</span>
            <span class="text-[10px] font-black text-emerald-400">+$${fmtNum(r.usd)}</span>
          </div>
        </div>
      `).join('');
    }

    function openReferralsView() {
        document.querySelectorAll('.view-section').forEach(el => el.classList.add('hidden'));
        document.getElementById('view-referrals').classList.remove('hidden');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function switchTab(tabId) {
      document.querySelectorAll('.view-section').forEach(el => el.classList.add('hidden'));
      document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('nav-active'));

      document.getElementById(`view-${tabId}`).classList.remove('hidden');
      
      const targetBtn = document.querySelector(`[data-target="${tabId}"]`);
      if (targetBtn) targetBtn.classList.add('nav-active');
      
      const header = document.getElementById('main-header');
      const mainContent = document.getElementById('app-content');

      // Hide header for Withdraw, Show for others
      if (tabId === 'withdraw' || tabId === 'referrals') {
        header.style.transform = 'translateY(-120%)';
        mainContent.classList.remove('pt-[110px]');
        mainContent.classList.add('pt-4');
      } else {
        header.style.transform = 'translateY(0)';
        mainContent.classList.remove('pt-4');
        mainContent.classList.add('pt-[110px]');
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
        
        const timerEl = document.getElementById('reset-timer');
        if(timerEl) {
          timerEl.innerText = `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
        }
    }
    
    async function initApp() {
      const res = await apiCall('init');
      
      setTimeout(() => {
        document.getElementById('loading-overlay').style.opacity = '0';
        setTimeout(() => { document.getElementById('loading-overlay').style.display = 'none'; }, 700); 
      }, 700);
      
      setInterval(updateTimer, 1000);
      updateTimer();
    }

    window.addEventListener('load', initApp);
  </script>
</body>
</html>
