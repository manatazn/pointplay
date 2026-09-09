<?php
session_start();

$dataDir = __DIR__ . '/data';
$usersDir = $dataDir . '/users';

// Automatically create data directories if they do not exist
if (!is_dir($dataDir)) mkdir($dataDir, 0777, true);
if (!is_dir($usersDir)) mkdir($usersDir, 0777, true);

function safeReadJson($filepath, $default = []) {
    if (!file_exists($filepath)) return $default;
    $fp = fopen($filepath, 'r');
    if (!$fp) return $default;
    flock($fp, LOCK_SH);
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return json_decode($content, true) ?: $default;
}

function safeWriteJson($filepath, $data) {
    $fp = fopen($filepath, 'c');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
    flock($fp, LOCK_UN);
    fclose($fp);
}

function getUser($uid) {
    global $usersDir;
    return safeReadJson("$usersDir/$uid.json", null);
}

function saveUser($uid, $data) {
    global $usersDir;
    safeWriteJson("$usersDir/$uid.json", $data);
}

// --- HANDLE API REQUESTS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';
    $uid = $input['tgId'] ?? null;
    
    if (!$uid) { 
        echo json_encode(['error' => 'No UID provided']); 
        exit; 
    }

    $user = getUser($uid);
    
    // Initialize new user
    if (!$user) {
        $referrer = $input['startapp'] ?? null;
        if ($referrer === (string)$uid) $referrer = null; // Prevent self-referral
        
        $user = [
            'tgId' => (string)$uid,
            'firstName' => $input['firstName'] ?? 'User',
            'username' => $input['username'] ?? '',
            'xp' => 0,
            'totalXp' => 0,
            'usdBalance' => 0.00,
            'adsWatchedToday' => 0,
            'totalAds' => 0,
            'totalTasks' => 0,
            'boxesOpened' => 0,
            'level' => 1,
            'streak' => 1,
            'lastResetDay' => date('Y-m-d'),
            'referrer' => $referrer,
            'referralStatus' => $referrer ? 'Pending' : null,
            'referralHistory' => [],
            'withdrawHistory' => [],
            'missions' => [],
            'gameState' => []
        ];
        
        // Link referral relationship
        if ($referrer) {
            $refIndexFile = "$dataDir/referrals_index.json";
            $refIndex = safeReadJson($refIndexFile, []);
            if (!isset($refIndex[$referrer])) $refIndex[$referrer] = [];
            if (!in_array($uid, $refIndex[$referrer])) {
                $refIndex[$referrer][] = $uid;
                safeWriteJson($refIndexFile, $refIndex);
            }
        }
    }

    // Daily reset check
    $today = date('Y-m-d');
    if ($user['lastResetDay'] !== $today) {
        $lastDate = new DateTime($user['lastResetDay']);
        $currDate = new DateTime($today);
        $diff = $currDate->diff($lastDate)->days;
        
        if ($diff === 1) {
            $user['streak'] = $user['streak'] >= 7 ? 1 : $user['streak'] + 1;
        } else {
            $user['streak'] = 1;
        }
        $user['adsWatchedToday'] = 0;
        $user['lastResetDay'] = $today;
        $user['missions'] = []; // Reset daily missions
        $user['gameState'] = []; // Reset daily game limit/state
    }

    $response = ['success' => true];

    // Referral requirement evaluator
    function evaluateReferralProgress(&$u) {
        if ($u['referrer'] && $u['referralStatus'] === 'Pending') {
            if ($u['totalAds'] >= 25 && $u['totalTasks'] >= 5) {
                $u['referralStatus'] = 'Approved';
                
                // Reward the referrer
                $referrerData = getUser($u['referrer']);
                if ($referrerData) {
                    $referrerData['xp'] += 250;
                    $referrerData['totalXp'] += 250;
                    $referrerData['usdBalance'] += 0.025;
                    array_unshift($referrerData['referralHistory'], [
                        'date' => date('M j, Y'),
                        'bonusXp' => 250,
                        'bonusUsd' => 0.025,
                        'referredName' => $u['firstName']
                    ]);
                    saveUser($referrerData['tgId'], $referrerData);
                }
            }
        }
    }

    // Route Actions
    if ($action === 'sync') {
        // Just return current user state
    } elseif ($action === 'watchAd') {
        if ($user['adsWatchedToday'] < 30) {
            $user['xp'] += 10;
            $user['totalXp'] += 10;
            $user['usdBalance'] += 0.0015;
            $user['adsWatchedToday'] += 1;
            $user['totalAds'] += 1;
            evaluateReferralProgress($user);
        }
    } elseif ($action === 'completeTask') {
        $reward = intval($input['reward'] ?? 0);
        $user['xp'] += $reward;
        $user['totalXp'] += $reward;
        $user['totalTasks'] += 1;
        if (isset($input['missions'])) $user['missions'] = $input['missions'];
        evaluateReferralProgress($user);
    } elseif ($action === 'openBox') {
        $cost = intval($input['cost'] ?? 0);
        $reward = floatval($input['reward'] ?? 0);
        if ($user['xp'] >= $cost) {
            $user['xp'] -= $cost;
            $user['usdBalance'] += $reward;
            $user['boxesOpened'] += 1;
        }
    } elseif ($action === 'withdraw') {
        $amount = floatval($input['amount'] ?? 0);
        $address = $input['address'] ?? '';
        if ($amount >= 10 && $user['usdBalance'] >= $amount) {
            $user['usdBalance'] -= $amount;
            array_unshift($user['withdrawHistory'], [
                'id' => '#' . strtoupper(substr(md5(uniqid()), 0, 6)),
                'date' => date('M j, Y'),
                'amount' => $amount,
                'address' => substr($address, 0, 6) . '...' . substr($address, -4),
                'status' => 'Pending'
            ]);
        }
    } elseif ($action === 'getReferrals') {
        $refIndex = safeReadJson("$dataDir/referrals_index.json", []);
        $myRefs = $refIndex[$uid] ?? [];
        $refList = [];
        $pending = 0; $approved = 0;
        
        foreach ($myRefs as $rUid) {
            $rUser = getUser($rUid);
            if ($rUser) {
                $status = $rUser['referralStatus'] ?? 'Pending';
                if ($status === 'Approved') $approved++; else $pending++;
                
                $refList[] = [
                    'firstName' => $rUser['firstName'],
                    'username' => $rUser['username'],
                    'status' => $status,
                    'totalAds' => min(25, $rUser['totalAds']),
                    'totalTasks' => min(5, $rUser['totalTasks'])
                ];
            }
        }
        $response['referrals'] = $refList;
        $response['stats'] = [
            'total' => count($myRefs),
            'pending' => $pending,
            'approved' => $approved
        ];
    } elseif ($action === 'saveGameState') {
        $user['gameState'] = $input['gameState'] ?? $user['gameState'];
    }

    // Dynamic level calculation based on Total XP
    if ($user['totalXp'] >= 20000) $user['level'] = 10;
    elseif ($user['totalXp'] >= 3000) $user['level'] = 5;
    elseif ($user['totalXp'] >= 1500) $user['level'] = 4;
    elseif ($user['totalXp'] >= 750) $user['level'] = 3;
    elseif ($user['totalXp'] >= 250) $user['level'] = 2;

    saveUser($uid, $user);
    $response['user'] = $user;
    
    echo json_encode($response);
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
    body { background-color: #050511; color: #f8fafc; overflow-x: hidden; -webkit-touch-callout: none; -webkit-user-select: none; user-select: none; }
    body.in-game { overflow: hidden !important; touch-action: none !important; position: fixed; inset: 0; width: 100vw; height: 100vh; }
    body.in-game #main-header, body.in-game #bottom-nav { display: none !important; }
    body.in-game #app-content { padding: 0 !important; margin: 0 !important; }
    .bg-orb-1 { position: fixed; top: -10%; left: -10%; width: 50vw; height: 50vw; background: radial-gradient(circle, rgba(59, 130, 246, 0.15) 0%, rgba(0, 0, 0, 0) 70%); z-index: -1; filter: blur(40px); }
    .bg-orb-2 { position: fixed; bottom: -10%; right: -10%; width: 60vw; height: 60vw; background: radial-gradient(circle, rgba(0, 240, 255, 0.1) 0%, rgba(0, 0, 0, 0) 70%); z-index: -1; filter: blur(50px); }
    input { user-select: auto !important; }
    ::-webkit-scrollbar { width: 0px; background: transparent; }
    .glass-card { background: linear-gradient(145deg, rgba(20, 22, 45, 0.6) 0%, rgba(10, 11, 26, 0.8) 100%); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.05); box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.3); }
    .glass-button { background: linear-gradient(135deg, rgba(59,130,246,0.2) 0%, rgba(0,240,255,0.1) 100%); border: 1px solid rgba(0,240,255,0.3); box-shadow: 0 0 15px rgba(0,240,255,0.1) inset; }
    .fade-in { animation: fadeIn 0.3s ease-out forwards; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    .nav-active { color: #00f0ff !important; transform: translateY(-2px); }
    .nav-active i { filter: drop-shadow(0 0 8px rgba(0, 240, 255, 0.6)); }
    #toast-container { position: fixed; top: 1.5rem; left: 50%; transform: translate(-50%, -150%) scale(0.9); width: 90%; max-width: 380px; z-index: 999999; transition: all 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55); opacity: 0; pointer-events: none; }
    .toast-show { transform: translate(-50%, 0) scale(1) !important; opacity: 1 !important; }
    .box-bronze { background: linear-gradient(135deg, rgba(205,127,50,0.1), rgba(139,69,19,0.2)); border: 1px solid rgba(205,127,50,0.4); }
    .box-silver { background: linear-gradient(135deg, rgba(226,232,240,0.1), rgba(148,163,184,0.2)); border: 1px solid rgba(226,232,240,0.4); }
    .box-gold { background: linear-gradient(135deg, rgba(255,184,0,0.15), rgba(217,119,6,0.25)); border: 1px solid rgba(255,184,0,0.5); }
    #game-play-container.fullscreen-mode { position: fixed; top: 0; left: 0; right: 0; bottom: 0; width: 100vw; height: 100vh; z-index: 99999; background: #050511; padding: 10px; display: flex; flex-direction: column; box-sizing: border-box; }
    #game-canvas-container { flex: 1 1 auto; width: 100%; height: 100%; background: radial-gradient(circle at center, #0f172a 0%, #050511 100%); border: 2px solid rgba(0, 240, 255, 0.2); border-radius: 1.5rem; overflow: hidden; position: relative; box-shadow: 0 0 30px rgba(0, 240, 255, 0.05) inset; }
    canvas { width: 100%; height: 100%; touch-action: none; display: block; }
    .level-node { width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 1.25rem; position: relative; z-index: 10; transition: transform 0.2s; }
    .level-node.completed { background: linear-gradient(135deg, #3b82f6, #00f0ff); color: #050511; box-shadow: 0 0 20px rgba(0, 240, 255, 0.4); }
    .level-node.current { background: #050511; border: 3px solid #00f0ff; color: #00f0ff; box-shadow: 0 0 20px rgba(0, 240, 255, 0.6); animation: pulse 2s infinite; }
    .level-node.locked { background: #1e293b; border: 2px solid #334155; color: #475569; }
  </style>
</head>
<body class="flex flex-col min-h-screen pb-32">
  <div class="bg-orb-1 animate-blob"></div>
  <div class="bg-orb-2 animate-blob animation-delay-2000"></div>

  <!-- Loading Splash Screen -->
  <div id="loading-overlay" class="bg-[#050511] flex flex-col items-center justify-center z-[100000] fixed inset-0 transition-opacity duration-500">
    <div class="relative w-24 h-24 mb-8">
      <div class="absolute inset-0 rounded-full border-t-4 border-crypto-glow animate-[spin_1s_linear_infinite] shadow-[0_0_20px_rgba(0,240,255,0.5)]"></div>
      <div class="absolute inset-3 rounded-full border-b-4 border-blue-500 animate-[spin_1.5s_linear_infinite_reverse]"></div>
      <div class="absolute inset-0 flex items-center justify-center">
        <i class="fa-solid fa-gamepad text-crypto-glow text-3xl animate-pulse"></i>
      </div>
    </div>
    <h2 class="text-white font-black tracking-[0.25em] text-2xl uppercase bg-clip-text text-transparent bg-gradient-to-r from-crypto-glow via-blue-400 to-indigo-500 mb-2 drop-shadow-lg">Point Play</h2>
    <p class="text-[10px] text-blue-400 font-bold tracking-widest mt-6 animate-pulse uppercase">Connecting Server...</p>
  </div>

  <div id="toast-container" class="glass-card rounded-2xl p-4 flex items-center gap-4">
    <div id="toast-icon" class="w-12 h-12 rounded-full flex shrink-0 items-center justify-center text-xl shadow-inner"></div>
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

      <button onclick="watchAd()" id="watch-ad-btn" class="w-full py-4 rounded-2xl text-white font-black text-sm tracking-[0.15em] uppercase flex items-center justify-center gap-3 shadow-[0_10px_30px_rgba(59,130,246,0.3)] bg-gradient-to-b from-blue-500 to-blue-700 relative overflow-hidden group active:scale-95 transition-all">
        <i class="fa-solid fa-play bg-white/20 p-2 rounded-full text-[10px]"></i> 
        <span>Watch Ad <span class="text-cyan-200">+10 XP & $0.0015</span></span>
      </button>

      <div class="glass-card rounded-xl p-3 flex justify-between items-center">
        <div class="flex items-center gap-2 text-slate-400 text-xs">
          <i class="fa-solid fa-rotate text-blue-400"></i> <span>Resets in:</span>
        </div>
        <span class="text-white font-mono font-bold tracking-widest" id="reset-timer">--:--:--</span>
      </div>
    </div>

    <!-- GAMES VIEW -->
    <div id="view-games" class="view-section hidden fade-in space-y-4 pb-4">
      <div id="game-menu-container">
        <div class="text-center pt-2 mb-4">
          <h2 class="text-3xl font-black text-white tracking-tight drop-shadow-lg">Games</h2>
          <p class="text-[10px] text-blue-400 mt-1 uppercase tracking-widest font-bold bg-blue-500/10 inline-block px-3 py-1 rounded-full border border-blue-500/20">Point Play Balloon Blast</p>
        </div>
        <div class="glass-card rounded-3xl p-6 relative overflow-hidden flex flex-col items-center min-h-[400px]">
          <p class="text-xs text-slate-400 font-bold uppercase tracking-widest mb-6">Daily Levels Progress</p>
          <div class="relative w-full max-w-[250px] mx-auto py-4" id="level-map"></div>
        </div>
      </div>

      <div id="game-play-container" class="hidden flex flex-col gap-3">
        <div class="glass-card p-3 rounded-2xl flex justify-between items-center border border-blue-500/30 shrink-0 shadow-[0_4px_20px_rgba(0,0,0,0.5)]">
          <button onclick="exitGame()" class="w-10 h-10 rounded-xl bg-slate-800/50 flex items-center justify-center text-slate-400 hover:text-white active:scale-95 transition-all"><i class="fa-solid fa-arrow-left"></i></button>
          <div class="text-center">
            <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest">Level <span id="ingame-level-display">1</span></p>
            <p class="text-lg font-black text-white drop-shadow-[0_0_5px_rgba(255,255,255,0.5)]" id="ingame-score">0</p>
          </div>
          <div class="w-10 h-10"></div> 
        </div>

        <div id="game-canvas-container">
          <div id="splash-screen" class="absolute inset-0 flex flex-col items-center justify-center text-center transition-opacity duration-500 bg-[#050511] z-50">
            <div class="w-20 h-20 bg-blue-500/20 rounded-2xl flex items-center justify-center mb-4 border border-blue-400/50 shadow-[0_0_30px_rgba(59,130,246,0.5)]">
              <i class="fa-solid fa-gamepad text-4xl text-crypto-glow"></i>
            </div>
            <h1 class="text-2xl font-black text-white tracking-widest uppercase mb-1">Point Play</h1>
            <h2 class="text-lg font-bold text-blue-400 tracking-wider">Studios</h2>
          </div>
          <canvas id="balloon-canvas"></canvas>
          <div id="game-over-modal" class="absolute inset-0 bg-[#050511]/95 backdrop-blur-md z-20 flex flex-col items-center justify-center p-6 hidden fade-in">
            <h2 id="end-title" class="text-4xl font-black text-white mb-2 drop-shadow-[0_0_15px_#00f0ff]">Level Clear!</h2>
            <p id="end-reward" class="text-lg font-bold text-emerald-400 mb-8 bg-emerald-500/10 px-6 py-3 rounded-xl border border-emerald-500/30 shadow-[0_0_20px_rgba(16,185,129,0.2)]">+0 XP</p>
            <div class="flex gap-4 w-full max-w-[250px]">
              <button onclick="exitGame()" class="flex-1 py-3.5 rounded-xl bg-slate-800 text-white font-black uppercase text-xs tracking-wider shadow-lg active:scale-95 transition-all border border-slate-600">Back</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- TASKS VIEW -->
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
        <div id="missions-container" class="space-y-3"></div>
      </div>
    </div>

    <!-- REFERRALS VIEW (NEW) -->
    <div id="view-referrals" class="view-section hidden fade-in space-y-6 pb-4">
      <div class="text-center pt-2 relative">
        <h2 class="text-3xl font-black text-white tracking-tight drop-shadow-lg">Referrals</h2>
        <button onclick="document.getElementById('ref-info-modal').classList.remove('hidden')" class="absolute top-2 right-4 w-8 h-8 rounded-full bg-blue-500/20 text-blue-400 border border-blue-500/30 flex items-center justify-center shadow-lg active:scale-95 transition-all">
           <i class="fa-solid fa-info"></i>
        </button>
      </div>
      <div class="grid grid-cols-3 gap-2">
        <div class="glass-card p-3 rounded-2xl text-center border-t border-t-blue-500/30">
          <p class="text-[9px] text-slate-400 uppercase font-black mb-1">Total</p>
          <p id="ref-total" class="text-lg font-black text-white">0</p>
        </div>
        <div class="glass-card p-3 rounded-2xl text-center border-t border-t-amber-500/30">
          <p class="text-[9px] text-slate-400 uppercase font-black mb-1">Pending</p>
          <p id="ref-pending" class="text-lg font-black text-white">0</p>
        </div>
        <div class="glass-card p-3 rounded-2xl text-center border-t border-t-emerald-500/30">
          <p class="text-[9px] text-slate-400 uppercase font-black mb-1">Approved</p>
          <p id="ref-approved" class="text-lg font-black text-white">0</p>
        </div>
      </div>
      <div class="glass-card rounded-[1.5rem] p-4 text-center border border-slate-700/50">
        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3">Your Referral Link</p>
        <div class="bg-[#050511] p-3 rounded-xl border border-slate-800 text-[11px] text-blue-400 font-mono mb-3 truncate select-all" id="ref-link-display"></div>
        <div class="flex gap-2">
          <button onclick="copyRefLink()" class="flex-1 py-2.5 bg-slate-800 text-white text-[10px] font-black uppercase tracking-wider rounded-xl border border-slate-600 active:scale-95 transition-all">Copy Link</button>
          <button onclick="shareRefLink()" class="flex-1 py-2.5 bg-gradient-to-r from-blue-600 to-cyan-500 text-white text-[10px] font-black uppercase tracking-wider rounded-xl shadow-[0_0_15px_rgba(0,240,255,0.4)] active:scale-95 transition-all">Share on Telegram</button>
        </div>
      </div>
      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-4 mb-3">Referral List</h3>
        <div id="ref-list-container" class="space-y-2"></div>
      </div>
      <div>
        <h3 class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] pl-4 mb-3">Reward History</h3>
        <div id="ref-history-container" class="space-y-2"></div>
      </div>
    </div>

    <!-- BOXES VIEW -->
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
        <button onclick="openBox('bronze')" class="relative z-10 bg-gradient-to-b from-orange-600 to-orange-800 text-white shadow-[0_4px_15px_rgba(205,127,50,0.4)] active:scale-95 transition-all px-4 py-2 rounded-xl text-xs font-black uppercase tracking-wider">Open</button>
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
        <button onclick="openBox('silver')" class="relative z-10 bg-gradient-to-b from-slate-400 to-slate-600 text-crypto-dark shadow-[0_4px_15px_rgba(226,232,240,0.3)] active:scale-95 transition-all px-4 py-2 rounded-xl text-xs font-black uppercase tracking-wider">Open</button>
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
        <button onclick="openBox('gold')" class="relative z-10 bg-gradient-to-b from-yellow-400 to-amber-600 text-crypto-dark shadow-[0_4px_20px_rgba(255,184,0,0.6)] active:scale-95 transition-all px-4 py-2 rounded-xl text-xs font-black uppercase tracking-wider">Open</button>
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
              <img src="https://cryptologos.cc/logos/toncoin-ton-logo.png" class="w-5 h-5 opacity-70 transition-opacity" alt="TON">
            </div>
            <input type="text" id="wallet-address" placeholder="UQ..." class="w-full bg-[#050511]/50 border-2 border-slate-700/50 rounded-xl py-3.5 pl-12 pr-4 text-sm font-medium text-white focus:outline-none focus:border-blue-500 transition-colors placeholder-slate-600 shadow-inner">
          </div>
        </div>
        <div>
          <label class="block text-[10px] font-black text-slate-400 uppercase tracking-[0.15em] mb-2 ml-1">Amount (USD)</label>
          <div class="relative group">
            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
              <i class="fa-solid fa-dollar-sign text-slate-500 transition-colors text-lg"></i>
            </div>
            <input type="number" id="withdraw-amount" placeholder="10" min="10" step="1" class="w-full bg-[#050511]/50 border-2 border-slate-700/50 rounded-xl py-3.5 pl-12 pr-4 text-sm font-bold text-emerald-400 focus:outline-none focus:border-emerald-500 transition-colors placeholder-slate-600 shadow-inner">
          </div>
        </div>
        <button onclick="requestWithdrawal()" class="w-full py-3.5 mt-2 bg-gradient-to-r from-emerald-600 to-teal-500 active:scale-95 transition-all text-white font-black rounded-xl text-sm uppercase tracking-[0.15em] flex items-center justify-center gap-2 shadow-[0_10px_20px_rgba(16,185,129,0.3)]">
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
             <img id="profile-photo-large" src="https://via.placeholder.com/150" class="w-full h-full rounded-full border-4 border-[#0a0b1a] object-cover">
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
              <span class="text-[9px] font-bold text-slate-500 uppercase">Target: <span id="profile-next-xp-text" class="text-blue-400">250 XP</span></span>
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
          <div class="w-10 h-10 mx-auto bg-amber-500/10 border border-amber-500/20 text-amber-400 rounded-xl flex items-center justify-center mb-2 text-lg shadow-[0_0_15px_rgba(245,158,11,0.2)inset]"><i class="fa-solid fa-box-open"></i></div>
          <p class="text-[9px] text-slate-400 uppercase font-black tracking-[0.15em] mb-1">Boxes Opened</p>
          <p id="profile-boxes" class="text-xl font-black text-white">0</p>
        </div>
      </div>
    </div>

  </main>

  <!-- NAVIGATION -->
  <nav id="bottom-nav" class="fixed bottom-6 left-1/2 -translate-x-1/2 w-[calc(100%-1rem)] max-w-[420px] glass-card rounded-2xl z-50 shadow-[0_20px_40px_rgba(0,0,0,0.8)] border border-slate-700/50 backdrop-blur-xl">
    <div class="flex justify-between items-center px-2 py-2.5 relative">
      <button onclick="switchTab('home')" class="nav-btn nav-active text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all duration-300 relative group" data-target="home">
        <i class="fa-solid fa-house text-lg transition-transform group-active:scale-90"></i>
        <span class="text-[7.5px] font-black uppercase tracking-widest">Home</span>
      </button>
      <button onclick="switchTab('games')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all duration-300 relative group" data-target="games">
        <i class="fa-solid fa-gamepad text-lg transition-transform group-active:scale-90"></i>
        <span class="text-[7.5px] font-black uppercase tracking-widest">Games</span>
      </button>
      <button onclick="switchTab('tasks')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all duration-300 relative group" data-target="tasks">
        <i class="fa-solid fa-list-check text-lg transition-transform group-active:scale-90"></i>
        <span class="text-[7.5px] font-black uppercase tracking-widest">Tasks</span>
      </button>
      <button onclick="switchTab('referrals')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all duration-300 relative group" data-target="referrals">
        <i class="fa-solid fa-users text-lg transition-transform group-active:scale-90"></i>
        <span class="text-[7.5px] font-black uppercase tracking-widest">Friends</span>
      </button>
      <button onclick="switchTab('boxes')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all duration-300 relative group" data-target="boxes">
        <i class="fa-solid fa-box-open text-lg transition-transform group-active:scale-90"></i>
        <span class="text-[7.5px] font-black uppercase tracking-widest">Boxes</span>
      </button>
      <button onclick="switchTab('withdraw')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all duration-300 relative group" data-target="withdraw">
        <i class="fa-solid fa-wallet text-lg transition-transform group-active:scale-90"></i>
        <span class="text-[7.5px] font-black uppercase tracking-widest">Cash</span>
      </button>
      <button onclick="switchTab('profile')" class="nav-btn text-slate-500 flex flex-col items-center gap-1 flex-1 transition-all duration-300 relative group" data-target="profile">
        <i class="fa-solid fa-user-astronaut text-lg transition-transform group-active:scale-90"></i>
        <span class="text-[7.5px] font-black uppercase tracking-widest">Profile</span>
      </button>
    </div>
  </nav>

  <!-- Referral Info Modal -->
  <div id="ref-info-modal" class="fixed inset-0 bg-[#050511]/95 backdrop-blur-md z-[1000] hidden flex-col items-center justify-center p-6 fade-in">
      <div class="glass-card rounded-[2rem] p-6 w-full max-w-sm border border-blue-500/30 relative shadow-[0_0_30px_rgba(59,130,246,0.2)]">
          <h3 class="text-xl font-black text-white mb-4 text-center">Referral Requirements</h3>
          <ul class="space-y-3 text-sm text-slate-300 font-medium mb-6">
              <li class="flex items-center gap-3"><i class="fa-solid fa-video text-blue-400"></i> Watch 25 ads.</li>
              <li class="flex items-center gap-3"><i class="fa-solid fa-list-check text-blue-400"></i> Complete 5 tasks.</li>
              <li class="flex items-center gap-3"><i class="fa-solid fa-clock text-blue-400"></i> There is no deadline.</li>
              <li class="flex items-start gap-3 text-xs text-slate-400 mt-3 pt-3 border-t border-slate-800"><i class="fa-solid fa-circle-info mt-1 text-crypto-glow"></i> The referral remains pending until all requirements are completed. Once completed, the referral becomes approved and you receive 250 XP and $0.025.</li>
          </ul>
          <button onclick="document.getElementById('ref-info-modal').classList.add('hidden')" class="w-full py-3 bg-blue-600 text-white font-black uppercase rounded-xl active:scale-95 transition-all">Understood</button>
      </div>
  </div>

  <script>
    const tg = window.Telegram.WebApp;
    tg.expand(); 
    tg.ready();
    tg.setHeaderColor('#0a0b1a');
    tg.setBackgroundColor('#050511');

    const tgUser = tg.initDataUnsafe?.user || { id: 123456789, first_name: "John", username: "john123", photo_url: "" };
    const startParam = tg.initDataUnsafe?.start_param || null;

    let userState = {};
    let gameState = { levelsCompleted: Array(10).fill(false) };
    
    // Core API synchronisation logic
    async function apiCall(action, payload = {}) {
        payload.action = action;
        payload.tgId = tgUser.id;
        payload.firstName = tgUser.first_name;
        payload.username = tgUser.username;
        payload.startapp = startParam;
        
        try {
            const res = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            return await res.json();
        } catch (e) {
            console.error("API Error", e);
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

      setTimeout(() => { toast.classList.remove('toast-show'); }, 3000); 
    }

    // Daily Mission Config (Share removed)
    function getDefaultMissions() {
      return {
        dailyReward: { claimed: false, reward: 5, target: 0, label: 'Daily Login', icon: 'fa-gift', color: 'text-purple-400', bg: 'bg-purple-500/10 border-purple-500/20' },
        watch5: { claimed: false, reward: 20, target: 5, label: 'Watch 5 Ads', icon: 'fa-video', color: 'text-blue-400', bg: 'bg-blue-500/10 border-blue-500/20' },
        watch30: { claimed: false, reward: 50, target: 30, label: 'Watch 30 Ads', icon: 'fa-film', color: 'text-indigo-400', bg: 'bg-indigo-500/10 border-indigo-500/20' }
      };
    }

    function updateUI() {
      document.getElementById('user-name').innerText = userState.firstName;
      document.getElementById('user-xp').innerHTML = `${userState.xp.toLocaleString()} <span class="text-[10px] text-crypto-glow font-bold">XP</span>`;
      document.getElementById('user-usd').innerText = userState.usdBalance.toFixed(4);
      
      if (tgUser.photo_url) {
        document.getElementById('user-photo').src = tgUser.photo_url;
        document.getElementById('profile-photo-large').src = tgUser.photo_url;
      }
      
      document.getElementById('ref-link-display').innerText = `https://t.me/pointplayappbot?startapp=${userState.tgId}`;

      document.getElementById('main-xp-display').innerText = `${userState.xp.toLocaleString()} XP`;
      document.getElementById('ads-watched').innerText = userState.adsWatchedToday;
      document.getElementById('streak-days').innerText = userState.streak;

      document.getElementById('withdraw-balance-display').innerText = userState.usdBalance.toFixed(2);
      renderWithdrawHistory();

      document.getElementById('profile-name').innerText = userState.firstName;
      document.getElementById('profile-tgid').innerText = `ID: ${userState.tgId}`;
      document.getElementById('profile-total-xp').innerText = userState.totalXp.toLocaleString();
      document.getElementById('profile-boxes').innerText = userState.boxesOpened;
      
      updateProfileProgress();
      renderMissions();
      renderStreakTracker();
      renderGameMap();
    }

    function updateProfileProgress() {
      const xp = userState.totalXp;
      let nextLvlXp = 250, prevLvlXp = 0;
      if (xp >= 20000) { nextLvlXp = 20000; prevLvlXp = 20000; }
      else if (xp >= 3000) { nextLvlXp = 20000; prevLvlXp = 3000; }
      else if (xp >= 1500) { nextLvlXp = 3000; prevLvlXp = 1500; }
      else if (xp >= 750) { nextLvlXp = 1500; prevLvlXp = 750; }
      else if (xp >= 250) { nextLvlXp = 750; prevLvlXp = 250; }

      let progress = nextLvlXp !== prevLvlXp ? ((xp - prevLvlXp) / (nextLvlXp - prevLvlXp)) * 100 : 100;
      document.getElementById('profile-xp-progress').style.width = `${progress}%`;
      document.getElementById('profile-current-xp-text').innerText = `${xp.toLocaleString()} XP`;
      document.getElementById('profile-next-xp-text').innerText = (nextLvlXp === 20000 && xp >= 20000) ? 'MAX LVL' : `${nextLvlXp.toLocaleString()} XP`;
      document.getElementById('profile-badge-level').innerText = userState.level;
    }

    function renderStreakTracker() {
      const container = document.getElementById('streak-tracker-container');
      container.innerHTML = '';
      const streakRewards = [5, 10, 15, 20, 25, 30, 50];
      
      for (let i = 1; i <= 7; i++) {
        let dailyMission = userState.missions['dailyReward'] || getDefaultMissions().dailyReward;
        const isPast = i < userState.streak || (i === userState.streak && dailyMission.claimed);
        const isToday = i === userState.streak && !dailyMission.claimed;
        
        let styles = isPast ? "bg-emerald-500/20 border-emerald-500/50 text-emerald-400 shadow-[0_0_15px_rgba(16,185,129,0.3)]" : (isToday ? "bg-blue-600/30 border-crypto-glow shadow-[0_0_20px_rgba(0,240,255,0.5)] text-white" : "bg-[#050511] border-slate-700 text-slate-600");
        let icon = isPast ? `<i class="fa-solid fa-check text-xs"></i>` : `<span class="text-[9px] font-black">${streakRewards[i-1]}</span>`;
        let lineStyle = isPast ? "bg-emerald-500/50 shadow-[0_0_5px_#34d399]" : "bg-slate-800";

        container.innerHTML += `
          <div class="relative flex flex-col items-center gap-1.5 z-10 flex-1">
            <div class="w-9 h-9 rounded-xl border-2 flex items-center justify-center transition-all duration-300 ${styles} z-10 relative bg-[#0a0b1a]">${icon}</div>
            <span class="text-[8px] font-black tracking-widest ${isToday ? 'text-crypto-glow drop-shadow-[0_0_5px_#00f0ff]' : 'text-slate-500'}">DAY ${i}</span>
            ${i < 7 ? `<div class="absolute top-4 left-[50%] w-full h-1 -z-0 ${lineStyle} rounded-full"></div>` : ''}
          </div>
        `;
      }
    }

    function renderMissions() {
      const container = document.getElementById('missions-container');
      container.innerHTML = '';
      const missions = userState.missions || {};
      const defs = getDefaultMissions();

      Object.keys(defs).forEach(k => {
        const m = defs[k];
        const state = missions[k] || { claimed: false };
        let isComplete = false;
        let progressText = '';
        
        if (m.target > 0 && k !== 'dailyReward') {
            isComplete = userState.adsWatchedToday >= m.target;
            progressText = `(${Math.min(userState.adsWatchedToday, m.target)}/${m.target})`;
        } else if (k === 'dailyReward') {
            isComplete = true;
        }

        let btnHtml = state.claimed 
          ? `<span class="text-[10px] font-black bg-emerald-500/10 text-emerald-400 px-3 py-1.5 rounded-xl border border-emerald-500/30 shadow-inner">Claimed</span>`
          : (isComplete 
             ? `<button onclick="claimMission('${k}')" class="text-[10px] font-black bg-gradient-to-r from-blue-600 to-cyan-500 text-white px-4 py-1.5 rounded-xl shadow-[0_0_15px_rgba(0,240,255,0.4)] active:scale-95 transition-all uppercase">Claim</button>`
             : `<span class="text-[10px] font-black bg-slate-800/50 text-slate-200 px-3 py-1.5 rounded-xl border border-slate-600 shadow-inner">+${m.reward} XP</span>`);

        container.innerHTML += `
          <div class="glass-card rounded-2xl p-3 flex justify-between items-center border border-slate-800">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl border ${m.bg} flex items-center justify-center"><i class="fa-solid ${m.icon} ${m.color} text-lg"></i></div>
              <div class="flex flex-col">
                <span class="text-xs font-black text-white tracking-wide">${m.label}</span>
                <span class="text-crypto-glow text-[10px] font-bold tracking-widest uppercase opacity-80 mt-0.5">${progressText}</span>
              </div>
            </div>
            ${btnHtml}
          </div>
        `;
      });
    }

    async function claimMission(key) {
        let missions = userState.missions || {};
        if (missions[key] && missions[key].claimed) return;
        
        let mDef = getDefaultMissions()[key];
        if (key === 'dailyReward') mDef.reward = [5, 10, 15, 20, 25, 30, 50][userState.streak-1];
        
        missions[key] = { claimed: true };
        const res = await apiCall('completeTask', { reward: mDef.reward, missions: missions });
        if (res) {
            userState = res.user;
            updateUI();
            showToast('Task Completed!', `You earned ${mDef.reward} XP!`, 'success');
        }
    }

    function watchAd() {
      if (userState.adsWatchedToday >= 30) {
        showToast('Limit Reached', 'You have reached the daily limit of 30 ads.', 'error');
        return;
      }
      const btn = document.getElementById('watch-ad-btn');
      const originalHTML = btn.innerHTML;
      btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> <span>Loading Ad...</span>`;
      btn.classList.add('opacity-80', 'pointer-events-none');

      if (window.Adsgram) {
        const AdController = window.Adsgram.init({ blockId: "int-35545" });
        AdController.show().then(async () => {
          const res = await apiCall('watchAd');
          if (res) {
             userState = res.user;
             updateUI();
             showToast('Reward Granted!', 'Earned +10 XP and $0.0015.', 'success');
          }
          btn.innerHTML = originalHTML;
          btn.classList.remove('opacity-80', 'pointer-events-none');
        }).catch(() => {
          btn.innerHTML = originalHTML;
          btn.classList.remove('opacity-80', 'pointer-events-none');
        });
      } else {
        showToast('Error', 'Ad system unavailable.', 'error');
        btn.innerHTML = originalHTML;
        btn.classList.remove('opacity-80', 'pointer-events-none');
      }
    }

    async function openBox(type) {
      const boxCosts = { bronze: 10000, silver: 50000, gold: 100000 };
      const cost = boxCosts[type];
      if (userState.xp < cost) {
        showToast('Not enough XP', `You need ${cost.toLocaleString()} XP to open this box.`, 'error');
        return;
      }

      const isJackpot = Math.random() >= 0.9999;
      let reward = 0;
      if (type === 'bronze') reward = isJackpot ? 1.00 : 0.10;
      else if (type === 'silver') reward = isJackpot ? 7.00 : 0.50;
      else if (type === 'gold') reward = isJackpot ? 15.00 : 1.00;

      const res = await apiCall('openBox', { cost, reward });
      if (res) {
          userState = res.user;
          updateUI();
          if (isJackpot) showToast('HUGE JACKPOT! 💸', `Incredible! You won $${reward.toFixed(2)}!`, 'jackpot');
          else showToast('Box Opened!', `Congratulations! You won $${reward.toFixed(2)}!`, 'success');
      }
    }

    async function requestWithdrawal() {
      const address = document.getElementById('wallet-address').value;
      const amount = parseFloat(document.getElementById('withdraw-amount').value);

      if (!address || address.length < 10) { showToast('Invalid Address', 'Enter a valid TON wallet.', 'error'); return; }
      if (isNaN(amount) || amount < 10) { showToast('Invalid Amount', 'Minimum withdrawal is 10.', 'error'); return; }
      if (amount > userState.usdBalance) { showToast('Insufficient balance', 'Not enough funds.', 'error'); return; }

      const res = await apiCall('withdraw', { amount, address });
      if (res) {
          userState = res.user;
          updateUI();
          document.getElementById('wallet-address').value = '';
          document.getElementById('withdraw-amount').value = '';
          showToast('Requested', `Withdrawal for $${amount.toFixed(2)} submitted.`, 'success');
      }
    }

    function renderWithdrawHistory() {
      const container = document.getElementById('withdraw-history-container');
      if (userState.withdrawHistory.length === 0) {
        container.innerHTML = `<div class="glass-card rounded-2xl p-6 text-center border-dashed border-2 border-slate-700"><p class="text-xs font-bold text-slate-500 uppercase">History is Empty</p></div>`;
        return;
      }
      container.innerHTML = userState.withdrawHistory.map(r => `
        <div class="glass-card rounded-2xl p-4 flex justify-between items-center transition-transform hover:-translate-y-0.5">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-slate-800 flex items-center justify-center"><i class="fa-solid fa-arrow-right-arrow-left text-slate-400"></i></div>
            <div>
              <p class="text-xs font-black text-white">${r.id} <span class="text-[9px] text-slate-400 ml-1">${r.date}</span></p>
              <p class="text-[10px] text-blue-400 mt-0.5 font-mono bg-blue-500/10 px-1.5 py-0.5 rounded">${r.address}</p>
            </div>
          </div>
          <div class="text-right">
            <p class="text-sm font-black text-emerald-400">-$${r.amount.toFixed(2)}</p>
            <p class="text-[9px] font-black text-amber-400 uppercase tracking-widest mt-0.5">${r.status}</p>
          </div>
        </div>
      `).join('');
    }

    async function loadReferralsPage() {
        const res = await apiCall('getReferrals');
        if (res) {
            document.getElementById('ref-total').innerText = res.stats.total;
            document.getElementById('ref-pending').innerText = res.stats.pending;
            document.getElementById('ref-approved').innerText = res.stats.approved;
            
            document.getElementById('ref-list-container').innerHTML = res.referrals.map(r => `
                <div class="glass-card rounded-xl p-3 flex justify-between items-center border border-slate-800">
                   <div>
                     <p class="text-xs font-black text-white">${r.firstName} <span class="text-[10px] text-slate-500 font-mono ml-1">${r.username ? '@'+r.username : ''}</span></p>
                     <p class="text-[10px] text-slate-400 mt-1">Ads: ${r.totalAds} / 25 &nbsp; Tasks: ${r.totalTasks} / 5</p>
                   </div>
                   <span class="text-[9px] font-black uppercase tracking-widest px-2 py-1 rounded-full ${r.status === 'Approved' ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/50' : 'bg-amber-500/20 text-amber-400 border border-amber-500/50'}">${r.status}</span>
                </div>
            `).join('') || `<p class="text-xs text-slate-500 text-center py-4">No referrals yet.</p>`;

            document.getElementById('ref-history-container').innerHTML = userState.referralHistory.map(h => `
                <div class="glass-card rounded-xl p-3 flex justify-between items-center border border-slate-800">
                    <div>
                        <p class="text-xs font-black text-white">Referral Bonus</p>
                        <p class="text-[10px] text-slate-400 mt-0.5">Referral: ${h.referredName} &bull; ${h.date}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs font-black text-crypto-glow">+${h.bonusXp} XP</p>
                        <p class="text-[10px] font-black text-emerald-400">+$${h.bonusUsd.toFixed(3)}</p>
                    </div>
                </div>
            `).join('') || `<p class="text-xs text-slate-500 text-center py-4">No reward history.</p>`;
        }
    }

    function copyRefLink() {
        navigator.clipboard.writeText(`https://t.me/pointplayappbot?startapp=${userState.tgId}`).then(() => showToast('Copied!', 'Referral link copied!', 'success'));
    }

    function shareRefLink() {
        const link = `https://t.me/pointplayappbot?startapp=${userState.tgId}`;
        const text = "🎯 Play games, complete tasks, earn XP, and collect exciting rewards 🚀 I’m already playing on Point Play now it’s your turn to join the adventure👇";
        tg.openTelegramLink(`https://t.me/share/url?url=${encodeURIComponent(link)}&text=${encodeURIComponent(text)}`);
    }

    function switchTab(tabId) {
      if(tabId === 'referrals') loadReferralsPage();
      
      document.querySelectorAll('.view-section').forEach(el => el.classList.add('hidden'));
      document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('nav-active'));
      document.getElementById(`view-${tabId}`).classList.remove('hidden');
      document.querySelector(`[data-target="${tabId}"]`).classList.add('nav-active');
      
      const header = document.getElementById('main-header');
      const mainContent = document.getElementById('app-content');

      if (['withdraw', 'profile', 'games', 'referrals'].includes(tabId)) {
        header.style.transform = 'translateY(-100%)';
        setTimeout(() => header.style.display = 'none', 300);
        mainContent.classList.replace('pt-24', 'pt-4');
      } else {
        header.style.display = 'block'; 
        setTimeout(() => header.style.transform = 'translateY(0)', 10);
        mainContent.classList.replace('pt-4', 'pt-24');
      }
      
      if (tg.HapticFeedback) tg.HapticFeedback.selectionChanged();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    setInterval(() => {
        const now = new Date();
        const tomorrow = new Date(now); tomorrow.setUTCHours(24, 0, 0, 0); 
        const diff = tomorrow.getTime() - now.getTime();
        const h = Math.floor(diff / 1000 / 60 / 60);
        const m = Math.floor((diff / 1000 / 60) % 60);
        const s = Math.floor((diff / 1000) % 60);
        document.getElementById('reset-timer').innerText = `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
    }, 1000);

    // Completely Functional Mini Game Integration
    let gameLoopId;
    let balloons = [];
    const canvas = document.getElementById('balloon-canvas');
    const ctx = canvas.getContext('2d');
    let gameEngine = { isPlaying: false, score: 0, target: 0 };

    function renderGameMap() {
      const positions = [{left: '50%'}, {left: '70%'}, {left: '80%'}, {left: '60%'}, {left: '30%'}, {left: '20%'}, {left: '40%'}, {left: '60%'}, {left: '80%'}, {left: '50%'}];
      document.getElementById('level-map').innerHTML = Array.from({length: 10}).map((_, i) => {
        let isCompleted = gameState.levelsCompleted[i];
        let isCurrent = !isCompleted && (i === 0 || gameState.levelsCompleted[i-1]);
        let stateClass = isCompleted ? 'completed' : (isCurrent ? 'current cursor-pointer' : 'locked');
        let icon = (!isCompleted && !isCurrent) ? '<i class="fa-solid fa-lock text-lg"></i>' : (i + 1);
        let onClick = (isCurrent || isCompleted) ? `onclick="startGame(${i + 1})"` : ``;
        return `<div class="relative w-full flex justify-center mb-10 group" style="transform: translateX(calc(${positions[i].left} - 50%))">
             <div class="level-node ${stateClass} shadow-lg" ${onClick}>${icon}</div>
             ${i < 9 ? `<div class="absolute left-1/2 -translate-x-1/2 top-full h-8 w-1.5 ${isCompleted ? 'bg-crypto-glow' : 'bg-slate-700'} -z-10 rounded-full"></div>` : ''}
          </div>`;
      }).join('');
    }

    function startGame(level) {
        document.body.classList.add('in-game');
        document.getElementById('game-play-container').classList.add('fullscreen-mode');
        document.getElementById('game-menu-container').classList.add('hidden');
        document.getElementById('game-play-container').classList.remove('hidden');
        document.getElementById('game-over-modal').classList.add('hidden');
        document.getElementById('splash-screen').style.display = 'flex';
        
        setTimeout(() => { document.getElementById('splash-screen').style.display = 'none'; }, 1000);

        canvas.width = canvas.parentElement.clientWidth; canvas.height = canvas.parentElement.clientHeight;
        gameEngine.isPlaying = true; gameEngine.score = 0; gameEngine.target = level * 10;
        balloons = [];
        document.getElementById('ingame-level-display').innerText = level;
        
        function loop() {
            if(!gameEngine.isPlaying) return;
            ctx.clearRect(0,0, canvas.width, canvas.height);
            if(Math.random() < 0.04) balloons.push({ x: Math.random()*(canvas.width-40)+20, y: canvas.height+30, r: 25, color: ['#ef4444', '#3b82f6', '#10b981', '#eab308'][Math.floor(Math.random()*4)] });
            balloons.forEach((b, i) => {
                b.y -= 3 + (level * 0.4);
                ctx.beginPath(); ctx.arc(b.x, b.y, b.r, 0, Math.PI*2); ctx.fillStyle = b.color; ctx.fill();
                if(b.y < -30) balloons.splice(i, 1);
            });
            document.getElementById('ingame-score').innerText = `${gameEngine.score} / ${gameEngine.target}`;
            if(gameEngine.score >= gameEngine.target) { endGame(true, level); return; }
            gameLoopId = requestAnimationFrame(loop);
        }
        loop();
    }
    
    canvas.addEventListener('pointerdown', (e) => {
        const rect = canvas.getBoundingClientRect(); const x = e.clientX - rect.left; const y = e.clientY - rect.top;
        balloons.forEach((b, i) => { if(Math.hypot(b.x - x, b.y - y) < b.r) { balloons.splice(i, 1); gameEngine.score++; } });
    });

    async function endGame(win, level) {
        gameEngine.isPlaying = false; cancelAnimationFrame(gameLoopId);
        document.getElementById('game-over-modal').classList.remove('hidden');
        const reward = win ? level * 50 : 0;
        document.getElementById('end-reward').innerText = `+${reward} XP`;
        if (win) {
            gameState.levelsCompleted[level-1] = true;
            const res = await apiCall('completeTask', { reward });
            if (res) userState = res.user;
            await apiCall('saveGameState', { gameState });
        }
    }

    function exitGame() {
      gameEngine.isPlaying = false;
      document.body.classList.remove('in-game');
      document.getElementById('game-play-container').classList.remove('fullscreen-mode');
      document.getElementById('game-play-container').classList.add('hidden');
      document.getElementById('game-menu-container').classList.remove('hidden');
      renderGameMap();
    }

    // Startup Execution
    (async function initApp() {
      const res = await apiCall('sync');
      if (res && res.user) {
          userState = res.user;
          gameState = userState.gameState && userState.gameState.levelsCompleted ? userState.gameState : { levelsCompleted: Array(10).fill(false) };
          updateUI();
      }
      setTimeout(() => {
        document.getElementById('loading-overlay').style.opacity = '0';
        setTimeout(() => document.getElementById('loading-overlay').style.display = 'none', 500);
      }, 1000);
    })();
  </script>
</body>
</html>
