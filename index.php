<?php
error_reporting(0);
ini_set('display_errors', 0);

$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

$usersFile = $dataDir . '/users.json';
$refsFile = $dataDir . '/referrals.json';
$withdrawalsFile = $dataDir . '/withdrawals.json';
$configFile = $dataDir . '/config.json';

function getJson($file) {
    if (!file_exists($file)) return [];
    $fp = fopen($file, 'r');
    if (!$fp) return [];
    flock($fp, LOCK_SH);
    $data = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $decoded = json_decode($data, true);
    return is_array($decoded) ? $decoded : [];
}

function saveJson($file, $data) {
    $fp = fopen($file, 'c');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $uid = $input['uid'] ?? null;
    
    if (!$uid) {
        echo json_encode(['error' => 'UID required']);
        exit;
    }

    if ($action === 'admin_get_stats') {
        if (!in_array($uid, ['8898574920', '5461064199'])) {
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        $usersData = getJson($usersFile);
        $configData = file_exists($configFile) ? json_decode(file_get_contents($configFile), true) : ['ad_block_id' => 'int-35545'];
        echo json_encode(['total_users' => count($usersData), 'sdk' => $configData['ad_block_id']]);
        exit;
    }

    if ($action === 'admin_get_users') {
        if (!in_array($uid, ['8898574920', '5461064199'])) {
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        $usersData = getJson($usersFile);
        $usersList = [];
        foreach ($usersData as $u) {
            $usersList[] = [
                'tgId' => $u['uid'],
                'name' => $u['firstName'] ?? 'User',
                'username' => $u['username'] ?? '',
                'xp' => $u['totalXp'] ?? 0,
                'usd' => $u['usdBalance'] ?? 0.00
            ];
        }
        echo json_encode(['users' => $usersList]);
        exit;
    }

    if ($action === 'admin_update_sdk') {
        if (!in_array($uid, ['8898574920', '5461064199'])) {
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        $new_sdk = $input['sdk'] ?? 'int-35545';
        saveJson($configFile, ['ad_block_id' => $new_sdk]);
        echo json_encode(['success' => true, 'sdk' => $new_sdk]);
        exit;
    }

    $users = getJson($usersFile);
    $refs = getJson($refsFile);
    $withdrawals = getJson($withdrawalsFile);

    if (!isset($users[$uid])) {
        $users[$uid] = [
            'uid' => $uid,
            'firstName' => $input['firstName'] ?? 'User',
            'username' => $input['username'] ?? '',
            'photoUrl' => $input['photoUrl'] ?? '',
            'xp' => 0,
            'totalXp' => 0,
            'usdBalance' => 0.00,
            'level' => 1,
            'adsWatchedToday' => 0,
            'totalAdsWatched' => 0,
            'tasksCompleted' => 0,
            'streak' => 1,
            'lastResetDay' => date('Y-m-d'),
            'missions' => [],
            'one_time_tasks' => [],
            'rewardHistory' => [],
            'withdrawHistory' => []
        ];

        $referrerUid = $input['referrer'] ?? '';
        if ($referrerUid && $referrerUid != $uid && isset($users[$referrerUid])) {
            $alreadyReferred = false;
            foreach ($refs as $ref) {
                if ($ref['referred_uid'] == $uid) {
                    $alreadyReferred = true;
                    break;
                }
            }
            if (!$alreadyReferred) {
                $refs[] = [
                    'referrer_uid' => $referrerUid,
                    'referred_uid' => $uid,
                    'name' => $users[$uid]['firstName'],
                    'username' => $users[$uid]['username'],
                    'status' => 'Pending',
                    'date' => date('M j, Y')
                ];
            }
        }
    } else {
        $users[$uid]['firstName'] = $input['firstName'] ?? $users[$uid]['firstName'];
        $users[$uid]['username'] = $input['username'] ?? $users[$uid]['username'];
        if(isset($input['photoUrl']) && !empty($input['photoUrl'])) {
            $users[$uid]['photoUrl'] = $input['photoUrl'];
        }
        if(!isset($users[$uid]['one_time_tasks'])) {
            $users[$uid]['one_time_tasks'] = [];
        }
    }

    $today = date('Y-m-d');
    if ($users[$uid]['lastResetDay'] !== $today) {
        $lastDay = new DateTime($users[$uid]['lastResetDay']);
        $currDay = new DateTime($today);
        $diff = $lastDay->diff($currDay)->days;
        
        if ($diff === 1) {
            $users[$uid]['streak'] = min(7, $users[$uid]['streak'] + 1);
        } else {
            $users[$uid]['streak'] = 1;
        }
        
        $users[$uid]['adsWatchedToday'] = 0;
        $users[$uid]['lastResetDay'] = $today;
        $users[$uid]['missions'] = [];
    }

    $checkReferralProgress = function() use (&$users, &$refs, $uid) {
        foreach ($refs as &$ref) {
            if ($ref['referred_uid'] == $uid && $ref['status'] === 'Pending') {
                $adProgress = $users[$uid]['totalAdsWatched'] ?? 0;
                $taskProgress = $users[$uid]['tasksCompleted'] ?? 0;
                
                if ($adProgress >= 25 && $taskProgress >= 5) {
                    $ref['status'] = 'Approved';
                    $rUid = $ref['referrer_uid'];
                    
                    if (isset($users[$rUid])) {
                        $users[$rUid]['xp'] += 250;
                        $users[$rUid]['totalXp'] += 250;
                        $users[$rUid]['usdBalance'] += 0.025;
                        
                        $users[$rUid]['rewardHistory'][] = [
                            'xp' => 250,
                            'usd' => 0.025,
                            'referred_name' => $users[$uid]['firstName'],
                            'date' => date('M j, Y')
                        ];
                    }
                }
            }
        }
    };

    if ($action === 'watch_ad') {
        if ($users[$uid]['adsWatchedToday'] < 30) {
            $users[$uid]['xp'] += 20;
            $users[$uid]['totalXp'] += 20;
            $users[$uid]['adsWatchedToday'] += 1;
            $users[$uid]['totalAdsWatched'] += 1;
            $checkReferralProgress();
        }
    } elseif ($action === 'claim_mission') {
        $mKey = $input['mission_key'] ?? '';
        $reward = intval($input['reward'] ?? 0);
        
        if ($mKey === 'azx_crypto') {
            if (!in_array($mKey, $users[$uid]['one_time_tasks'])) {
                $users[$uid]['one_time_tasks'][] = $mKey;
                $users[$uid]['xp'] += $reward;
                $users[$uid]['totalXp'] += $reward;
                $users[$uid]['tasksCompleted'] += 1;
                $checkReferralProgress();
            }
        } else {
            if ($mKey && !in_array($mKey, $users[$uid]['missions'])) {
                $users[$uid]['missions'][] = $mKey;
                $users[$uid]['xp'] += $reward;
                $users[$uid]['totalXp'] += $reward;
                $users[$uid]['tasksCompleted'] += 1;
                $checkReferralProgress();
            }
        }
    } elseif ($action === 'withdraw') {
        $amount = floatval($input['amount'] ?? 0);
        $address = $input['address'] ?? '';
        
        if ($amount >= 10 && $users[$uid]['usdBalance'] >= $amount) {
            $users[$uid]['usdBalance'] -= $amount;
            
            $wRecord = [
                'id' => '#' . strtoupper(substr(md5(uniqid()), 0, 6)),
                'amount' => $amount,
                'address' => substr($address, 0, 6) . '...' . substr($address, -4),
                'date' => date('Y-m-d'),
                'status' => 'Pending'
            ];
            
            array_unshift($users[$uid]['withdrawHistory'], $wRecord);
            
            $wGlobal = $wRecord;
            $wGlobal['uid'] = $uid;
            $withdrawals[] = $wGlobal;
        }
    } elseif ($action === 'sync') {
    }

    saveJson($usersFile, $users);
    saveJson($refsFile, $refs);
    saveJson($withdrawalsFile, $withdrawals);

    $myReferrals = array_values(array_filter($refs, function($r) use ($uid) {
        return $r['referrer_uid'] == $uid;
    }));
    
    foreach ($myReferrals as &$myRef) {
        $refUid = $myRef['referred_uid'];
        if (isset($users[$refUid])) {
            $myRef['ads_watched'] = $users[$refUid]['totalAdsWatched'];
            $myRef['tasks_completed'] = $users[$refUid]['tasksCompleted'];
        } else {
            $myRef['ads_watched'] = 0;
            $myRef['tasks_completed'] = 0;
        }
    }

    echo json_encode([
        'user' => $users[$uid],
        'referrals' => $myReferrals
    ]);
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
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800;900&display=swap" rel="stylesheet">
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { sans: ['Inter', 'sans-serif'] },
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
    img { pointer-events: none; -webkit-touch-callout: none; -webkit-user-select: none; user-select: none; }
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
    .fade-in { animation: fadeIn 0.2s ease-out forwards; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
    .nav-active { color: #00f0ff !important; transform: translateY(-2px); }
    .nav-active i { filter: drop-shadow(0 0 8px rgba(0, 240, 255, 0.6)); }
    .nav-active::before {
      content: ''; position: absolute; top: -10px; left: 50%; transform: translateX(-50%);
      width: 20px; height: 4px; background: #00f0ff; border-radius: 4px;
      box-shadow: 0 0 12px #00f0ff, 0 0 20px #3b82f6;
    }
    #bottom-nav {
      transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.2s ease-in-out;
    }
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
      transition: all 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
      opacity: 0; pointer-events: none;
    }
    .toast-show { transform: translate(-50%, 0) scale(1) !important; opacity: 1 !important; }
    
    .modal-overlay-custom {
      position: fixed; inset: 0; background: rgba(5,5,17, 0.95); backdrop-filter: blur(5px);
      z-index: 999999; display: none; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.2s;
    }
    .modal-overlay-custom.active { display: flex; opacity: 1; }
    .modal-content-custom {
      background: #0a0b1a; border: 1px solid rgba(0,240,255,0.3); box-shadow: 0 0 30px rgba(0,240,255,0.1);
      border-radius: 1.5rem; padding: 1.5rem; width: 90%; max-width: 350px;
      transform: scale(0.9); transition: transform 0.2s;
    }
    .modal-overlay-custom.active .modal-content-custom { transform: scale(1); }
    
    .admin-user-list {
        max-height: 300px;
        overflow-y: auto;
    }
    .admin-user-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px;
        border-bottom: 1px solid rgba(255,255,255,0.05);
    }
    .admin-user-item:last-child {
        border-bottom: none;
    }
  </style>
</head>
<body class="flex flex-col min-h-screen pb-32">
  <div class="bg-orb-1 animate-blob"></div>
  <div class="bg-orb-2 animate-blob animation-delay-2000"></div>

  <div id="loading-overlay" class="bg-[#050511] flex flex-col items-center justify-center z-[100000] fixed inset-0 transition-opacity duration-300">
    <div class="relative w-24 h-24 mb-8">
      <div class="absolute inset-0 rounded-full border-t-4 border-crypto-glow animate-[spin_0.5s_linear_infinite] shadow-[0_0_20px_rgba(0,240,255,0.5)]"></div>
      <div class="absolute inset-3 rounded-full border-b-4 border-blue-500 animate-[spin_0.7s_linear_infinite_reverse]"></div>
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

  <div id="toast-container" class="glass-card rounded-2xl p-4 flex items-center gap-4">
    <div id="toast-icon" class="w-12 h-12 rounded-full flex shrink-0 items-center justify-center text-xl shadow-inner">
      <i class="fa-solid fa-bell"></i>
    </div>
    <div class="flex-1">
      <h4 id="toast-title" class="text-sm font-black text-white tracking-wide">Notification</h4>
      <p id="toast-message" class="text-xs text-slate-300 mt-0.5 leading-tight">Message goes here</p>
    </div>
  </div>

  <header id="main-header" class="fixed top-0 left-0 right-0 z-50 w-full p-4 glass-card rounded-b-3xl border-b-0 shadow-lg transition-transform duration-200">
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
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-4 mb-3">Tasks & Missions</h3>
        <div id="missions-container" class="space-y-3"></div>
      </div>
    </div>

    <div id="view-referrals" class="view-section hidden fade-in space-y-6 pb-4">
      <div class="text-center pt-2 relative">
        <h2 class="text-3xl font-black text-white tracking-tight drop-shadow-lg">Referrals</h2>
        <p class="text-xs text-blue-400 mt-1 uppercase tracking-widest font-bold">Invite & Earn Crypto</p>
        <button onclick="toggleRefModal(true)" class="absolute right-4 top-4 text-slate-400 hover:text-white transition-colors text-xl">
            <i class="fa-solid fa-circle-info"></i>
        </button>
      </div>
      <div class="grid grid-cols-3 gap-2">
        <div class="glass-card p-3 rounded-2xl text-center border-t-2 border-t-blue-500/30">
          <p class="text-[9px] text-slate-400 uppercase font-black tracking-widest mb-1">Total</p>
          <p id="ref-total" class="text-xl font-black text-white">0</p>
        </div>
        <div class="glass-card p-3 rounded-2xl text-center border-t-2 border-t-amber-500/30">
          <p class="text-[9px] text-slate-400 uppercase font-black tracking-widest mb-1">Pending</p>
          <p id="ref-pending" class="text-xl font-black text-white">0</p>
        </div>
        <div class="glass-card p-3 rounded-2xl text-center border-t-2 border-t-emerald-500/30">
          <p class="text-[9px] text-slate-400 uppercase font-black tracking-widest mb-1">Approved</p>
          <p id="ref-approved" class="text-xl font-black text-white">0</p>
        </div>
      </div>
      <div class="glass-card rounded-2xl p-5 border border-slate-700/50 relative overflow-hidden">
        <div class="absolute -right-4 -top-4 w-24 h-24 bg-blue-600/10 rounded-full blur-xl"></div>
        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Your Referral Link</p>
        <div class="flex gap-2">
            <input type="text" id="ref-link-input" readonly class="w-full bg-[#050511]/80 border border-slate-700 rounded-xl px-3 py-2 text-xs font-mono text-blue-200 focus:outline-none">
            <button onclick="copyRefLink()" class="w-10 shrink-0 bg-slate-800 border border-slate-600 text-white rounded-xl flex items-center justify-center active:scale-95 transition-transform">
                <i class="fa-regular fa-copy"></i>
            </button>
        </div>
        <button onclick="shareRefLink()" class="w-full mt-3 py-3 bg-gradient-to-r from-blue-600 to-cyan-500 text-white font-black rounded-xl text-xs uppercase tracking-wider flex items-center justify-center gap-2 shadow-[0_5px_15px_rgba(0,240,255,0.3)] active:scale-95 transition-transform">
            <i class="fa-brands fa-telegram text-lg"></i> Share on Telegram
        </button>
      </div>
      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-4 mb-3">My Referrals</h3>
        <div id="ref-list-container" class="space-y-3"></div>
      </div>
      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-4 mb-3">Reward History</h3>
        <div id="ref-history-container" class="space-y-3"></div>
      </div>
    </div>

    <div id="view-withdraw" class="view-section hidden fade-in space-y-6">
      <div class="glass-card rounded-[2rem] p-6 text-center border-t-2 border-emerald-500/40 bg-gradient-to-b from-emerald-900/30 to-[#050511]">
        <div class="w-14 h-14 mx-auto bg-emerald-500/10 rounded-full flex items-center justify-center mb-3 border border-emerald-500/30 shadow-[0_0_20px_rgba(16,185,129,0.2)]">
          <i class="fa-solid fa-wallet text-xl text-emerald-400"></i>
        </div>
        <p class="text-[10px] font-black text-emerald-400 uppercase tracking-[0.2em] mb-1 opacity-80">Withdrawal Balance</p>
        <h1 class="text-5xl font-black text-white tracking-tighter mb-3">$<span id="withdraw-balance-display">0.00</span></h1>
        <div class="inline-block bg-[#050511]/80 backdrop-blur-md px-3 py-1.5 rounded-full border border-slate-700/50">
          <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest"><i class="fa-solid fa-circle-info text-blue-400 mr-1"></i> <span>Minimum:</span> $10</p>
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
          <i class="fa-solid fa-money-bill-transfer"></i> <span>Request Withdrawal</span>
        </button>
      </div>
      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-4 mb-3">History</h3>
        <div id="withdraw-history-container" class="space-y-3"></div>
      </div>
    </div>

    <div id="view-profile" class="view-section hidden fade-in space-y-6">
      <div class="glass-card rounded-[2.5rem] p-1 text-center relative overflow-hidden border border-slate-700/50">
        <div class="h-32 bg-gradient-to-r from-blue-900 via-indigo-900 to-purple-900 rounded-t-[2.3rem] relative overflow-hidden">
           <div class="absolute inset-0 bg-[url('https://www.transparenttextures.com/patterns/cubes.png')] opacity-20"></div>
           <div class="absolute -bottom-10 left-1/2 -translate-x-1/2 w-full h-20 bg-gradient-to-t from-[#0a0b1a] to-transparent"></div>
        </div>
        <div class="px-6 pb-8 -mt-16 relative z-10">
          <div class="relative w-24 h-24 mx-auto rounded-full bg-gradient-to-b from-crypto-glow to-blue-600 p-1 mb-3 shadow-[0_0_30px_rgba(0,240,255,0.4)]">
             <img id="profile-photo-large" src="https://via.placeholder.com/150/0a0b1a/00f0ff?text=PP" class="w-full h-full rounded-full border-4 border-[#0a0b1a] object-cover">
             <div class="absolute -bottom-2 -right-2 bg-gradient-to-br from-[#0a0b1a] to-blue-900 border-2 border-crypto-glow text-white w-8 h-8 rounded-full flex items-center justify-center font-black text-xs shadow-[0_0_15px_#00f0ff]" id="profile-badge-level">1</div>
          </div>
          <h2 id="profile-name" class="text-2xl font-black text-white tracking-tight">User</h2>
          <p id="profile-tgid" class="text-[10px] text-blue-400 font-mono mt-1 tracking-widest bg-blue-500/10 inline-block px-3 py-1 rounded-full border border-blue-500/20">ID: 00000000</p>
          <div class="mt-6">
            <div class="flex justify-between items-end mb-1.5 px-1">
               <span class="text-[10px] font-black text-crypto-glow uppercase tracking-wider">Level Progress</span>
               <span class="text-[10px] font-bold text-slate-400" id="profile-current-xp-text">0 XP</span>
            </div>
            <div class="w-full bg-[#050511] rounded-full h-3 border border-slate-700/50 overflow-hidden relative shadow-inner p-0.5">
               <div id="profile-xp-progress" class="bg-gradient-to-r from-blue-600 via-crypto-glow to-indigo-400 h-full rounded-full transition-all duration-1000 w-[0%] relative">
                 <div class="absolute inset-0 bg-white/20 w-full h-full animate-[shimmer_2s_infinite]"></div>
               </div>
            </div>
            <div class="text-right mt-1 px-1">
              <span class="text-[9px] font-bold text-slate-500 uppercase"><span>Target:</span> <span id="profile-next-xp-text" class="text-blue-400">250 XP</span></span>
            </div>
          </div>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div class="glass-card p-5 rounded-2xl text-center border-t-2 border-t-blue-500/30">
          <div class="w-10 h-10 mx-auto bg-blue-500/10 border border-blue-500/20 text-blue-400 rounded-xl flex items-center justify-center mb-2 text-lg shadow-[0_0_15px_rgba(59,130,246,0.2)inset]"><i class="fa-solid fa-chart-line"></i></div>
          <p class="text-[9px] text-slate-400 uppercase font-black tracking-[0.15em] mb-1">Total Earned</p>
          <p id="profile-total-xp" class="text-xl font-black text-white">0</p>
        </div>
        <div class="glass-card p-5 rounded-2xl text-center border-t-2 border-t-amber-500/30">
          <div class="w-10 h-10 mx-auto bg-amber-500/10 border border-amber-500/20 text-amber-400 rounded-xl flex items-center justify-center mb-2 text-lg shadow-[0_0_15px_rgba(245,158,11,0.2)inset]"><i class="fa-solid fa-list-check"></i></div>
          <p class="text-[9px] text-slate-400 uppercase font-black tracking-[0.15em] mb-1">Tasks Done</p>
          <p id="profile-tasks" class="text-xl font-black text-white">0</p>
        </div>
      </div>
      <button id="admin-btn" onclick="openAdminPanel()" style="display: none;" class="w-full mt-4 py-3 bg-slate-800 text-white font-black rounded-xl border border-slate-600 active:scale-95 transition-transform flex items-center justify-center gap-2">
        <i class="fa-solid fa-shield-halved"></i> Admin Panel
      </button>
    </div>

    <div id="view-admin" class="view-section hidden fade-in space-y-6">
      <div class="text-center pt-2">
        <h2 class="text-3xl font-black text-white tracking-tight drop-shadow-lg">Admin Panel</h2>
      </div>
      <div class="glass-card rounded-3xl p-6 space-y-6">
        <div class="flex justify-between items-center">
          <span class="text-white font-bold">Total Users</span>
          <span id="admin-total-users" class="text-crypto-glow font-black text-2xl">0</span>
        </div>
        <div class="space-y-2">
          <label class="text-slate-400 text-xs font-bold uppercase">SDK Block ID</label>
          <input type="text" id="admin-current-sdk" class="w-full bg-[#050511] border border-slate-700 rounded-xl px-4 py-3 text-white font-mono focus:outline-none focus:border-crypto-glow">
        </div>
        <button onclick="updateSdk()" class="w-full py-3 bg-gradient-to-r from-blue-600 to-cyan-500 text-white font-black rounded-xl shadow-lg active:scale-95 transition-transform">Update SDK</button>
        
        <div class="pt-4 border-t border-slate-700">
            <h3 class="text-white font-bold mb-3">User List</h3>
            <div id="admin-user-list" class="admin-user-list space-y-2">
                <div class="text-center text-slate-500 py-4">Loading users...</div>
            </div>
        </div>
      </div>
    </div>
  </main>

  <nav id="bottom-nav" class="fixed bottom-6 left-1/2 -translate-x-1/2 w-[calc(100%-1rem)] max-w-[420px] glass-card rounded-2xl pb-safe z-50 shadow-[0_20px_40px_rgba(0,0,0,0.8)] border border-slate-700/50 backdrop-blur-xl">
    <div class="flex justify-between items-center px-2 py-2.5 relative">
      <button onclick="switchTab('home')" class="nav-btn nav-active text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all duration-200 relative group" data-target="home">
        <i class="fa-solid fa-house text-lg transition-transform group-active:scale-90"></i>
        <span class="text-[7.5px] font-black uppercase tracking-widest">Home</span>
      </button>
      <button onclick="switchTab('tasks')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all duration-200 relative group" data-target="tasks">
        <i class="fa-solid fa-list-check text-lg transition-transform group-active:scale-90"></i>
        <span class="text-[7.5px] font-black uppercase tracking-widest">Tasks</span>
      </button>
      <button onclick="switchTab('referrals')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all duration-200 relative group" data-target="referrals">
        <i class="fa-solid fa-users text-lg transition-transform group-active:scale-90"></i>
        <span class="text-[7.5px] font-black uppercase tracking-widest">Referrals</span>
      </button>
      <button onclick="switchTab('withdraw')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all duration-200 relative group" data-target="withdraw">
        <i class="fa-solid fa-wallet text-lg transition-transform group-active:scale-90"></i>
        <span class="text-[7.5px] font-black uppercase tracking-widest">Withdraw</span>
      </button>
      <button onclick="switchTab('profile')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all duration-200 relative group" data-target="profile">
        <i class="fa-solid fa-user-astronaut text-lg transition-transform group-active:scale-90"></i>
        <span class="text-[7.5px] font-black uppercase tracking-widest">Profile</span>
      </button>
    </div>
  </nav>

  <div id="ref-info-modal" class="modal-overlay-custom">
    <div class="modal-content-custom text-center relative">
        <button onclick="toggleRefModal(false)" class="absolute top-3 right-4 text-slate-400 hover:text-white"><i class="fa-solid fa-xmark text-lg"></i></button>
        <div class="w-12 h-12 rounded-full bg-blue-500/20 text-crypto-glow flex items-center justify-center mx-auto mb-3 text-xl border border-blue-500/30">
            <i class="fa-solid fa-users"></i>
        </div>
        <h3 class="text-lg font-black text-white mb-2">Referral Rules</h3>
        <ul class="text-left text-sm text-slate-300 space-y-2 mb-4">
            <li><i class="fa-solid fa-circle-check text-blue-400 mr-2"></i><strong>Watch 25 ads.</strong></li>
            <li><i class="fa-solid fa-circle-check text-blue-400 mr-2"></i><strong>Complete 5 tasks.</strong></li>
            <li><i class="fa-solid fa-clock text-blue-400 mr-2"></i>There is no deadline.</li>
            <li><i class="fa-solid fa-hourglass-half text-amber-400 mr-2"></i>The referral remains pending until all requirements are completed.</li>
            <li><i class="fa-solid fa-gift text-emerald-400 mr-2"></i>Once completed, you receive the bonus!</li>
        </ul>
        <button onclick="toggleRefModal(false)" class="w-full py-2.5 bg-slate-800 text-white rounded-xl font-bold uppercase text-xs tracking-wider border border-slate-600">Got It</button>
    </div>
  </div>

  <script>
    const tg = window.Telegram.WebApp;
    tg.expand(); 
    tg.ready();
    tg.setHeaderColor('#0a0b1a');
    tg.setBackgroundColor('#050511');

    let userState = { tgId: null, firstName: "User", username: "", photoUrl: "", one_time_tasks: [] };
    let myReferrals = [];
    let currentSdk = "<?= $CURRENT_SDK_ID ?>";
    
    const streakRewards = [5, 10, 15, 20, 25, 30, 50]; 
    const missionList = [
      { key: 'dailyReward', label: 'Daily Login', icon: 'fa-gift', color: 'text-purple-400', bg: 'bg-purple-500/10 border-purple-500/20', reward: 5, type: 'boolean' },
      { key: 'azx_crypto', label: 'AZX Crypto', type: 'sponsor', reward: 200, logo: 'https://i.postimg.cc/rsz7NnZp/IMG-20260903-114036-951.jpg', link: 'https://t.me/azxcrypto' },
      { key: 'watch5', label: 'Watch 5 Ads', icon: 'fa-video', color: 'text-blue-400', bg: 'bg-blue-500/10 border-blue-500/20', target: 5, reward: 20, type: 'progress' },
      { key: 'watch30', label: 'Watch 30 Ads', icon: 'fa-film', color: 'text-indigo-400', bg: 'bg-indigo-500/10 border-indigo-500/20', target: 30, reward: 50, type: 'progress' }
    ];

    let headerTimeout; 
    let maxViewportHeight = window.visualViewport ? window.visualViewport.height : window.innerHeight;

    function handleKeyboardState() {
      let currentHeight = window.visualViewport ? window.visualViewport.height : window.innerHeight;
      if (currentHeight > maxViewportHeight) maxViewportHeight = currentHeight;
      if (maxViewportHeight - currentHeight > 100) {
          document.getElementById('bottom-nav').classList.add('nav-hidden');
      } else {
          document.getElementById('bottom-nav').classList.remove('nav-hidden');
      }
    }

    if (window.visualViewport) window.visualViewport.addEventListener('resize', handleKeyboardState);
    window.addEventListener('resize', handleKeyboardState);
    if (tg.onEvent) tg.onEvent('viewportChanged', handleKeyboardState);

    async function apiCall(action, payload = {}) {
        if (!userState.tgId) return null;
        payload.uid = userState.tgId;
        payload.firstName = userState.firstName;
        payload.username = userState.username;
        payload.photoUrl = userState.photoUrl;
        try {
            const res = await fetch(`?action=${action}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            return await res.json();
        } catch (e) {
            return null;
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
        else tg.HapticFeedback.notificationOccurred('warning');
      }
      setTimeout(() => { toast.classList.remove('toast-show'); }, 2000); 
    }

    function checkLevelUp() {
      const xp = userState.totalXp;
      let newLevel = 1;
      if (xp >= 20000) newLevel = 10;
      else if (xp >= 3000) newLevel = 5;
      else if (xp >= 1500) newLevel = 4;
      else if (xp >= 750) newLevel = 3;
      else if (xp >= 250) newLevel = 2;
      if (newLevel > userState.level) {
        userState.level = newLevel;
        showToast('Level Up!', `Congratulations! New Level: ${newLevel}!`, "success");
        if (tg.HapticFeedback) tg.HapticFeedback.impactOccurred('heavy');
      }
    }

    function updateProfileProgress() {
      const xp = userState.totalXp;
      let nextLvlXp = 250;
      let prevLvlXp = 0;
      if (xp >= 20000) { nextLvlXp = 20000; prevLvlXp = 20000; }
      else if (xp >= 3000) { nextLvlXp = 20000; prevLvlXp = 3000; }
      else if (xp >= 1500) { nextLvlXp = 3000; prevLvlXp = 1500; }
      else if (xp >= 750) { nextLvlXp = 1500; prevLvlXp = 750; }
      else if (xp >= 250) { nextLvlXp = 750; prevLvlXp = 250; }
      let progress = 100;
      if (nextLvlXp !== prevLvlXp) {
        progress = ((xp - prevLvlXp) / (nextLvlXp - prevLvlXp)) * 100;
      }
      document.getElementById('profile-xp-progress').style.width = `${progress}%`;
      document.getElementById('profile-current-xp-text').innerText = `${xp.toLocaleString()} XP`;
      document.getElementById('profile-next-xp-text').innerText = (nextLvlXp === 20000 && xp >= 20000) ? 'MAX LVL' : `${nextLvlXp.toLocaleString()} XP`;
      document.getElementById('profile-badge-level').innerText = userState.level;
    }

    function renderStreakTracker() {
      const container = document.getElementById('streak-tracker-container');
      container.innerHTML = '';
      const claimedToday = (userState.missions || []).includes('dailyReward');
      for (let i = 1; i <= 7; i++) {
        const isPast = i < userState.streak || (i === userState.streak && claimedToday);
        const isToday = i === userState.streak && !claimedToday;
        const reward = streakRewards[i-1];
        let styles = "bg-[#050511] border-slate-700 text-slate-600";
        let icon = `<span class="text-[9px] font-black">${reward}</span>`;
        let lineStyle = "bg-slate-800";
        if (isPast) {
          styles = "bg-emerald-500/20 border-emerald-500/50 text-emerald-400 shadow-[0_0_15px_rgba(16,185,129,0.3)]";
          icon = `<i class="fa-solid fa-check text-xs"></i>`;
          lineStyle = "bg-emerald-500/50 shadow-[0_0_5px_#34d399]";
        } else if (isToday) {
          styles = "bg-blue-600/30 border-crypto-glow shadow-[0_0_20px_rgba(0,240,255,0.5)] text-white";
        }
        container.innerHTML += `
          <div class="relative flex flex-col items-center gap-1.5 z-10 flex-1">
            <div class="w-9 h-9 rounded-xl border-2 flex items-center justify-center transition-all duration-300 ${styles} z-10 relative bg-[#0a0b1a]">
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
      const claimedMissions = userState.missions || [];
      const claimedOneTime = userState.one_time_tasks || [];
      
      missionList.forEach(m => {
        const isOneTime = m.type === 'sponsor';
        const claimed = isOneTime ? claimedOneTime.includes(m.key) : claimedMissions.includes(m.key);
        let isComplete = false;
        let progressText = '';
        
        if (m.type === 'progress') {
            isComplete = userState.adsWatchedToday >= m.target;
            progressText = `(${Math.min(userState.adsWatchedToday, m.target)}/${m.target})`;
        } else if (m.type === 'boolean' || m.type === 'sponsor') {
            isComplete = true;
        }

        let btnHtml = '';
        if (claimed) {
            btnHtml = `<span class="text-[10px] font-black bg-emerald-500/10 text-emerald-400 px-3 py-1.5 rounded-xl border border-emerald-500/30 flex items-center gap-1 shadow-[0_0_10px_rgba(16,185,129,0.1)inset]"><i class="fa-solid fa-check-double"></i> Claimed</span>`;
        } else if (isComplete) {
            if (m.type === 'sponsor') {
                btnHtml = `<button onclick="claimSponsor('${m.key}', ${m.reward}, '${m.link}')" class="text-[10px] font-black bg-gradient-to-r from-blue-600 to-cyan-500 text-white px-4 py-1.5 rounded-xl shadow-[0_0_15px_rgba(0,240,255,0.4)] active:scale-95 transition-all uppercase tracking-wider">Join & Claim</button>`;
            } else {
                let rew = m.key === 'dailyReward' ? streakRewards[userState.streak-1] : m.reward;
                btnHtml = `<button onclick="claimMission('${m.key}', ${rew})" class="text-[10px] font-black bg-gradient-to-r from-blue-600 to-cyan-500 text-white px-4 py-1.5 rounded-xl shadow-[0_0_15px_rgba(0,240,255,0.4)] active:scale-95 transition-all uppercase tracking-wider">Claim</button>`;
            }
        } else {
            let rew = m.key === 'dailyReward' ? streakRewards[userState.streak-1] : m.reward;
            btnHtml = `<span class="text-[10px] font-black bg-slate-800/50 text-slate-200 px-3 py-1.5 rounded-xl border border-slate-600 shadow-inner">+${rew} XP</span>`;
        }
        
        let iconHtml = m.logo ? 
            `<img src="${m.logo}" alt="logo" class="w-full h-full rounded-xl object-cover pointer-events-none">` : 
            `<i class="fa-solid ${m.icon} ${m.color} text-lg"></i>`;
            
        let bgClass = m.bg || 'bg-slate-800/50 border-slate-600';

        container.innerHTML += `
          <div class="glass-card rounded-2xl p-3 flex justify-between items-center transition-transform hover:-translate-y-0.5 border border-slate-800">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl border ${bgClass} flex items-center justify-center overflow-hidden">
                 ${iconHtml}
              </div>
              <div class="flex flex-col">
                <span class="text-xs font-black text-white tracking-wide">${m.label}</span>
                <span class="text-crypto-glow text-[10px] font-bold tracking-widest uppercase opacity-80 mt-0.5">${m.type === 'sponsor' ? 'Sponsor' : progressText}</span>
              </div>
            </div>
            ${btnHtml}
          </div>
        `;
      });
    }

    async function claimMission(key, reward) {
        const res = await apiCall('claim_mission', { mission_key: key, reward: reward });
        if (res) applyState(res);
        showToast('Task Completed!', `You earned ${reward} XP!`, "success");
    }

    async function claimSponsor(key, reward, link) {
        tg.openTelegramLink(link);
        setTimeout(async () => {
            const res = await apiCall('claim_mission', { mission_key: key, reward: reward });
            if (res) applyState(res);
            showToast('Task Completed!', `You earned ${reward} XP!`, "success");
        }, 1500);
    }

    async function watchAd() {
      if (userState.adsWatchedToday >= 30) {
        showToast('Limit', 'You have reached the daily limit of 30 ads. Come back tomorrow!', "error");
        return;
      }
      const btn = document.getElementById('watch-ad-btn');
      const originalHTML = btn.innerHTML;
      btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> <span>Loading Ad...</span>`;
      btn.classList.add('opacity-80', 'pointer-events-none');
      if (window.Adsgram) {
        const AdController = window.Adsgram.init({ blockId: currentSdk });
        AdController.show().then(async (result) => {
          const res = await apiCall('watch_ad');
          if (res) applyState(res);
          showToast('Reward Granted!', 'You successfully watched the ad and earned +20 XP.', "success");
          btn.innerHTML = originalHTML;
          btn.classList.remove('opacity-80', 'pointer-events-none');
        }).catch((error) => {
          btn.innerHTML = originalHTML;
          btn.classList.remove('opacity-80', 'pointer-events-none');
        });
      } else {
        showToast('Error', 'The ad system is currently not working.', "error");
        btn.innerHTML = originalHTML;
        btn.classList.remove('opacity-80', 'pointer-events-none');
      }
    }

    async function requestWithdrawal() {
      const address = document.getElementById('wallet-address').value;
      const amount = parseFloat(document.getElementById('withdraw-amount').value);
      if (!address || address.length < 10) {
        showToast('Invalid Address', 'Please enter a valid TON wallet address.', "error");
        return;
      }
      if (isNaN(amount) || amount < 10) {
        showToast('Invalid Amount', 'The minimum withdrawal amount is $10.', "error");
        return;
      }
      if (amount > userState.usdBalance) {
        showToast('Insufficient balance', 'You do not have enough funds to withdraw this amount.', "error");
        return;
      }
      const res = await apiCall('withdraw', { amount: amount, address: address });
      if (res) {
          applyState(res);
          document.getElementById('wallet-address').value = '';
          document.getElementById('withdraw-amount').value = '';
          showToast('Withdrawal Requested', `Your request for $${amount.toFixed(2)} has been submitted.`, "success");
      }
    }

    function renderWithdrawHistory() {
      const container = document.getElementById('withdraw-history-container');
      const hist = userState.withdrawHistory || [];
      if (hist.length === 0) {
        container.innerHTML = `<div class="glass-card rounded-2xl p-6 text-center border-dashed border-2 border-slate-700"><i class="fa-solid fa-clock-rotate-left text-3xl text-slate-600 mb-2"></i><p class="text-xs font-bold text-slate-500 uppercase tracking-widest">History is Empty</p></div>`;
        return;
      }
      container.innerHTML = '';
      hist.forEach(record => {
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

    function renderReferrals() {
        const total = myReferrals.length;
        const pending = myReferrals.filter(r => r.status === 'Pending').length;
        const approved = total - pending;
        document.getElementById('ref-total').innerText = total;
        document.getElementById('ref-pending').innerText = pending;
        document.getElementById('ref-approved').innerText = approved;
        document.getElementById('ref-link-input').value = `https://t.me/pointplayappbot?startapp=${userState.tgId}`;
        const listContainer = document.getElementById('ref-list-container');
        if (total === 0) {
            listContainer.innerHTML = `<div class="text-center p-6 text-slate-500 text-xs font-bold uppercase tracking-widest">No Referrals Yet</div>`;
        } else {
            listContainer.innerHTML = myReferrals.map(r => {
                const isAppr = r.status === 'Approved';
                const sColor = isAppr ? 'text-emerald-400 border-emerald-500/30 bg-emerald-500/10' : 'text-amber-400 border-amber-500/30 bg-amber-500/10';
                return `
                <div class="glass-card rounded-xl p-3 flex justify-between items-center border border-slate-700/50">
                    <div>
                        <p class="text-sm font-black text-white">${r.name}</p>
                        ${r.username ? `<p class="text-[10px] text-blue-400">@${r.username}</p>` : ''}
                        <div class="flex gap-2 mt-1">
                            <span class="text-[9px] text-slate-400"><i class="fa-solid fa-play mr-1"></i>Ads: ${r.ads_watched}/25</span>
                            <span class="text-[9px] text-slate-400"><i class="fa-solid fa-check mr-1"></i>Tasks: ${r.tasks_completed}/5</span>
                        </div>
                    </div>
                    <div class="text-[9px] font-black uppercase tracking-widest px-2 py-1 rounded-full border ${sColor}">
                        ${r.status}
                    </div>
                </div>`;
            }).join('');
        }
        const histContainer = document.getElementById('ref-history-container');
        const hist = userState.rewardHistory || [];
        if (hist.length === 0) {
            histContainer.innerHTML = `<div class="text-center p-6 text-slate-500 text-xs font-bold uppercase tracking-widest">No Rewards Yet</div>`;
        } else {
            histContainer.innerHTML = hist.map(h => `
                <div class="glass-card rounded-xl p-3 flex justify-between items-center border-l-4 border-l-crypto-glow">
                    <div>
                        <p class="text-xs font-black text-white">Referral Bonus</p>
                        <p class="text-[10px] text-slate-400 mt-0.5">Referral: ${h.referred_name} • ${h.date}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-black text-crypto-glow">+${h.xp} XP</p>
                        <p class="text-[10px] font-bold text-emerald-400">+$${h.usd.toFixed(3)}</p>
                    </div>
                </div>
            `).join('');
        }
    }

    function toggleRefModal(show) {
        const m = document.getElementById('ref-info-modal');
        if (show) m.classList.add('active');
        else m.classList.remove('active');
    }

    function copyRefLink() {
        const link = document.getElementById('ref-link-input').value;
        navigator.clipboard.writeText(link).then(() => {
            showToast('Copied!', 'Referral link copied!', 'success');
        });
    }

    function shareRefLink() {
        const link = document.getElementById('ref-link-input').value;
        const msg = `🎯 Play games, complete tasks, earn XP, and collect exciting rewards 🚀 I’m already playing on Point Play now it’s your turn to join the adventure👇\n\n${link}`;
        const url = `https://t.me/share/url?url=${encodeURIComponent(link)}&text=${encodeURIComponent(msg)}`;
        tg.openTelegramLink(url);
    }

    function applyState(data) {
        userState = { ...userState, ...data.user };
        myReferrals = data.referrals || [];
        if (data.sdk) {
            currentSdk = data.sdk;
        }
        updateUI();
    }

    function updateUI() {
      document.getElementById('user-name').innerText = userState.firstName;
      document.getElementById('user-xp').innerHTML = `${userState.xp.toLocaleString()} <span class="text-[10px] text-crypto-glow font-bold">XP</span>`;
      document.getElementById('user-usd').innerText = userState.usdBalance.toFixed(4);
      if (userState.photoUrl) {
        document.getElementById('user-photo').src = userState.photoUrl;
        document.getElementById('profile-photo-large').src = userState.photoUrl;
      }
      document.getElementById('main-xp-display').innerText = `${userState.xp.toLocaleString()} XP`;
      document.getElementById('ads-watched').innerText = userState.adsWatchedToday;
      document.getElementById('streak-days').innerText = userState.streak;
      document.getElementById('withdraw-balance-display').innerText = userState.usdBalance.toFixed(2);
      document.getElementById('profile-name').innerText = userState.firstName;
      document.getElementById('profile-tgid').innerText = `ID: ${userState.tgId}`;
      document.getElementById('profile-total-xp').innerText = userState.totalXp.toLocaleString();
      document.getElementById('profile-tasks').innerText = userState.tasksCompleted || 0;
      checkLevelUp();
      updateProfileProgress();
      renderMissions();
      renderStreakTracker();
      renderWithdrawHistory();
      renderReferrals();
    }

    function switchTab(tabId) {
      document.querySelectorAll('.view-section').forEach(el => el.classList.add('hidden'));
      document.querySelectorAll('.nav-btn').forEach(btn => {
        btn.classList.remove('nav-active');
      });
      document.getElementById(`view-${tabId}`).classList.remove('hidden');
      const activeBtn = document.querySelector(`[data-target="${tabId}"]`);
      activeBtn.classList.add('nav-active');
      const header = document.getElementById('main-header');
      const mainContent = document.getElementById('app-content');
      clearTimeout(headerTimeout);
      
      if (tabId === 'withdraw' || tabId === 'admin') {
        header.style.display = 'none';
        mainContent.classList.remove('pt-24');
        mainContent.classList.add('pt-4');
      } else {
        header.style.display = 'block'; 
        mainContent.classList.remove('pt-4');
        mainContent.classList.add('pt-24');
      }
      
      if (tg.HapticFeedback) tg.HapticFeedback.selectionChanged();
      window.scrollTo({ top: 0, behavior: 'smooth' });
      
      if (tabId === 'admin') {
          loadAdminStats();
      }
    }

    function updateTimer() {
        const now = new Date();
        const tomorrow = new Date();
        tomorrow.setUTCHours(24, 0, 0, 0); 
        const diff = tomorrow.getTime() - now.getTime();
        const h = Math.floor(diff / 1000 / 60 / 60);
        const m = Math.floor((diff / 1000 / 60) % 60);
        const s = Math.floor((diff / 1000) % 60);
        const timerEl = document.getElementById('reset-timer');
        if (timerEl) {
            timerEl.innerText = `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
        }
    }
    
    async function initApp() {
        const initDataUnsafe = tg.initDataUnsafe || {};
        const u = initDataUnsafe.user || {};
        const startParam = initDataUnsafe.start_param || "";
        userState.tgId = u.id || 123456789;
        userState.firstName = u.first_name || "User";
        userState.username = u.username || "";
        userState.photoUrl = u.photo_url || "";
        if (['8898574920', '5461064199'].includes(userState.tgId.toString())) {
            document.getElementById('admin-btn').style.display = 'flex';
        }
        const res = await apiCall('sync', { referrer: startParam });
        if (res) applyState(res);
        else updateUI();
        document.getElementById('loading-overlay').style.display = 'none';
        setInterval(updateTimer, 1000);
        updateTimer();
    }

    function openAdminPanel() {
        const pass = prompt("Enter admin password:");
        if (pass === "admin123") {
            switchTab('admin');
        } else {
            showToast("Error", "Wrong password", "error");
        }
    }

    async function loadAdminStats() {
        const res = await apiCall('admin_get_stats');
        if (res) {
            document.getElementById('admin-total-users').innerText = res.total_users;
            document.getElementById('admin-current-sdk').value = res.sdk;
            
            const userRes = await apiCall('admin_get_users');
            if (userRes && userRes.users) {
                const listContainer = document.getElementById('admin-user-list');
                if (userRes.users.length === 0) {
                    listContainer.innerHTML = '<div class="text-center text-slate-500 py-4">No users found</div>';
                } else {
                    listContainer.innerHTML = userRes.users.map(u => `
                        <div class="admin-user-item">
                            <div>
                                <div class="text-white font-bold">${u.name}</div>
                                <div class="text-xs text-slate-400">@${u.username || 'no_username'}</div>
                            </div>
                            <div class="text-right">
                                <div class="text-crypto-glow font-bold">${u.xp} XP</div>
                                <div class="text-xs text-emerald-400">$${u.usd.toFixed(2)}</div>
                            </div>
                        </div>
                    `).join('');
                }
            }
        }
    }

    async function updateSdk() {
        const newSdk = document.getElementById('admin-current-sdk').value;
        const res = await apiCall('admin_update_sdk', { sdk: newSdk });
        if (res && res.success) {
            currentSdk = newSdk;
            showToast("Success", "SDK updated successfully", "success");
        }
    }

    initApp();
  </script>
</body>
</html>
